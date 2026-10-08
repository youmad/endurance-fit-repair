<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Restores recorded chronology inside an uninterrupted run of independent Records. */
final readonly class RecordedObservationOrderRepairer
{
    public const string RULE = 'recorded_observation_order';
    public const string MULTI_SESSION_RULE = 'recorded_observation_order_inside_native_multisession_partition';

    public function repair(string $bytes, bool $multipleSessions = false): RepairResult
    {
        $file = EditableFitFile::read($bytes);
        $records = $sessions = [];

        foreach ($file->messages as $index => $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);

            if ($message->wasCompressed) {
                throw new RepairFailed('Observation reordering requires explicit timestamps throughout the FIT member.');
            }

            if (20 === $message->number) {
                $records[$index] = $this->recordTimestamp($message);
            } elseif (18 === $message->number) {
                $sessions[$index] = $message;
            }
        }

        if (count($records) < 2 || (!$multipleSessions && 1 !== count($sessions)) || [] === $sessions) {
            throw new RepairFailed('Observation reordering requires multiple Records and one unchanged Session.');
        }

        if ($multipleSessions) {
            if (count($sessions) < 2 || count($sessions) > 128) {
                throw new RepairFailed('Multisession Record ordering requires 2..128 native Sessions.');
            }
            $previousEnd = null;
            foreach (array_values($sessions) as $ordinal => $s) {
                foreach ([254, 25, 26] as $field) {
                    $seen = false;
                    foreach ($s->fields as $value) {
                        if ($value->definition->fieldNumber === $field) {
                            if ($seen || 0x84 !== $value->definition->baseType->definitionByte() || 2 !== $value->definition->size) {
                                throw new RepairFailed('Multisession ordering requires unique scalar uint16 references.');
                            }
                            $seen = true;
                        }
                    }
                }
                $start = $this->scalar($s, 2);
                $elapsed = $this->scalar($s, 7);
                if (null === $start || $start < 0x10000000 || null === $elapsed || $elapsed <= 0
                    || $s->integer(254) !== $ordinal || $s->integer(25) !== $ordinal || 1 !== $s->integer(26)
                    || (null !== $previousEnd && abs($start * 1000 - $previousEnd) > 1999)) {
                    throw new RepairFailed('Multisession Record ordering requires the complete unchanged native Session partition.');
                }
                $previousEnd = $start * 1000 + $elapsed;
            }
            $first = reset($sessions);
            $start = $this->scalar($first, 2);
            $elapsed = $previousEnd - $start * 1000;
        } else {
            $session = reset($sessions);
            $start = $this->scalar($session, 2);
            $elapsed = $this->scalar($session, 7);
        }

        if (null === $start || $start < 0x10000000 || null === $elapsed || $elapsed <= 0
            || array_key_last($records) >= array_key_first($sessions)) {
            throw new RepairFailed('An absolute Session start and positive recorded elapsed time must bound the preceding Records.');
        }

        foreach ($records as $timestamp) {
            if ($timestamp < $start - 1 || ($timestamp - $start) * 1000 > $elapsed + 1000) {
                throw new RepairFailed('Every original Record timestamp must already lie within the unchanged Session interval.');
            }
        }

        $slots = array_keys($records);
        $ordered = $slots;
        usort($ordered, static fn (int $a, int $b): int => ($records[$a] <=> $records[$b]) ?: ($a <=> $b));
        $order = [];

        foreach ($slots as $ordinal => $target) {
            if ($target !== $ordered[$ordinal]) {
                $order[$target] = $ordered[$ordinal];
            }
        }

        if ([] === $order) {
            throw new RepairFailed('Original Record timestamps are already chronological.');
        }

        // Do not move a measurement across an event, Lap, metadata or any other data message.
        for ($index = min(array_keys($order)), $last = max(array_keys($order)); $index <= $last; ++$index) {
            if (!isset($records[$index])) {
                throw new RepairFailed('Reordered Records must remain inside one uninterrupted Record run.');
            }
        }

        foreach ($order as $target => $source) {
            if (!isset($records[$target + 1]) && $records[$target] !== $records[$source]) {
                throw new RepairFailed('Reordering cannot change the implicit timestamp context of following messages.');
            }
        }

        $output = $file->reorderRecords($order);
        $moves = [];

        foreach ($order as $target => $source) {
            $moves[] = ['fromOriginalMessage' => $source, 'toMessage' => $target, 'timestamp' => $records[$source]];
        }

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => $multipleSessions ? self::MULTI_SESSION_RULE : self::RULE, 'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => [], 'moved_messages' => $moves,
            'evidence' => ['session_sequence' => reset($sessions)->sourceSequence, 'session_sequences' => array_map(static fn (PhysicalMessage $s): int => $s->sourceSequence, $sessions), 'record_count' => count($records), 'equal_timestamps' => 'original_order_retained'],
            'preservation' => 'complete original Record packets permuted; all fields, timestamps, durations, non-Record packets and definitions retained',
            'activity_validation' => 'not_performed',
        ]);
    }

    private function recordTimestamp(PhysicalMessage $message): int
    {
        if ([] !== $message->developerFields) {
            throw new RepairFailed('Observation reordering cannot relocate developer metadata dependencies.');
        }

        // Exclude component accumulators (8, 18, 28, ...). Legacy opaque scalar
        // uint32 fields 14/15 have no expansion in the supported Profile.
        $types = [0 => [0x85, 4], 1 => [0x85, 4], 2 => [0x84, 2], 3 => [0x02, 1], 4 => [0x02, 1],
            5 => [0x86, 4], 6 => [0x84, 2], 7 => [0x84, 2], 9 => [0x83, 2], 10 => [0x02, 1], 11 => [0x85, 4],
            12 => [0x02, 1], 13 => [0x01, 1], 14 => [0x86, 4], 15 => [0x86, 4], 30 => [0x02, 1],
            39 => [0x84, 2], 40 => [0x84, 2], 41 => [0x84, 2], 52 => [0x84, 2], 53 => [0x02, 1], 88 => [0x84, 2], 253 => [0x86, 4]];
        $seen = [];

        foreach ($message->fields as $field) {
            $number = $field->definition->fieldNumber;

            if (isset($seen[$number]) || !isset($types[$number])
                || ($types[$number] !== [$field->definition->baseType->definitionByte(), $field->definition->size]
                    && !(5 === $number && 0x0D === $field->definition->baseType->definitionByte() && 4 === $field->definition->size))) {
                throw new RepairFailed('Observation reordering supports only unique independent scalar Record fields.');
            }

            $seen[$number] = true;
        }

        return $message->integer(253) ?? throw new RepairFailed('Every Record requires its original valid scalar timestamp.');
    }

    private function scalar(PhysicalMessage $message, int $number): ?int
    {
        $count = 0;

        foreach ($message->fields as $field) {
            if ($number === $field->definition->fieldNumber
                && (++$count > 1 || 0x86 !== $field->definition->baseType->definitionByte() || 4 !== $field->definition->size)) {
                throw new RepairFailed('Session bounds require unique scalar uint32 fields.');
            }
        }

        return $message->integer($number);
    }
}
