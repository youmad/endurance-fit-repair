<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Restores a final Lap from its recorded timer, corroborated boundaries and unchanged Session totals. */
final readonly class UnpausedFinalLapRepairer
{
    public const string RULE = 'unpaused_final_lap_from_recorded_values';
    private const int LIMIT = 4096;

    public function repair(string $bytes): RepairResult
    {
        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate unpaused final Lap evidence input.');
        }

        $sessions = $laps = $activities = $events = [];
        $ordinal = $fileIds = $recordCount = 0;
        $firstRecord = $lastRecord = null;

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill unpaused final Lap evidence input.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;

            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Unpaused final Lap repair requires one complete supported FIT member at byte zero.');
            }

            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }

                $number = $raw->globalMessageNumber();

                if (in_array($number, [0, 18, 19, 20, 21, 34], true)) {
                    $message = PhysicalMessage::fromRaw($raw, 0);

                    if (0 === $number) {
                        ++$fileIds;

                        if (1 !== $fileIds || 0 !== $ordinal || 4 !== $this->scalar($message, 0, [0x00, 0x02], 1)) {
                            throw new RepairFailed('Unpaused final Lap repair requires one Activity FileId as the first data message.');
                        }
                    } elseif (20 === $number) {
                        $timestamp = $this->scalar($message, 253, [0x86], 4);

                        if (null === $timestamp || $timestamp < 0x10000000 || (null !== $lastRecord && $timestamp < $lastRecord)) {
                            throw new RepairFailed('Unpaused final Lap evidence requires complete, ordered absolute Record timestamps.');
                        }

                        $firstRecord ??= $timestamp;
                        $lastRecord = $timestamp;
                        ++$recordCount;
                    } elseif (18 === $number || 19 === $number) {
                        if ($raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Unpaused final Lap repair requires uncompressed summaries.');
                        }

                        $summary = [
                            'message' => $ordinal, 'sequence' => $raw->sequence(),
                            'index' => $this->scalar($message, 254, [0x84], 2),
                            'start' => $this->scalar($message, 2, [0x86], 4),
                            'timestamp' => $this->scalar($message, 253, [0x86], 4),
                            'elapsed' => $this->scalar($message, 7, [0x86], 4),
                            'timer' => $this->scalar($message, 8, [0x86], 4),
                        ];

                        if (18 === $number) {
                            $summary['first_lap'] = $this->scalar($message, 25, [0x84], 2);
                            $summary['num_laps'] = $this->scalar($message, 26, [0x84], 2);
                            $sessions[] = $summary;
                        } else {
                            $position = $raw->byteOffset() + 1;

                            foreach ($raw->standardFields() as $field) {
                                if (in_array($field->definition->fieldNumber, [2, 7], true)) {
                                    $summary[2 === $field->definition->fieldNumber ? 'start_offset' : 'elapsed_offset'] = $position;
                                }

                                $position += $field->definition->size;
                            }

                            $summary['word'] = FitArchitecture::LittleEndian === $raw->definition()->architecture ? 'V' : 'N';
                            $laps[] = $summary;
                        }
                    } elseif (34 === $number) {
                        $activities[] = [
                            'timestamp' => $this->scalar($message, 253, [0x86], 4),
                            'timer' => $this->scalar($message, 0, [0x86], 4),
                            'sessions' => $this->scalar($message, 1, [0x84], 2),
                        ];
                    } else {
                        $kind = $this->scalar($message, 0, [0x00, 0x02], 1);

                        if (null === $kind) {
                            throw new RepairFailed('A missing Event kind cannot exclude contradictory timer evidence.');
                        }

                        if (0 === $kind) {
                            $events[] = [
                                'timestamp' => $this->scalar($message, 253, [0x86], 4),
                                'type' => $this->scalar($message, 1, [0x00, 0x02], 1),
                                'group' => $this->scalar($message, 4, [0x02], 1),
                            ];
                        }
                    }
                }

                if (count($sessions) > 1 || count($activities) > 1 || count($laps) > self::LIMIT || count($events) > self::LIMIT) {
                    throw new RepairFailed('Unpaused final Lap repair requires bounded evidence for one Session, Activity and uninterrupted timer pair.');
                }

                ++$ordinal;
            }

            $file->trailer();
        } finally {
            fclose($stream);
        }

        if (count($events) > 2) {
            return $this->pausedSave($bytes, $fileIds, $sessions, $laps, $activities, $events, $recordCount, $firstRecord, $lastRecord);
        }

        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($activities) || count($laps) < 2 || $recordCount < 2 || 2 !== count($events)) {
            throw new RepairFailed('Unpaused final Lap repair requires at least two Laps, complete Records and one START/STOP pair.');
        }

        $session = $sessions[0];
        $activity = $activities[0];
        [$startEvent, $stopEvent] = $events;
        $start = $startEvent['timestamp'];
        $finish = $stopEvent['timestamp'];

        if (null === $start || null === $finish || $start < 0x10000000 || $finish <= $start
            || 0 !== $startEvent['type'] || !in_array($stopEvent['type'], [1, 4], true)
            || !in_array($startEvent['group'], [null, 0], true) || !in_array($stopEvent['group'], [null, 0], true)
            || $firstRecord !== $start || $lastRecord !== $finish || $session['start'] !== $start
            || !in_array($session['index'], [null, 0], true) || !in_array($session['first_lap'], [null, 0], true)
            || !in_array($session['num_laps'], [null, count($laps)], true) || 1 !== $activity['sessions']
            || null === $session['timestamp'] || abs($session['timestamp'] - $finish) > 1
            || null === $activity['timestamp'] || $activity['timestamp'] < $start || $activity['timestamp'] > $finish + 1
            || null === $session['elapsed'] || null === $session['timer'] || $session['timer'] <= 0
            || $session['elapsed'] < $session['timer'] || $session['elapsed'] - $session['timer'] > 1000
            || (null !== $activity['timer'] && abs($activity['timer'] - $session['timer']) > 1000)) {
            throw new RepairFailed('Session, Activity, Record boundaries and the uninterrupted timer trace must agree.');
        }

        $span = ($finish - $start) * 1000;
        $end = $start + intdiv($session['elapsed'], 1000);

        if (abs($span - $session['elapsed']) > 1000 || abs($span - $session['timer']) > 1000 || $lastRecord > $end + 1) {
            throw new RepairFailed('Unchanged Session durations must corroborate the full uninterrupted Record span.');
        }

        $elapsed = $timer = 0;
        $previousEnd = null;
        $lastIndex = count($laps) - 1;
        $indexed = null !== $laps[0]['index'];
        $lateSave = null !== $laps[$lastIndex]['timestamp'] && $laps[$lastIndex]['timestamp'] > $finish + 1;

        foreach ($laps as $index => $lap) {
            if (($indexed ? $lap['index'] !== $index : null !== $lap['index'])
                || null === $lap['start'] || $lap['start'] < $start || null === $lap['timestamp'] || $lap['timestamp'] < $lap['start']
                || null === $lap['elapsed'] || $lap['elapsed'] <= 0 || null === $lap['timer'] || $lap['timer'] <= 0
                || $lap['timer'] > $lap['elapsed'] || (0 === $index && $lap['start'] !== $start)
                || abs($lap['timestamp'] - $lap['start'] - intdiv($lap['elapsed'], 1000)) > 1
                || ((!$lateSave || $index !== $lastIndex)
                    && ($lap['timestamp'] > $finish + 1 || $lap['start'] + intdiv($lap['elapsed'], 1000) > $end + 1))) {
                throw new RepairFailed('All recorded Laps must have consistent clocks, durations and optional consecutive indexes.');
            }

            if ($index < $lastIndex && ($lap['elapsed'] - $lap['timer'] > 1000
                || (null !== $previousEnd && (abs($lap['start'] - $previousEnd) > 2 || $lap['start'] < $laps[$index - 1]['start'])))) {
                throw new RepairFailed('Every preceding Lap must corroborate one uninterrupted, abutting partition.');
            }

            $elapsed += $lap['elapsed'];
            $timer += $lap['timer'];
            $previousEnd = $lap['start'] + intdiv($lap['elapsed'], 1000);
        }

        $last = $laps[$lastIndex];
        $previous = $laps[$lastIndex - 1];
        $boundary = $previous['timestamp'];
        $repairedElapsed = $elapsed - $last['elapsed'] + $last['timer'];
        $repairedStart = $lateSave ? $last['start'] : $boundary;
        $repairedEnd = $repairedStart + intdiv($last['timer'], 1000);
        $exactTotals = $session['elapsed'] === $session['timer']
            && $timer === $session['timer'] && $repairedElapsed === $session['elapsed']
            && $activity['timer'] === $session['timer'];
        // Exact, independently recorded totals can corroborate the Session end
        // when its whole-second clock and STOP differ by the already checked second.
        $sessionEndpoint = $lateSave && $exactTotals
            && (abs(($finish - $last['start']) * 1000 - $last['timer']) > 1000 || abs($repairedEnd - $finish) > 1);
        $finalEndpoint = $sessionEndpoint ? $end : $finish;

        if ($lateSave) {
            if (abs($last['start'] - $previous['start'] - intdiv($previous['elapsed'], 1000)) > 2
                || abs($last['start'] - $boundary) > 1 || $boundary <= $previous['start']
                || $last['elapsed'] - $last['timer'] <= 1000
                || abs(($finalEndpoint - $last['start']) * 1000 - $last['timer']) > 1000
                || abs($repairedEnd - $finalEndpoint) > 1 || $repairedEnd > $end + 1
                || $elapsed - $session['elapsed'] <= 1000 || abs($timer - $session['timer']) > 1000
                || abs($repairedElapsed - $session['elapsed']) > 1000) {
                throw new RepairFailed('A late final Lap save can replace only an excessive elapsed when its unchanged start abuts the preceding Lap and its recorded timer restores both unchanged Session totals and STOP.');
            }
        } elseif ($previous['start'] + intdiv($previous['elapsed'], 1000) - $last['start'] <= 2
            || $last['elapsed'] - $last['timer'] <= 1000 || $boundary <= $previous['start']
            || $boundary >= $last['timestamp'] || abs($last['timestamp'] - $finish) > 1
            || abs(($last['timestamp'] - $boundary) * 1000 - $last['timer']) > 1000
            || abs($repairedEnd - $finish) > 1 || $repairedEnd > $end + 1
            || ($boundary - $start) * 1000 + $last['timer'] > $session['elapsed'] + 1000
            || $elapsed - $session['elapsed'] <= 1000 || abs($timer - $session['timer']) > 1000
            || abs($repairedElapsed - $session['elapsed']) > 1000) {
            throw new RepairFailed('Only one overlapping final Lap whose recorded timer and previous boundary restore both unchanged Session totals can be repaired.');
        }

        $changes = $lateSave ? [] : [['message' => $last['message'], 'field' => 2, 'type' => 0x86, 'value' => $boundary]];
        $changes[] = ['message' => $last['message'], 'field' => 7, 'type' => 0x86, 'value' => $last['timer']];
        $body = substr_replace(substr($bytes, 0, -2), pack($last['word'], $last['timer']), $last['elapsed_offset'], 4);

        if (!$lateSave) {
            $body = substr_replace($body, pack($last['word'], $boundary), $last['start_offset'], 4);
        }

        $output = $body.pack('v', FitCrc16::calculate($body));

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE,
            'variant' => $lateSave ? 'elapsed_includes_late_save' : 'overlapping_final_lap',
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes,
            'evidence' => [
                'lap_sequence' => $last['sequence'], 'lap_position' => $lastIndex, 'lap_count' => count($laps),
                'start_byte_offset' => $last['start_offset'], 'elapsed_byte_offset' => $last['elapsed_offset'],
                'original_lap_start' => $last['start'], 'original_lap_elapsed_ms' => $last['elapsed'],
                'previous_lap_timestamp' => $boundary, 'recorded_lap_timer_ms' => $last['timer'],
                'original_elapsed_sum_ms' => $elapsed, 'repaired_elapsed_sum_ms' => $repairedElapsed, 'lap_timer_sum_ms' => $timer,
                'session_elapsed_ms' => $session['elapsed'], 'session_timer_ms' => $session['timer'],
                'timer_start' => $start, 'timer_stop' => $finish, 'timer_paused_ms' => 0,
                'final_lap_endpoint' => $finalEndpoint,
                'final_lap_endpoint_source' => $sessionEndpoint ? 'unchanged Session start and elapsed, exact Lap and Activity totals' : 'recorded STOP',
                'record_count' => $recordCount, 'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                'policy' => $lateSave
                    ? 'copy only the existing final Lap timer into its excessive elapsed when the original elapsed reaches a later save timestamp, while its unchanged start, the complete uninterrupted trace, all other Laps and both unchanged Session totals corroborate that timer; retain the original start and save timestamp'
                    : 'copy only the preceding recorded timestamp and the existing final Lap timer when the complete uninterrupted trace, all other Laps and both unchanged Session totals corroborate them',
            ],
            'preservation' => $lateSave
                ? 'all original bytes retained outside final Lap.total_elapsed_time and file CRC'
                : 'all original bytes retained outside final Lap.start_time, Lap.total_elapsed_time and file CRC',
            'activity_validation' => 'not_performed',
        ]);
    }

    /**
     * @param list<array<string, mixed>>                                        $sessions
     * @param list<array<string, mixed>>                                        $laps
     * @param list<array<string, mixed>>                                        $activities
     * @param list<array{timestamp: int|null, type: int|null, group: int|null}> $events
     */
    private function pausedSave(string $bytes, int $fileIds, array $sessions, array $laps, array $activities, array $events, int $recordCount, ?int $firstRecord, ?int $lastRecord): RepairResult
    {
        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($activities) || count($laps) < 2 || $recordCount < 2 || 0 !== count($events) % 2) {
            throw new RepairFailed('Paused final SAVE requires one complete Session, Activity, multiple Laps and alternating timer pairs.');
        }

        $session = $sessions[0];
        $activity = $activities[0];
        $start = $session['start'];
        $finish = $events[array_key_last($events)]['timestamp'];

        if (null === $start || $start < 0x10000000 || null === $finish || $finish <= $start || $session['timestamp'] !== $finish
            || $firstRecord !== $start || $lastRecord !== $finish || $events[0]['timestamp'] !== $start
            || !in_array($session['index'], [null, 0], true) || !in_array($session['first_lap'], [null, 0], true)
            || !in_array($session['num_laps'], [null, count($laps)], true) || 1 !== $activity['sessions']
            || null === $session['elapsed'] || null === $session['timer'] || $session['timer'] <= 0 || $session['elapsed'] <= $session['timer']
            || !in_array($activity['timer'], [$session['timer'], $session['elapsed']], true)
            || null === $activity['timestamp'] || $activity['timestamp'] < $start || $activity['timestamp'] > $finish + 1
            || abs(($finish - $start) * 1000 - $session['elapsed']) > 1000) {
            throw new RepairFailed('Native Session clocks, durations and endpoint Records must corroborate the paused activity.');
        }

        $active = 0;

        foreach ($events as $index => $event) {
            if (null === $event['timestamp'] || $event['timestamp'] < $start || $event['timestamp'] > $finish || !in_array($event['group'], [null, 0], true)
                || (0 === $index % 2 ? 0 !== $event['type'] : !in_array($event['type'], [1, 4], true))
                || ($index > 0 && $event['timestamp'] < $events[$index - 1]['timestamp'])) {
                throw new RepairFailed('Paused final SAVE requires a complete ordered alternating native timer trace.');
            }

            if (1 === $index % 2) {
                $active += ($event['timestamp'] - $events[$index - 1]['timestamp']) * 1000;
            }
        }

        if (abs($active - $session['timer']) > 1000 || abs(($finish - $start) * 1000 - $active - $session['elapsed'] + $session['timer']) > 1000) {
            throw new RepairFailed('Complete active and paused spans must independently corroborate unchanged Session durations.');
        }

        $lastIndex = count($laps) - 1;
        $last = $laps[$lastIndex];
        $elapsed = $timer = 0;
        $indexed = null !== $laps[0]['index'];

        foreach ($laps as $index => $lap) {
            if (($indexed ? $lap['index'] !== $index : null !== $lap['index']) || null === $lap['start'] || null === $lap['timestamp']
                || null === $lap['elapsed'] || null === $lap['timer'] || $lap['elapsed'] <= 0 || $lap['timer'] <= 0 || $lap['timer'] > $lap['elapsed']
                || $lap['start'] < $start || $lap['timestamp'] < $lap['start'] || (0 === $index && $lap['start'] !== $start)
                || abs(($lap['timestamp'] - $lap['start']) * 1000 - $lap['elapsed']) > 1000
                || ($index > 0 && ($lap['sequence'] <= $laps[$index - 1]['sequence']
                    || abs($lap['start'] - $laps[$index - 1]['start'] - intdiv($laps[$index - 1]['elapsed'], 1000)) > 2))
                || ($index < $lastIndex && ($lap['timestamp'] > $finish || $lap['start'] + intdiv($lap['elapsed'], 1000) > $start + intdiv($session['elapsed'], 1000) + 1))) {
                throw new RepairFailed('Existing native Lap clocks, indexes and durations must identify only the final late SAVE.');
            }

            $timer += $lap['timer'];

            if ($index < $lastIndex) {
                $elapsed += $lap['elapsed'];
            }
        }

        $replacement = $session['elapsed'] - $elapsed;
        $finalActive = 0;

        for ($index = 0; $index < count($events); $index += 2) {
            $finalActive += max(0, min($finish, $events[$index + 1]['timestamp']) - max($last['start'], $events[$index]['timestamp'])) * 1000;
        }

        $finalPaused = ($finish - $last['start']) * 1000 - $finalActive;
        $previous = $laps[$lastIndex - 1];

        if ($timer !== $session['timer'] || $replacement < $last['timer'] || $last['elapsed'] - $replacement <= 1000
            || $last['timestamp'] <= $finish + 1 || abs($last['start'] - $previous['timestamp']) > 1
            || abs(($finish - $last['start']) * 1000 - $replacement) > 1000
            || abs($finalActive - $last['timer']) > 1000 || abs($finalPaused - $replacement + $last['timer']) > 1000
            || abs($last['elapsed'] - $replacement - ($last['timestamp'] - $finish) * 1000) > 1000
            || abs($last['start'] + intdiv($replacement, 1000) - $start - intdiv($session['elapsed'], 1000)) > 1) {
            throw new RepairFailed('Only the final late-save elapsed residual may disagree with exact native Session and Lap timer totals and recorded active/paused boundaries.');
        }

        $offset = $last['elapsed_offset'];
        $body = substr_replace(substr($bytes, 0, -2), pack($last['word'], $replacement), $offset, 4);
        $output = $body.pack('v', FitCrc16::calculate($body));

        if (strlen($output) !== strlen($bytes) || substr($output, 0, $offset) !== substr($bytes, 0, $offset)
            || substr($output, $offset + 4, -2) !== substr($bytes, $offset + 4, -2)) {
            throw new RepairFailed('Paused final SAVE changed bytes outside final Lap elapsed and file CRC.');
        }

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE, 'variant' => 'paused_elapsed_includes_late_save',
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => [['message' => $last['message'], 'field' => 7, 'type' => 0x86, 'value' => $replacement]],
            'evidence' => [
                'elapsed_byte_offset' => $offset, 'session_elapsed_ms' => $session['elapsed'], 'session_timer_ms' => $session['timer'],
                'preceding_lap_elapsed_sum_ms' => $elapsed, 'lap_timer_sum_ms' => $timer, 'original_lap_elapsed_ms' => $last['elapsed'],
                'repaired_lap_elapsed_ms' => $replacement, 'final_lap_timer_ms' => $last['timer'],
                'timer_start' => $start, 'timer_stop' => $finish, 'session_active_ms' => $active,
                'final_lap_active_ms' => $finalActive, 'final_lap_paused_ms' => $finalPaused,
                'save_timestamp' => $last['timestamp'], 'save_delay_seconds' => $last['timestamp'] - $finish,
                'record_count' => $recordCount, 'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                'policy' => 'the unchanged Session elapsed minus unchanged preceding Lap elapsed identifies the final duration; exact native Lap and Session timers and the complete active/paused trace independently corroborate it; the excessive original elapsed instead reaches the retained later SAVE',
            ],
            'preservation' => 'all original bytes retained outside final Lap.total_elapsed_time and file CRC',
            'activity_validation' => 'not_performed',
        ]);
    }

    /** @param list<int> $types */
    private function scalar(PhysicalMessage $message, int $number, array $types, int $size): ?int
    {
        $found = false;

        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }

            if ($found || $size !== $field->definition->size || !in_array($field->definition->baseType->definitionByte(), $types, true)) {
                throw new RepairFailed('Unpaused final Lap evidence requires unique fields of a supported physical type.');
            }

            $found = true;
        }

        return $message->integer($number);
    }
}
