<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Reconciles isolated Record clock discontinuities against unchanged native duration evidence. */
final readonly class RecordedRecordClockRepairer
{
    public const string DATE_RULE = 'record_dates_from_native_activity_interval';
    public const string PREFIX_RULE = 'initial_device_clock_from_native_elapsed_and_stop';

    public function repair(string $bytes, bool $initialClock = false): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Record clock reconciliation requires a bounded FIT member.');
        }
        $file = EditableFitFile::read($bytes);
        $summaries = $records = $events = [];
        $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if ($m->wasCompressed) {
                throw new RepairFailed('Record clock reconciliation requires explicit timestamps throughout the member.');
            }
            if (0 === $m->number && (++$fileIds > 1 || 0 !== $index || 4 !== $m->integer(0))) {
                throw new RepairFailed('Record clock evidence requires one original Activity FileId first.');
            }
            $types = match ($m->number) {
                0 => [0 => [0x00, 0x02], 4 => [0x86]],
                18 => [2 => [0x86], 7 => [0x86], 8 => [0x86], 253 => [0x86], 254 => [0x84], 25 => [0x84], 26 => [0x84]],
                19 => [2 => [0x86], 7 => [0x86], 8 => [0x86], 253 => [0x86], 254 => [0x84]],
                20 => [253 => [0x86]],
                21 => [0 => [0x00, 0x02], 1 => [0x00, 0x02], 4 => [0x02], 253 => [0x86]],
                34 => [0 => [0x86], 1 => [0x84], 253 => [0x86]],
                default => [],
            };
            $seen = [];
            foreach ($m->fields as $f) {
                $n = $f->definition->fieldNumber;
                if (isset($types[$n])) {
                    $size = match ($types[$n][0]) {
                        0x86 => 4,
                        0x84 => 2,
                        default => 1,
                    };
                    if (isset($seen[$n]) || $f->definition->size !== $size || !in_array($f->definition->baseType->definitionByte(), $types[$n], true)) {
                        throw new RepairFailed('Record clock evidence requires unique scalar native clocks and counters.');
                    }
                    $seen[$n] = true;
                }
            }
            if (in_array($m->number, [18, 19, 34], true)) {
                if (isset($summaries[$m->number])) {
                    throw new RepairFailed('Record clock reconciliation requires sole original summaries.');
                }
                $summaries[$m->number] = ['index' => $index, 'm' => $m];
            } elseif (20 === $m->number) {
                $t = $m->integer(253);
                if (null === $t || $t < 0x10000000) {
                    throw new RepairFailed('Every Record must retain its own absolute clock.');
                }
                $records[$index] = $t;
            } elseif (21 === $m->number) {
                if (null === $m->integer(0)) {
                    throw new RepairFailed('An unknown Event cannot exclude a timer contradiction.');
                }
                if (0 === $m->integer(0)) {
                    $events[$index] = $m;
                }
            } elseif (in_array($m->number, [101, 142, 225], true)) {
                throw new RepairFailed('Clock reconciliation cannot replace pool, segment or set boundaries.');
            }
        }
        if (1 !== $fileIds || 3 !== count($summaries) || count($records) < 4 || count($events) < 2 || count($events) > 4096) {
            throw new RepairFailed('Record clock evidence requires complete summaries, Records and a native timer trace.');
        }
        $s = $summaries[18]['m'];
        $l = $summaries[19]['m'];
        $a = $summaries[34]['m'];
        $start = $s->integer(2);
        $elapsed = $s->integer(7);
        $timer = $s->integer(8);
        $trace = array_values($events);
        $end = $trace[array_key_last($trace)]->integer(253);
        if (null === $start || $start < 0x10000000 || null === $elapsed || $elapsed <= 0 || null === $timer || $timer <= 0 || $timer > $elapsed
            || $l->integer(2) !== $start || $l->integer(7) !== $elapsed || $l->integer(8) !== $timer || $a->integer(0) !== $timer
            || 1 !== $a->integer(1) || !in_array($s->integer(26), [null, 1], true) || !in_array($s->integer(25), [null, 0], true)
            || !in_array($s->integer(254), [null, 0], true) || !in_array($l->integer(254), [null, 0], true)
            || $trace[0]->integer(253) !== $start || null === $end || $end <= $start || $l->integer(253) !== $end
            || null === $s->integer(253) || $s->integer(253) < $end || null === $a->integer(253) || $a->integer(253) < $end
            || abs($records[array_key_last($records)] - $end) > 1) {
            throw new RepairFailed('Original counters and terminal STOP/SAVE clocks must independently corroborate the complete sole Lap.');
        }
        $active = 0;
        $activeStart = null;
        foreach ($trace as $i => $event) {
            $t = $event->integer(253);
            if (null === $t || $t < $start || $t > $end || !in_array($event->integer(4), [null, 0], true)
                || !in_array($event->integer(1), [0, 1, 4], true)
                || ($i > 0 && $t < $trace[$i - 1]->integer(253))) {
                throw new RepairFailed('Native timer transitions must alternate and retain one ordered group.');
            }
            if (0 === $event->integer(1)) {
                if (null !== $activeStart) {
                    throw new RepairFailed('Conflicting START transitions cannot corroborate Record clocks.');
                }
                $activeStart = $t;
            } elseif (null !== $activeStart) {
                $active += ($t - $activeStart) * 1000;
                $activeStart = null;
            } elseif ($i !== array_key_last($trace)) {
                throw new RepairFailed('Only a terminal redundant STOP can corroborate the original saved endpoint.');
            }
        }
        if (null !== $activeStart) {
            throw new RepairFailed('Record clock reconciliation requires a closed native timer trace.');
        }
        $changes = [];
        $normalized = $records;
        $offset = null;
        if ($initialClock) {
            $correctedStart = $end - intdiv($elapsed + 500, 1000);
            $offset = $correctedStart - $start;
            if (2 !== count($events) || $elapsed !== $timer || abs($offset) < 2 || abs($offset) > 600
                || reset($records) !== $start || $correctedStart < 0x10000000) {
                throw new RepairFailed('Initial clock correction requires uninterrupted matching native timers and a bounded start discontinuity.');
            }
            $previous = null;
            $boundary = null;
            foreach ($records as $index => $t) {
                if (null !== $previous && $t < $previous) {
                    $boundary = $index;
                    break;
                }
                $previous = $t;
            }
            if (null === $boundary) {
                throw new RepairFailed('There is no isolated backward clock correction in the original Record stream.');
            }
            foreach ($records as $index => $t) {
                if ($index >= $boundary) {
                    break;
                }
                $normalized[$index] = $t + $offset;
                $changes[] = ['message' => $index, 'field' => 253, 'type' => 0x86, 'value' => $t + $offset];
            }
            foreach ([18, 19] as $number) {
                $changes[] = ['message' => $summaries[$number]['index'], 'field' => 2, 'type' => 0x86, 'value' => $correctedStart];
            }
            foreach ($file->messages as $index => $entry) {
                $m = PhysicalMessage::fromRaw($entry['raw'], 0);
                if ($index >= $boundary) {
                    break;
                }
                if (21 === $m->number && $m->integer(253) === $start) {
                    $changes[] = ['message' => $index, 'field' => 253, 'type' => 0x86, 'value' => $correctedStart];
                } elseif (0 === $m->number && $m->integer(4) === $start) {
                    $changes[] = ['message' => $index, 'field' => 4, 'type' => 0x86, 'value' => $correctedStart];
                }
            }
            $start = $correctedStart;
            if (abs(($end - $start) * 1000 - $timer) > 999) {
                throw new RepairFailed('Corrected initial clock must agree with unchanged native counters within timestamp resolution.');
            }
        } else {
            if ($elapsed >= 86_400_000 || abs(($end - $start) * 1000 - $elapsed) > 999
                || reset($records) < $start || reset($records) > $end) {
                throw new RepairFailed('Date correction requires a sub-day native interval corroborated by unchanged timer Events and endpoint Records.');
            }
            foreach ($records as $index => $t) {
                if ($t >= $start && $t <= $end) {
                    continue;
                }
                $candidate = $start + (($t - $start) % 86400 + 86400) % 86400;
                $delta = $candidate - $t;
                if ($candidate > $end || (null !== $offset && $delta !== $offset)) {
                    throw new RepairFailed('Misdated Records do not share one unique whole-day displacement.');
                }
                $offset = $delta;
                $normalized[$index] = $candidate;
                $changes[] = ['message' => $index, 'field' => 253, 'type' => 0x86, 'value' => $candidate];
            }
            if (count($changes) < 2) {
                throw new RepairFailed('An isolated corrupt timestamp is not a corroborated device date interval.');
            }
        }
        $previous = null;
        foreach ($normalized as $t) {
            if ($t < $start || $t > $end || (null !== $previous && $t < $previous)) {
                throw new RepairFailed('Corrected clocks must yield complete native chronology inside the unchanged endpoint.');
            }
            $previous = $t;
        }
        $output = $file->apply($changes);

        return new RepairResult($output, ['mode' => 'normalize_device_clock', 'rule' => $initialClock ? self::PREFIX_RULE : self::DATE_RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inference' => ['historical_accuracy' => 'not_proven', 'source' => 'matching_native_summary_counters_and_original_terminal_stop',
                'offset_seconds' => $offset, 'original_timer_trace_counter_delta_ms' => $timer - $active,
                'policy' => $initialClock ? 'initial clock phase reconciled to native elapsed and terminal STOP; corrected Records must join the unchanged tail in order' : 'unique whole-day displacement; native time of day, all interval bounds and pauses retained; original timer/counter discrepancies not normalized'],
            'preservation' => 'all physical messages and measurement values retained in place; native durations and terminal STOP unchanged', 'activity_validation' => 'not_performed']);
    }
}
