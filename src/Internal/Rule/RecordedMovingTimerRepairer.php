<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Fills missing active counters when native moving and elapsed totals leave no paused time. */
final readonly class RecordedMovingTimerRepairer
{
    public const string RULE = 'missing_timers_from_equal_native_moving_and_elapsed';

    public function repair(string $bytes): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Moving-time recovery requires a bounded FIT member.');
        }
        $file = EditableFitFile::read($bytes);
        $summaries = [];
        $first = $last = null;
        $records = $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (in_array($message->number, [21, 101, 142, 225], true)) {
                throw new RepairFailed('Moving-time recovery requires one whole-session Lap without Events or other interval scopes.');
            }
            if (0 === $message->number) {
                if (4 !== $message->integer(0) || 1 !== ++$fileIds) {
                    throw new RepairFailed('Moving-time recovery requires one Activity FileId.');
                }
            } elseif (20 === $message->number) {
                $this->assertScalars($message, [253 => 0x86]);
                $timestamp = $message->integer(253);
                if (null === $timestamp || $timestamp < 0x10000000 || (null !== $last && $timestamp < $last)) {
                    throw new RepairFailed('Moving-time recovery requires ordered absolute Record timestamps.');
                }
                $first ??= $timestamp;
                $last = $timestamp;
                ++$records;
            } elseif (in_array($message->number, [18, 19, 34], true)) {
                if ($message->wasCompressed || isset($summaries[$message->number])) {
                    throw new RepairFailed('Moving-time recovery requires sole explicit Lap, Session and Activity summaries.');
                }
                $types = 34 === $message->number
                    ? [0 => 0x86, 1 => 0x84, 253 => 0x86]
                    : [2 => 0x86, 7 => 0x86, 8 => 0x86, 253 => 0x86, 254 => 0x84, (18 === $message->number ? 59 : 52) => 0x86];
                if (18 === $message->number) {
                    $types += [25 => 0x84, 26 => 0x84, 70 => 0x84];
                }
                $this->assertScalars($message, $types);
                $summaries[$message->number] = ['message' => $message, 'index' => $index];
            }
        }
        if (1 !== $fileIds || 3 !== count($summaries) || $records < 2 || null === $first || null === $last) {
            throw new RepairFailed('Moving-time recovery requires complete native summaries and endpoint Records.');
        }
        $session = $summaries[18]['message'];
        $lap = $summaries[19]['message'];
        $activity = $summaries[34]['message'];
        $start = $session->integer(2);
        $elapsed = $session->integer(7);
        $save = $session->integer(253);
        if (null === $start || null === $elapsed || $elapsed <= 0 || null === $save || $save <= $start
            || $start !== $first || $save - $last < 0 || $save - $last > 1
            || abs(($save - $start) * 1000 - $elapsed) >= 1000
            || $lap->integer(2) !== $start || $lap->integer(253) !== $save || $lap->integer(7) !== $elapsed
            || $activity->integer(253) !== $save || $session->integer(59) !== $elapsed || $lap->integer(52) !== $elapsed
            || !in_array($session->integer(8), [null, $elapsed], true) || !in_array($lap->integer(8), [null, $elapsed], true)
            || !in_array($activity->integer(0), [null, $elapsed], true)
            || !in_array($session->integer(254), [null, 0], true) || !in_array($lap->integer(254), [null, 0], true)
            || !in_array($session->integer(25), [null, 0], true) || !in_array($session->integer(26), [null, 1], true)
            || !in_array($session->integer(70), [null, 0], true) || 1 !== $activity->integer(1)) {
            throw new RepairFailed('Matching native moving/elapsed totals, clocks and references must establish one complete interval.');
        }
        $changes = [];
        foreach ([19 => 8, 18 => 8, 34 => 0] as $number => $field) {
            if (null === $summaries[$number]['message']->integer($field)) {
                $changes[] = ['message' => $summaries[$number]['index'], 'field' => $field, 'type' => 0x86, 'value' => $elapsed];
            }
        }
        if ([] === $changes) {
            throw new RepairFailed('No missing timer counter can be recovered from native moving time.');
        }
        $output = $file->apply($changes);

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes,
            'evidence' => ['lap_moving_ms' => $lap->integer(52), 'session_moving_ms' => $session->integer(59),
                'native_elapsed_ms' => $elapsed, 'record_count' => $records, 'first_record' => $first, 'last_record' => $last,
                'policy' => 'native moving <= timer <= elapsed; equal Lap and Session moving/elapsed counters determine the missing timer; no Event, pause, clock or interval is synthesized'],
            'inference' => ['historical_accuracy' => 'not_proven', 'source' => 'matching_native_moving_and_elapsed_counters'],
            'activity_validation' => 'not_performed',
        ]);
    }

    /** @param array<int, int> $types */
    private function assertScalars(PhysicalMessage $message, array $types): void
    {
        $seen = [];
        foreach ($message->fields as $field) {
            $number = $field->definition->fieldNumber;
            if (!isset($types[$number])) {
                continue;
            }
            if (isset($seen[$number]) || $field->definition->baseType->definitionByte() !== $types[$number]
                || $field->definition->size !== (0x84 === $types[$number] ? 2 : 4)) {
                throw new RepairFailed('Moving-time evidence requires unique scalar uint32 clocks/counters and uint16 references.');
            }
            $seen[$number] = true;
        }
    }
}
