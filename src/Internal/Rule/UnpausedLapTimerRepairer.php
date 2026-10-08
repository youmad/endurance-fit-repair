<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\ActivityHeartRateTail;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Copies recorded elapsed into impossible timers corroborated by a complete unpaused trace. */
final readonly class UnpausedLapTimerRepairer
{
    public const string RULE = 'unpaused_lap_timer_from_elapsed';
    private const int LIMIT = 4096;

    public function repair(string $bytes): RepairResult
    {
        [$member, $tail] = ActivityHeartRateTail::split($bytes);
        if ('' !== $tail) {
            return ActivityHeartRateTail::append($bytes, $tail, $this->repair($member));
        }

        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate unpaused Lap timer evidence input.');
        }

        $sessions = $laps = $activities = $events = [];
        $ordinal = $fileIds = $recordCount = 0;
        $firstRecord = $lastRecord = null;
        $continuousRecords = true;

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill unpaused Lap timer evidence input.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;

            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Unpaused Lap timer repair requires one complete supported FIT member at byte zero.');
            }

            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }

                $number = $raw->globalMessageNumber();

                if (in_array($number, [0, 18, 19, 20, 21, 34], true)) {
                    $message = PhysicalMessage::fromRaw($raw, 0);
                    $timerOffset = null;
                    $position = $raw->byteOffset() + 1;

                    if (in_array($number, [18, 19, 34], true)) {
                        foreach ($raw->standardFields() as $field) {
                            if ((34 === $number ? 0 : 8) === $field->definition->fieldNumber) {
                                $timerOffset = $position;
                            }

                            $position += $field->definition->size;
                        }
                    }

                    $word = FitArchitecture::LittleEndian === $raw->definition()->architecture ? 'V' : 'N';

                    if (0 === $number) {
                        ++$fileIds;

                        if (1 !== $fileIds || 0 !== $ordinal || 4 !== $this->scalar($message, 0, [0x00, 0x02], 1)) {
                            throw new RepairFailed('Unpaused Lap timer repair requires one Activity FileId as the first data message.');
                        }
                    } elseif (20 === $number) {
                        $timestamp = $this->scalar($message, 253, [0x86], 4);

                        if (null === $timestamp || $timestamp < 0x10000000 || (null !== $lastRecord && $timestamp < $lastRecord)) {
                            throw new RepairFailed('Unpaused Lap timer evidence requires complete, ordered absolute Record timestamps.');
                        }

                        if (null !== $lastRecord && $timestamp !== $lastRecord + 1) {
                            $continuousRecords = false;
                        }

                        $firstRecord ??= $timestamp;
                        $lastRecord = $timestamp;
                        ++$recordCount;
                    } elseif (18 === $number || 19 === $number) {
                        if ($raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Unpaused Lap timer repair requires uncompressed summaries.');
                        }

                        $summary = [
                            'message' => $ordinal, 'sequence' => $raw->sequence(),
                            'index' => $this->scalar($message, 254, [0x84], 2),
                            'start' => $this->scalar($message, 2, [0x86], 4),
                            'timestamp' => $this->scalar($message, 253, [0x86], 4),
                            'elapsed' => $this->scalar($message, 7, [0x86], 4),
                            'timer' => $this->scalar($message, 8, [0x86], 4),
                            'timer_offset' => $timerOffset, 'word' => $word,
                        ];

                        if (18 === $number) {
                            $summary['first_lap'] = $this->scalar($message, 25, [0x84], 2);
                            $summary['num_laps'] = $this->scalar($message, 26, [0x84], 2);
                            $sessions[] = $summary;
                        } else {
                            $laps[] = $summary;
                        }
                    } elseif (34 === $number) {
                        $activities[] = [
                            'message' => $ordinal, 'sequence' => $raw->sequence(),
                            'timestamp' => $this->scalar($message, 253, [0x86], 4),
                            'timer' => $this->scalar($message, 0, [0x86], 4),
                            'sessions' => $this->scalar($message, 1, [0x84], 2),
                            'timer_offset' => $timerOffset, 'word' => $word,
                            'compressed' => $raw instanceof RawCompressedTimestampDataMessage,
                        ];
                    } else {
                        $kind = $this->scalar($message, 0, [0x00, 0x02], 1);

                        if (null === $kind) {
                            throw new RepairFailed('A missing Event kind cannot exclude contradictory timer evidence.');
                        }

                        if (0 === $kind) {
                            $events[] = [
                                'timestamp' => $this->scalar($message, 253, [0x86], 4),
                                'type' => $this->scalar($message, 1, [0x00, 0x02], 1), 'group' => $this->scalar($message, 4, [0x02], 1),
                            ];
                        }
                    }
                }

                if (count($sessions) > 1 || count($activities) > 1 || count($laps) > self::LIMIT || count($events) > 2) {
                    throw new RepairFailed('Unpaused Lap timer repair requires bounded evidence for one Session, Activity and uninterrupted timer pair.');
                }

                ++$ordinal;
            }

            $file->trailer();
        } finally {
            fclose($stream);
        }

        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($activities) || [] === $laps || $recordCount < 2 || 2 !== count($events)) {
            throw new RepairFailed('Unpaused Lap timer repair requires a complete Lap partition, Records and one START/STOP pair.');
        }

        $session = $sessions[0];
        $activity = $activities[0];

        if (1 === count($laps) && null !== $session['timer'] && null !== $session['elapsed'] && $session['timer'] > $session['elapsed']) {
            $lap = $laps[0];
            [$startEvent, $stopEvent] = $events;
            $start = $startEvent['timestamp'];
            $finish = $lap['timestamp'];
            $stop = $stopEvent['timestamp'];
            $elapsed = $session['elapsed'];

            if (null === $start || null === $finish || null === $stop || $start < 0x10000000 || $finish <= $start || $stop < $finish || $elapsed <= 0
                || 0 !== $startEvent['type'] || !in_array($stopEvent['type'], [1, 4], true)
                || !in_array($startEvent['group'], [null, 0], true) || !in_array($stopEvent['group'], [null, 0], true)
                || $session['start'] !== $start || $lap['start'] !== $start
                || $session['timestamp'] !== $stop
                || null === $activity['timestamp'] || $activity['timestamp'] < $stop || 1 !== $activity['sessions']
                || !in_array($session['index'], [null, 0], true) || !in_array($lap['index'], [null, 0], true)
                || !in_array($session['first_lap'], [null, 0], true) || !in_array($session['num_laps'], [null, 1], true)
                || $lap['elapsed'] !== $elapsed || $lap['timer'] !== $session['timer'] || $activity['timer'] !== $session['timer']
                || $elapsed !== ($finish - $start) * 1000 || $session['timer'] !== $recordCount * 1000
                || !$continuousRecords || $recordCount !== $finish - $start + 1
                || $firstRecord < $start || $firstRecord > $start + 1 || $lastRecord < $finish || $lastRecord > $finish + 1
                || $firstRecord - $start !== $lastRecord - $finish || $activity['compressed']) {
                throw new RepairFailed('One whole-session Lap needs identical recorded elapsed values matching START and the existing Lap end, a later or equal STOP/SAVE, and identical impossible timers equal to the inclusive count of consecutive one-second Records.');
            }

            $body = substr($bytes, 0, -2);
            $changes = $offsets = [];

            foreach ([$session, $lap, $activity] as $index => $target) {
                if (null === $target['timer_offset']) {
                    throw new RepairFailed('Whole-session timer repair requires three existing scalar timer fields.');
                }

                $offsets[] = $target['timer_offset'];
                $body = substr_replace($body, pack($target['word'], $elapsed), $target['timer_offset'], 4);
                $changes[] = ['message' => $target['message'], 'field' => 2 === $index ? 0 : 8, 'type' => 0x86, 'value' => $elapsed];
            }

            $output = $body.pack('v', FitCrc16::calculate($body));

            return new RepairResult($output, [
                'mode' => 'preserve', 'rule' => self::RULE, 'variant' => 'whole_session_impossible_timer',
                'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
                'changes' => $changes, 'evidence' => [
                    'session_sequence' => $session['sequence'], 'lap_sequence' => $lap['sequence'],
                    'recorded_elapsed_ms' => $elapsed, 'original_timer_ms' => $session['timer'], 'timer_byte_offsets' => $offsets,
                    'timer_start' => $start, 'timer_stop' => $stop, 'timer_active_ms' => ($stop - $start) * 1000, 'recorded_lap_end' => $finish, 'recorded_span_ms' => ($finish - $start) * 1000, 'timer_paused_ms' => 0,
                    'record_count' => $recordCount, 'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                    'policy' => 'copy the exact unchanged Session and whole-session Lap elapsed into their identical impossible timers and the matching Activity timer only when the recorded START/Lap end and consecutive one-second Record intervals match both elapsed values, while the timers equal the inclusive sample count; retain a later STOP/SAVE and all clocks; no duration is calculated from timestamps',
                ],
                'preservation' => 'all original bytes retained outside three existing summary timers and file CRC',
                'activity_validation' => 'not_performed',
            ]);
        }

        if (0 !== $session['index'] || 0 !== $session['first_lap'] || count($laps) !== $session['num_laps']
            || 1 !== $activity['sessions'] || null === $session['start'] || $session['start'] < 0x10000000
            || null === $session['elapsed'] || null === $session['timer'] || $session['timer'] <= 0 || $session['timer'] > $session['elapsed']
            || null === $session['timestamp'] || null === $activity['timestamp']
            || (null !== $activity['timer'] && $activity['timer'] !== $session['timer'])) {
            throw new RepairFailed('Session references, clocks and unchanged Activity timer must agree.');
        }

        [$startEvent, $stopEvent] = $events;
        $start = $startEvent['timestamp'];
        $finish = $stopEvent['timestamp'];

        if (null === $start || null === $finish || $start !== $session['start'] || $finish <= $start
            || 0 !== $startEvent['type'] || !in_array($stopEvent['type'], [1, 4], true)
            || !in_array($startEvent['group'], [null, 0], true) || !in_array($stopEvent['group'], [null, 0], true)
            || $session['timestamp'] < $finish || $activity['timestamp'] < $finish) {
            throw new RepairFailed('Unpaused Lap timer repair requires one ordered, unambiguous START/STOP trace.');
        }

        $span = ($finish - $start) * 1000;
        $end = $start + intdiv($session['elapsed'], 1000);

        if (abs($span - $session['elapsed']) > 1000 || abs($span - $session['timer']) > 1000
            || $session['elapsed'] - $session['timer'] > 1000 || $firstRecord < $start || $lastRecord > $finish + 1 || $lastRecord > $end + 1) {
            throw new RepairFailed('Unchanged Session elapsed, active time and all Records must corroborate the uninterrupted timer trace.');
        }

        $elapsed = $timer = 0;
        $previousEnd = $candidate = null;

        foreach ($laps as $index => $lap) {
            if ($index !== $lap['index'] || null === $lap['start'] || $lap['start'] < $start
                || null === $lap['timestamp'] || $lap['timestamp'] < $lap['start']
                || null === $lap['elapsed'] || $lap['elapsed'] <= 0 || null === $lap['timer'] || $lap['timer'] <= 0
                || (0 === $index && $lap['start'] !== $start)
                || (null !== $previousEnd && (abs($lap['start'] - $previousEnd) > 2 || $lap['start'] < $laps[$index - 1]['start']))) {
                throw new RepairFailed('Unchanged Laps must form one complete indexed and abutting partition with usable durations.');
            }

            if ($lap['timer'] - $lap['elapsed'] > 1000) {
                if (null !== $candidate) {
                    throw new RepairFailed('Only one independently identifiable impossible Lap timer can be repaired.');
                }

                $candidate = $lap;
            } elseif (abs($lap['timer'] - $lap['elapsed']) > 1000) {
                throw new RepairFailed('Other Lap timers must agree with their elapsed times in the uninterrupted trace.');
            }

            $elapsed += $lap['elapsed'];
            $timer += $lap['timer'];
            $previousEnd = $lap['start'] + intdiv($lap['elapsed'], 1000);

            if ($previousEnd > $end + 1 || $previousEnd > $finish + 1) {
                throw new RepairFailed('Every unchanged Lap end must remain inside the corroborated Session and timer bounds.');
            }
        }

        if (null === $candidate || $elapsed >= 0xFFFFFFFF || abs($elapsed - $session['elapsed']) > 1
            || abs($previousEnd - $finish) > 1 || $timer - $session['timer'] <= 1000) {
            throw new RepairFailed('One impossible Lap timer, an excessive timer sum and the exact recorded elapsed partition are required.');
        }

        $repairedTimer = $timer - $candidate['timer'] + $candidate['elapsed'];

        if (abs($repairedTimer - $session['timer']) > 1000) {
            throw new RepairFailed('Copying the existing Lap elapsed must restore agreement with the unchanged Session timer.');
        }

        $offset = $candidate['timer_offset'];
        $body = substr_replace(substr($bytes, 0, -2), pack($candidate['word'], $candidate['elapsed']), $offset, 4);
        $output = $body.pack('v', FitCrc16::calculate($body));

        if (strlen($output) !== strlen($bytes) || substr($output, 0, $offset) !== substr($bytes, 0, $offset)
            || substr($output, $offset + 4, -2) !== substr($bytes, $offset + 4, -2)) {
            throw new RepairFailed('Unpaused Lap timer repair changed bytes outside one timer field and file CRC.');
        }

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => [['message' => $candidate['message'], 'field' => 8, 'type' => 0x86, 'value' => $candidate['elapsed']]],
            'original_lap_timer_ms' => $candidate['timer'],
            'evidence' => [
                'lap_sequence' => $candidate['sequence'], 'lap_index' => $candidate['index'], 'timer_byte_offset' => $offset,
                'lap_elapsed_ms' => $candidate['elapsed'], 'lap_count' => count($laps),
                'lap_elapsed_sum_ms' => $elapsed, 'original_lap_timer_sum_ms' => $timer, 'repaired_lap_timer_sum_ms' => $repairedTimer,
                'session_elapsed_ms' => $session['elapsed'], 'session_timer_ms' => $session['timer'], 'activity_timer_ms' => $activity['timer'],
                'timer_start' => $start, 'timer_stop' => $finish, 'timer_active_ms' => $span, 'timer_paused_ms' => 0,
                'original_timer_sum_residual_ms' => $timer - $session['timer'], 'repaired_timer_sum_residual_ms' => $repairedTimer - $session['timer'],
                'record_count' => $recordCount, 'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                'policy' => 'copy only the exact existing elapsed of one impossible Lap timer when an uninterrupted timer trace and the unchanged complete summaries corroborate it; retain all elapsed durations, other timers, timestamps and events',
            ],
            'preservation' => 'all original bytes retained outside one Lap.total_timer_time and file CRC',
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
                throw new RepairFailed('Unpaused Lap timer evidence requires unique fields of a supported physical type.');
            }

            $found = true;
        }

        return $message->integer($number);
    }
}
