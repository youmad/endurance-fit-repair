<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** @internal Restores missing Lap summaries from a complete recorded Set partition. */
final readonly class RecordedSetLapRepairer
{
    public function repair(EditableFitFile $file): RepairResult
    {
        $sessions = $sets = $events = $activities = [];
        $fileIds = 0;
        $records = ['count' => 0, 'first' => null, 'last' => null, 'first_sequence' => null, 'last_sequence' => null];

        foreach ($file->messages as $index => $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);

            switch ($message->number) {
                case 0:
                    ++$fileIds;

                    break;

                case 18:
                    $sessions[$index] = $message;

                    break;

                case 19:
                    throw new RepairFailed('Recorded Set recovery requires no existing Laps.');
                case 20:
                    $time = $this->integer($message, 253, 0x86, 4);

                    if (null === $time || (null !== $records['last'] && $time < $records['last'])) {
                        throw new RepairFailed('Recorded Set recovery requires usable ordered Record timestamps.');
                    }

                    ++$records['count'];
                    $records['first'] ??= $time;
                    $records['first_sequence'] ??= $message->sourceSequence;
                    $records['last'] = $time;
                    $records['last_sequence'] = $message->sourceSequence;

                    break;

                case 21:
                    $kind = $this->eventByte($message, 0);

                    if (null === $kind) {
                        throw new RepairFailed('A missing Event kind cannot exclude a timer contradiction.');
                    }

                    if (0 === $kind) {
                        $events[] = $message;
                    }

                    break;

                case 34:
                    $activities[] = $message;

                    break;

                case 225:
                    $sets[] = $message;

                    break;
            }

            if (4096 < count($sets) || 4 < count($events) || 1 < count($sessions) || 1 < count($activities)) {
                throw new RepairFailed('Recorded Set recovery requires one Session, one Activity and at most one pause.');
            }
        }

        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($activities) || !in_array(count($events), [2, 4], true) || count($sets) < 2) {
            throw new RepairFailed('Recorded Set recovery requires complete Session, Activity, Set and timer evidence.');
        }

        $fileId = PhysicalMessage::fromRaw($file->messages[0]['raw'], $file->header->headerSize);
        $index = array_key_first($sessions);
        $session = $sessions[$index];
        $start = $this->integer($session, 2, 0x86, 4);
        $timestamp = $this->integer($session, 253, 0x86, 4);
        $elapsed = $this->integer($session, 7, 0x86, 4);
        $timer = $this->integer($session, 8, 0x86, 4);
        $subSport = $this->integer($session, 6, 0x00, 1);

        if (0 !== $fileId->number || 4 !== $this->integer($fileId, 0, 0x00, 1) || $session->wasCompressed
            || 0 !== $this->integer($session, 254, 0x84, 2) || 0 !== $this->integer($session, 25, 0x84, 2)
            || count($sets) !== $this->integer($session, 26, 0x84, 2) || 10 !== $this->integer($session, 5, 0x00, 1)
            || !in_array($subSport, [20, 43], true) || !in_array($this->integer($session, 9, 0x86, 4), [null, 0], true)
            || null === $start || 0x10000000 > $start || null === $timestamp || $timestamp < $start
            || null === $elapsed || 0 >= $elapsed || null === $timer || 0 >= $timer || $timer > $elapsed) {
            throw new RepairFailed('Session references and unchanged durations do not establish a strength or yoga Set partition.');
        }

        $begin = $events[0];
        $stop = $events[array_key_last($events)];
        $stopTime = $this->integer($stop, 253, 0x86, 4);
        $end = $start + intdiv($elapsed, 1000);
        $activity = $activities[0];
        $pause = $elapsed - $timer;
        $pausedSet = null;
        $recordedPause = 0;

        if (4 === count($events)) {
            $pauseStart = $this->integer($events[1], 253, 0x86, 4);
            $pauseEnd = $this->integer($events[2], 253, 0x86, 4);

            if (null === $pauseStart || null === $pauseEnd || $pauseStart <= $start || $pauseEnd <= $pauseStart
                || null === $stopTime || $pauseEnd >= $stopTime || $pause <= 0
                || abs(($pauseEnd - $pauseStart) * 1000 - $pause) > 1000
                || !in_array($this->eventByte($events[1], 1), [1, 4], true) || 0 !== $this->eventByte($events[2], 1)
                || !in_array($this->integer($events[1], 4, 0x02, 1), [null, 0], true)
                || !in_array($this->integer($events[2], 4, 0x02, 1), [null, 0], true)) {
                throw new RepairFailed('One complete pause must corroborate the exact unchanged Session elapsed-minus-timer duration.');
            }

            foreach ($sets as $ordinal => $set) {
                $setStart = $this->integer($set, 6, 0x86, 4);
                $nextStart = isset($sets[$ordinal + 1]) ? $this->integer($sets[$ordinal + 1], 6, 0x86, 4) : $end;

                if (null !== $setStart && null !== $nextStart && $pauseStart >= $setStart + 2 && $pauseEnd <= $nextStart - 2) {
                    if (null !== $pausedSet) {
                        throw new RepairFailed('Pause ownership is ambiguous.');
                    }

                    $pausedSet = $ordinal;
                }
            }

            if (null === $pausedSet) {
                throw new RepairFailed('The recorded pause must lie strictly inside one Set boundary pair.');
            }

            $recordedPause = ($pauseEnd - $pauseStart) * 1000;
        } elseif (0 !== $pause) {
            throw new RepairFailed('A Session pause without a recorded STOP/START pair cannot be allocated to Sets.');
        }

        if ($start !== $this->integer($begin, 253, 0x86, 4) || 0 !== $this->eventByte($begin, 1)
            || !in_array($this->eventByte($stop, 1), [1, 4], true) || null === $stopTime || $stopTime < $start
            || !in_array($this->integer($begin, 4, 0x02, 1), [null, 0], true)
            || !in_array($this->integer($stop, 4, 0x02, 1), [null, 0], true)
            || abs(($stopTime - $start) * 1000 - $elapsed) > 1000
            || abs(($stopTime - $start) * 1000 - $recordedPause - $timer) > 1000
            || $records['count'] < 2 || $records['first'] !== $start || $records['last'] !== $stopTime
            || $begin->sourceSequence >= $records['first_sequence'] || $stop->sourceSequence <= $records['last_sequence']
            || 1 !== $this->integer($activity, 1, 0x84, 2) || $timer !== $this->integer($activity, 0, 0x86, 4)
            || $timestamp !== $this->integer($activity, 253, 0x86, 4)) {
            throw new RepairFailed('Records, timer events and Activity do not corroborate the unchanged Session.');
        }

        $laps = $insertions = [];
        $sum = 0;
        $previousStart = null;
        $previousEnd = null;

        foreach ($sets as $ordinal => $set) {
            $setStart = $this->integer($set, 6, 0x86, 4);
            // Set uses field 254 for its write timestamp and field 10 for its index.
            $setTimestamp = $this->integer($set, 254, 0x86, 4);
            $duration = $this->integer($set, 0, 0x86, 4);
            $type = $this->eventByte($set, 5);
            $lapElapsed = null === $duration ? null : $duration + ($ordinal === $pausedSet ? $pause : 0);

            if ($set->wasCompressed || $ordinal !== $this->integer($set, 10, 0x84, 2)
                || null === $setStart || $setStart < $start || $setStart > $end + 1
                || null === $setTimestamp || 0x10000000 > $setTimestamp
                || null === $duration || 0 >= $duration || !in_array($type, [0, 1], true)
                || (0 === $ordinal && $setStart !== $start)
                || (null !== $previousStart && $setStart <= $previousStart)
                || (null !== $previousEnd && abs($setStart - $previousEnd) > 2)
                || null === $lapElapsed || $lapElapsed >= 0xFFFFFFFF || $setStart + intdiv($lapElapsed, 1000) > $end + 1) {
                throw new RepairFailed('Recorded Set indexes, boundaries or durations contradict the Session partition.');
            }

            $lap = (new PhysicalMessage(19, $set->architecture))
                ->withInteger(254, 0x84, $ordinal)
                ->withInteger(253, 0x86, $setTimestamp)
                ->withInteger(2, 0x86, $setStart)
                ->withInteger(7, 0x86, $lapElapsed)
                ->withInteger(8, 0x86, $duration)
                ->withInteger(0, 0x00, 9)
                ->withInteger(1, 0x00, 1)
                ->withInteger(25, 0x00, 10)
                ->withInteger(39, 0x00, $subSport);
            $laps[] = $lap;
            $sum += $duration;
            $previousStart = $setStart;
            $previousEnd = $setStart + intdiv($lapElapsed, 1000);
            $insertions[] = [
                'globalMessageNumber' => 19, 'beforeOriginalMessage' => $index, 'synthetic' => true,
                'basis' => null === $pausedSet ? 'recorded_set_partition_of_unpaused_session' : 'recorded_set_partition_with_one_contained_pause',
                'sourceSequence' => $set->sourceSequence, 'sourceByteOffset' => $set->sourceOffset,
                'set_type' => $type,
                'fields' => array_map(static fn ($field): array => ['number' => $field->definition->fieldNumber, 'value' => $lap->integer($field->definition->fieldNumber)], $lap->fields),
            ];
        }

        if ($sum !== $timer || abs($previousEnd - $end) > 2) {
            throw new RepairFailed('Exact recorded Set duration sum and final boundary do not cover the Session.');
        }

        $bytes = $file->insertManyBefore($index, $laps);

        return new RepairResult($bytes, [
            'mode' => 'preserve', 'rule' => PreservingActivityRepairer::MISSING_LAP,
            'input_sha256' => hash('sha256', $file->bytes), 'output_sha256' => hash('sha256', $bytes),
            'changes' => [], 'inserted_messages' => $insertions,
            'evidence' => [
                'basis' => null === $pausedSet ? 'recorded_set_partition_of_unpaused_session' : 'recorded_set_partition_with_one_contained_pause',
                'set_count' => count($sets), 'set_duration_sum_ms' => $sum,
                'session_sequence' => $session->sourceSequence, 'session_elapsed_ms' => $elapsed, 'session_timer_ms' => $timer,
                'paused_set_index' => $pausedSet, 'recorded_session_pause_ms' => $pause,
                'timer_start_sequence' => $begin->sourceSequence, 'timer_stop_sequence' => $stop->sourceSequence,
                'first_record_sequence' => $records['first_sequence'], 'last_record_sequence' => $records['last_sequence'],
                'policy' => 'copy each recorded Set start, write timestamp and exact timer duration; allocate the exact Session elapsed-minus-timer duration only when the single recorded pause lies strictly inside one Set; retain active and rest Sets separately without rounding or original field edits',
            ],
            'preservation' => 'verified', 'activity_validation' => 'not_performed',
        ]);
    }

    private function eventByte(PhysicalMessage $message, int $number): ?int
    {
        $type = 0x00;

        foreach ($message->fields as $field) {
            if ($number === $field->definition->fieldNumber && 0x02 === $field->definition->baseType->definitionByte()) {
                $type = 0x02;
            }
        }

        return $this->integer($message, $number, $type, 1);
    }

    private function integer(PhysicalMessage $message, int $number, int $type, int $size): ?int
    {
        $found = false;

        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }

            if ($found || $type !== $field->definition->baseType->definitionByte() || $size !== $field->definition->size) {
                throw new RepairFailed('Recorded Set evidence contains an ambiguous or incompatible physical field.');
            }

            $found = true;
        }

        return $message->integer($number);
    }
}
