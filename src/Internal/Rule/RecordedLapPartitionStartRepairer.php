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

/** Recovers delayed Lap starts within one corroborated elapsed partition. */
final readonly class RecordedLapPartitionStartRepairer
{
    public const string RULE = 'lap_starts_from_unpaused_recorded_partition';

    public const string PAUSED_RULE = 'lap_starts_from_paused_recorded_partition';

    public const string FRONT_RULE = 'front_summary_clocks_from_recorded_partition';

    public const string NATIVE_RULE = 'lap_starts_from_native_unpaused_totals';

    public function repair(string $bytes, bool $withPauses = false): RepairResult
    {
        return $this->partition($bytes, $withPauses, false);
    }

    public function repairFrontSummaries(string $bytes): RepairResult
    {
        return $this->partition($bytes, true, true);
    }

    public function repairNativeSummaries(string $bytes): RepairResult
    {
        return $this->partition($bytes, false, false, true);
    }

    private function partition(string $bytes, bool $withPauses, bool $front, bool $native = false): RepairResult
    {
        [$member, $tail] = ActivityHeartRateTail::split($bytes);
        if ('' !== $tail) {
            return ActivityHeartRateTail::append($bytes, $tail, $this->partition($member, $withPauses, $front, $native));
        }
        if (67_108_864 < strlen($bytes)) {
            throw new RepairFailed('Recorded partition repair is bounded to 64 MiB.');
        }
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new RepairFailed('Cannot allocate recorded partition input.');
        }
        $laps = $sessions = $activities = $events = [];
        $firstRecord = $lastRecord = $firstRecordSequence = $lastRecordSequence = null;
        $ordinal = $recordCount = $fileIds = 0;
        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill recorded partition input.');
            }
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;
            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Recorded partition requires one complete supported FIT member.');
            }
            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }
                $number = $raw->globalMessageNumber();
                if ($native && in_array($number, [21, 101], true)) {
                    throw new RepairFailed('Native unpaused partition excludes Events and pool Lengths.');
                }
                if ($front && (101 === $number || (0 === $recordCount && $raw instanceof \Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage))) {
                    throw new RepairFailed('Front-summary recovery excludes pool Lengths and compressed clocks before the first absolute Record.');
                }
                if (0 === $ordinal && 0 !== $number) {
                    throw new RepairFailed('Recorded partition requires the original FileId first.');
                }
                if (in_array($number, [0, 18, 19, 20, 21, 34], true)) {
                    $message = PhysicalMessage::fromRaw($raw, 0);
                    if (0 === $number) {
                        ++$fileIds;
                        if (4 !== $this->scalar($message, 0, 0x00, 1)) {
                            throw new RepairFailed('Recorded partition requires an Activity FileId.');
                        }
                    } elseif (20 === $number) {
                        if ($front && 0 === $recordCount && (1 !== count($sessions) || 1 !== count($activities) || count($laps) < 2
                            || $sessions[0]['timestamp'] !== $sessions[0]['start'] || $activities[0]['timestamp'] !== $sessions[0]['start'])) {
                            throw new RepairFailed('Front-summary recovery requires the complete original summaries with frozen start clocks before Records.');
                        }
                        $timestamp = $front ? $this->scalar($message, 253, 0x86, 4) : $message->integer(253);
                        if (null === $timestamp || 0x10000000 > $timestamp || (null !== $lastRecord && $timestamp < $lastRecord)) {
                            throw new RepairFailed('Recorded partition requires ordered absolute Records.');
                        }
                        $firstRecord ??= $timestamp;
                        $firstRecordSequence ??= $raw->sequence();
                        $lastRecord = $timestamp;
                        $lastRecordSequence = $raw->sequence();
                        ++$recordCount;
                    } else {
                        if ($message->wasCompressed) {
                            throw new RepairFailed('Recorded partition requires uncompressed summaries and Events.');
                        }
                        $summary = ['message' => $ordinal, 'sequence' => $raw->sequence(), 'timestamp' => $this->scalar($message, 253, 0x86, 4)];
                        if ($native) {
                            $summary['physical'] = $message;
                        }
                        if ($front && in_array($number, [18, 19, 34], true)) {
                            $position = $raw->byteOffset() + 1;
                            $summary['clock_offsets'] = [];
                            foreach ($raw->standardFields() as $field) {
                                $summary['clock_offsets'][$field->definition->fieldNumber] = $position;
                                $position += $field->definition->size;
                            }
                            $summary['word'] = FitArchitecture::LittleEndian === $raw->definition()->architecture ? 'V' : 'N';
                        }
                        if (18 === $number || 19 === $number) {
                            $summary += ['start' => $this->scalar($message, 2, 0x86, 4), 'elapsed' => $this->scalar($message, 7, 0x86, 4),
                                'timer' => $this->scalar($message, 8, 0x86, 4), 'index' => $this->scalar($message, 254, 0x84, 2)];
                            if (18 === $number) {
                                if ($front && 5 === $this->scalar($message, 5, 0x00, 1)) {
                                    throw new RepairFailed('Front-summary recovery excludes swimming summaries.');
                                }
                                $summary += ['first_lap' => $this->scalar($message, 25, 0x84, 2), 'num_laps' => $this->scalar($message, 26, 0x84, 2)];
                                $sessions[] = $summary;
                            } else {
                                $position = $raw->byteOffset() + 1;
                                foreach ($raw->standardFields() as $field) {
                                    if (2 === $field->definition->fieldNumber) {
                                        $summary['offset'] = $position;
                                    }
                                    $position += $field->definition->size;
                                }
                                $summary['word'] = FitArchitecture::LittleEndian === $raw->definition()->architecture ? 'V' : 'N';
                                $laps[] = $summary;
                            }
                        } elseif (34 === $number) {
                            $summary += ['timer' => $this->scalar($message, 0, 0x86, 4), 'sessions' => $this->scalar($message, 1, 0x84, 2)];
                            if ($front) {
                                $summary['local'] = $this->scalar($message, 5, 0x86, 4);
                            }
                            $activities[] = $summary;
                        } else {
                            $kind = $this->scalar($message, 0, 0x00, 1);
                            if (null === $kind) {
                                throw new RepairFailed('Unknown Event kind cannot exclude a timer contradiction.');
                            }
                            if (0 === $kind) {
                                $summary += ['type' => $this->scalar($message, 1, 0x00, 1), 'group' => $this->scalar($message, 4, 0x02, 1)];
                                $events[] = $summary;
                            }
                        }
                    }
                }
                if (4096 < count($laps) || ($withPauses ? 4096 : 2) < count($events) || 1 < count($sessions) || 1 < count($activities) || 1 < $fileIds) {
                    throw new RepairFailed('Recorded partition requires bounded Laps, one Session and Activity, and bounded complete timer pairs.');
                }
                ++$ordinal;
            }
            $file->trailer();
        } finally {
            fclose($stream);
        }
        if ($native && 1 === $fileIds && 1 === count($sessions) && 1 === count($activities) && count($laps) >= 2 && $recordCount >= 2) {
            return $this->nativeStarts($bytes, $sessions[0], $activities[0], $laps, $firstRecord, $lastRecord, $ordinal);
        }
        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($activities) || (count($events) < 2 || 0 !== count($events) % 2) || 2 > count($laps) || 2 > $recordCount) {
            throw new RepairFailed('Recorded partition evidence is incomplete.');
        }
        $session = $sessions[0];
        $activity = $activities[0];
        $startEvent = $events[0];
        $stopEvent = $events[array_key_last($events)];
        $base = $session['start'];
        $elapsed = $session['elapsed'];
        $stop = $stopEvent['timestamp'];
        if (null === $base || 0x10000000 > $base || null === $elapsed || 0 >= $elapsed || null === $session['timer'] || $session['timer'] > $elapsed || $session['timer'] !== $activity['timer']
            || (!$front && ($withPauses ? $elapsed === $session['timer'] : $elapsed !== $session['timer']))
            || 0 !== $session['index'] || 0 !== $session['first_lap'] || count($laps) !== $session['num_laps'] || 1 !== $activity['sessions']
            || $laps[0]['start'] !== $base || $startEvent['timestamp'] !== $base || 0 !== $startEvent['type'] || !in_array($stopEvent['type'], [1, 4], true)
            || !in_array($startEvent['group'], [null, 0], true) || !in_array($stopEvent['group'], [null, 0], true)
            || null === $firstRecord || $firstRecord < $base || $firstRecord > $base + 1 || null === $stop || $stop !== $lastRecord || $stop <= $firstRecord
            || $startEvent['sequence'] >= $firstRecordSequence || $lastRecordSequence >= $stopEvent['sequence']
            || (!$front && ($stopEvent['sequence'] >= $laps[array_key_last($laps)]['sequence'] || $laps[array_key_last($laps)]['sequence'] >= $session['sequence']
                || $session['sequence'] >= $activity['sequence'] || null === $session['timestamp'] || $session['timestamp'] < $stop
                || null === $activity['timestamp'] || $activity['timestamp'] < $session['timestamp']))
            || ($front && ($session['timer'] <= 0 || $activity['sequence'] >= $session['sequence'] || $session['sequence'] >= $laps[0]['sequence']
                || $laps[array_key_last($laps)]['sequence'] >= $startEvent['sequence']
                || $session['timestamp'] !== $base || $activity['timestamp'] !== $base || null === $activity['local']))) {
            throw new RepairFailed('Native counters, references and timer/Record endpoints do not establish one continuous elapsed partition.');
        }
        $pauseWindows = [];
        foreach ($events as $index => $event) {
            if (null === $event['timestamp'] || !in_array($event['group'], [null, 0], true)
                || (0 === $index % 2 ? 0 !== $event['type'] : !in_array($event['type'], [1, 4], true))
                || ($index > 0 && $event['timestamp'] <= $events[$index - 1]['timestamp'])) {
                throw new RepairFailed('Recorded partition requires complete, strictly ordered timer pairs in one group.');
            }
            if (0 < $index && 0 === $index % 2) {
                $pauseWindows[] = [($events[$index - 1]['timestamp'] - $base) * 1000, ($event['timestamp'] - $base) * 1000];
            }
        }
        // One shared fractional origin must explain both the stop and every retained start.
        $low = max(0, ($stop - $base) * 1000 - $elapsed);
        $high = min(999, ($stop - $base + 1) * 1000 - $elapsed - 1);
        $prefix = 0;
        $damaged = $partition = [];
        $timerSum = 0;
        foreach ($laps as $index => $lap) {
            if ($index !== $lap['index'] || null === $lap['elapsed'] || $lap['elapsed'] <= 0 || null === $lap['timer'] || $lap['timer'] < 0 || $lap['timer'] > $lap['elapsed']
                || (!$withPauses && $lap['elapsed'] !== $lap['timer'])
                || null === $lap['start'] || null === $lap['timestamp']
                || (!$front && ($lap['timestamp'] < $lap['start'] || $lap['timestamp'] > $session['timestamp']))
                || ($front && $lap['timestamp'] !== $base)
                || ($index > 0 && ($lap['sequence'] <= $laps[$index - 1]['sequence'] || ($front && $lap['start'] <= $laps[$index - 1]['start']) || $lap['timestamp'] < $laps[$index - 1]['timestamp']))) {
                throw new RepairFailed('Recorded Laps must have explicit identities, ordered clocks and unchanged positive elapsed and valid timer durations.');
            }
            if ($front) {
                // Retain each original start or admit only the next-sample second.
                $low = max($low, ($lap['start'] - $base - (0 === $index ? 0 : 1)) * 1000 - $prefix);
                $high = min($high, ($lap['start'] - $base + 1) * 1000 - $prefix - 1);
            } elseif ($index > 0 && $lap['start'] === $laps[$index - 1]['timestamp'] + 1 && $lap['start'] > $base + intdiv($prefix + $high, 1000)) {
                $damaged[] = $lap + ['prefix' => $prefix];
            } else {
                $low = max($low, ($lap['start'] - $base) * 1000 - $prefix);
                $high = min($high, ($lap['start'] - $base + 1) * 1000 - $prefix - 1);
            }
            $prefix += $lap['elapsed'];
            $timerSum += $lap['timer'];
            $partition[] = ['elapsed' => $lap['elapsed'], 'timer' => $lap['timer']];
        }
        if ($prefix !== $elapsed || $timerSum !== $session['timer'] || $low > $high || (!$front && [] === $damaged)) {
            throw new RepairFailed('Exact native Lap sums and all retained clocks must admit one shared fractional origin and exclude the delayed starts.');
        }
        $originCount = $high - $low + 1;
        if ($withPauses) {
            [$low, $high, $originCount] = $this->pausedOrigins($partition, $pauseWindows, $low, $high);
        }
        if ($front) {
            return $this->frontClocks($bytes, $session, $activity, $laps, $events, $recordCount, $low, $high, $originCount);
        }
        $body = substr($bytes, 0, -2);
        $changes = $boundaries = [];
        foreach ($damaged as $lap) {
            $minimum = $base + intdiv($lap['prefix'] + $low, 1000);
            $maximum = $base + intdiv($lap['prefix'] + $high, 1000);
            if ($maximum >= $lap['start'] || $maximum > $lap['timestamp'] || !isset($lap['offset'])) {
                throw new RepairFailed('Derived partition does not exclude the recorded delayed start.');
            }
            // All damaged starts are late: the greatest shared phase minimizes every edit.
            $body = substr_replace($body, pack($lap['word'], $maximum), $lap['offset'], 4);
            $changes[] = ['message' => $lap['message'], 'field' => 2, 'type' => 0x86, 'value' => $maximum];
            $boundaries[] = ['lap_index' => $lap['index'], 'sequence' => $lap['sequence'], 'start_byte_offset' => $lap['offset'],
                'original_start' => $lap['start'], 'admissible_starts' => [$minimum, $maximum], 'selected_start' => $maximum, 'preceding_elapsed_ms' => $lap['prefix']];
        }
        $output = $body.pack('v', FitCrc16::calculate($body));
        $unchanged = $body;
        foreach ($damaged as $lap) {
            $unchanged = substr_replace($unchanged, substr($bytes, $lap['offset'], 4), $lap['offset'], 4);
        }
        if ($unchanged !== substr($bytes, 0, -2)) {
            throw new RepairFailed('Recorded partition changed bytes outside selected Lap starts and the file CRC.');
        }

        return new RepairResult($output, ['mode' => 'preserve', 'rule' => $withPauses ? self::PAUSED_RULE : self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'evidence' => ['session_start' => $base, 'elapsed_sum_ms' => $elapsed, 'timer_start' => $startEvent, 'timer_stop' => $stopEvent,
                'record_count' => $recordCount, 'timer_events' => $events, 'admissible_origin_count' => $originCount, 'fractional_origin_ms' => [$low, $high], 'selected_fractional_origin_ms' => $high, 'boundaries' => $boundaries,
                'policy' => 'exact unchanged Lap/Session/Activity counters establish a continuous elapsed partition; complete timer pairs corroborate its active and paused durations; all retained clocks agree; delayed SAVE+1 starts lie outside every admissible second; choose one shared feasible phase minimizing edits, without claiming unique subsecond recovery'],
            'preservation' => 'all original bytes retained outside selected Lap.start_time fields and file CRC', 'activity_validation' => 'not_performed']);
    }

    /**
     * @param array<string, mixed>       $session
     * @param array<string, mixed>       $activity
     * @param list<array<string, mixed>> $laps
     */
    private function nativeStarts(string $bytes, array $session, array $activity, array $laps, ?int $firstRecord, ?int $lastRecord, int $messageCount): RepairResult
    {
        $base = $session['start'];
        $elapsed = $session['elapsed'];
        if (null === $base || $base < 0x10000000 || null === $elapsed || $elapsed <= 0 || $elapsed !== $session['timer']
            || null === $firstRecord || $firstRecord < $base || $firstRecord >= $laps[0]['timestamp'] || $lastRecord !== $base + intdiv($elapsed, 1000)
            || $session['timestamp'] !== $lastRecord || $activity['timestamp'] !== $lastRecord
            || $laps[array_key_last($laps)]['sequence'] >= $session['sequence'] || $session['sequence'] >= $activity['sequence']
            || $messageCount > 20000 || strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Native unpaused totals require matching absolute Session and Record endpoints and bounded physical input.');
        }
        $changes = $boundaries = [];
        $prefix = 0;
        $add = static function (array $entry, int $field, int $type, int $value) use (&$changes): void {
            $physical = $entry['physical'];
            $fields = array_values(array_filter($physical->fields, static fn ($candidate): bool => $candidate->definition->fieldNumber === $field));
            if ([] !== $fields) {
                if (1 !== count($fields) || $fields[0]->definition->baseType->definitionByte() !== $type
                    || $fields[0]->definition->size !== (0x84 === $type ? 2 : 4) || $physical->integer($field) !== $value) {
                    throw new RepairFailed('Existing native partition identities and Activity timer must agree; invalid fields are not missing.');
                }

                return;
            }
            $changes[] = ['message' => $entry['message'], 'field' => $field, 'type' => $type, 'value' => $value, 'original_value' => null];
        };
        foreach ($laps as $index => $lap) {
            if (null === $lap['start'] || $lap['start'] <= $lastRecord || !isset($lap['offset'])
                || null === $lap['elapsed'] || $lap['elapsed'] <= 0 || $lap['elapsed'] !== $lap['timer']
                || (0 < $index && $lap['start'] <= $laps[$index - 1]['start'])) {
                throw new RepairFailed('Every native Lap must have an impossible future start and positive unpaused original counters.');
            }
            $start = $base + intdiv($prefix, 1000);
            $prefix += $lap['elapsed'];
            $end = $base + intdiv($prefix, 1000);
            if ($lap['timestamp'] !== $end || $end <= $start || $prefix > $elapsed) {
                throw new RepairFailed('Every unchanged Lap SAVE clock must corroborate its exact cumulative native duration at one-second resolution.');
            }
            $changes[] = ['message' => $lap['message'], 'field' => 2, 'type' => 0x86, 'value' => $start, 'original_value' => $lap['start']];
            $add($lap, 254, 0x84, $index);
            $boundaries[] = ['sequence' => $lap['sequence'], 'original_start' => $lap['start'], 'selected_start' => $start,
                'retained_timestamp' => $lap['timestamp'], 'elapsed_ms' => $lap['elapsed'], 'timer_ms' => $lap['timer']];
        }
        if ($prefix !== $elapsed) {
            throw new RepairFailed('Exact native Lap elapsed and timer sums must equal the unchanged Session totals.');
        }
        $add($session, 254, 0x84, 0);
        $add($session, 25, 0x84, 0);
        $add($session, 26, 0x84, count($laps));
        $add($activity, 1, 0x84, 1);
        $add($activity, 0, 0x86, $elapsed);
        $output = EditableFitFile::read($bytes)->apply($changes);

        return new RepairResult($output, ['mode' => 'preserve', 'rule' => self::NATIVE_RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inference' => ['historical_accuracy' => 'not_proven', 'resolution_seconds' => 1],
            'evidence' => ['session_start' => $base, 'session_end' => $lastRecord, 'elapsed_sum_ms' => $elapsed, 'timer_sum_ms' => $elapsed,
                'boundaries' => $boundaries, 'policy' => 'all Lap starts are impossible future clocks; exact native unpaused Lap sums equal the Session totals; every original Lap SAVE clock matches its cumulative native end; Session and Activity SAVE clocks match the last Record; no Event or Length permits an alternative pause partition'],
            'preservation' => 'retain every original duration, timestamp, Record, measurement and developer field; replace only impossible Lap starts and add absent partition references and Activity timer',
            'activity_validation' => 'not_performed']);
    }

    /**
     * @param array<string, mixed>       $session
     * @param array<string, mixed>       $activity
     * @param list<array<string, mixed>> $laps
     * @param list<array<string, mixed>> $events
     */
    private function frontClocks(string $bytes, array $session, array $activity, array $laps, array $events, int $recordCount, int $low, int $high, int $originCount): RepairResult
    {
        $base = $session['start'];
        $stop = $events[array_key_last($events)]['timestamp'];
        $offset = $activity['local'] - $base;
        if ($offset < -43200 || $offset > 50400 || 0 !== $offset % 900 || $stop + $offset < 0x10000000 || $stop + $offset >= 0xFFFFFFFF) {
            throw new RepairFailed('Front summaries require an existing usable local-clock offset, retained without geographic inference.');
        }
        $body = substr($bytes, 0, -2);
        $changes = $clockEvidence = [];
        $edit = static function (array $summary, int $field, int $value) use (&$body, &$changes): void {
            if (!isset($summary['clock_offsets'][$field])) {
                throw new RepairFailed('Front clocks require existing scalar fields.');
            }
            $body = substr_replace($body, pack($summary['word'], $value), $summary['clock_offsets'][$field], 4);
            $changes[] = ['message' => $summary['message'], 'field' => $field, 'type' => 0x86, 'value' => $value];
        };
        $prefix = 0;
        foreach ($laps as $lap) {
            $start = $base + intdiv($prefix + $high, 1000);
            if ($start !== $lap['start']) {
                $edit($lap, 2, $start);
            }
            $prefix += $lap['elapsed'];
            $end = $base + intdiv($prefix + $high, 1000);
            $edit($lap, 253, $end);
            $clockEvidence[] = ['index' => $lap['index'], 'sequence' => $lap['sequence'], 'original_start' => $lap['start'],
                'elapsed_ms' => $lap['elapsed'], 'timer_ms' => $lap['timer'], 'selected_start' => $start, 'selected_end' => $end];
        }
        $edit($session, 253, $stop);
        $edit($activity, 253, $stop);
        $edit($activity, 5, $stop + $offset);
        $output = $body.pack('v', FitCrc16::calculate($body));

        return new RepairResult($output, ['mode' => 'preserve', 'rule' => self::FRONT_RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'evidence' => ['frozen_summary_timestamp' => $base, 'elapsed_sum_ms' => $session['elapsed'], 'timer_sum_ms' => $session['timer'],
                'timer_events' => $events, 'record_count' => $recordCount, 'original_local_timestamp' => $activity['local'], 'retained_local_offset_seconds' => $offset,
                'fractional_origin_ms' => [$low, $high], 'admissible_origin_count' => $originCount, 'selected_fractional_origin_ms' => $high, 'lap_clocks' => $clockEvidence,
                'policy' => 'all front summary timestamps repeat START and contradict later positive Lap spans; exact unchanged Lap/Session/Activity counters, absolute endpoint Records and every recorded pause corroborate one partition; each existing start is retained or is its next-sample second; the greatest feasible shared phase minimizes start edits; retain the original local offset; no counter, pause or observation is reconstructed'],
            'preservation' => 'all original bytes retained outside audited front summary clocks, selected Lap starts and file CRC', 'activity_validation' => 'not_performed']);
    }

    /**
     * @param list<array{elapsed: int, timer: int}> $partition
     * @param list<array{int, int}>                 $pauses
     *
     * @return array{int, int, int}
     */
    private function pausedOrigins(array $partition, array $pauses, int $low, int $high): array
    {
        $boundaries = [0];
        foreach ($partition as $lap) {
            $boundaries[] = $boundaries[array_key_last($boundaries)] + $lap['elapsed'];
        }
        $origins = [];
        for ($phase = $low; $phase <= $high; ++$phase) {
            $minimum = $maximum = array_fill(0, count($partition), 0);
            foreach ($pauses as [$stop, $resume]) {
                $first = $this->windowLap($boundaries, $stop - $phase, $stop + 999 - $phase);
                $last = $this->windowLap($boundaries, $resume - $phase, $resume + 999 - $phase);
                // A second containing a Lap boundary cannot assign this Event to one Lap independently.
                if (null === $first || null === $last) {
                    continue 2;
                }
                if ($first === $last) {
                    $minimum[$first] += $resume - $stop - 999;
                    $maximum[$first] += $resume - $stop + 999;
                } else {
                    $minimum[$first] += $boundaries[$first + 1] - ($stop + 999 - $phase);
                    $maximum[$first] += $boundaries[$first + 1] - ($stop - $phase);
                    $minimum[$last] += $resume - $phase - $boundaries[$last];
                    $maximum[$last] += $resume + 999 - $phase - $boundaries[$last];
                    for ($index = $first + 1; $index < $last; ++$index) {
                        $minimum[$index] += $partition[$index]['elapsed'];
                        $maximum[$index] += $partition[$index]['elapsed'];
                    }
                }
            }
            foreach ($partition as $index => $lap) {
                $paused = $lap['elapsed'] - $lap['timer'];
                if ($paused < $minimum[$index] || $paused > $maximum[$index]) {
                    continue 2;
                }
            }
            $origins[] = $phase;
        }
        if ([] === $origins) {
            throw new RepairFailed('No shared fractional origin reconciles every unchanged Lap timer with the recorded pauses.');
        }

        return [$origins[0], $origins[array_key_last($origins)], count($origins)];
    }

    /** @param list<int> $boundaries */
    private function windowLap(array $boundaries, int $minimum, int $maximum): ?int
    {
        $low = 0;
        $high = count($boundaries) - 2;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($minimum < $boundaries[$middle]) {
                $high = $middle - 1;
            } elseif ($minimum >= $boundaries[$middle + 1]) {
                $low = $middle + 1;
            } else {
                return $maximum < $boundaries[$middle + 1] ? $middle : null;
            }
        }

        return null;
    }

    private function scalar(PhysicalMessage $message, int $number, int $type, int $size): ?int
    {
        $found = 0;
        foreach ($message->fields as $field) {
            if ($number === $field->definition->fieldNumber) {
                if ($type !== $field->definition->baseType->definitionByte() || $size !== $field->definition->size || 1 < ++$found) {
                    throw new RepairFailed('Recorded partition requires unique scalar native fields.');
                }
            }
        }

        return $message->integer($number);
    }
}
