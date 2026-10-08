<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\ActivityHeartRateTail;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/**
 * Reconciles contradictory summary counters against original clocks and corroborated timer spans.
 *
 * @phpstan-type Summary array{message: int, sequence: int, number: int, word: string, timestamp: ?int, offsets: array<int, int>, timer: ?int, sessions?: ?int, start?: ?int, elapsed?: ?int, index?: ?int, first_lap?: ?int, num_laps?: ?int, best_lap?: ?int}
 * @phpstan-type StartedSummary array{message: int, sequence: int, number: int, word: string, timestamp: ?int, offsets: array<int, int>, timer: ?int, start: ?int, elapsed: ?int, index: ?int, first_lap?: ?int, num_laps?: ?int, best_lap?: ?int}
 * @phpstan-type TimerEvent array{timestamp: int, type: ?int, group: ?int, sequence: int}
 */
final readonly class RecordedSummaryDurationReconciler
{
    public const string RULE = 'summary_durations_from_recorded_timer_partition';

    public const string CLOCK_RULE = 'incomplete_summaries_from_recorded_timer_partition';

    public function repair(string $bytes, bool $inferMissingClocks = false): RepairResult
    {
        if (strlen($bytes) > 67_108_864) {
            throw new RepairFailed('Summary duration reconciliation is bounded to 64 MiB.');
        }
        [$member, $tail] = ActivityHeartRateTail::split($bytes);
        if ('' !== $tail) {
            return ActivityHeartRateTail::append($bytes, $tail, $this->repair($member, $inferMissingClocks));
        }
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new RepairFailed('Cannot allocate recorded timer input.');
        }
        $session = $activity = null;
        $laps = $sessions = $events = $sessionEnds = $opaqueClocks = [];
        $firstRecord = $firstRecordSequence = $lastRecord = $lastRecordSequence = $lastLengthStart = $lastLengthEnd = null;
        $lastLengthSave = $lastLengthSequence = null;
        $records = $ordinal = $fileIds = $lengths = 0;
        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill recorded timer input.');
            }
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;
            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || $header->protocolMajorVersion() > 2) {
                throw new RepairFailed('Summary duration reconciliation requires one complete supported Activity member.');
            }
            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }
                $number = $raw->globalMessageNumber();
                if (0 === $ordinal && 0 !== $number) {
                    throw new RepairFailed('Original Activity FileId must be first.');
                }
                if (in_array($number, [0, 18, 19, 20, 21, 34, 101], true)) {
                    $message = PhysicalMessage::fromRaw($raw, 0);
                    if (0 === $number) {
                        if (++$fileIds > 1 || 4 !== $this->scalar($message, 0, 0x00, 1)) {
                            throw new RepairFailed('Exactly one original Activity FileId is required.');
                        }
                    } elseif (101 === $number) {
                        $lengthStart = $this->scalar($message, 2, 0x86, 4);
                        $lengthElapsed = $this->scalar($message, 3, 0x86, 4);
                        if (null === $lengthStart || $lengthStart < 0x10000000 || null === $lengthElapsed || $lengthElapsed < 0
                            || (0 === $lengthElapsed && 0 !== $this->scalar($message, 12, 0x00, 1))
                            || (null !== $lastLengthStart && $lengthStart < $lastLengthStart)
                            || (null !== $lastLengthEnd && $lengthStart * 1000 <= $lastLengthEnd - 1000)) {
                            throw new RepairFailed('Original Length intervals must be complete and nonoverlapping within their one-second resolution.');
                        }
                        $lastLengthStart = $lengthStart;
                        $lastLengthEnd = $lengthStart * 1000 + $lengthElapsed;
                        $lastLengthSave = $this->scalar($message, 253, 0x86, 4);
                        $lastLengthSequence = $raw->sequence();
                        ++$lengths;
                    } elseif (20 === $number) {
                        $time = $this->scalar($message, 253, 0x86, 4, true);
                        if (null === $time || $time < 0x10000000 || (null !== $lastRecord && $time < $lastRecord)) {
                            throw new RepairFailed('Original Records must retain ordered absolute timestamps.');
                        }
                        $firstRecord ??= $time;
                        $firstRecordSequence ??= $raw->sequence();
                        $lastRecord = $time;
                        $lastRecordSequence = $raw->sequence();
                        ++$records;
                    } else {
                        if ($message->wasCompressed) {
                            throw new RepairFailed('Original summaries and Events must have uncompressed scalar clocks.');
                        }
                        $time = $this->scalar($message, 253, 0x86, 4);
                        if ((null === $time && (!$inferMissingClocks || 21 === $number)) || (null !== $time && $time < 0x10000000)) {
                            throw new RepairFailed('Every retained summary and Event must have an absolute clock.');
                        }
                        if (21 === $number) {
                            $kind = $this->scalar($message, 0, 0x00, 1);
                            if (null === $kind) {
                                if (1 !== count($message->fields) || [] !== $message->developerFields) {
                                    throw new RepairFailed('Unknown Event kinds cannot exclude a timer contradiction.');
                                }
                                $opaqueClocks[] = ['timestamp' => $time, 'sequence' => $raw->sequence()];
                            }
                            if (0 === $kind) {
                                $events[] = ['timestamp' => $time, 'type' => $this->scalar($message, 1, 0x00, 1), 'group' => $this->scalar($message, 4, 0x02, 1), 'sequence' => $raw->sequence()];
                            } elseif (8 === $kind) {
                                $sessionEnds[] = ['timestamp' => $time, 'type' => $this->scalar($message, 1, 0x00, 1), 'group' => $this->scalar($message, 4, 0x02, 1), 'sequence' => $raw->sequence()];
                            }
                        } else {
                            $entry = ['message' => $ordinal, 'sequence' => $raw->sequence(), 'number' => $number,
                                'word' => FitArchitecture::LittleEndian === $message->architecture ? 'V' : 'N', 'timestamp' => $time, 'offsets' => []];
                            $position = $raw->byteOffset() + 1;
                            foreach ($raw->standardFields() as $field) {
                                $entry['offsets'][$field->definition->fieldNumber] = $position;
                                $position += $field->definition->size;
                            }
                            $entry['timer'] = $this->scalar($message, 34 === $number ? 0 : 8, 0x86, 4);
                            if (34 === $number) {
                                $entry['sessions'] = $this->scalar($message, 1, 0x84, 2);
                                if (null !== $activity) {
                                    throw new RepairFailed('One original Activity is required.');
                                }
                                $activity = $entry;
                            } else {
                                $entry += ['start' => $this->scalar($message, 2, 0x86, 4), 'elapsed' => $this->scalar($message, 7, 0x86, 4), 'index' => $this->scalar($message, 254, 0x84, 2)];
                                if (18 === $number) {
                                    $sessions[] = $entry + ['first_lap' => $this->scalar($message, 25, 0x84, 2), 'num_laps' => $this->scalar($message, 26, 0x84, 2), 'best_lap' => $this->scalar($message, 70, 0x84, 2)];
                                    $session ??= $sessions[0];
                                } else {
                                    $laps[] = $entry;
                                }
                            }
                        }
                    }
                }
                if (count($sessions) > 256 || count($laps) > 4096 || count($events) > 4096 || count($sessionEnds) > 4096 || count($opaqueClocks) > 4096) {
                    throw new RepairFailed('Duration reconciliation requires at most 4096 Laps and timer Events.');
                }
                ++$ordinal;
            }
            $file->trailer();
        } finally {
            fclose($stream);
        }
        if (1 !== $fileIds || null === $session || null === $activity || $records < 2) {
            throw new RepairFailed('Complete original summaries, Records and timer pairs are required.');
        }
        if (count($sessions) > 1) {
            if (0 !== $lengths || [] !== $opaqueClocks) {
                throw new RepairFailed('Multiple Session reconciliation requires unambiguous non-pool timer evidence.');
            }

            return $this->repairMultipleSessions($bytes, $sessions, $activity, $laps, $events, $firstRecord, $lastRecord, $records, $header->headerSize);
        }
        if (!in_array($activity['sessions'], [null, 1], true)) {
            throw new RepairFailed('One original Activity must reference the sole Session.');
        }
        $withoutLaps = [] === $laps;
        if ($withoutLaps) {
            if ($inferMissingClocks || 0 !== $lengths || count($events) < 2 || !in_array($session['index'], [null, 0], true)
                || !in_array($session['first_lap'], [null, 0], true) || !in_array($session['num_laps'], [null, 0, 1], true)) {
                throw new RepairFailed('A Session without Laps requires complete timer evidence and no reference to lost segmentation.');
            }
            // A temporary calculation interval is not an inserted Lap. A separate preserving rule may later copy the reconciled Session.
            $laps = [$session];
        }
        $nativeSessionSave = $session['timestamp'];
        $nativeActivitySave = $activity['timestamp'];
        $clockTargets = [];
        if ($inferMissingClocks) {
            $this->completeClocks($session, $activity, $laps, $events, $clockTargets);
            if (null !== $firstRecord && null !== $session['start'] && $firstRecord < $session['start'] - 1
                && [] !== $events && abs($events[0]['timestamp'] - $session['start']) <= 1 && abs($laps[0]['start'] - $session['start']) <= 1
                && null !== $nativeSessionSave && null !== $nativeActivitySave && $nativeSessionSave >= $lastRecord && $nativeActivitySave >= $lastRecord
                && $session['sequence'] > $lastRecordSequence && $activity['sequence'] > $session['sequence']) {
                // The sole original Session owns the observed prefix; its first START still controls active time.
                foreach ([$session, $laps[0]] as $entry) {
                    $merged = false;
                    foreach ($clockTargets as &$target) {
                        if ($target[0]['message'] === $entry['message'] && 2 === $target[1]) {
                            $target[3] = $firstRecord;
                            $merged = true;
                        }
                    }
                    unset($target);
                    if (!$merged) {
                        $clockTargets[] = [$entry, 2, $entry['start'], $firstRecord];
                    }
                }
                $session['start'] = $laps[0]['start'] = $firstRecord;
            }
        }
        foreach ([$session, ...$laps] as $entry) {
            if (null === $entry['start'] || $entry['start'] < 0x10000000) {
                throw new RepairFailed('Native summary start clocks must be complete.');
            }
        }
        $start = $session['start'];
        $nativeEnd = null;
        $frozenSessionEnd = 1 === count($sessionEnds) && 9 === $sessionEnds[0]['type'] && in_array($sessionEnds[0]['group'], [null, 0], true)
            && $sessionEnds[0]['timestamp'] === $start && $session['timestamp'] === $start && $activity['timestamp'] === $start
            && $sessionEnds[0]['sequence'] > $lastRecordSequence && $sessionEnds[0]['sequence'] < $session['sequence'] && $session['sequence'] < $activity['sequence'];
        $nativeRecordEnd = count($events) < 2 && ([] === $sessionEnds || $frozenSessionEnd) && 0 === $lengths && count($laps) >= 2;
        $frozenSaves = $session['timestamp'] === $start && $activity['timestamp'] === $start;
        foreach ($laps as $lap) {
            $frozenSaves = $frozenSaves && ($frozenSessionEnd || $lap['timestamp'] === $lap['start']);
        }
        if ($nativeRecordEnd && null !== $session['elapsed'] && $session['elapsed'] > 0) {
            $nativeEnd = $start + intdiv($session['elapsed'], 1000);
            $nativeRecordEnd = null !== $lastRecord && abs($lastRecord - $nativeEnd) <= 1
                && ($frozenSaves || (abs($session['timestamp'] - $nativeEnd) <= 1 && abs($activity['timestamp'] - $nativeEnd) <= 1));
        } else {
            $nativeRecordEnd = false;
        }
        $completeNativeElapsedPartition = count($laps) >= 2 && $session['elapsed'] > 0
            && 0 === count(array_filter($laps, static fn (array $lap): bool => null === $lap['elapsed'] || $lap['elapsed'] <= 0))
            && $session['elapsed'] === array_sum(array_column($laps, 'elapsed'));
        $nativeUnpaused = count($events) < 2 && $session['elapsed'] > 0 && $session['elapsed'] === $session['timer']
            && ($session['timer'] === $activity['timer'] || (null === $activity['timer'] && $completeNativeElapsedPartition))
            && ($nativeRecordEnd || (1 === count($sessionEnds) && 4 === $sessionEnds[0]['type'] && in_array($sessionEnds[0]['group'], [null, 0], true)));
        foreach ($laps as $lap) {
            $nativeUnpaused = $nativeUnpaused && null !== $lap['elapsed']
                && ($lap['elapsed'] === $lap['timer'] || (null === $lap['timer'] && $completeNativeElapsedPartition));
        }
        if ([] !== $opaqueClocks && (!$nativeUnpaused || !$nativeRecordEnd || !$frozenSessionEnd || [] !== $events
            || 1 !== count($opaqueClocks) || $opaqueClocks[0]['timestamp'] !== $start || $opaqueClocks[0]['sequence'] >= $firstRecordSequence)) {
            throw new RepairFailed('A timestamp-only Event requires independently corroborated native unpaused totals and frozen Session SAVE clocks.');
        }
        $terminalSessionStop = count($events) >= 3 && 0 === $events[array_key_last($events)]['type']
            && 1 === count($sessionEnds) && 4 === $sessionEnds[0]['type'] && in_array($sessionEnds[0]['group'], [null, 0], true);
        $singleStartSessionStop = !$nativeUnpaused && 1 === count($events) && 0 === $events[0]['type'] && $events[0]['timestamp'] === $firstRecord
            && $events[0]['timestamp'] >= $start && 1 === count($sessionEnds) && 4 === $sessionEnds[0]['type'] && in_array($sessionEnds[0]['group'], [null, 0], true)
            && null !== $session['elapsed'] && $session['elapsed'] > 0 && $session['timer'] === $session['elapsed'] && $activity['timer'] === $session['timer']
            && count($laps) >= 2 && 0 === count(array_filter($laps, static fn (array $lap): bool => null === $lap['elapsed']))
            && abs(array_sum(array_column($laps, 'elapsed')) - $session['elapsed']) < 1000;
        $frozenTerminalStop = count($events) >= 3 && 0 === $events[array_key_last($events)]['type'] && 0 === $lengths
            && 1 === count($sessionEnds) && 9 === $sessionEnds[0]['type']
            && 0 === $session['index'] && 0 === $session['first_lap'] && count($laps) === $session['num_laps'] && 1 === $activity['sessions']
            && $session['timestamp'] === $start && in_array($activity['timestamp'], [$start, $sessionEnds[0]['timestamp']], true)
            && $session['sequence'] < $firstRecordSequence && $sessionEnds[0]['sequence'] > $lastRecordSequence && $activity['sequence'] > $sessionEnds[0]['sequence']
            && $sessionEnds[0]['timestamp'] === $lastRecord && $session['timer'] > 0 && $session['timer'] === array_sum(array_column($laps, 'timer'))
            && in_array($activity['timer'], [$session['timer'], ($sessionEnds[0]['timestamp'] - $start) * 1000], true);
        foreach ($laps as $lap) {
            $frozenTerminalStop = $frozenTerminalStop && $lap['timestamp'] === $start && $lap['sequence'] < $firstRecordSequence && null !== $lap['timer'];
        }
        $terminalSessionStop = $terminalSessionStop || $singleStartSessionStop || $frozenTerminalStop;
        if (count($events) < 2 && !$nativeUnpaused && !$singleStartSessionStop) {
            throw new RepairFailed('A missing timer STOP requires matching native unpaused totals and one recorded Session end.');
        }
        $stop = $nativeUnpaused && $nativeRecordEnd ? $nativeEnd : ($nativeUnpaused || $terminalSessionStop ? $sessionEnds[0]['timestamp'] : $events[array_key_last($events)]['timestamp']);
        if ($withoutLaps && (null === $session['timestamp'] || abs($session['timestamp'] - $stop) > 1)) {
            throw new RepairFailed('A Session without Laps must independently corroborate the final timer STOP with its native SAVE.');
        }
        $observedPrefix = $inferMissingClocks && $firstRecord === $start && [] !== $events && $events[0]['timestamp'] > $start + 1
            && [] !== array_filter($clockTargets, static fn (array $target): bool => 18 === $target[0]['number'] && 2 === $target[1] && null !== $target[2] && $target[2] > $target[3] + 1);
        $timerStop = $stop;
        $lastLap = $laps[array_key_last($laps)];
        $nativeStoppedTail = !$withoutLaps && 0 === $lengths && null !== $lastRecord && null !== $session['elapsed']
            && abs(($lastRecord - $start) * 1000 - $session['elapsed']) < 1000
            && 0 === count(array_filter($laps, static fn (array $lap): bool => null === $lap['elapsed'] || $lap['elapsed'] <= 0))
            && abs(array_sum(array_column($laps, 'elapsed')) - $session['elapsed']) < 1000
            && $lastLap['timestamp'] === $lastRecord
            && ($lastLap['sequence'] > $lastRecordSequence || 0 === count(array_filter($laps, static fn (array $lap): bool => $lap['sequence'] >= $firstRecordSequence)))
            && $session['sequence'] > $lastLap['sequence']
            && $session['timestamp'] === $stop && $activity['timestamp'] === $stop;
        $bufferedPoolTail = 0 < $lengths && 0 === $session['index'] && 0 === $session['first_lap']
            && count($laps) === $session['num_laps'] && in_array($activity['sessions'], [null, 1], true)
            && $session['sequence'] < $firstRecordSequence && $activity['sequence'] < $firstRecordSequence
            && $lastLap['timestamp'] === $lastRecord && $lastLengthSave === $lastRecord && $lastLengthSequence < $lastLap['sequence']
            && null !== $lastLengthEnd && $lastLengthEnd < ($lastRecord + 1) * 1000
            && null !== $session['timestamp'] && null !== $activity['timestamp']
            && $session['timestamp'] >= $lastRecord && $activity['timestamp'] >= $lastRecord;
        $stoppedRecordTail = !$nativeUnpaused && !$terminalSessionStop && null !== $lastRecord && $lastRecord > $stop + 1
            && null !== $session['timestamp'] && null !== $activity['timestamp']
            && (($session['timestamp'] >= $lastRecord && $activity['timestamp'] >= $lastRecord) || $nativeStoppedTail)
            && (($session['sequence'] > $lastRecordSequence && $activity['sequence'] > $session['sequence']) || $bufferedPoolTail);
        if ($stoppedRecordTail) {
            // Records after a complete STOP extend the observed interval, never the active spans.
            $stop = $lastRecord;
        }
        $terminalLapTail = !$nativeUnpaused && !$terminalSessionStop && 0 === $lengths && null !== $lastLap['start'] && $lastLap['start'] > $stop
            && null !== $lastLap['elapsed'] && $lastLap['elapsed'] > 0 && $lastLap['elapsed'] < 1000 && $lastLap['elapsed'] === $lastLap['timer']
            && $lastLap['timestamp'] === $lastLap['start'] && $session['timestamp'] === $lastLap['start'] && $activity['timestamp'] === $lastLap['start']
            && null !== $lastRecord && $lastRecord <= $timerStop + 1 && $lastLap['sequence'] > $events[array_key_last($events)]['sequence']
            && $session['sequence'] > $lastLap['sequence'] && $activity['sequence'] > $session['sequence']
            && 0 === count(array_filter($laps, static fn (array $lap): bool => null === $lap['elapsed']))
            && $session['elapsed'] === array_sum(array_column($laps, 'elapsed'));
        if ($terminalLapTail) {
            $stop = $lastLap['start'];
        }
        if (abs($laps[0]['start'] - $start) > 1
            || ([] !== $events && ($events[0]['timestamp'] < $start - 1 || (!$observedPrefix && !$singleStartSessionStop && $events[0]['timestamp'] > $start + 1)))
            || null === $lastRecord || $lastRecord > $stop + 1 || null === $firstRecord || $firstRecord < $start - 1
            || $stop <= $start) {
            throw new RepairFailed('Original references and timer/Record endpoints must establish the entire Session.');
        }
        if ($nativeUnpaused && (abs(($stop - $start) * 1000 - $session['elapsed']) >= 1000 || (!$nativeRecordEnd && ($session['timestamp'] !== $stop || $activity['timestamp'] !== $stop))
            || $lastRecord < $stop - 1 || ([] !== $events && (0 !== $events[0]['type'] || !in_array($events[0]['group'], [null, 0], true))))) {
            throw new RepairFailed('Native unpaused totals must agree with the recorded Session end, both SAVE clocks and the last Record within one second.');
        }
        if ($terminalSessionStop && !$frozenTerminalStop && ($session['elapsed'] <= 0 || $session['timer'] < 0 || $session['timer'] > $session['elapsed']
            || $activity['timer'] !== $session['timer'] || abs(($stop - $start) * 1000 - $session['elapsed']) >= 1000
            || $session['timestamp'] !== $stop || $activity['timestamp'] !== $stop || $lastRecord < $stop - 1
            || $sessionEnds[0]['sequence'] <= $events[array_key_last($events)]['sequence'] || $sessionEnds[0]['sequence'] <= $lastRecordSequence
            || $sessionEnds[0]['sequence'] >= $session['sequence'] || $session['sequence'] >= $activity['sequence'])) {
            throw new RepairFailed('A terminal Session STOP_ALL requires matching native totals, both SAVE clocks, final Record and original message order.');
        }
        if ($frozenTerminalStop && (null === $session['elapsed'] || $session['elapsed'] <= 0 || $session['timer'] > $session['elapsed']
            || abs(($stop - $start) * 1000 - $session['elapsed']) > 1000 || $sessionEnds[0]['sequence'] <= $events[array_key_last($events)]['sequence'])) {
            throw new RepairFailed('A terminal Session STOP_DISABLE_ALL requires the final Record, front summary order and corroborating native elapsed/timer totals.');
        }
        if ($singleStartSessionStop) {
            foreach ($laps as $index => $lap) {
                $end = $laps[$index + 1]['start'] ?? $stop;
                if (abs(($end - $lap['start']) * 1000 - $lap['elapsed']) >= 1000) {
                    throw new RepairFailed('A single START closed by Session STOP_ALL requires every native Lap elapsed counter to corroborate its unchanged clock interval.');
                }
            }
        }
        $active = 0;
        $running = null;
        $spans = [];
        $wholeSecondTotals = false;
        $redundantStops = $redundantSingleStops = $disabledStops = $redundantStarts = 0;
        foreach ($events as $index => $event) {
            if (!in_array($event['group'], [null, 0], true) || !in_array($event['type'], [0, 1, 4, 9], true)
                || ($index > 0 && $event['timestamp'] < $events[$index - 1]['timestamp'])) {
                throw new RepairFailed('Timer evidence must be complete, ordered START/STOP transitions in one group.');
            }
            if (9 === $event['type']) {
                if (null !== $running || 0 === $index || $event['timestamp'] !== $events[$index - 1]['timestamp']
                    || !in_array($events[$index - 1]['type'], [1, 4], true)) {
                    throw new RepairFailed('STOP_DISABLE_ALL may only confirm an already recorded STOP at the same clock.');
                }
                ++$disabledStops;
                continue;
            }
            if (0 === $event['type']) {
                if (null !== $running) {
                    if ($event['timestamp'] === $running && $index > 0 && 0 === $events[$index - 1]['type']) {
                        ++$redundantStarts;
                        continue;
                    }
                    throw new RepairFailed('A repeated START cannot establish a missing STOP boundary.');
                }
                $running = $event['timestamp'];
            } elseif (null !== $running) {
                $spans[] = ['start' => $running, 'stop' => $event['timestamp']];
                $active += ($event['timestamp'] - $running) * 1000;
                $running = null;
            } else {
                if (4 === $event['type']) {
                    ++$redundantStops;
                } else {
                    ++$redundantSingleStops;
                }
            }
        }
        if ($terminalSessionStop) {
            if (null === $running || $running > $stop) {
                throw new RepairFailed('The recorded Session end may close only the final running timer span.');
            }
            $spans[] = ['start' => $running, 'stop' => $stop];
            $active += ($stop - $running) * 1000;
            $running = null;
            $wholeSecondTotals = $frozenTerminalStop && 0 === $session['timer'] % 1000
                && 0 === count(array_filter($laps, static fn (array $lap): bool => 0 !== $lap['timer'] % 1000));
            if (!$singleStartSessionStop && abs($active - $session['timer']) > count($spans) * ($wholeSecondTotals ? 1000 : 999)) {
                throw new RepairFailed('Native Session and Activity timer totals contradict the recorded spans within their per-span one-second rounding bounds.');
            }
        }
        if (!$nativeUnpaused && (0 !== $events[0]['type'] || null !== $running || [] === $spans)) {
            throw new RepairFailed('Original timer transitions must start running and finish stopped.');
        }
        $elapsed = $nativeUnpaused ? $session['elapsed'] : ($stop - $start) * 1000;
        if ($terminalLapTail) {
            $elapsed += $lastLap['elapsed'];
        }
        if (null !== $lastLengthEnd && $lastLengthEnd >= ($stop + 1) * 1000) {
            throw new RepairFailed('A native Length endpoint beyond the final timer clock resolution cannot bound the Session.');
        }
        if (!$nativeUnpaused && null !== $lastLengthEnd && $lastLengthEnd > $stop * 1000 && $lastLengthEnd < ($stop + 1) * 1000) {
            $elapsed = $lastLengthEnd - $start * 1000;
        }
        if ($nativeUnpaused) {
            $active = $session['timer'];
        }
        if ($active > $elapsed) {
            throw new RepairFailed('Recorded active spans cannot exceed the retained Session clock interval.');
        }
        $contradiction = [] !== $clockTargets || null === $session['elapsed'] || null === $session['timer'] || null === $activity['timer'] || $session['timer'] - $session['elapsed'] >= 1000 || $lastRecord > $start + intdiv($session['elapsed'], 1000) + 1
            || abs(array_sum(array_column($laps, 'elapsed')) - $session['elapsed']) > 1000 || abs(array_sum(array_column($laps, 'timer')) - $session['timer']) > 1000;
        $targets = $clockTargets;
        $partition = [];
        $gappedIndexes = !$withoutLaps && 0 === $lengths && 0 === $session['index'] && 0 === $session['first_lap'] && count($laps) === $session['num_laps']
            && null === $session['best_lap'] && 1 === $activity['sessions'] && 0 === $laps[0]['index'] && $session['timer'] > 0
            && $session['timer'] === array_sum(array_column($laps, 'timer'));
        $previousIndex = -1;
        $indexMapping = [];
        foreach ($laps as $index => $lap) {
            $gappedIndexes = $gappedIndexes && null !== $lap['index'] && $lap['index'] > $previousIndex && $lap['index'] <= 4095 && null !== $lap['timer'];
            $previousIndex = $lap['index'];
            if ($lap['index'] !== $index) {
                $indexMapping[] = ['sequence' => $lap['sequence'], 'from' => $lap['index'], 'to' => $index];
            }
        }
        $gappedIndexes = $gappedIndexes && [] !== $indexMapping;
        $contradiction = $contradiction || $gappedIndexes;
        $references = $withoutLaps ? [] : [[$activity, 1, $activity['sessions'], 1], [$session, 254, $session['index'], 0], [$session, 25, $session['first_lap'], 0], [$session, 26, $session['num_laps'], count($laps)]];
        foreach ($laps as $index => $lap) {
            $end = $laps[$index + 1]['start'] ?? $stop;
            $endMs = isset($laps[$index + 1]) ? $end * 1000 : $start * 1000 + $elapsed;
            $nativeEmptyLap = 0 === $lap['elapsed'] && 0 === $lap['timer'] && $lap['timestamp'] === $lap['start'];
            if ($lap['start'] < $start || $endMs < $lap['start'] * 1000 || ($lap['start'] * 1000 === $endMs && !$nativeEmptyLap)
                || $endMs > $start * 1000 + $elapsed) {
                throw new RepairFailed('Every original Lap must have an ordered clock interval inside the Session; an empty interval requires native zero counters and a matching SAVE.');
            }
            if (!$withoutLaps) {
                $references[] = [$lap, 254, $lap['index'], $index];
            }
            if (null === $lap['elapsed'] || null === $lap['timer']) {
                $contradiction = true;
            }
            if ($index > 0 && null !== $laps[$index - 1]['elapsed'] && abs($lap['start'] - $laps[$index - 1]['start'] - intdiv($laps[$index - 1]['elapsed'], 1000)) > 2) {
                $contradiction = true;
            }
            $timer = 0;
            $intersections = 0;
            foreach ($spans as $span) {
                $overlap = max(0, min($end, $span['stop']) - max($lap['start'], $span['start']));
                $timer += $overlap * 1000;
                $intersections += $overlap > 0 ? 1 : 0;
            }
            if ($frozenTerminalStop && abs($timer - $lap['timer']) > $intersections * (0 === $lap['timer'] % 1000 ? 1000 : 999)) {
                throw new RepairFailed('Each frozen native Lap timer must corroborate its intersections with the original running spans at the recorded whole-second resolution.');
            }
            $lapElapsed = $endMs - $lap['start'] * 1000;
            if ($nativeUnpaused) {
                $timer = $lapElapsed;
            }
            if (null === $lap['elapsed'] || null === $lap['timer'] || abs($lapElapsed - $lap['elapsed']) >= 1000 || abs($timer - $lap['timer']) >= 1000) {
                $contradiction = true;
            }
            if (!$withoutLaps) {
                $targets[] = [$lap, 7, $lap['elapsed'], $lapElapsed];
                $targets[] = [$lap, 8, $lap['timer'], $timer];
                $partition[] = ['lap_index' => $index, 'start' => $lap['start'], 'end' => $end, 'elapsed_ms' => $lapElapsed, 'timer_ms' => $timer, 'paused_ms' => $lapElapsed - $timer];
            }
        }
        if (!$contradiction) {
            throw new RepairFailed('Native counters and timeline already satisfy the relevant checks; no duration reconciliation is needed.');
        }
        $targets[] = [$session, 7, $session['elapsed'], $elapsed];
        $targets[] = [$session, 8, $session['timer'], $active];
        $targets[] = [$activity, 0, $activity['timer'], $active];
        $body = substr($bytes, 0, -2);
        $changes = $additions = [];
        foreach ($references as [$entry, $field, $original, $value]) {
            if ($original === $value) {
                continue;
            }
            if (254 === $field && null !== $original && ((19 === $entry['number'] && $gappedIndexes)
                || (18 === $entry['number'] && 0 === ($original & 0x8FFF)
                    && 0 === $session['first_lap'] && count($laps) === $session['num_laps'] && 1 === $activity['sessions']))) {
                $offset = $entry['offsets'][$field];
                $body = substr_replace($body, pack('V' === $entry['word'] ? 'v' : 'n', $value), $offset, 2);
                $changes[] = ['message' => $entry['message'], 'sequence' => $entry['sequence'], 'global_message_number' => $entry['number'],
                    'byte_offset' => $offset, 'field' => $field, 'type' => 0x84, 'original_value' => $original,
                    'original_bytes' => bin2hex(substr($bytes, $offset, 2)), 'value' => $value];
                continue;
            }
            if (null !== $original || isset($entry['offsets'][$field])) {
                throw new RepairFailed('Existing Session/Lap identities and references must agree with the complete recorded partition.');
            }
            $additions[] = ['message' => $entry['message'], 'field' => $field, 'type' => 0x84, 'value' => $value];
            $changes[] = ['message' => $entry['message'], 'sequence' => $entry['sequence'], 'global_message_number' => $entry['number'],
                'byte_offset' => null, 'field' => $field, 'type' => 0x84, 'original_value' => null, 'original_bytes' => null, 'value' => $value];
        }
        foreach ($targets as [$entry, $field, $original, $value]) {
            if ($original === $value) {
                continue;
            }
            if ($value < 0 || $value > 0xFFFFFFFE) {
                throw new RepairFailed('Reconstructed counter is outside the FIT uint32 range.');
            }
            $offset = $entry['offsets'][$field] ?? null;
            if (null === $offset) {
                $additions[] = ['message' => $entry['message'], 'field' => $field, 'type' => 0x86, 'value' => $value];
            } else {
                if ($offset < $header->headerSize || $offset + 4 > strlen($body)) {
                    throw new RepairFailed('Duration offset is outside the original data.');
                }
                $body = substr_replace($body, pack($entry['word'], $value), $offset, 4);
            }
            $changes[] = ['message' => $entry['message'], 'sequence' => $entry['sequence'], 'global_message_number' => $entry['number'],
                'byte_offset' => $offset, 'field' => $field, 'type' => 0x86, 'original_value' => $original,
                'original_bytes' => null === $offset ? null : bin2hex(substr($bytes, $offset, 4)), 'value' => $value];
        }
        $restored = $body;
        foreach ($changes as $change) {
            if (null !== $change['byte_offset']) {
                $size = 0x84 === $change['type'] ? 2 : 4;
                $restored = substr_replace($restored, substr($bytes, $change['byte_offset'], $size), $change['byte_offset'], $size);
            }
        }
        if ($restored !== substr($bytes, 0, -2)) {
            throw new RepairFailed('Duration reconciliation changed bytes outside the audited counters.');
        }
        $output = $body.pack('v', FitCrc16::calculate($body));
        if ([] !== $additions) {
            $output = EditableFitFile::read($output)->apply($additions);
        }

        if ([] !== array_filter($clockTargets, static fn (array $target): bool => 253 === $target[1])
            && $this->preservedDataDigest($bytes) !== $this->preservedDataDigest($output)) {
            throw new RepairFailed('Inferred summary SAVEs would change original data or compressed Record clocks.');
        }

        return new RepairResult($output, ['mode' => 'reconcile_durations', 'rule' => $inferMissingClocks ? self::CLOCK_RULE : self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inference' => ['historical_accuracy' => 'not_proven', 'resolution_seconds' => 1,
                'clock_authority' => $inferMissingClocks ? 'complete original timer trace and Session start; a missing Session start requires matching native first Lap and first START (an empty first Lap uses its native zero counters and SAVE); an observed Record prefix may extend only Session/first Lap starts when their original starts match the first START and both later SAVEs corroborate sole-Session ownership; missing multi-Lap starts use ordered native SAVE boundaries corroborated by native Session elapsed time; a terminal collapsed sole Lap uses matching native Session counters or zero counters; a one-second Session start correction requires matching original first Lap and first START; millisecond-scaled Lap clocks require the same scale error in every original Lap, unchanged native Lap intervals and exact Session/timer endpoints' : 'unchanged original summary clocks',
                'authority' => match (true) {
                    $nativeUnpaused => 'identical positive native Session elapsed/timer totals, corroborated by the final Record and both end SAVE clocks or consistently frozen SAVEs; present Activity/Lap timers agree with native unpaused totals; absent timers require a complete positive native Lap elapsed partition exactly equal to the Session total',
                    $singleStartSessionStop => 'one original START at the first Record and one Session STOP_ALL at both final SAVEs and the final Record; positive native Session elapsed/timer and Activity timer totals agree, and each unchanged Lap elapsed interval corroborates the complete elapsed partition; any interval before START remains paused',
                    $frozenTerminalStop => 'explicit sole-Session Lap ownership, frozen front summaries, ordered original timer transitions, and one later Session STOP_DISABLE_ALL at the final Record close only the last running span; native Session timer equals the native Lap sum and every Lap timer corroborates its running intersections; whole-second totals allow an inclusive one-second bound per span',
                    $terminalSessionStop => 'original Lap starts and all recorded timer transitions; only the final running span closes at the recorded Session STOP_ALL, corroborated by native elapsed/timer totals, both SAVE clocks and the final Record',
                    $terminalLapTail => 'complete original timer transitions; a native subsecond terminal Lap after STOP is corroborated by its matching SAVE, both summary SAVEs, message order and the native Lap elapsed sum; its retained elapsed time does not restart the timer',
                    $stoppedRecordTail => 'original Lap starts and complete recorded timer transitions; Records after final STOP extend only elapsed time, corroborated by both later summary SAVEs or matching native Session/Lap elapsed totals and the last Lap SAVE; summaries follow Records, or explicitly owned pool summaries are buffered before Records with matching final native Length/Lap SAVEs and a bounded Length endpoint; the historical finish may be later',
                    $withoutLaps => 'one original Session without any reference to lost Lap segmentation, ordered Records, complete original timer transitions and native Session SAVE at final STOP; only Session/Activity counters are reconciled and no Lap is inserted',
                    default => 'original Lap start clocks and complete recorded timer transitions; final STOP containing every Record within one second; summary write timestamps do not bound the recorded interval',
                }],
            'evidence' => ['record_count' => $records, 'retained_length_count' => $lengths, 'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                'original_session_elapsed_ms' => $session['elapsed'], 'original_session_timer_ms' => $session['timer'],
                'elapsed_ms' => $elapsed, 'timer_ms' => $active, 'paused_ms' => $elapsed - $active, 'timer_events' => $events,
                'end_source' => $nativeUnpaused ? ($nativeRecordEnd ? 'last_record_and_native_unpaused_totals' : 'recorded_session_end_and_native_unpaused_totals') : ($terminalSessionStop ? 'recorded_session_end_closing_timer_trace' : ($terminalLapTail ? 'native_subsecond_terminal_lap_after_stop' : ($stoppedRecordTail ? 'last_record_after_complete_timer_stop' : 'recorded_timer_transitions'))), 'session_end_events' => $sessionEnds,
                'final_timer_timestamp' => $nativeUnpaused ? null : $timerStop, 'stopped_record_tail_ms' => $stoppedRecordTail ? ($stop - $timerStop) * 1000 : 0,
                'native_subsecond_terminal_lap_ms' => $terminalLapTail ? $lastLap['elapsed'] : 0,
                'observed_pre_timer_ms' => $observedPrefix || $singleStartSessionStop ? ($events[0]['timestamp'] - $start) * 1000 : 0,
                'retained_timestamp_only_events' => $opaqueClocks, 'frozen_session_end' => $frozenSessionEnd,
                'original_session_message_index' => $session['index'], 'reserved_session_index_mask' => 0x7000,
                'redundant_stop_disable_all_count' => $disabledStops,
                'redundant_same_clock_start_count' => $redundantStarts, 'session_without_laps' => $withoutLaps,
                'single_start_session_stop' => $singleStartSessionStop, 'frozen_terminal_session_stop_disable_all' => $frozenTerminalStop,
                'whole_second_native_timer_totals' => $wholeSecondTotals, 'stopped_record_tail_native_elapsed_corroboration' => $stoppedRecordTail && $nativeStoppedTail,
                'stopped_record_tail_buffered_pool_corroboration' => $stoppedRecordTail && $bufferedPoolTail,
                'lap_index_mapping' => $gappedIndexes ? $indexMapping : [],
                'retained_length_tail_ms' => 0 === $lengths ? 0 : max(0, $elapsed - ($stop - $start) * 1000),
                'active_spans' => $spans, 'redundant_stop_all_count' => $redundantStops, 'redundant_stop_count' => $redundantSingleStops, 'lap_partition' => $partition, 'inferred_clock_count' => count($clockTargets),
                'clock_inferences' => array_map(static fn (array $target): array => ['sequence' => $target[0]['sequence'], 'field' => $target[1], 'original_value' => $target[2], 'value' => $target[3]], $clockTargets)],
            'preservation' => 'all original messages, Lap count/order, Record and Event bytes, observations and developer fields retained; only audited summary counters, inferred summary clocks, absent references, explicitly corroborated gapped Lap indices, reserved bits of a corroborated sole Session index and CRC/definition sizes changed',
            'activity_validation' => 'not_performed']);
    }

    /**
     * @param list<StartedSummary> $sessions
     * @param Summary              $activity
     * @param list<StartedSummary> $laps
     * @param list<TimerEvent>     $events
     */
    private function repairMultipleSessions(string $bytes, array $sessions, array $activity, array $laps, array $events, ?int $firstRecord, ?int $lastRecord, int $records, int $headerSize): RepairResult
    {
        $start = $sessions[0]['start'];
        if ($activity['sessions'] !== count($sessions) || null === $activity['timestamp'] || null === $start || $start < 0x10000000
            || count($events) < 2 || 0 !== $events[0]['type'] || abs($events[0]['timestamp'] - $start) > 1
            || null === $firstRecord || $firstRecord < $start - 1 || null === $lastRecord) {
            throw new RepairFailed('Multiple Sessions require explicit ownership and complete original timer endpoints.');
        }
        $stop = $events[array_key_last($events)]['timestamp'];
        if ($stop <= $start || $lastRecord > $stop + 1) {
            throw new RepairFailed('The final recorded STOP must contain every original Record.');
        }
        $spans = [];
        $running = null;
        foreach ($events as $index => $event) {
            if (!in_array($event['group'], [null, 0], true) || !in_array($event['type'], [0, 1, 4, 9], true)
                || ($index > 0 && $event['timestamp'] < $events[$index - 1]['timestamp'])) {
                throw new RepairFailed('Multiple Sessions require ordered timer transitions in one group.');
            }
            if (9 === $event['type']) {
                if (null !== $running || 0 === $index || $event['timestamp'] !== $events[$index - 1]['timestamp'] || !in_array($events[$index - 1]['type'], [1, 4], true)) {
                    throw new RepairFailed('STOP_DISABLE_ALL must confirm the immediately preceding STOP.');
                }
            } elseif (0 === $event['type']) {
                if (null !== $running) {
                    throw new RepairFailed('A repeated START cannot establish a missing STOP boundary.');
                }
                $running = $event['timestamp'];
            } elseif (null !== $running) {
                $spans[] = ['start' => $running, 'stop' => $event['timestamp']];
                $running = null;
            }
        }
        if (null !== $running || [] === $spans) {
            throw new RepairFailed('Multiple Session timer evidence must finish stopped.');
        }
        $targets = $partition = $sessionPartition = [];
        $nextLap = $totalTimer = 0;
        $contradiction = false;
        foreach ($sessions as $index => $session) {
            $sessionStart = $session['start'];
            $end = $sessions[$index + 1]['start'] ?? $stop;
            $count = $session['num_laps'];
            if ($session['index'] !== $index || $session['first_lap'] !== $nextLap || null === $count || $count <= 0
                || $nextLap + $count > count($laps) || null === $sessionStart || $sessionStart < $start || $end <= $sessionStart || $end > $stop
                || null === $session['timestamp'] || $laps[$nextLap]['start'] !== $sessionStart) {
                throw new RepairFailed('Original Session indices, Lap ranges and starts must establish one complete ordered partition.');
            }
            $sessionTimer = 0;
            for ($lapIndex = $nextLap; $lapIndex < $nextLap + $count; ++$lapIndex) {
                $lap = $laps[$lapIndex];
                $lapEnd = $lapIndex + 1 < $nextLap + $count ? $laps[$lapIndex + 1]['start'] : $end;
                if ($lap['index'] !== $lapIndex || null === $lap['timestamp'] || null === $lap['start'] || null === $lapEnd
                    || $lap['start'] < $sessionStart || $lapEnd <= $lap['start'] || $lapEnd > $end) {
                    throw new RepairFailed('Each original Lap must retain its identity and a positive ordered interval inside its explicitly referenced Session.');
                }
                $elapsed = ($lapEnd - $lap['start']) * 1000;
                $timer = 0;
                foreach ($spans as $span) {
                    $timer += max(0, min($lapEnd, $span['stop']) - max($lap['start'], $span['start'])) * 1000;
                }
                $sessionTimer += $timer;
                $contradiction = $contradiction || null === $lap['elapsed'] || null === $lap['timer'] || abs($elapsed - $lap['elapsed']) >= 1000 || abs($timer - $lap['timer']) >= 1000;
                $targets[] = [$lap, 7, $lap['elapsed'], $elapsed];
                $targets[] = [$lap, 8, $lap['timer'], $timer];
                $partition[] = ['lap_index' => $lapIndex, 'session_index' => $index, 'start' => $lap['start'], 'end' => $lapEnd, 'elapsed_ms' => $elapsed, 'timer_ms' => $timer, 'paused_ms' => $elapsed - $timer];
            }
            $elapsed = ($end - $sessionStart) * 1000;
            $contradiction = $contradiction || null === $session['elapsed'] || null === $session['timer'] || abs($elapsed - $session['elapsed']) >= 1000 || abs($sessionTimer - $session['timer']) >= 1000;
            $targets[] = [$session, 7, $session['elapsed'], $elapsed];
            $targets[] = [$session, 8, $session['timer'], $sessionTimer];
            $sessionPartition[] = ['session_index' => $index, 'first_lap_index' => $nextLap, 'num_laps' => $count, 'start' => $sessionStart, 'end' => $end, 'elapsed_ms' => $elapsed, 'timer_ms' => $sessionTimer, 'paused_ms' => $elapsed - $sessionTimer];
            $totalTimer += $sessionTimer;
            $nextLap += $count;
        }
        if ($nextLap !== count($laps) || (!$contradiction && null !== $activity['timer'] && abs($totalTimer - $activity['timer']) < 1000)) {
            throw new RepairFailed('Every original Lap must be owned exactly once and a duration contradiction must require reconciliation.');
        }
        $targets[] = [$activity, 0, $activity['timer'], $totalTimer];
        $body = substr($bytes, 0, -2);
        $changes = [];
        foreach ($targets as [$entry, $field, $original, $value]) {
            if ($value === $original) {
                continue;
            }
            $offset = $entry['offsets'][$field] ?? null;
            if (null === $offset || $offset < $headerSize || $offset + 4 > strlen($body) || $value < 0 || $value > 0xFFFFFFFE) {
                throw new RepairFailed('Multiple Session reconciliation requires existing uint32 counter fields within the original data.');
            }
            $body = substr_replace($body, pack($entry['word'], $value), $offset, 4);
            $changes[] = ['message' => $entry['message'], 'sequence' => $entry['sequence'], 'global_message_number' => $entry['number'],
                'byte_offset' => $offset, 'field' => $field, 'type' => 0x86, 'original_value' => $original, 'original_bytes' => bin2hex(substr($bytes, $offset, 4)), 'value' => $value];
        }
        $output = $body.pack('v', FitCrc16::calculate($body));

        return new RepairResult($output, ['mode' => 'reconcile_durations', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inference' => ['historical_accuracy' => 'not_proven', 'resolution_seconds' => 1, 'clock_authority' => 'unchanged original Session and Lap starts',
                'authority' => 'explicit original Activity Session count, Session indices and contiguous Lap ranges; complete recorded timer transitions; each Session ends at the next original Session start, and the last at the final STOP; original paused tails stay paused'],
            'evidence' => ['record_count' => $records, 'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                'elapsed_ms' => ($stop - $start) * 1000, 'timer_ms' => $totalTimer, 'paused_ms' => ($stop - $start) * 1000 - $totalTimer,
                'timer_events' => $events, 'active_spans' => $spans, 'session_partition' => $sessionPartition, 'lap_partition' => $partition],
            'preservation' => 'all original messages, summary clocks, Session/Lap identities and references, observations, timer Events and developer fields retained; only audited summary duration counters and CRC changed',
            'activity_validation' => 'not_performed']);
    }

    /**
     * @param StartedSummary                       $session
     * @param Summary                              $activity
     * @param list<StartedSummary>                 $laps
     * @param list<TimerEvent>                     $events
     * @param list<array{Summary, int, ?int, int}> $targets
     */
    private function completeClocks(array &$session, array &$activity, array &$laps, array $events, array &$targets): void
    {
        if ([] === $events && null !== $session['start'] && null !== $session['timestamp'] && null !== $activity['timestamp']
            && [] === array_filter($laps, static fn (array $lap): bool => null === $lap['start'] || null === $lap['timestamp'])) {
            return;
        }
        if (null === $session['start'] && count($events) >= 2 && 0 === $events[0]['type']
            && ($laps[0]['start'] === $events[0]['timestamp'] || (null === $laps[0]['start'] && 0 === $laps[0]['elapsed'] && 0 === $laps[0]['timer'] && $laps[0]['timestamp'] === $events[0]['timestamp']))) {
            $targets[] = [$session, 2, null, $events[0]['timestamp']];
            $session['start'] = $events[0]['timestamp'];
        }
        if (count($events) < 2 || 0 !== $events[0]['type'] || !in_array($events[array_key_last($events)]['type'], [1, 4, 9], true)
            || null === $session['start'] || $session['start'] < 0x10000000) {
            throw new RepairFailed('Incomplete summary clocks require an original Session start and complete timer endpoints.');
        }
        $start = $session['start'];
        $stop = $events[array_key_last($events)]['timestamp'];
        if (count($laps) >= 3 && 2 === count($events) && $laps[0]['start'] > $stop
            && $events[0]['timestamp'] === $start && 4 === $events[1]['type']
            && $session['timestamp'] === $stop && in_array($activity['timestamp'], [$start, $stop], true)
            && ($stop - $start) * 1000 === $session['elapsed'] && $session['elapsed'] === $session['timer'] && $session['timer'] === $activity['timer']) {
            $scaled = [];
            $previous = $start;
            foreach ($laps as $lap) {
                if (null === $lap['timestamp'] || null === $lap['start'] || null === $lap['elapsed'] || $lap['elapsed'] <= 0
                    || 0 !== $lap['elapsed'] % 1000 || $lap['elapsed'] !== $lap['timer'] || $lap['start'] <= $stop
                    || ($lap['timestamp'] - $lap['start']) * 1000 !== $lap['elapsed']) {
                    throw new RepairFailed('A millisecond-scaled Lap clock pattern must be corroborated by every native Lap interval.');
                }
                $end = $start + intdiv($lap['timestamp'] - $start, 1000);
                $begin = $end - intdiv($lap['elapsed'], 1000);
                if ($begin < $previous || $begin > $previous + (0 === count($scaled) ? 0 : 1) || $end > $stop) {
                    throw new RepairFailed('Rescaled Lap clocks must form one ordered partition inside the original timer endpoints.');
                }
                $scaled[] = [$begin, $end];
                $previous = $end;
            }
            foreach ($laps as $index => &$lap) {
                foreach ([2 => $scaled[$index][0], 253 => $scaled[$index][1]] as $field => $value) {
                    $targets[] = [$lap, $field, 2 === $field ? $lap['start'] : $lap['timestamp'], $value];
                }
                [$lap['start'], $lap['timestamp']] = $scaled[$index];
            }
            unset($lap);
        }
        if ($laps[0]['start'] === $start - 1 && $events[0]['timestamp'] === $laps[0]['start']) {
            $targets[] = [$session, 2, $start, $start - 1];
            $session['start'] = --$start;
        }
        foreach ($laps as $index => &$lap) {
            $value = 0 === $index ? $start : $laps[$index - 1]['timestamp'];
            $collapsed = 1 === count($laps) && $lap['start'] === $stop && in_array($lap['timestamp'], [null, $stop], true)
                && null !== $session['elapsed'] && $session['elapsed'] > 0 && null !== $session['timer'] && $session['timer'] >= 0 && $session['timer'] <= $session['elapsed']
                && (($lap['elapsed'] === $session['elapsed'] && $lap['timer'] === $session['timer']) || (0 === $lap['elapsed'] && 0 === $lap['timer']));
            if (null === $lap['start'] || $collapsed) {
                $emptyFirstLap = 0 === $index && 0 === $lap['elapsed'] && 0 === $lap['timer'] && $lap['timestamp'] === $start && $events[0]['timestamp'] === $start;
                if (null === $value || $value < $start || $value >= $stop
                    || (!$emptyFirstLap && count($laps) > 1 && (null === $session['elapsed'] || abs(($stop - $start) * 1000 - $session['elapsed']) >= 1000
                        || null === $lap['timestamp'] || $lap['timestamp'] <= $value || $lap['timestamp'] > $stop
                        || (null !== $lap['elapsed'] && abs(($lap['timestamp'] - $value) * 1000 - $lap['elapsed']) >= 1000)))) {
                    throw new RepairFailed('Missing Lap starts require ordered native SAVE boundaries corroborated by native Session elapsed time.');
                }
                $targets[] = [$lap, 2, $lap['start'], $value];
                $lap['start'] = $value;
            }
        }
        unset($lap);
        foreach ($laps as $index => &$lap) {
            if (null === $lap['timestamp']) {
                $value = $laps[$index + 1]['start'] ?? $stop;
                $targets[] = [$lap, 253, null, $value];
                $lap['timestamp'] = $value;
            }
        }
        unset($lap);
        foreach ([&$session, &$activity] as &$entry) {
            if (null === $entry['timestamp']) {
                $targets[] = [$entry, 253, null, $stop];
                $entry['timestamp'] = $stop;
            }
        }
        unset($entry);
    }

    private function preservedDataDigest(string $bytes): string
    {
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new RepairFailed('Cannot allocate summary preservation input.');
        }
        $hash = hash_init('sha256');
        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill summary preservation input.');
            }
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }
                $number = $raw->globalMessageNumber();
                hash_update($hash, pack('v', $number));
                if (!in_array($number, [18, 19, 34], true)) {
                    hash_update($hash, pack('CC', $raw->recordHeaderByte(), $raw->definition()->architecture->value));
                    hash_update($hash, pack('C', count($raw->standardFields())));
                    foreach ($raw->standardFields() as $field) {
                        hash_update($hash, pack('CCC', $field->definition->fieldNumber, $field->definition->size, $field->definition->baseType->definitionByte()).$field->value->bytes());
                    }
                    if ($raw instanceof \Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage) {
                        hash_update($hash, pack('V', $raw->reconstructedTimestamp));
                    }
                }
                hash_update($hash, pack('C', count($raw->developerFields())));
                foreach ($raw->developerFields() as $field) {
                    hash_update($hash, pack('CCC', $field->definition->fieldNumber, $field->definition->size, $field->definition->developerDataIndex).$field->value->bytes());
                }
            }
            $file->trailer();
        } finally {
            fclose($stream);
        }

        return hash_final($hash);
    }

    private function scalar(PhysicalMessage $message, int $number, int $type, int $size, bool $allowIdenticalDuplicates = false): ?int
    {
        $nativeBytes = null;
        foreach ($message->fields as $field) {
            if ($field->definition->fieldNumber !== $number) {
                continue;
            }
            if ((null !== $nativeBytes && (!$allowIdenticalDuplicates || $nativeBytes !== $field->value->bytes()))
                || $field->definition->size !== $size || !in_array($field->definition->baseType->definitionByte(), 0x00 === $type ? [0x00, 0x02] : [$type], true)) {
                throw new RepairFailed('Duration evidence requires unique native scalar fields of the expected type.');
            }
            $nativeBytes = $field->value->bytes();
        }

        return $message->integer($number);
    }
}
