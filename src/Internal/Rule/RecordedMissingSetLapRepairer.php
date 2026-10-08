<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Recreates absent strength Laps only from a complete native indexed Set partition. */
final readonly class RecordedMissingSetLapRepairer
{
    public const string RULE = 'missing_strength_laps_from_native_set_ranges';

    public function repair(string $bytes): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Native Set range recovery is bounded to 4 MiB.');
        }
        $file = EditableFitFile::read($bytes);
        $sessions = $laps = $sets = $activities = $events = $lapPositions = [];
        $first = $last = null;
        $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (in_array($m->number, [101, 142], true)) {
                throw new RepairFailed('Pool Lengths and SegmentLaps cannot establish a strength Set range.');
            }
            if (!in_array($m->number, [0, 18, 19, 20, 21, 34, 225], true)) {
                continue;
            }
            $seen = [];
            foreach ($m->fields as $field) {
                $n = $field->definition->fieldNumber;
                $type = match ($m->number) {
                    0 => 0 === $n ? 0x00 : null,
                    20 => 253 === $n ? 0x86 : null,
                    21 => match ($n) {
                        0, 1 => 0x00,
                        4 => 0x02,
                        253 => 0x86,
                        default => null,
                    },
                    34 => match ($n) {
                        0, 253 => 0x86,
                        1 => 0x84,
                        default => null,
                    },
                    225 => match ($n) {
                        0, 6, 254 => 0x86,
                        10 => 0x84,
                        5 => 0x00,
                        default => null,
                    },
                    default => match ($n) {
                        2, 7, 8, 253 => 0x86,
                        254 => 0x84,
                        25, 26 => 18 === $m->number ? 0x84 : null,
                        5, 6 => 18 === $m->number ? 0x00 : null,
                        default => null,
                    },
                };
                $actual = $field->definition->baseType->definitionByte();
                if (null !== $type && (isset($seen[$n]) || !in_array($actual, 0x00 === $type ? [0x00, 0x02] : [$type], true)
                    || (0x86 === $type ? 4 : (0x84 === $type ? 2 : 1)) !== $field->definition->size)) {
                    throw new RepairFailed('Native Set range evidence requires unique scalar clocks, counters and references.');
                }
                $seen[$n] = true;
            }
            if (0 === $m->number) {
                if (++$fileIds > 1 || 0 !== $index || 4 !== $m->integer(0)) {
                    throw new RepairFailed('Native Set range recovery requires one Activity FileId first.');
                }
            } elseif (20 === $m->number) {
                $t = $m->integer(253);
                if (null === $t || $t < 0x10000000 || (null !== $last && $t < $last)) {
                    throw new RepairFailed('Native Set range recovery requires ordered absolute Records.');
                }
                $first ??= $t;
                $last = $t;
            } elseif (21 === $m->number) {
                if (null === $m->integer(0)) {
                    throw new RepairFailed('Unknown Events cannot exclude a contradictory timer trace.');
                }
                if (0 === $m->integer(0)) {
                    $events[] = $m;
                }
            } else {
                if ($m->wasCompressed) {
                    throw new RepairFailed('Native Set range recovery requires explicit summary and Set clocks.');
                }
                if (18 === $m->number) {
                    $sessions[$index] = $m;
                } elseif (34 === $m->number) {
                    $activities[] = $m;
                } else {
                    $id = $m->integer(225 === $m->number ? 10 : 254);
                    if (null === $id || $id > 4095 || isset((225 === $m->number ? $sets : $laps)[$id])
                        || (225 === $m->number && [] !== $sets && $id <= array_key_last($sets))
                        || (19 === $m->number && [] !== $laps && $id <= array_key_last($laps))) {
                        throw new RepairFailed('Native Set and existing Lap identities must be complete and unique.');
                    }
                    if (225 === $m->number) {
                        $sets[$id] = $m;
                    } else {
                        $laps[$id] = $m;
                        $lapPositions[$id] = $index;
                    }
                }
            }
            if (count($sessions) > 128 || count($activities) > 1 || count($sets) > 4096 || count($events) > 4096) {
                throw new RepairFailed('Native Set range evidence exceeds its bounded partition.');
            }
        }
        if (1 !== $fileIds || [] === $sessions || [] === $sets || 1 !== count($activities) || null === $first || null === $last
            || $activities[0]->integer(1) !== count($sessions) || count($events) < 2) {
            throw new RepairFailed('Native Set range recovery requires Sessions, Activity, Records and a complete timer trace.');
        }
        $intervals = [];
        $running = $previous = null;
        foreach ($events as $event) {
            $t = $event->integer(253);
            $kind = $event->integer(1);
            if (null === $t || $t < 0x10000000 || !in_array($kind, [0, 1, 4], true)
                || !in_array($event->integer(4), [null, 0], true) || (null !== $previous && $t < $previous)) {
                throw new RepairFailed('Native Set ranges require an ordered same-group timer trace.');
            }
            if (0 === $kind) {
                if (null !== $running) {
                    throw new RepairFailed('Repeated START cannot corroborate native Set durations.');
                }
                $running = $t;
            } else {
                if (null === $running) {
                    throw new RepairFailed('An unmatched STOP cannot corroborate native Set durations.');
                }
                $intervals[] = [$running, $t];
                $running = null;
            }
            $previous = $t;
        }
        if (null !== $running) {
            throw new RepairFailed('Native Set ranges require a closed timer trace.');
        }
        $rows = array_values($sessions);
        $finalSession = $rows[array_key_last($rows)];
        if ($events[0]->integer(253) !== $rows[0]->integer(2)
            || $previous * 1000 > ($finalSession->integer(2) ?? 0) * 1000 + ($finalSession->integer(7) ?? 0) + 999
            || $last > $previous + 1) {
            throw new RepairFailed('Native Session bounds and first START/final STOP must retain the complete timer trace and all Records.');
        }
        $cursor = $timerTotal = 0;
        $insertions = $evidence = $usedSets = $usedLaps = $batches = $changes = [];
        foreach ($sessions as $sessionIndex => $s) {
            $ordinal = count($evidence);
            $start = $s->integer(2);
            $elapsed = $s->integer(7);
            $timer = $s->integer(8);
            $count = $s->integer(26);
            if ($s->integer(254) !== $ordinal || $s->integer(25) !== $cursor || null === $count || $count < 1
                || $cursor + $count > 4096 || null === $start || $start < 0x10000000 || null === $elapsed
                || null === $timer || $timer <= 0 || $elapsed < $timer || null === $s->integer(253)) {
                throw new RepairFailed('Native Sessions must declare complete ordered Lap ranges and original durations.');
            }
            $nextStart = isset($rows[$ordinal + 1]) ? $rows[$ordinal + 1]->integer(2) : null;
            $endMs = null === $nextStart ? $start * 1000 + $elapsed : $nextStart * 1000;
            if ($endMs <= $start * 1000 || $endMs - $start * 1000 < $timer || $first < $rows[0]->integer(2) || $last * 1000 > $rows[array_key_last($rows)]->integer(2) * 1000 + $rows[array_key_last($rows)]->integer(7) + 999) {
                throw new RepairFailed('Native Session starts and final elapsed must retain every Record.');
            }
            $active = 0;
            $hasStart = false;
            foreach ($intervals as [$a, $b]) {
                $hasStart = $hasStart || $a === $start;
                $active += max(0, min($b * 1000, $endMs) - max($a * 1000, $start * 1000));
            }
            if (!$hasStart || abs($active - $timer) > 999) {
                throw new RepairFailed('Native Session timer must independently agree with its recorded running spans.');
            }
            $missing = [];
            $sum = 0;
            for ($id = $cursor; $id < $cursor + $count; ++$id) {
                if (isset($laps[$id])) {
                    $usedLaps[$id] = true;
                    $sum += $laps[$id]->integer(8) ?? throw new RepairFailed('Existing Lap timers must remain usable.');
                } else {
                    $missing[] = $id;
                }
            }
            $sessionElapsed = $elapsed;
            if ([] === $missing) {
                $sessionElapsed = 0;
                for ($id = $cursor; $id < $cursor + $count; ++$id) {
                    $lap = $laps[$id];
                    $a = $lap->integer(2);
                    $t = $lap->integer(8);
                    $bound = $id + 1 < $cursor + $count ? $laps[$id + 1]->integer(2) : null;
                    $boundMs = null === $bound ? $endMs : $bound * 1000;
                    if (null === $a || null === $t || (null === $bound && $id + 1 < $cursor + $count)
                        || ($id === $cursor && $a !== $start) || $boundMs <= $a * 1000) {
                        throw new RepairFailed('Existing native Laps must have complete ordered interval boundaries.');
                    }
                    $activeLap = 0;
                    foreach ($intervals as [$b, $c]) {
                        $activeLap += max(0, min($boundMs, $c * 1000) - max($a * 1000, $b * 1000));
                    }
                    if (abs($activeLap - $t) > 999) {
                        throw new RepairFailed('Every existing Lap timer must agree with its independent native running span.');
                    }
                    $e = max($t, $boundMs - $a * 1000);
                    if ($lap->integer(7) !== $e) {
                        $changes[] = ['message' => $lapPositions[$id], 'field' => 7, 'type' => 0x86, 'value' => $e];
                    }
                    $sessionElapsed += $e;
                }
            }
            if ([] !== $missing) {
                if (count($missing) !== $count || 10 !== $s->integer(5) || !in_array($s->integer(6), [20, 43], true)) {
                    throw new RepairFailed('Only entirely absent strength/yoga Lap ranges can be recovered from Sets.');
                }
                $position = $sessionIndex;
                if ([] !== $lapPositions) {
                    $following = array_filter($lapPositions, static fn (int $id): bool => $id > $cursor, ARRAY_FILTER_USE_KEY);
                    $position = [] !== $following ? min($following) : max($lapPositions) + 1;
                }
                $generated = [];
                $sum = 0;
                $elapsedValues = [];
                foreach ($missing as $i => $id) {
                    $set = $sets[$id] ?? throw new RepairFailed('Every missing native Lap identity requires its matching Set.');
                    $a = $set->integer(6);
                    $t = $set->integer(0);
                    $next = $i + 1 < $count ? ($sets[$id + 1] ?? null)?->integer(6) : null;
                    if (null === $a || null === $t || ($i + 1 < $count && null === $next)) {
                        throw new RepairFailed('Native Set clocks and timers must be complete.');
                    }
                    $bound = null === $next ? $endMs : $next * 1000;
                    $recordedActive = 0;
                    foreach ($intervals as [$b, $c]) {
                        $recordedActive += max(0, min($bound, $c * 1000) - max($a * 1000, $b * 1000));
                    }
                    if (abs($recordedActive - $t) > 999) {
                        throw new RepairFailed('Each native Set timer must agree independently with its recorded running spans.');
                    }
                    $elapsedValues[$id] = $t + max(0, $bound - $a * 1000 - $recordedActive);
                }
                $residual = array_sum($elapsedValues) - ($endMs - $start * 1000);
                foreach (array_reverse($missing) as $id) {
                    $t = $sets[$id]->integer(0);
                    $correction = $residual > 0 ? min($residual, $elapsedValues[$id] - $t) : $residual;
                    $elapsedValues[$id] -= $correction;
                    $residual -= $correction;
                    if (0 === $residual) {
                        break;
                    }
                }
                if (0 !== $residual) {
                    throw new RepairFailed('Subsecond native timer rounding must admit the exact elapsed range without reducing active time.');
                }
                foreach ($missing as $i => $id) {
                    $set = $sets[$id] ?? throw new RepairFailed('Every missing native Lap identity requires its matching Set.');
                    $a = $set->integer(6);
                    $t = $set->integer(0);
                    $next = $i + 1 < $count ? ($sets[$id + 1] ?? null)?->integer(6) : null;
                    $e = $elapsedValues[$id];
                    if (null === $a || null === $t || $t <= 0 || !in_array($set->integer(5), [0, 1], true)
                        || null === $set->integer(254) || (0 === $i && $a !== $start) || $a < $start
                        || ($i > 0 && $a <= $sets[$id - 1]->integer(6)) || $e <= 0 || $e >= 0xFFFFFFFF || $t > $e + 1999) {
                        throw new RepairFailed('Native Set starts and timers must fit their declared Session range.');
                    }
                    $bound = null === $next ? $endMs : $next * 1000;
                    if (abs($a * 1000 + $e - $bound) > 1999) {
                        throw new RepairFailed('Native Set elapsed bounds exceed their whole-second clock resolution.');
                    }
                    $lap = (new PhysicalMessage(19, $set->architecture))->withInteger(254, 0x84, $id)
                        ->withInteger(2, 0x86, $a)->withInteger(253, 0x86, $a + intdiv($e, 1000))
                        ->withInteger(7, 0x86, $e)->withInteger(8, 0x86, $t)
                        ->withInteger(0, 0x00, 9)->withInteger(1, 0x00, 1)
                        ->withInteger(25, 0x00, 10)->withInteger(39, 0x00, $s->integer(6));
                    $generated[] = $lap;
                    $insertions[] = ['globalMessageNumber' => 19, 'beforeOriginalMessage' => $position, 'synthetic' => true,
                        'basis' => 'complete_native_set_range_with_original_timers', 'sourceSequence' => $set->sourceSequence,
                        'fields' => array_map(static fn ($f): array => ['number' => $f->definition->fieldNumber, 'value' => $lap->integer($f->definition->fieldNumber)], $lap->fields)];
                    $sum += $t;
                    $usedSets[$id] = true;
                }
                $batches[$position] = array_merge($batches[$position] ?? [], $generated);
                $sessionElapsed = array_sum($elapsedValues);
            }
            if ($sum !== $timer) {
                throw new RepairFailed('Every native Session timer must equal its unchanged existing Lap or Set timer sum.');
            }
            if ($s->integer(7) !== $sessionElapsed) {
                $changes[] = ['message' => $sessionIndex, 'field' => 7, 'type' => 0x86, 'value' => $sessionElapsed];
            }
            $evidence[] = ['session_index' => $ordinal, 'first_lap_index' => $cursor, 'num_laps' => $count,
                'native_timer_ms' => $timer, 'recorded_active_ms' => $active, 'restored_laps' => count($missing)];
            $timerTotal += $timer;
            $cursor += $count;
        }
        if ([] === $insertions || count($usedLaps) !== count($laps) || count($usedSets) !== count($sets) || $activities[0]->integer(0) !== $timerTotal) {
            throw new RepairFailed('Complete native ranges and the Activity timer must corroborate every retained Lap and Set.');
        }
        $output = $file->apply($changes);
        krsort($batches);
        foreach ($batches as $position => $generated) {
            $output = EditableFitFile::read($output)->insertManyBefore($position, $generated);
        }

        return new RepairResult($output, ['mode' => 'restore_missing_native_partition', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes, 'inserted_messages' => $insertions, 'evidence' => $evidence,
            'inference' => ['historical_accuracy' => 'not_proven',
                'policy' => 'copy each native indexed Set timer and start into its absent Lap; retain every original Lap, Set and timer Event; elapsed bounds include the recorded inactive gaps; synthesized SAVE clocks and original Lap metadata are not historically proven'],
            'activity_validation' => 'not_performed']);
    }
}
