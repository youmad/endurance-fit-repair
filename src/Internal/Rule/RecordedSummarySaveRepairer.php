<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Repairs summary SAVE clocks only from a complete, independently corroborated native counter interval. */
final readonly class RecordedSummarySaveRepairer
{
    public const string RULE = 'summary_save_from_native_counter_partition';
    public const string MINIMUM_RULE = 'minimum_elapsed_from_matching_native_timers';

    public function repair(string $bytes, bool $minimumElapsed = false): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Counter SAVE recovery requires a bounded FIT member.');
        }
        $file = EditableFitFile::read($bytes);
        $session = $activity = null;
        $laps = $events = [];
        $first = $last = null;
        $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (0 === $m->number) {
                if (++$fileIds > 1 || 0 !== $index || 4 !== $m->integer(0)) {
                    throw new RepairFailed('One original Activity FileId must be first.');
                }
            } elseif (20 === $m->number) {
                $t = $this->scalar($m, 253);
                if (null === $t || $t < 0x10000000 || (null !== $last && $t < $last)) {
                    throw new RepairFailed('Counter SAVE recovery requires ordered absolute Records.');
                }
                $first ??= $t;
                $last = $t;
            } elseif (21 === $m->number) {
                if (null === $m->integer(0) || 0 === $m->integer(0)) {
                    $events[] = $m;
                }
            } elseif (in_array($m->number, [18, 19, 34], true)) {
                if ($m->wasCompressed) {
                    throw new RepairFailed('Native summary counters require explicit clocks.');
                }
                foreach (34 === $m->number ? [0, 253] : [2, 7, 8, 253] as $n) {
                    $this->scalar($m, $n);
                }
                foreach (match ($m->number) {
                    18 => [254, 25, 26], 19 => [254], default => [1],
                } as $n) {
                    $this->scalar($m, $n, 0x84);
                }
                if (18 === $m->number) {
                    if (null !== $session) {
                        throw new RepairFailed('Counter SAVE recovery requires a sole Session.');
                    }
                    $session = ['index' => $index, 'm' => $m];
                } elseif (34 === $m->number) {
                    if (null !== $activity) {
                        throw new RepairFailed('Counter SAVE recovery requires a sole Activity.');
                    }
                    $activity = ['index' => $index, 'm' => $m];
                } else {
                    $laps[] = ['index' => $index, 'm' => $m];
                }
            } elseif (in_array($m->number, [101, 142, 225], true)) {
                throw new RepairFailed('Counter SAVE recovery does not replace pool, segment or set clocks.');
            }
        }
        if (1 !== $fileIds || null === $session || null === $activity || count($laps) > 4096) {
            throw new RepairFailed('Counter SAVE recovery requires one native Session and Activity.');
        }
        $s = $session['m'];
        $a = $activity['m'];
        $start = $s->integer(2);
        $elapsed = $s->integer(7);
        $timer = $s->integer(8);
        if (null === $start || $start < 0x10000000 || null === $elapsed || null === $timer || $timer <= 0
            || $a->integer(0) !== $timer || 1 !== $a->integer(1) || !in_array($s->integer(254), [null, 0], true)
            || !in_array($s->integer(25), [null, 0], true) || !in_array($s->integer(26), [null, count($laps)], true)) {
            throw new RepairFailed('Native counters and references must corroborate the complete sole Session.');
        }
        if ($minimumElapsed) {
            if (1 !== count($laps) || 0 !== $elapsed || [] !== $events || null !== $first
                || $s->integer(253) !== $start || $a->integer(253) !== $start || 0 !== $timer % 1000
                || $laps[0]['m']->integer(2) !== $start || $laps[0]['m']->integer(253) !== $start
                || 0 !== $laps[0]['m']->integer(7) || $laps[0]['m']->integer(8) !== $timer) {
                throw new RepairFailed('Minimum elapsed requires matching native timers, zero elapsed and frozen clocks without a contradictory trace.');
            }
            $elapsed = $timer;
        } elseif ($elapsed <= 0 || $elapsed !== $timer || 0 !== $elapsed % 1000) {
            throw new RepairFailed('SAVE recovery requires unchanged whole-second native unpaused counters.');
        }
        $finish = $start + intdiv($elapsed, 1000);
        if ($finish > 0xFFFFFFFE || (null !== $first && ($first < $start || $last > $finish))) {
            throw new RepairFailed('Native counters do not encompass the recorded observations.');
        }
        foreach ($events as $event) {
            if (1 !== count($events) || 0 !== $event->integer(0) || 0 !== $event->integer(1) || $event->integer(253) !== $start) {
                throw new RepairFailed('SAVE recovery cannot reinterpret native pause or STOP Events.');
            }
        }
        $changes = [];
        $next = $start;
        $sum = 0;
        foreach ($laps as $i => $lap) {
            $m = $lap['m'];
            $e = $minimumElapsed ? $elapsed : $m->integer(7);
            if ($m->integer(2) !== $next || null === $e || $e <= 0 || 0 !== $e % 1000 || $m->integer(8) !== $e
                || !in_array($m->integer(254), [null, $i], true)) {
                throw new RepairFailed('Native Laps must form the complete counter partition without lost segmentation.');
            }
            $next += intdiv($e, 1000);
            $sum += $e;
            if (null === $m->integer(253) || ($minimumElapsed && $m->integer(253) === $start)) {
                $changes[] = ['message' => $lap['index'], 'field' => 253, 'type' => 0x86, 'value' => $next];
            } elseif ($m->integer(253) !== $next) {
                throw new RepairFailed('An existing Lap SAVE contradicts its native counter endpoint.');
            }
            if ($minimumElapsed) {
                $changes[] = ['message' => $lap['index'], 'field' => 7, 'type' => 0x86, 'value' => $elapsed];
            }
        }
        if ([] !== $laps && ($sum !== $elapsed || $next !== $finish)) {
            throw new RepairFailed('Original Session counters must equal the complete original Lap totals.');
        }
        if ([] === $laps && (null !== $first || $s->integer(253) !== $start || $a->integer(253) !== $finish || [] !== $events)) {
            throw new RepairFailed('Without Laps or Records, native Activity SAVE must corroborate the frozen Session counter endpoint.');
        }
        $sessionSave = $s->integer(253);
        $activitySave = $a->integer(253);
        if (null === $sessionSave || null === $activitySave || $sessionSave < $start || $sessionSave >= $finish
            || ($activitySave !== $sessionSave && $activitySave !== $finish)) {
            throw new RepairFailed('Native summary SAVE clocks must identify a necessary corroborated correction.');
        }
        $changes[] = ['message' => $session['index'], 'field' => 253, 'type' => 0x86, 'value' => $finish];
        if ($minimumElapsed) {
            $changes[] = ['message' => $session['index'], 'field' => 7, 'type' => 0x86, 'value' => $elapsed];
        }
        if ($activitySave !== $finish) {
            $changes[] = ['message' => $activity['index'], 'field' => 253, 'type' => 0x86, 'value' => $finish];
            $local = $this->scalar($a, 5);
            if (null !== $local && $local >= 0x10000000) {
                $offset = $local - $activitySave;
                if ($offset < -43200 || $offset > 50400 || $finish + $offset < 0x10000000 || $finish + $offset > 0xFFFFFFFE) {
                    throw new RepairFailed('Original local clock offset cannot be retained.');
                }
                $changes[] = ['message' => $activity['index'], 'field' => 5, 'type' => 0x86, 'value' => $finish + $offset];
            }
        }
        $output = $file->apply($changes);

        return new RepairResult($output, ['mode' => 'normalize_summary_clock', 'rule' => $minimumElapsed ? self::MINIMUM_RULE : self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inference' => ['historical_accuracy' => 'not_proven', 'source' => $minimumElapsed ? 'matching_native_timer_lower_bound' : 'complete_native_counter_partition',
                'elapsed_policy' => $minimumElapsed ? 'minimum consistent elapsed; actual pauses and original finish remain unknown' : 'all native durations retained; counter endpoint used instead of contradictory summary SAVE'],
            'preservation' => 'native timers, Lap starts, physical Laps, Events and measurements retained; no STOP or pause invented', 'activity_validation' => 'not_performed']);
    }

    private function scalar(PhysicalMessage $m, int $number, int $type = 0x86): ?int
    {
        $seen = false;
        foreach ($m->fields as $f) {
            if ($number === $f->definition->fieldNumber) {
                if ($seen || $type !== $f->definition->baseType->definitionByte() || (0x86 === $type ? 4 : 2) !== $f->definition->size) {
                    throw new RepairFailed('Counter SAVE evidence requires unique scalar fields of the native clock or reference type.');
                }
                $seen = true;
            }
        }

        return $m->integer($number);
    }
}
