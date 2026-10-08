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

/** Copies an independently corroborated Lap elapsed sum into an excessive Session total. */
final readonly class SessionElapsedFromLapsRepairer
{
    public const string RULE = 'session_elapsed_from_recorded_laps';
    private const int LIMIT = 4096;

    public function repair(string $bytes): RepairResult
    {
        [$member, $tail] = ActivityHeartRateTail::split($bytes);
        if ('' !== $tail) {
            return ActivityHeartRateTail::append($bytes, $tail, $this->repair($member));
        }

        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate Session elapsed evidence input.');
        }

        $sessions = $laps = $activities = $events = [];
        $ordinal = $fileIds = $recordCount = 0;
        $firstRecord = $lastRecord = null;

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill Session elapsed evidence input.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;

            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Session elapsed repair requires one complete supported FIT member at byte zero.');
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
                            throw new RepairFailed('Session elapsed repair requires one Activity FileId as the first data message.');
                        }
                    } elseif (20 === $number) {
                        $timestamp = $this->scalar($message, 253, [0x86], 4);

                        if (null === $timestamp || $timestamp < 0x10000000 || (null !== $lastRecord && $timestamp < $lastRecord)) {
                            throw new RepairFailed('Session elapsed evidence requires complete, ordered absolute Record timestamps.');
                        }

                        $firstRecord ??= $timestamp;
                        $lastRecord = $timestamp;
                        ++$recordCount;
                    } elseif (18 === $number || 19 === $number) {
                        if ($raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Session elapsed repair requires uncompressed summaries.');
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
                            $position = $raw->byteOffset() + 1;

                            foreach ($raw->standardFields() as $field) {
                                if (7 === $field->definition->fieldNumber) {
                                    $summary['elapsed_offset'] = $position;
                                }

                                $position += $field->definition->size;
                            }

                            $summary['word'] = FitArchitecture::LittleEndian === $raw->definition()->architecture ? 'V' : 'N';
                            $sessions[] = $summary;
                        } else {
                            $laps[] = $summary;
                        }
                    } elseif (34 === $number) {
                        $activities[] = [
                            'timestamp' => $this->scalar($message, 253, [0x86], 4),
                            'timer' => $this->scalar($message, 0, [0x86], 4),
                            'sessions' => $this->scalar($message, 1, [0x84], 2),
                        ];
                    } else {
                        // Enum and uint8 share their one-byte representation and invalid sentinel.
                        $kind = $this->scalar($message, 0, [0x00, 0x02], 1);

                        if (null === $kind) {
                            throw new RepairFailed('A missing Event kind cannot exclude contradictory timer evidence.');
                        }

                        if (0 === $kind) {
                            $events[] = [
                                'sequence' => $raw->sequence(), 'timestamp' => $this->scalar($message, 253, [0x86], 4),
                                'type' => $this->scalar($message, 1, [0x00, 0x02], 1), 'group' => $this->scalar($message, 4, [0x02], 1),
                            ];
                        }
                    }
                }

                if (count($sessions) > 1 || count($activities) > 1 || count($laps) > self::LIMIT || count($events) > self::LIMIT) {
                    throw new RepairFailed('Session elapsed repair requires bounded evidence for one Session and Activity.');
                }

                ++$ordinal;
            }

            $file->trailer();
        } finally {
            fclose($stream);
        }

        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($activities) || [] === $laps || $recordCount < 2
            || count($events) < 2 || 0 !== count($events) % 2) {
            throw new RepairFailed('Session elapsed repair requires a complete Lap partition, Records and complete timer pairs.');
        }

        $session = $sessions[0];
        $activity = $activities[0];

        if (0 !== $session['index'] || 0 !== $session['first_lap'] || count($laps) !== $session['num_laps']
            || 1 !== $activity['sessions'] || null === $session['start'] || $session['start'] < 0x10000000
            || null === $session['elapsed'] || null === $session['timer'] || $session['timer'] <= 0 || $session['timer'] > $session['elapsed']
            || null === $session['timestamp'] || $session['timestamp'] < $session['start']
            || null === $activity['timestamp'] || $activity['timestamp'] < $session['start']
            || (null !== $activity['timer'] && $activity['timer'] !== $session['timer'])) {
            throw new RepairFailed('Session references, clocks and unchanged Activity timer must agree.');
        }

        $elapsed = $timer = 0;
        $previousEnd = null;
        $lapEnds = [];

        foreach ($laps as $index => $lap) {
            if ($index !== $lap['index'] || null === $lap['start'] || $lap['start'] < $session['start']
                || null === $lap['timestamp'] || $lap['timestamp'] < $session['start']
                || null === $lap['elapsed'] || $lap['elapsed'] <= 0 || null === $lap['timer'] || $lap['timer'] < 0 || $lap['timer'] > $lap['elapsed']
                || (0 === $index && $lap['start'] !== $session['start'])
                || (null !== $previousEnd && (abs($lap['start'] - $previousEnd) > 2 || $lap['start'] < $laps[$index - 1]['start']))) {
                throw new RepairFailed('Unchanged Laps must form one complete indexed and abutting partition with usable durations.');
            }

            $elapsed += $lap['elapsed'];
            $timer += $lap['timer'];
            $previousEnd = $lap['start'] + intdiv($lap['elapsed'], 1000);
            $lapEnds[] = $previousEnd;
        }

        if ($elapsed >= 0xFFFFFFFF || $elapsed < $session['timer'] || abs($timer - $session['timer']) > 1
            || $session['elapsed'] - $elapsed <= 1000) {
            throw new RepairFailed('Only an excessive Session elapsed total with an unchanged corroborated Lap timer sum can be repaired.');
        }

        $active = 0;

        foreach ($events as $index => $event) {
            if (null === $event['timestamp'] || $event['timestamp'] < $session['start'] || !in_array($event['group'], [null, 0], true)
                || (0 === $index % 2 ? 0 !== $event['type'] : !in_array($event['type'], [1, 4], true))
                || ($index > 0 && $event['timestamp'] < $events[$index - 1]['timestamp'])) {
                throw new RepairFailed('Session elapsed repair requires one ordered, alternating timer trace.');
            }

            if (1 === $index % 2) {
                $active += ($event['timestamp'] - $events[$index - 1]['timestamp']) * 1000;
            }
        }

        $start = $events[0]['timestamp'];
        $finish = $events[array_key_last($events)]['timestamp'];
        $span = ($finish - $start) * 1000;
        $paused = $span - $active;
        $end = $session['start'] + intdiv($elapsed, 1000);
        $finalLap = $laps[array_key_last($laps)];
        $recordedFinalEnd = 2 === count($events) && $finalLap['timestamp'] === $finish
            && 2 === abs($lapEnds[array_key_last($lapEnds)] - $finish)
            && $elapsed === $timer && $timer === $session['timer'] && $activity['timer'] === $timer;

        // A whole-second START may follow the independently identical Session
        // and first Lap starts by one tick; exact source durations stay intact.
        if ($start < $session['start'] || $start > $session['start'] + 1 || abs($span - $elapsed) > 1000 || abs($active - $session['timer']) > 1000
            || abs($paused - ($elapsed - $timer)) > 1000 || $session['elapsed'] - $span <= 1000
            || $firstRecord < $start || $lastRecord > $finish + 1 || $lastRecord > $end + 1
            || (abs($lapEnds[array_key_last($lapEnds)] - $finish) > 1 && !$recordedFinalEnd)
            || max($lapEnds) > $end + 1) {
            throw new RepairFailed('Recorded timer elapsed, active and paused spans and all Records must corroborate the unchanged Lap totals, excluding the original Session elapsed.');
        }

        $offset = $session['elapsed_offset'];
        $body = substr_replace(substr($bytes, 0, -2), pack($session['word'], $elapsed), $offset, 4);
        $output = $body.pack('v', FitCrc16::calculate($body));

        if (strlen($output) !== strlen($bytes) || substr($output, 0, $offset) !== substr($bytes, 0, $offset)
            || substr($output, $offset + 4, -2) !== substr($bytes, $offset + 4, -2)) {
            throw new RepairFailed('Session elapsed repair changed bytes outside one elapsed field and file CRC.');
        }

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => [['message' => $session['message'], 'field' => 7, 'type' => 0x86, 'value' => $elapsed]],
            'original_session_elapsed_ms' => $session['elapsed'],
            'evidence' => [
                'session_sequence' => $session['sequence'], 'elapsed_byte_offset' => $offset,
                'lap_count' => count($laps), 'lap_elapsed_sum_ms' => $elapsed, 'lap_timer_sum_ms' => $timer,
                'session_timer_ms' => $session['timer'], 'activity_timer_ms' => $activity['timer'], 'timer_start' => $start, 'timer_stop' => $finish,
                'timer_elapsed_ms' => $span, 'timer_active_ms' => $active, 'timer_paused_ms' => $paused,
                'original_elapsed_residual_ms' => $session['elapsed'] - $span, 'repaired_elapsed_residual_ms' => $elapsed - $span,
                'active_residual_ms' => $session['timer'] - $active, 'paused_residual_ms' => $elapsed - $timer - $paused,
                'timer_pairs' => intdiv(count($events), 2), 'record_count' => $recordCount,
                'recorded_final_end_corroborated' => $recordedFinalEnd,
                'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                'policy' => 'copy the exact existing Lap elapsed sum only when the complete timer trace corroborates elapsed, active and paused spans; retain all Lap durations, timestamps and timer events',
            ],
            'preservation' => 'all original bytes retained outside one Session.total_elapsed_time and file CRC',
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
                throw new RepairFailed('Session elapsed evidence requires unique fields of a supported physical type.');
            }

            $found = true;
        }

        return $message->integer($number);
    }
}
