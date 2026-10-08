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

/** Recovers a shared, deferred summary/timer start from a corroborated Record span. */
final readonly class RecordedSpanStartRepairer
{
    public const string RULE = 'single_lap_start_from_recorded_span';

    public function repair(string $bytes): RepairResult
    {
        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate recorded span repair input.');
        }

        $laps = $sessions = $activities = $events = [];
        $first = $last = null;
        $lastCompressedSequence = null;
        $ordinal = $recordCount = 0;

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill recorded span repair input.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;

            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Recorded span repair requires one complete supported FIT member at byte zero.');
            }

            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }

                $number = $raw->globalMessageNumber();

                if ($raw instanceof RawCompressedTimestampDataMessage) {
                    $lastCompressedSequence = $raw->sequence();
                }

                if (in_array($number, [18, 19, 20, 21, 34], true)) {
                    $message = PhysicalMessage::fromRaw($raw, 0);
                    $entry = [
                        'message' => $ordinal, 'sequence' => $raw->sequence(), 'offset' => $raw->byteOffset(),
                        'timestamp' => $this->scalar($message, 253, 0x86, 4),
                    ];

                    if (20 === $number) {
                        if (null === $entry['timestamp'] || $entry['timestamp'] < 0x10000000
                            || (null !== $last && $entry['timestamp'] < $last['timestamp'])) {
                            throw new RepairFailed('Record timestamps must form one complete, absolute, ordered span.');
                        }

                        $first ??= $entry;
                        $last = $entry;
                        ++$recordCount;
                    } else {
                        if ($raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Recorded span repair requires uncompressed summary and Event evidence.');
                        }

                        $entry['word'] = FitArchitecture::LittleEndian === $raw->definition()->architecture ? 'V' : 'N';

                        if (18 === $number || 19 === $number) {
                            $entry['start'] = $this->scalar($message, 2, 0x86, 4);
                            $entry['start_offset'] = $this->fieldOffset($raw, 2);
                            $entry['elapsed'] = $this->scalar($message, 7, 0x86, 4);
                            $entry['timer'] = $this->scalar($message, 8, 0x86, 4);
                            $entry['index'] = $this->scalar($message, 254, 0x84, 2);

                            if (18 === $number) {
                                $entry['first_lap'] = $this->scalar($message, 25, 0x84, 2);
                                $entry['num_laps'] = $this->scalar($message, 26, 0x84, 2);
                                $sessions[] = $entry;
                            } else {
                                $laps[] = $entry;
                            }
                        } elseif (34 === $number) {
                            $entry['sessions'] = $this->scalar($message, 1, 0x84, 2);
                            $entry['timer'] = $this->scalar($message, 0, 0x86, 4);
                            $activities[] = $entry;
                        } else {
                            $event = $this->scalar($message, 0, 0x00, 1);

                            if (null === $event) {
                                throw new RepairFailed('An Event with an unusable event kind makes the timer trace ambiguous.');
                            }

                            if (0 === $event) {
                                $entry['type'] = $this->scalar($message, 1, 0x00, 1);
                                $entry['group'] = $this->scalar($message, 4, 0x02, 1);
                                $entry['timestamp_offset'] = $this->fieldOffset($raw, 253);
                                $events[] = $entry;
                            }
                        }
                    }
                }

                if (count($laps) > 1 || count($sessions) > 1 || count($activities) > 1 || count($events) > 2) {
                    throw new RepairFailed('Recorded span repair requires one Lap, Session, Activity and a single timer start/stop pair.');
                }

                ++$ordinal;
            }

            $file->trailer();
        } finally {
            fclose($stream);
        }

        if (1 !== count($laps) || 1 !== count($sessions) || 1 !== count($activities) || 2 !== count($events)
            || $recordCount < 2 || null === $first || null === $last || $last['timestamp'] <= $first['timestamp']) {
            throw new RepairFailed('A complete single-Lap partition, recorded endpoints and one timer pair are required.');
        }

        $lap = $laps[0];
        $session = $sessions[0];
        $activity = $activities[0];
        [$start, $stop] = $events;

        // Replacing an explicit timestamp must never change the implicit
        // timestamp of a later compressed packet, including unknown messages.
        if (null !== $lastCompressedSequence && $lastCompressedSequence > $start['sequence']) {
            throw new RepairFailed('A compressed timestamp after the timer start could depend on its original clock context.');
        }

        if (0 !== $lap['index'] || 0 !== $session['index'] || 0 !== $session['first_lap'] || 1 !== $session['num_laps'] || 1 !== $activity['sessions']
            || null === $lap['start'] || $lap['start'] !== $session['start'] || $lap['start'] !== $start['timestamp']
            || null === $lap['elapsed'] || 0 === $lap['elapsed'] || $lap['elapsed'] !== $session['elapsed']
            || null === $lap['timer'] || 0 === $lap['timer'] || $lap['timer'] > $lap['elapsed']
            || $lap['timer'] !== $session['timer'] || $lap['timer'] !== $activity['timer']) {
            throw new RepairFailed('Shared original starts, complete references and all existing durations must agree.');
        }

        if (0 !== $start['type'] || !in_array($stop['type'], [1, 4], true) || 0 !== $start['group'] || 0 !== $stop['group']
            || $start['sequence'] <= $last['sequence'] || $stop['sequence'] <= $start['sequence']
            || $lap['sequence'] <= $stop['sequence'] || $session['sequence'] <= $lap['sequence'] || $activity['sequence'] <= $session['sequence']
            || $stop['timestamp'] !== $last['timestamp'] || $lap['timestamp'] !== $last['timestamp']
            || null === $session['timestamp'] || $session['timestamp'] < $last['timestamp']
            || null === $activity['timestamp'] || $activity['timestamp'] < $session['timestamp']
            || $start['timestamp'] >= $stop['timestamp']) {
            throw new RepairFailed('Deferred timer and summary evidence must independently confirm the recorded end.');
        }

        $boundary = $first['timestamp'];
        $recordedSpan = ($last['timestamp'] - $boundary) * 1000;
        $residual = $recordedSpan - $lap['elapsed'];
        $timerResidual = $recordedSpan - $lap['timer'];
        $originalResidual = ($last['timestamp'] - $lap['start']) * 1000 - $lap['elapsed'];

        if ($lap['start'] <= $boundary + 1 || abs($residual) > 1000 || abs($timerResidual) > 1000 || $originalResidual >= -1000) {
            throw new RepairFailed('Unchanged elapsed does not independently establish the earlier recorded start.');
        }

        $targets = [
            ['entry' => $start, 'number' => 21, 'field' => 253, 'offset' => $start['timestamp_offset']],
            ['entry' => $lap, 'number' => 19, 'field' => 2, 'offset' => $lap['start_offset']],
            ['entry' => $session, 'number' => 18, 'field' => 2, 'offset' => $session['start_offset']],
        ];
        $body = substr($bytes, 0, -2);
        $changes = [];
        $position = 0;

        foreach ($targets as $target) {
            $offset = $target['offset'];

            if (null === $offset || $offset < $position || substr($bytes, $offset, 4) !== pack($target['entry']['word'], $lap['start'])) {
                throw new RepairFailed('Recorded start fields are not uniquely located at the expected physical offsets.');
            }

            $body = substr_replace($body, pack($target['entry']['word'], $boundary), $offset, 4);
            $changes[] = [
                'message' => $target['entry']['message'], 'sequence' => $target['entry']['sequence'],
                'global_message_number' => $target['number'], 'byte_offset' => $offset,
                'field' => $target['field'], 'type' => 0x86, 'original_value' => $lap['start'], 'value' => $boundary,
            ];

            if (substr($body, $position, $offset - $position) !== substr($bytes, $position, $offset - $position)) {
                throw new RepairFailed('Recorded span repair changed bytes outside the three start fields.');
            }

            $position = $offset + 4;
        }

        if (strlen($body) !== strlen($bytes) - 2 || substr($body, $position) !== substr($bytes, $position, -2)) {
            throw new RepairFailed('Recorded span repair changed bytes outside the three start fields.');
        }

        $output = $body.pack('v', FitCrc16::calculate($body));

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes,
            'evidence' => [
                'record_count' => $recordCount, 'first_record' => $first, 'last_record' => $last,
                'lap_timestamp' => $lap['timestamp'], 'timer_start_sequence' => $start['sequence'],
                'timer_stop_sequence' => $stop['sequence'], 'timer_stop_timestamp' => $stop['timestamp'],
                'elapsed_ms' => $lap['elapsed'], 'timer_ms' => $lap['timer'],
                'original_boundary_residual_ms' => $originalResidual, 'recovered_boundary_residual_ms' => $residual,
                'recovered_timer_residual_ms' => $timerResidual,
                'policy' => 'single complete Lap; ordered Records, recorded Lap end and deferred timer stop agree; unchanged elapsed spans the Records within one-second FIT timestamp resolution; all three erroneous shared starts replaced with the first recorded timestamp',
            ],
            'preservation' => 'all original bytes retained outside Session.start_time, Lap.start_time, one timer start timestamp and file CRC; durations, measurements, developer fields, event kinds/order and segmentation retained',
            'activity_validation' => 'not_performed',
        ]);
    }

    private function scalar(PhysicalMessage $message, int $number, int $type, int $size): ?int
    {
        $found = false;

        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }

            if ($found || $size !== $field->definition->size || $type !== $field->definition->baseType->definitionByte()) {
                throw new RepairFailed('Recorded span evidence requires unique fields of the expected physical type.');
            }

            $found = true;
        }

        return $message->integer($number);
    }

    private function fieldOffset(RawDataRecord $raw, int $number): ?int
    {
        $position = $raw->byteOffset() + 1;

        foreach ($raw->standardFields() as $field) {
            if ($number === $field->definition->fieldNumber) {
                return $position;
            }

            $position += $field->definition->size;
        }

        return null;
    }
}
