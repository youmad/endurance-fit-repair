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

/** Restores a Lap start using its recorded end and independently corroborated active span. */
final readonly class RecordedLapEndRepairer
{
    public const string RULE = 'lap_start_from_recorded_end';
    private const int LIMIT = 4096;

    public function repair(string $bytes): RepairResult
    {
        [$member, $tail] = ActivityHeartRateTail::split($bytes);
        if ('' !== $tail) {
            return ActivityHeartRateTail::append($bytes, $tail, $this->repair($member));
        }

        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate recorded Lap boundary input.');
        }

        $laps = $sessions = $activities = $events = [];
        $ordinal = $recordCount = $fileIdCount = 0;
        $firstRecord = $lastRecord = $lastRecordSequence = null;

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill recorded Lap boundary input.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;

            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Recorded Lap repair requires one complete supported FIT member at byte zero.');
            }

            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }

                $number = $raw->globalMessageNumber();

                if (in_array($number, [0, 18, 19, 20, 21, 34], true)) {
                    $message = PhysicalMessage::fromRaw($raw, 0);

                    if (0 === $number) {
                        ++$fileIdCount;

                        if (1 !== $fileIdCount || 0 !== $ordinal || 4 !== $this->scalar($message, 0, 0x00, 1)) {
                            throw new RepairFailed('Recorded Lap end repair requires one Activity FileId as the first data message.');
                        }
                    } elseif (20 === $number) {
                        $timestamp = $this->scalar($message, 253, 0x86, 4);

                        if (null === $timestamp || $timestamp < 0x10000000 || (null !== $lastRecord && $timestamp < $lastRecord)) {
                            throw new RepairFailed('Recorded Lap repair requires ordered, absolute Record timestamps.');
                        }

                        $firstRecord ??= $timestamp;
                        $lastRecord = $timestamp;
                        $lastRecordSequence = $raw->sequence();
                        ++$recordCount;
                    } elseif (18 === $number || 19 === $number) {
                        if ($raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Recorded Lap repair requires uncompressed summaries.');
                        }

                        $summary = [
                            'message' => $ordinal, 'sequence' => $raw->sequence(),
                            'start' => $this->scalar($message, 2, 0x86, 4),
                            'timestamp' => $this->scalar($message, 253, 0x86, 4),
                            'elapsed' => $this->scalar($message, 7, 0x86, 4),
                            'timer' => $this->scalar($message, 8, 0x86, 4),
                            'index' => $this->scalar($message, 254, 0x84, 2),
                        ];

                        if (18 === $number) {
                            $summary['first_lap'] = $this->scalar($message, 25, 0x84, 2);
                            $summary['num_laps'] = $this->scalar($message, 26, 0x84, 2);
                            $sessions[] = $summary;
                        } else {
                            $position = $raw->byteOffset() + 1;

                            foreach ($raw->standardFields() as $field) {
                                if (2 === $field->definition->fieldNumber) {
                                    $summary['start_offset'] = $position;
                                }

                                $position += $field->definition->size;
                            }

                            $summary['word'] = FitArchitecture::LittleEndian === $raw->definition()->architecture ? 'V' : 'N';
                            $laps[] = $summary;
                        }
                    } elseif (34 === $number) {
                        $activities[] = [
                            'sequence' => $raw->sequence(), 'timestamp' => $this->scalar($message, 253, 0x86, 4),
                            'timer' => $this->scalar($message, 0, 0x86, 4), 'sessions' => $this->scalar($message, 1, 0x84, 2),
                        ];
                    } else {
                        $event = $this->scalar($message, 0, 0x00, 1);

                        if (null === $event) {
                            throw new RepairFailed('An Event with missing kind cannot exclude a timer contradiction.');
                        }

                        if (0 === $event) {
                            $events[] = [
                                'sequence' => $raw->sequence(), 'timestamp' => $this->scalar($message, 253, 0x86, 4),
                                'type' => $this->scalar($message, 1, 0x00, 1), 'group' => $this->scalar($message, 4, 0x02, 1),
                            ];
                        }
                    }
                }

                if (count($laps) > self::LIMIT || count($events) > self::LIMIT || count($sessions) > 1 || count($activities) > 1) {
                    throw new RepairFailed('Recorded Lap repair requires bounded evidence for one Session and Activity.');
                }

                ++$ordinal;
            }

            $file->trailer();
        } finally {
            fclose($stream);
        }

        if (1 !== $fileIdCount || 1 !== count($sessions) || 1 !== count($activities) || count($laps) < 2 || $recordCount < 2
            || count($events) < 2 || 0 !== count($events) % 2) {
            throw new RepairFailed('Recorded Lap end repair requires one complete Session, multiple Laps and Records, and complete timer pairs.');
        }

        $session = $sessions[0];
        $activity = $activities[0];

        if (!in_array($session['index'], [null, 0], true) || 0 !== $session['first_lap'] || count($laps) !== $session['num_laps']
            || 1 !== $activity['sessions'] || null === $session['start'] || null === $session['timestamp']
            || null === $session['elapsed'] || null === $session['timer'] || $session['elapsed'] <= 0 || $session['timer'] <= 0
            || $session['timer'] > $session['elapsed'] || $activity['timer'] !== $session['timer']
            || null === $activity['timestamp'] || $activity['timestamp'] < $session['timestamp'] || $firstRecord !== $session['start']
            || abs(($lastRecord - $firstRecord) * 1000 - $session['elapsed']) > 1000
            || $lastRecordSequence >= $session['sequence']) {
            throw new RepairFailed('Session references, durations, Activity and the whole Record span must corroborate one activity.');
        }

        $elapsed = $timer = 0;
        $candidate = null;
        $sessionEnd = $session['start'] + intdiv($session['elapsed'], 1000);

        foreach ($laps as $index => $lap) {
            if ($index !== $lap['index'] || null === $lap['start'] || null === $lap['timestamp'] || null === $lap['elapsed'] || null === $lap['timer']
                || $lap['elapsed'] <= 0 || $lap['timer'] <= 0 || $lap['timer'] > $lap['elapsed']
                || $lap['start'] < $session['start'] || $lap['timestamp'] < $lap['start'] || $lap['timestamp'] > $session['timestamp']
                || $lap['sequence'] >= $session['sequence'] || (0 === $index && $lap['start'] !== $session['start'])
                || ($index > 0 && ($lap['sequence'] <= $laps[$index - 1]['sequence'] || $lap['start'] < $laps[$index - 1]['start']))) {
                throw new RepairFailed('Recorded Lap summaries must form one ordered, explicitly indexed Session partition.');
            }

            $elapsed += $lap['elapsed'];
            $timer += $lap['timer'];

            if ($index > 0 && null === $candidate && abs($lap['start'] - $laps[$index - 1]['start'] - intdiv($laps[$index - 1]['elapsed'], 1000)) > 2) {
                $candidate = $index;
            }
        }

        $final = $laps[array_key_last($laps)];
        $finalBoundary = null === $candidate || ($candidate === array_key_last($laps) && 2 === $final['start'] + intdiv($final['elapsed'], 1000) - $sessionEnd);

        if ($finalBoundary) {
            $candidate = array_key_last($laps);
            $final = $laps[$candidate];

            if (2 !== $final['start'] + intdiv($final['elapsed'], 1000) - $sessionEnd) {
                throw new RepairFailed('Without an abutment failure, only a two-second final Lap excess is considered.');
            }

            foreach ($laps as $index => $other) {
                if ($index !== $candidate && $other['start'] + intdiv($other['elapsed'], 1000) > $sessionEnd + 1) {
                    throw new RepairFailed('A different Lap exceeds the unchanged Session interval.');
                }
            }
        }

        if ($elapsed !== $session['elapsed'] || $timer !== $session['timer'] || $session['sequence'] >= $activity['sequence']) {
            throw new RepairFailed('One failing Lap boundary and exact unchanged Session/Lap duration sums are required.');
        }

        foreach ($events as $index => $event) {
            if (null === $event['timestamp'] || $event['timestamp'] < $session['start'] || !in_array($event['group'], [null, 0], true)
                || (0 === $index % 2 ? 0 !== $event['type'] : !in_array($event['type'], [1, 4], true))
                || ($index > 0 && $event['timestamp'] < $events[$index - 1]['timestamp'])) {
                throw new RepairFailed('Recorded Lap end repair requires one ordered, alternating timer trace.');
            }
        }

        $stop = $events[array_key_last($events)];
        $fullActive = $this->activeSpan($events, $session['start'], $lastRecord);

        if ($events[0]['timestamp'] !== $session['start'] || $stop['timestamp'] !== $lastRecord
            || $stop['sequence'] <= $lastRecordSequence || $stop['sequence'] >= $session['sequence']
            || $stop['timestamp'] > $session['timestamp'] || abs($fullActive - $session['timer']) > 1000) {
            throw new RepairFailed('The complete timer trace must corroborate the Session active duration and final Record.');
        }

        $previous = $laps[$candidate - 1];
        $lap = $laps[$candidate];
        $boundary = $previous['timestamp'];
        $finish = $finalBoundary ? $stop['timestamp'] : $lap['timestamp'];
        $gap = $lap['start'] - $previous['start'] - intdiv($previous['elapsed'], 1000);
        $oldResidual = ($finish - $lap['start']) * 1000 - $lap['elapsed'];
        $newResidual = ($finish - $boundary) * 1000 - $lap['elapsed'];
        $originalActive = $this->activeSpan($events, $lap['start'], $finish);
        $repairedActive = $this->activeSpan($events, $boundary, $finish);
        $paused = ($finish - $boundary) * 1000 - $repairedActive;

        if (($finalBoundary ? abs($gap - 1) > 2 : 3 !== $gap) || $lap['start'] !== $boundary + 1 || $oldResidual >= -1000 || abs($newResidual) > 1000
            || ($finalBoundary ? $lap['timestamp'] < $finish : $lap['timestamp'] > $stop['timestamp']) || $boundary < $session['start']
            || ($finish === $stop['timestamp'] && $stop['sequence'] >= $lap['sequence'])
            || 1000 !== $repairedActive - $originalActive || abs($repairedActive - $lap['timer']) > 1000
            || $originalActive - $lap['timer'] >= -1000
            || ($finalBoundary && abs($paused - ($lap['elapsed'] - $lap['timer'])) > 1000)
            || $boundary + intdiv($lap['elapsed'], 1000) > $sessionEnd + 1
            || (isset($laps[$candidate + 1]) && abs($laps[$candidate + 1]['start'] - $boundary - intdiv($lap['elapsed'], 1000)) > 2)) {
            throw new RepairFailed('Only the previous recorded timestamp must agree with the current Lap elapsed and active spans; the original start must contradict both.');
        }

        foreach ($events as $event) {
            if (($event['timestamp'] > $boundary && $event['sequence'] < $previous['sequence'])
                || ($event['timestamp'] < $boundary && $event['sequence'] > $previous['sequence'])
                || ($event['timestamp'] > $finish && $event['sequence'] < $lap['sequence'])
                || ($event['timestamp'] < $finish && $event['sequence'] > $lap['sequence'])) {
                throw new RepairFailed('Physical timer and Lap order contradict the recorded boundaries.');
            }
        }

        $offset = $lap['start_offset'];
        $body = substr_replace(substr($bytes, 0, -2), pack($lap['word'], $boundary), $offset, 4);
        $output = $body.pack('v', FitCrc16::calculate($body));

        if (strlen($output) !== strlen($bytes) || substr($output, 0, $offset) !== substr($bytes, 0, $offset)
            || substr($output, $offset + 4, -2) !== substr($bytes, $offset + 4, -2)) {
            throw new RepairFailed('Recorded Lap end repair changed bytes outside the start field and file CRC.');
        }

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => [['message' => $lap['message'], 'field' => 2, 'type' => 0x86, 'value' => $boundary]],
            'original_start_time' => $lap['start'],
            'evidence' => [
                'lap_sequence' => $lap['sequence'], 'start_byte_offset' => $offset, 'previous_lap_sequence' => $previous['sequence'],
                'previous_lap_timestamp' => $boundary, 'lap_timestamp' => $lap['timestamp'],
                'finish_basis' => $finalBoundary ? 'final_timer_stop' : 'lap_timestamp', 'finish_timestamp' => $finish,
                'original_session_end_excess_seconds' => $finalBoundary ? 2 : null,
                'repaired_session_end_excess_seconds' => $finalBoundary ? 1 : null,
                'final_lap_paused_ms' => $finalBoundary ? $paused : null,
                'original_boundary_delta_seconds' => $gap, 'repaired_boundary_delta_seconds' => $gap - 1,
                'original_elapsed_residual_ms' => $oldResidual, 'repaired_elapsed_residual_ms' => $newResidual,
                'original_active_ms' => $originalActive, 'repaired_active_ms' => $repairedActive,
                'original_active_residual_ms' => $originalActive - $lap['timer'], 'repaired_active_residual_ms' => $repairedActive - $lap['timer'],
                'session_elapsed_residual_ms' => ($lastRecord - $firstRecord) * 1000 - $session['elapsed'],
                'session_active_ms' => $fullActive, 'session_active_residual_ms' => $fullActive - $session['timer'],
                'session_lap_elapsed_sum_ms' => $elapsed, 'session_lap_timer_sum_ms' => $timer,
                'record_count' => $recordCount, 'last_record_timestamp' => $lastRecord, 'timer_stop' => $stop,
                'policy' => $finalBoundary
                    ? 'copy previous recorded timestamp only when final Record/timer stop and unchanged final Lap elapsed, active and paused durations exclude the original one-second-later start; whole Session elapsed, active timer trace and exact Lap sums independently agree; SAVE timestamps retained'
                    : 'copy previous recorded timestamp only when current recorded end and unchanged elapsed and active durations exclude the original one-second-later start; whole Session elapsed, active timer trace and exact Lap sums independently agree',
            ],
            'preservation' => 'all original bytes retained outside one Lap.start_time and file CRC',
            'activity_validation' => 'not_performed',
        ]);
    }

    /** @param list<array{sequence: int, timestamp: int|null, type: int|null, group: int|null}> $events */
    private function activeSpan(array $events, int $start, int $finish): int
    {
        $active = 0;

        for ($index = 0; $index < count($events); $index += 2) {
            $active += max(0, min($finish, $events[$index + 1]['timestamp']) - max($start, $events[$index]['timestamp'])) * 1000;
        }

        return $active;
    }

    private function scalar(PhysicalMessage $message, int $number, int $type, int $size): ?int
    {
        $found = false;

        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }

            if ($found || $size !== $field->definition->size || $type !== $field->definition->baseType->definitionByte()) {
                throw new RepairFailed('Recorded Lap evidence requires unique fields of the expected physical type.');
            }

            $found = true;
        }

        return $message->integer($number);
    }
}
