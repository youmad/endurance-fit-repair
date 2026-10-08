<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Restores missing references from a complete native sole-Session Lap partition. */
final readonly class RecordedLapReferenceRepairer
{
    public const string RULE = 'missing_lap_references_from_native_counter_partition';
    public const string SAVED_BOUNDARIES_RULE = 'missing_lap_starts_from_native_saved_partition';

    public function repair(string $bytes, bool $savedBoundaries = false): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Native Lap reference recovery is bounded to 4 MiB.');
        }
        $file = EditableFitFile::read($bytes);
        $laps = $sessions = $activities = [];
        $activityPosition = null;
        $fileIds = 0;
        $first = $last = null;
        $lapRecords = [];
        $firstInLap = null;
        $lastDistance = null;
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (in_array($m->number, [0, 20], true) || ($savedBoundaries && 21 === $m->number)) {
                $fieldNumber = 20 === $m->number ? 253 : 0;
                $seen = false;
                foreach ($m->fields as $field) {
                    if ($fieldNumber !== $field->definition->fieldNumber) {
                        continue;
                    }
                    if ($seen || (20 === $m->number ? 4 : 1) !== $field->definition->size
                        || !in_array($field->definition->baseType->definitionByte(), 20 === $m->number ? [0x86] : [0x00, 0x02], true)) {
                        throw new RepairFailed('Native identity and Record clocks require unique scalar fields.');
                    }
                    $seen = true;
                }
            }
            if ($savedBoundaries && 21 === $m->number && in_array($m->integer(0), [null, 0], true)) {
                throw new RepairFailed('A native timer trace or unknown Event takes precedence over saved-boundary recovery.');
            }
            if (101 === $m->number) {
                throw new RepairFailed('Pool reference recovery requires its own complete native Length partition.');
            }
            if (0 === $m->number) {
                if (++$fileIds > 1 || 0 !== $index || 4 !== $m->integer(0)) {
                    throw new RepairFailed('Native Lap reference recovery requires one Activity FileId first.');
                }
            } elseif (20 === $m->number) {
                if ($savedBoundaries) {
                    $seen = false;
                    foreach ($m->fields as $field) {
                        if (5 !== $field->definition->fieldNumber) {
                            continue;
                        }
                        if ($seen || 0x86 !== $field->definition->baseType->definitionByte() || 4 !== $field->definition->size) {
                            throw new RepairFailed('Saved-boundary distance corroboration requires a unique scalar native Record distance.');
                        }
                        $seen = true;
                    }
                    $lastDistance = $m->integer(5);
                }
                $t = $m->integer(253);
                if (null === $t || $t < 0x10000000 || (null !== $last && $t < $last)) {
                    throw new RepairFailed('Native Lap reference recovery requires ordered absolute Records.');
                }
                $first ??= $t;
                $firstInLap ??= $t;
                $last = $t;
            } elseif (in_array($m->number, [18, 19, 34], true)) {
                if ($m->wasCompressed) {
                    throw new RepairFailed('Native Lap references require explicit summary clocks.');
                }
                $seen = [];
                foreach ($m->fields as $field) {
                    $n = $field->definition->fieldNumber;
                    $type = match ($m->number) {
                        34 => match ($n) {
                            0, 253 => 0x86,
                            1 => 0x84,
                            default => null,
                        },
                        default => match ($n) {
                            2, 7, 8, 9, 253 => 0x86,
                            254 => 0x84,
                            25, 26, 70 => 18 === $m->number ? 0x84 : null,
                            default => null,
                        },
                    };
                    if (null !== $type && (isset($seen[$n]) || $field->definition->baseType->definitionByte() !== $type
                        || (0x86 === $type ? 4 : 2) !== $field->definition->size)) {
                        throw new RepairFailed('Native reference evidence requires unique scalar counters and indexes.');
                    }
                    $seen[$n] = true;
                }
                if (18 === $m->number) {
                    $sessions[$index] = $m;
                } elseif (19 === $m->number) {
                    $laps[$index] = $m;
                    $lapRecords[$index] = [$firstInLap, $last];
                    $firstInLap = null;
                } else {
                    $activities[] = $m;
                    $activityPosition = $index;
                }
            }
            if (count($sessions) > 1 || count($activities) > 1 || count($laps) > 4096) {
                throw new RepairFailed('Native reference recovery requires one bounded Session partition.');
            }
        }
        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($activities) || null === $activityPosition || count($laps) < 2 || null === $first || null === $last) {
            throw new RepairFailed('Native reference recovery requires one Session, Activity and multiple original Laps with Records.');
        }
        $s = reset($sessions);
        $a = $activities[0];
        $start = $s->integer(2);
        $timer = $s->integer(8);
        if (!in_array($s->integer(254), [null, 0], true) || !in_array($s->integer(25), [null, 0], true)
            || !in_array($s->integer(26), [null, count($laps)], true) || !in_array($s->integer(70), [null, 0], true)
            || 1 !== $a->integer(1) || null === $start || $start < 0x10000000 || null === $timer || $timer <= 0
            || $a->integer(0) !== $timer || null === $s->integer(7) || $s->integer(7) < $timer) {
            throw new RepairFailed('Existing references and original Session/Activity timers must agree.');
        }
        $changes = [];
        if ($savedBoundaries) {
            $nextStart = $start;
            foreach ($laps as $index => &$lap) {
                $save = $lap->integer(253);
                [$recordFrom, $recordUntil] = $lapRecords[$index];
                if (null !== $lap->integer(2) || 0 !== $lap->integer(7) || null === $save || $save <= $nextStart
                    || null === $recordFrom || null === $recordUntil || $recordFrom < $nextStart || $recordUntil > $save
                    || $save - $recordUntil > 4 || null === $lap->integer(8) || $lap->integer(8) > ($save - $nextStart) * 1000) {
                    throw new RepairFailed('Missing Lap starts require zero native elapsed, ordered SAVEs and original observations corroborating every retained Lap.');
                }
                $elapsed = ($save - $nextStart) * 1000;
                $changes[] = ['message' => $index, 'field' => 2, 'type' => 0x86, 'value' => $nextStart];
                $changes[] = ['message' => $index, 'field' => 7, 'type' => 0x86, 'value' => $elapsed];
                $lap = $lap->withInteger(2, 0x86, $nextStart)->withInteger(7, 0x86, $elapsed);
                $nextStart = $save;
            }
            unset($lap);
            if (null !== $firstInLap) {
                throw new RepairFailed('The complete saved Lap partition must contain every original Record and its native Session start.');
            }
        }
        $sumElapsed = $sumTimer = $sumDistance = 0;
        $distanceComplete = true;
        $previousEnd = null;
        $ordinal = 0;
        foreach ($laps as $index => $lap) {
            $lStart = $lap->integer(2);
            $e = $lap->integer(7);
            $t = $lap->integer(8);
            if (!in_array($lap->integer(254), [null, $ordinal], true) || null === $lStart || null === $e || $e <= 0
                || null === $t || $t < 0 || $t > $e || null === $lap->integer(253)
                || abs($lap->integer(253) * 1000 - ($lStart * 1000 + $e)) > 999
                || (0 === $ordinal && $lStart !== $start) || (null !== $previousEnd && abs($lStart * 1000 - $previousEnd) > 1999)) {
                throw new RepairFailed('Every original Lap must independently define one abutting native interval.');
            }
            $previousEnd = $lStart * 1000 + $e;
            $sumElapsed += $e;
            $sumTimer += $t;
            $distanceComplete = $distanceComplete && null !== $lap->integer(9);
            $sumDistance += $lap->integer(9) ?? 0;
            if (null === $lap->integer(254)) {
                $changes[] = ['message' => $index, 'field' => 254, 'type' => 0x84, 'value' => $ordinal];
            }
            ++$ordinal;
        }
        if (abs($sumTimer - $timer) > 999 || $sumElapsed >= 0xFFFFFFFF || $sumElapsed < $timer
            || abs($start * 1000 + $sumElapsed - $previousEnd) > 1999 || $first < $start
            || $last * 1000 > $previousEnd + 999 || $last * 1000 > $start * 1000 + $sumElapsed + 999
            || ($distanceComplete && null !== $s->integer(9) && $sumDistance !== $s->integer(9)
                && (!$savedBoundaries || $lastDistance !== $s->integer(9) || abs($sumDistance - $lastDistance) > 100))) {
            throw new RepairFailed('Native Lap timer totals, complete distance and Record bounds must corroborate the whole Session.');
        }
        if ($last * 1000 > $start * 1000 + $s->integer(7) + 999 && $sumElapsed > $s->integer(7)) {
            $changes[] = ['message' => array_key_first($sessions), 'field' => 7, 'type' => 0x86, 'value' => $sumElapsed];
        }
        foreach ([254 => 0, 25 => 0, 26 => count($laps)] as $field => $value) {
            if (null === $s->integer($field)) {
                $changes[] = ['message' => array_key_first($sessions), 'field' => $field, 'type' => 0x84, 'value' => $value];
            }
        }
        if ($savedBoundaries) {
            $finish = intdiv($previousEnd, 1000);
            foreach ([array_key_first($sessions) => $s, $activityPosition => $a] as $index => $summary) {
                if (null !== $summary->integer(253) && $summary->integer(253) < $last && $summary->integer(253) < $finish) {
                    $changes[] = ['message' => $index, 'field' => 253, 'type' => 0x86, 'value' => $finish];
                }
            }
        }
        if ([] === $changes) {
            throw new RepairFailed('No missing native Lap reference or corroborated observed Session extent requires repair.');
        }
        $output = $file->apply($changes);

        return new RepairResult($output, ['mode' => 'restore_native_references', 'rule' => $savedBoundaries ? self::SAVED_BOUNDARIES_RULE : self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'evidence' => ['native_lap_count' => count($laps), 'native_lap_elapsed_sum_ms' => $sumElapsed,
                'native_lap_timer_sum_ms' => $sumTimer, 'unchanged_session_timer_ms' => $timer,
                'distance_residual_cm' => $distanceComplete && null !== $s->integer(9) ? $sumDistance - $s->integer(9) : null,
                'last_native_record_distance_cm' => $savedBoundaries ? $lastDistance : null,
                'policy' => 'fill absent native indexes and sole-Session references; optionally restore all missing Lap starts and zero elapsed from increasing original SAVEs corroborated by each physical Record block; extend insufficient Session elapsed to the exact Lap sum covering all original Records; retain every original Lap, active timer, measurement and Event; original pauses and millisecond boundaries remain unknown'],
            'inference' => ['historical_accuracy' => 'not_proven'], 'activity_validation' => 'not_performed']);
    }
}
