<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Fills missing SegmentLap elapsed from an independently corroborated recorded timer. */
final readonly class SegmentLapElapsedRepairer
{
    public const string RULE = 'segment_lap_missing_recorded_elapsed';
    public const string CLOCK_RULE = 'segment_elapsed_from_native_counter_and_endpoints';

    public function repair(string $bytes, bool $nativeBounds = false): RepairResult
    {
        $file = EditableFitFile::read($bytes);
        $segments = $records = $events = $sessions = [];
        $lastRecord = null;

        foreach ($file->messages as $index => $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);

            if (142 === $message->number) {
                if (null === $this->scalar($message, 7, 0x86, 4)) {
                    $segments[$index] = $message;
                }
            } elseif (18 === $message->number) {
                $sessions[] = $message;
            } elseif (20 === $message->number) {
                $timestamp = $this->scalar($message, 253, 0x86, 4);

                if (null === $timestamp || $timestamp < 0x10000000 || (null !== $lastRecord && $timestamp < $lastRecord)) {
                    throw new RepairFailed('Segment elapsed recovery requires ordered absolute Record timestamps.');
                }

                $records[$timestamp] = true;
                $lastRecord = $timestamp;
            } elseif (21 === $message->number) {
                $kind = $this->eventEnum($message, 0);

                if (null === $kind) {
                    throw new RepairFailed('Missing Event kind cannot exclude a timer contradiction.');
                }

                if (0 === $kind) {
                    $events[] = ['timestamp' => $this->scalar($message, 253, 0x86, 4),
                        'type' => $this->eventEnum($message, 1), 'group' => $this->scalar($message, 4, 0x02, 1)];
                }
            }
        }

        if ([] === $segments || count($segments) > 128 || 1 !== count($sessions) || count($events) > 4096 || [] === $events) {
            throw new RepairFailed('Segment elapsed recovery requires missing SegmentLap elapsed, one Session and a bounded timer timeline.');
        }

        $active = null;
        $previous = null;
        $intervals = [];

        foreach ($events as $event) {
            $timestamp = $event['timestamp'];

            if (null === $timestamp || $timestamp < 0x10000000 || !in_array($event['group'], [null, 0], true)
                || !in_array($event['type'], [0, 1, 4], true) || (null !== $previous && $timestamp < $previous['timestamp'])) {
                throw new RepairFailed('Timer evidence must be one ordered, supported timeline.');
            }

            if (0 === $event['type']) {
                if (null !== $active) {
                    throw new RepairFailed('Repeated START makes segment timer evidence ambiguous.');
                }

                $active = $timestamp;
            } elseif (null !== $active) {
                $intervals[] = [$active, $timestamp];
                $active = null;
            } elseif (null === $previous || 0 === $previous['type'] || $timestamp !== $previous['timestamp']) {
                throw new RepairFailed('A STOP without START must repeat the preceding STOP timestamp.');
            }

            $previous = $event;
        }

        if (null !== $active) {
            throw new RepairFailed('Segment elapsed recovery requires a completed timer timeline.');
        }

        $session = $sessions[0];
        $sessionStart = $this->scalar($session, 2, 0x86, 4);
        $sessionElapsed = $this->scalar($session, 7, 0x86, 4);
        $changes = $evidence = [];

        foreach ($segments as $index => $segment) {
            $start = $this->scalar($segment, 2, 0x86, 4);
            $finish = $this->scalar($segment, 253, 0x86, 4);
            $timer = $this->scalar($segment, 8, 0x86, 4);
            $unpaused = null;

            foreach ($intervals as $interval) {
                if (null !== $start && null !== $finish && $interval[0] <= $start && $finish <= $interval[1]) {
                    $unpaused = $interval;
                    break;
                }
            }

            if ($segment->wasCompressed || null === $start || null === $finish || $start >= $finish || null === $timer || $timer <= 0
                || ($finish - $start) * 1000 !== $timer
                || (!$nativeBounds && (!isset($records[$start], $records[$finish]) || null === $unpaused))
                || ($nativeBounds && ([] === $records || $start < min(array_keys($records)) || $finish > max(array_keys($records)) + 1))
                || null === $sessionStart || null === $sessionElapsed || $start < $sessionStart
                || $finish * 1000 > $sessionStart * 1000 + $sessionElapsed + 1000) {
                throw new RepairFailed('Missing segment elapsed requires an exact recorded timer, matching Record endpoints and an unpaused interval inside the Session.');
            }

            $changes[] = ['message' => $index, 'field' => 7, 'type' => 0x86, 'value' => $timer];
            $evidence[] = ['segment_sequence' => $segment->sourceSequence, 'start_time' => $start, 'timestamp' => $finish,
                'recorded_timer_ms' => $timer, 'unpaused_timer_interval' => $unpaused];
        }

        $output = $file->apply($changes);

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => $nativeBounds ? self::CLOCK_RULE : self::RULE, 'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes, 'evidence' => $evidence,
            'inference' => ['historical_accuracy' => $nativeBounds ? 'not_proven' : 'native_unpaused_interval',
                'policy' => 'copy the original SegmentLap timer only when its native start and SAVE independently define that exact duration inside the Session and Record envelope; preserve the original segment active counter and global pauses, whose segment ownership is unknown'],
            'preservation' => 'all physical messages retained outside missing SegmentLap.total_elapsed_time fields, their definitions and FIT framing',
            'activity_validation' => 'not_performed',
        ]);
    }

    private function eventEnum(PhysicalMessage $message, int $number): ?int
    {
        foreach ($message->fields as $field) {
            if ($number === $field->definition->fieldNumber && in_array($field->definition->baseType->definitionByte(), [0x00, 0x02], true)) {
                return $this->scalar($message, $number, $field->definition->baseType->definitionByte(), 1);
            }
        }

        return $this->scalar($message, $number, 0x00, 1);
    }

    private function scalar(PhysicalMessage $message, int $number, int $type, int $size): ?int
    {
        $count = 0;

        foreach ($message->fields as $field) {
            if ($number === $field->definition->fieldNumber
                && (++$count > 1 || $field->definition->baseType->definitionByte() !== $type || $field->definition->size !== $size)) {
                throw new RepairFailed('Segment elapsed evidence requires unique scalar fields of the expected type and size.');
            }
        }

        return $message->integer($number);
    }
}
