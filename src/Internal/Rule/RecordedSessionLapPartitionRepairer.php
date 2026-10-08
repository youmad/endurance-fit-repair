<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Restores absent multisession references from independently matching native Lap intervals. */
final readonly class RecordedSessionLapPartitionRepairer
{
    public const string RULE = 'multisession_references_from_native_whole_session_laps';

    public function repair(string $bytes): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Native multisession partition recovery is bounded to 4 MiB.');
        }
        $file = EditableFitFile::read($bytes);
        $sessions = $laps = $activities = [];
        $records = [];
        $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (in_array($m->number, [101, 142, 225], true)) {
                throw new RepairFailed('Native detailed partitions take precedence over whole-session Lap pairing.');
            }
            $types = match ($m->number) {
                0 => [0 => 0x00],
                18 => [2 => 0x86, 7 => 0x86, 8 => 0x86, 253 => 0x86, 254 => 0x84, 25 => 0x84, 26 => 0x84, 70 => 0x84],
                19 => [2 => 0x86, 7 => 0x86, 8 => 0x86, 253 => 0x86, 254 => 0x84],
                20 => [253 => 0x86],
                34 => [0 => 0x86, 1 => 0x84, 253 => 0x86],
                default => [],
            };
            $seen = [];
            foreach ($m->fields as $field) {
                $n = $field->definition->fieldNumber;
                if (!isset($types[$n])) {
                    continue;
                }
                $type = $types[$n];
                if (isset($seen[$n]) || $field->definition->baseType->definitionByte() !== $type
                    || (0x86 === $type ? 4 : (0x84 === $type ? 2 : 1)) !== $field->definition->size) {
                    throw new RepairFailed('Native multisession proof requires unique scalar clocks, counters and references.');
                }
                $seen[$n] = true;
            }
            if (0 === $m->number) {
                if (++$fileIds > 1 || 0 !== $index || 4 !== $m->integer(0)) {
                    throw new RepairFailed('Native multisession recovery requires one Activity FileId first.');
                }
            } elseif (20 === $m->number) {
                $t = $m->integer(253);
                if (null === $t || $t < 0x10000000) {
                    throw new RepairFailed('Native multisession recovery requires absolute Record timestamps.');
                }
                $records[] = $t;
            } elseif (in_array($m->number, [18, 19, 34], true)) {
                if ($m->wasCompressed) {
                    throw new RepairFailed('Native multisession recovery requires explicit summary clocks.');
                }
                if (18 === $m->number) {
                    $sessions[$index] = $m;
                } elseif (19 === $m->number) {
                    $laps[$index] = $m;
                } else {
                    $activities[] = $m;
                }
            }
            if (count($sessions) > 128 || count($laps) > 128 || count($activities) > 1) {
                throw new RepairFailed('Native multisession proof exceeds its bounded summary partition.');
            }
        }
        if (1 !== $fileIds || count($sessions) < 2 || count($sessions) !== count($laps) || 1 !== count($activities)
            || $activities[0]->integer(1) !== count($sessions) || count($records) < 2) {
            throw new RepairFailed('Native multisession proof requires one original Lap per Session and a complete Activity count.');
        }
        $lapRows = array_values($laps);
        $lapPositions = array_keys($laps);
        $changes = $evidence = [];
        $previousEnd = null;
        $ordinal = 0;
        foreach ($sessions as $index => $s) {
            $l = $lapRows[$ordinal];
            $start = $l->integer(2);
            $elapsed = $s->integer(7);
            $timer = $s->integer(8);
            if (null === $start || $start < 0x10000000 || null === $elapsed || $elapsed <= 0 || null === $timer || $timer > $elapsed
                || null === $l->integer(7) || null === $l->integer(8) || $l->integer(8) > $l->integer(7)
                || abs($elapsed - $l->integer(7)) > 1999 || abs($timer - $l->integer(8)) > 999
                || null === $l->integer(253) || abs(($l->integer(253) - $start) * 1000 - $l->integer(7)) > 999
                || null === $s->integer(253) || $s->integer(253) < $l->integer(253)
                || ($ordinal + 1 < count($sessions) && $s->integer(253) !== $l->integer(253))
                || (null !== $previousEnd && abs($start * 1000 - $previousEnd) > 1999)
                || !in_array($s->integer(254), [null, $ordinal], true) || !in_array($s->integer(25), [null, $ordinal], true)
                || !in_array($s->integer(26), [null, 1], true) || !in_array($s->integer(70), [null, $ordinal], true)
                || !in_array($l->integer(254), [null, 0, 1, $ordinal], true)) {
                throw new RepairFailed('Every native Session and Lap must independently identify one complete abutting interval.');
            }
            if ($s->integer(2) !== $start) {
                if (0 !== $ordinal || min($records) !== $start || null === $s->integer(2)
                    || abs(($s->integer(253) - $start) * 1000 - $elapsed) > 1999) {
                    throw new RepairFailed('A displaced first Session start requires the native Lap, first Record and saved endpoint.');
                }
                $changes[] = ['message' => $index, 'field' => 2, 'type' => 0x86, 'value' => $start];
            }
            if ($l->integer(7) - $elapsed > 999 && $elapsed === $timer
                && ($l->integer(253) - $start) * 1000 === $l->integer(7)) {
                $elapsed = $l->integer(7);
                $changes[] = ['message' => $index, 'field' => 7, 'type' => 0x86, 'value' => $elapsed];
            }
            $end = $start * 1000 + $elapsed;
            if ([] === array_filter($records, static fn (int $t): bool => $t >= $start && $t * 1000 <= $end + 999)) {
                throw new RepairFailed('Every native Session/Lap pair requires original observations within its interval.');
            }
            foreach ([254 => $ordinal, 25 => $ordinal, 26 => 1] as $field => $value) {
                if (null === $s->integer($field)) {
                    $changes[] = ['message' => $index, 'field' => $field, 'type' => 0x84, 'value' => $value];
                }
            }
            if ($l->integer(254) !== $ordinal) {
                $changes[] = ['message' => $lapPositions[$ordinal], 'field' => 254, 'type' => 0x84, 'value' => $ordinal];
            }
            $previousEnd = $end;
            $evidence[] = ['session_index' => $ordinal, 'lap_source_sequence' => $l->sourceSequence,
                'native_start' => $start, 'native_elapsed_ms' => $elapsed, 'native_timer_ms' => $timer];
            ++$ordinal;
        }
        if (min($records) !== $lapRows[0]->integer(2) || max($records) * 1000 > $previousEnd + 999 || [] === $changes) {
            throw new RepairFailed('A complete native whole-session partition must bound all original Records and require actual missing references.');
        }
        $output = $file->apply($changes);

        return new RepairResult($output, ['mode' => 'restore_native_multisession_partition', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes, 'evidence' => $evidence,
            'inference' => ['historical_accuracy' => 'not_proven',
                'policy' => 'pair each existing Session with its native whole-session Lap using saved clocks and matching counters; restore missing global references and conflicting local Lap indexes; only a displaced first Session start may copy both the original Lap and first Record start; reconcile elapsed rounded to active time only against the native Lap and saved endpoint; retain every native active timer, measurement and Event'],
            'activity_validation' => 'not_performed']);
    }
}
