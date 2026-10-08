<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Fills absent paired pool clocks from native Length intervals and distance corroboration. */
final readonly class RecordedPoolTimestampRepairer
{
    public const string RULE = 'missing_pool_timestamps_from_recorded_lengths';

    public function repair(string $bytes): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Paired pool timestamp inference is bounded to 4 MiB.');
        }
        $this->requireUntimedPool($bytes);
        $file = EditableFitFile::read($bytes);
        if (count($file->messages) > 20000) {
            throw new RepairFailed('Paired pool timestamp inference is bounded to 20000 messages.');
        }
        $session = $activity = $pending = null;
        $laps = $pairs = $events = [];
        $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $number = $entry['raw']->globalMessageNumber();
            if (!in_array($number, [0, 18, 19, 20, 21, 34, 101], true)) {
                continue;
            }
            $message = PhysicalMessage::fromRaw($entry['raw'], 0);
            if ($message->wasCompressed) {
                throw new RepairFailed('Paired pool evidence requires uncompressed native clocks.');
            }
            if (0 === $number) {
                if (++$fileIds > 1 || 0 !== $index || 4 !== $this->scalar($message, 0, [0x00, 0x02], 1)) {
                    throw new RepairFailed('Paired pool evidence requires one Activity FileId first.');
                }
            } elseif (18 === $number || 34 === $number) {
                if (null !== $pending || null !== (18 === $number ? $session : $activity)) {
                    throw new RepairFailed('Complete pairs and one original Session/Activity are required.');
                }
                if (18 === $number) {
                    $session = $message;
                } else {
                    $activity = $message;
                }
            } elseif (21 === $number) {
                $kind = $this->scalar($message, 0, [0x00, 0x02], 1);
                if (null === $kind) {
                    throw new RepairFailed('An unknown Event cannot corroborate pool clock order.');
                }
                if (0 === $kind) {
                    $events[] = ['message' => $index, 'sequence' => $message->sourceSequence, 'timestamp' => $this->scalar($message, 253, [0x86], 4),
                        'type' => $this->scalar($message, 1, [0x00, 0x02], 1), 'group' => $this->scalar($message, 4, [0x02], 1)];
                }
            } elseif (19 === $number) {
                if (null !== $pending) {
                    throw new RepairFailed('A Record/Length pair cannot cross a Lap boundary.');
                }
                $laps[] = $message;
            } elseif (20 === $number) {
                if (null !== $pending || null !== $session || null !== $activity || null !== $this->scalar($message, 253, [0x86], 4)) {
                    throw new RepairFailed('Only one-to-one pre-summary pool Records with absent timestamps are supported.');
                }
                $pending = ['message' => $index, 'physical' => $message, 'distance' => $this->scalar($message, 5, [0x86], 4),
                    'speed' => $this->scalar($message, 6, [0x84], 2)];
            } else {
                if (null === $pending || null !== $this->scalar($message, 253, [0x86], 4)) {
                    throw new RepairFailed('Every missing Length clock requires one preceding untimed Record.');
                }
                $start = $this->scalar($message, 2, [0x86], 4);
                $elapsed = $this->scalar($message, 3, [0x86], 4);
                $timer = $this->scalar($message, 4, [0x86], 4);
                $kind = $this->scalar($message, 12, [0x00, 0x02], 1);
                $id = $this->scalar($message, 254, [0x84], 2);
                if (null === $start || $start < 0x10000000 || null === $elapsed || $elapsed !== $timer
                    || !in_array($kind, [0, 1], true) || (1 === $kind && 0 === $elapsed)
                    || null === $id || $id > 4095 || ([] === $pairs ? 0 !== $id : $id <= $pairs[array_key_last($pairs)]['index'])
                    || 28 !== $this->scalar($message, 0, [0x00, 0x02], 1) || 1 !== $this->scalar($message, 1, [0x00, 0x02], 1)
                    || (null !== $pending['speed'] && $pending['speed'] !== $this->scalar($message, 6, [0x84], 2))) {
                    throw new RepairFailed('Native Length identity, interval, type and paired speed must agree.');
                }
                $end = $start + intdiv($elapsed, 1000);
                if ($end > 0xFFFFFFFE || ([] !== $pairs && ($start < $pairs[array_key_last($pairs)]['start'] || $end < $pairs[array_key_last($pairs)]['end']))) {
                    throw new RepairFailed('Native Length starts and inferred endpoints must remain absolute and ordered.');
                }
                $pairs[] = $pending + ['length_message' => $index, 'length' => $message, 'start' => $start,
                    'elapsed_ms' => $elapsed, 'kind' => $kind, 'end' => $end, 'index' => $id];
                $pending = null;
            }
        }
        if (1 !== $fileIds || null === $session || null === $activity || null !== $pending || count($pairs) < 2 || [] === $laps || count($events) < 2) {
            throw new RepairFailed('Complete pool summaries, Record/Length pairs and a recorded timer trace are required.');
        }
        $start = $this->scalar($session, 2, [0x86], 4);
        $pool = $this->scalar($session, 44, [0x84], 2);
        if (null === $start || $start < 0x10000000 || null === $pool || 0 === $pool
            || 5 !== $this->scalar($session, 5, [0x00, 0x02], 1) || 17 !== $this->scalar($session, 6, [0x00, 0x02], 1)
            || 0 !== $this->scalar($session, 254, [0x84], 2) || 0 !== $this->scalar($session, 25, [0x84], 2)
            || count($laps) !== $this->scalar($session, 26, [0x84], 2) || $pairs[array_key_last($pairs)]['index'] + 1 !== $this->scalar($session, 33, [0x84], 2)
            || 1 !== $this->scalar($activity, 1, [0x84], 2) || $session->sourceSequence >= $activity->sourceSequence || $pairs[0]['start'] !== $start) {
            throw new RepairFailed('Original pool length, sport and complete Session references must corroborate all pairs.');
        }
        $cursor = 0;
        foreach ($laps as $index => $lap) {
            $count = $this->scalar($lap, 32, [0x84], 2);
            $first = $this->scalar($lap, 35, [0x84], 2);
            $until = isset($laps[$index + 1]) ? $this->scalar($laps[$index + 1], 35, [0x84], 2) : $pairs[array_key_last($pairs)]['index'] + 1;
            if ($index !== $this->scalar($lap, 254, [0x84], 2) || ($pairs[$cursor]['index'] ?? null) !== $first
                || null === $first || null === $count || $count <= 0 || null === $until || $first + $count !== $until) {
                throw new RepairFailed('Existing Lap references must partition all original Length identities in order.');
            }
            while (isset($pairs[$cursor]) && $pairs[$cursor]['index'] < $until) {
                if ($pairs[$cursor]['length']->sourceSequence >= $lap->sourceSequence || $lap->sourceSequence >= $session->sourceSequence) {
                    throw new RepairFailed('Available native Lengths must precede their owning Lap and Session.');
                }
                ++$cursor;
            }
        }
        if ($cursor !== count($pairs)) {
            throw new RepairFailed('Original Lap references must retain every Length.');
        }
        $running = null;
        foreach ($events as $index => $event) {
            if (null === $event['timestamp'] || $event['timestamp'] < $start || !in_array($event['group'], [null, 0], true)
                || !in_array($event['type'], [0, 1, 4], true) || ($index > 0 && $event['timestamp'] < $events[$index - 1]['timestamp'])) {
                throw new RepairFailed('Original timer clocks must form an ordered same-group trace.');
            }
            if (0 === $event['type']) {
                if (null !== $running) {
                    throw new RepairFailed('A repeated START cannot corroborate inferred pool timestamps.');
                }
                $running = $event['timestamp'];
            } else {
                if (null === $running && 4 !== $event['type']) {
                    throw new RepairFailed('Only STOP_ALL can corroborate an already stopped timer.');
                }
                $running = null;
            }
        }
        if (0 !== $events[0]['type'] || $events[0]['timestamp'] > $start + 1 || null !== $running
            || $events[0]['message'] >= $pairs[0]['message'] || $events[array_key_last($events)]['sequence'] >= $session->sourceSequence) {
            throw new RepairFailed('Recorded START and final STOP must corroborate the pool interval before Session SAVE.');
        }
        $stop = $events[array_key_last($events)]['timestamp'];
        $distance = $active = 0;
        $changes = $evidence = [];
        foreach ($pairs as $index => $pair) {
            $distance += 1 === $pair['kind'] ? $pool : 0;
            $active += 1 === $pair['kind'] ? 1 : 0;
            if ($distance !== $pair['distance']) {
                throw new RepairFailed('Each paired Record distance must equal the complete native active-Length prefix.');
            }
            if ($pair['end'] > $stop + 1) {
                throw new RepairFailed('Inferred pool clocks must remain within the recorded timer interval.');
            }
            foreach ([$pair['message'], $pair['length_message']] as $messageIndex) {
                $entry = $file->messages[$messageIndex];
                $position = $entry['offset'] + 1;
                $original = $offset = null;
                foreach ($entry['raw']->standardFields() as $field) {
                    if (253 === $field->definition->fieldNumber) {
                        $original = bin2hex($field->value->bytes());
                        $offset = $position;
                    }
                    $position += $field->definition->size;
                }
                $changes[] = ['message' => $messageIndex, 'sequence' => $entry['raw']->sequence(), 'global_message_number' => $entry['raw']->globalMessageNumber(),
                    'field' => 253, 'type' => 0x86, 'byte_offset' => $offset, 'original_value' => null, 'original_bytes' => $original, 'value' => $pair['end']];
            }
            $evidence[] = ['length_index' => $pair['index'], 'record_sequence' => $pair['physical']->sourceSequence,
                'length_sequence' => $pair['length']->sourceSequence, 'start_time' => $pair['start'], 'elapsed_ms' => $pair['elapsed_ms'],
                'length_type' => $pair['kind'], 'distance' => $distance, 'record_speed' => $pair['speed'],
                'timestamp' => $pair['end'], 'admissible_timestamp' => [$pair['end'], $pair['end'] + (0 === $pair['elapsed_ms'] % 1000 ? 0 : 1)]];
        }
        if ($distance !== $this->scalar($session, 9, [0x86], 4)) {
            throw new RepairFailed('Native Session distance must corroborate every available pair.');
        }
        $output = $file->apply($changes);

        return new RepairResult($output, ['mode' => 'infer_pool_timestamps', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inference' => ['historical_accuracy' => 'not_proven', 'resolution_seconds' => 1,
                'authority' => 'native Length intervals and indexes, one-to-one preceding Record pairs, matching speed and cumulative pool distance, original Lap index ranges and complete timer interval; select the lower whole-second endpoint; buffered pairs retain their original packet order; no missing Length is manufactured'],
            'evidence' => ['pool_length' => $pool, 'retained_length_count' => count($pairs), 'retained_active_length_count' => $active,
                'original_length_count' => $session->integer(33), 'original_active_length_count' => $session->integer(47),
                'absent_length_indexes' => array_values(array_diff(range(0, $pairs[array_key_last($pairs)]['index']), array_column($pairs, 'index'))), 'pairs' => $evidence],
            'preservation' => 'all original messages, metrics, durations, clocks and developer fields retained; only absent or invalid Record/Length timestamp fields filled, with definition/header sizes and CRC updated',
            'activity_validation' => 'not_performed']);
    }

    /** Reject unrelated or unbounded streams before materializing Record payloads. */
    private function requireUntimedPool(string $bytes): void
    {
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new RepairFailed('Cannot allocate pool clock input.');
        }
        $sessions = $count = $fields = 0;
        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill pool clock input.');
            }
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }
                if (++$count > 20000) {
                    throw new RepairFailed('Pool timestamp inference is bounded to 20000 messages.');
                }
                $fields += count($raw->standardFields()) + count($raw->developerFields());
                if ($fields > 100000) {
                    throw new RepairFailed('Pool timestamp inference is bounded to 100000 physical fields.');
                }
                if (20 === $raw->globalMessageNumber() && null !== $this->scalar(PhysicalMessage::fromRaw($raw, 0), 253, [0x86], 4)) {
                    throw new RepairFailed('Existing Record clocks are retained; only wholly untimed pool pairs are supported.');
                }
                if (18 === $raw->globalMessageNumber()) {
                    $message = PhysicalMessage::fromRaw($raw, 0);
                    if (++$sessions > 1 || 5 !== $this->scalar($message, 5, [0x00, 0x02], 1) || 17 !== $this->scalar($message, 6, [0x00, 0x02], 1)) {
                        throw new RepairFailed('Pool timestamp inference requires one original pool swimming Session.');
                    }
                }
            }
            $file->trailer();
        } finally {
            fclose($stream);
        }
        if (1 !== $sessions) {
            throw new RepairFailed('One original pool swimming Session is required.');
        }
    }

    /** @param non-empty-list<int> $types */
    private function scalar(PhysicalMessage $message, int $number, array $types, int $size): ?int
    {
        $found = false;
        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }
            if ($found || !in_array($field->definition->baseType->definitionByte(), $types, true) || $size !== $field->definition->size) {
                throw new RepairFailed('Pool clock evidence requires unique native scalar fields of the expected type.');
            }
            $found = true;
        }

        return $message->integer($number);
    }
}
