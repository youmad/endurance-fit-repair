<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Copies missing fields between native whole-session summaries with corroborating clocks and timers. */
final readonly class RecordedSingleLapFieldsRepairer
{
    public const string RULE = 'recorded_single_lap_missing_fields';
    public const string STOP_TRACE_RULE = 'missing_timers_from_native_stop_trace';
    public const string EXTENT_RULE = 'frozen_summaries_include_recorded_observed_extent';

    public function repair(string $bytes, bool $missingTimers = false, bool $observedExtent = false): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Single-Lap field propagation requires a bounded FIT member.');
        }
        $file = EditableFitFile::read($bytes);
        $summaries = [];
        $first = $last = null;
        $stops = [];
        $timerEvents = $sports = [];
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (20 === $m->number) {
                $t = $m->integer(253);
                if (null === $t || $t < 0x10000000 || (null !== $last && $t < $last)) {
                    throw new RepairFailed('Single-Lap field propagation requires ordered absolute Records.');
                }
                $first ??= $t;
                $last = $t;
            } elseif (in_array($m->number, [18, 19, 34], true)) {
                if (isset($summaries[$m->number]) || $m->wasCompressed) {
                    throw new RepairFailed('Single-Lap field propagation requires sole explicit native summaries.');
                }
                $seen = [];
                foreach ($m->fields as $field) {
                    $n = $field->definition->fieldNumber;
                    $type = 34 === $m->number ? match ($n) {
                        0, 5, 253 => 0x86, 1 => 0x84, default => null,
                    }
                    : match ($n) {
                        2, 7, 8, 253 => 0x86, 254 => 0x84, 25, 26, 70 => 18 === $m->number ? 0x84 : null, default => null,
                    };
                    if (null !== $type && (isset($seen[$n]) || $type !== $field->definition->baseType->definitionByte()
                        || (0x86 === $type ? 4 : 2) !== $field->definition->size)) {
                        throw new RepairFailed('Native summary propagation requires scalar clocks and counters.');
                    }
                    $seen[$n] = true;
                }
                $summaries[$m->number] = ['index' => $index, 'message' => $m];
            } elseif (21 === $m->number && 0 === $m->integer(0)) {
                $timerEvents[] = $m;
                if (in_array($m->integer(1), [1, 4, 8, 9], true)) {
                    $stops[] = $m->integer(253);
                }
            } elseif (12 === $m->number && null !== $m->integer(0)) {
                $sports[] = $m->integer(0);
            }
        }
        if (3 !== count($summaries) || null === $first || null === $last) {
            throw new RepairFailed('Missing fields require one original Lap, Session, Activity and Records.');
        }
        $session = $summaries[18]['message'];
        $lap = $summaries[19]['message'];
        $activity = $summaries[34]['message'];
        if ($missingTimers) {
            return $this->missingTimers($file, $summaries, $timerEvents, $first, $last);
        }
        if ($observedExtent) {
            return $this->observedExtent($file, $summaries, $timerEvents, $first, $last);
        }
        if (null === $session->integer(2) && null === $session->integer(7) && null === $session->integer(8)
            && null === $lap->integer(2) && null === $lap->integer(7) && null === $lap->integer(8)) {
            return $this->emptySummaries($file, $summaries, $timerEvents, $sports, $first, $last);
        }
        $start = $session->integer(2);
        $save = $session->integer(253);
        $elapsed = $session->integer(7);
        $timer = $session->integer(8) ?? $lap->integer(8);
        $wholeActivityTimer = null === $timer && null !== $elapsed && $elapsed > 0 && $activity->integer(0) === $elapsed
            && null !== $start && $first === $start && null !== $save && $lap->integer(253) === $save
            && abs(($save - $start) * 1000 - $elapsed) <= 1000 && abs($last - $save) <= 1
            && 1 === count($timerEvents) && 0 === $timerEvents[0]->integer(1) && $timerEvents[0]->integer(253) === $start;
        if ($wholeActivityTimer) {
            $timer = $activity->integer(0);
        }
        $copiedElapsedTimer = null !== $timer && null !== $elapsed && $activity->integer(0) === $elapsed
            && $elapsed > $timer && null !== $lap->integer(7) && abs($lap->integer(7) - $timer) <= 999
            && $first === $start && abs(($last - $start) * 1000 - $timer) <= 999;
        if (null === $elapsed && null !== $start && null !== $save && null !== $timer
            && $lap->integer(2) === $start && $lap->integer(253) === $save && $last === $save
            && $activity->integer(0) === $timer && $activity->integer(253) === $save
            && abs(($save - $start) * 1000 - $timer) <= 999) {
            $elapsed = max(($save - $start) * 1000, $timer);
        }
        if (null === $start || $start < 0x10000000 || null === $save || $save <= $start || null === $timer || $timer <= 0
            || (!$wholeActivityTimer && $lap->integer(8) !== $timer) || !in_array($activity->integer(1), [null, 1], true)
            || !in_array($session->integer(26), [null, 1], true) || !in_array($session->integer(254), [null, 0], true)
            || (null !== $lap->integer(2) && $lap->integer(2) !== $start)
            || $first < $start || $last > $save || (null !== $activity->integer(0) && $activity->integer(0) !== $timer && !$copiedElapsedTimer)) {
            throw new RepairFailed('Original timers and native references must confirm one complete whole-session Lap.');
        }
        $firstIndex = $session->integer(25) ?? $lap->integer(254) ?? 0;
        if ($firstIndex > 4095 || (null !== $lap->integer(254) && $lap->integer(254) !== $firstIndex)
            || (null !== $session->integer(70) && $session->integer(70) !== $firstIndex)) {
            throw new RepairFailed('Native single-Lap references disagree.');
        }
        $changes = [];
        if ($copiedElapsedTimer) {
            $changes[] = ['message' => $summaries[34]['index'], 'field' => 0, 'type' => 0x86, 'value' => $timer];
        }
        $add = static function (int $number, int $field, int $type, int $value) use (&$changes, $summaries): void {
            if (null === $summaries[$number]['message']->integer($field)) {
                $changes[] = ['message' => $summaries[$number]['index'], 'field' => $field, 'type' => $type, 'value' => $value];
            }
        };
        if (null !== $elapsed && $elapsed >= $timer && abs(($save - $start) * 1000 - $elapsed) <= 1000
            && (null === $activity->integer(253) || $activity->integer(253) === $save)
            && (null === $lap->integer(253) || ($lap->integer(253) >= $last && $lap->integer(253) <= $save))) {
            // Copy missing bounds and elapsed only. Existing conflicting counters are handled separately.
            $add(19, 2, 0x86, $start);
            $add(19, 7, 0x86, $elapsed);
            if ($wholeActivityTimer) {
                $add(19, 8, 0x86, $timer);
            }
            $add(19, 253, 0x86, $save);
            $add(19, 254, 0x84, $firstIndex);
            $add(18, 7, 0x86, $elapsed);
            $add(18, 8, 0x86, $timer);
            $add(18, 254, 0x84, 0);
            $add(18, 25, 0x84, $firstIndex);
            $add(18, 26, 0x84, 1);
            $add(34, 1, 0x84, 1);
        }
        if (null === $activity->integer(253) && $lap->integer(253) === $save && in_array($save, $stops, true)) {
            $add(34, 253, 0x86, $save);
            $add(34, 0, 0x86, $timer);
        }
        if ([] === $changes) {
            throw new RepairFailed('No missing single-Lap field has independently corroborated native evidence.');
        }
        $output = $file->apply($changes);

        return new RepairResult($output, [
            'mode' => $copiedElapsedTimer ? 'normalize_recorded_durations' : 'preserve', 'rule' => self::RULE, 'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes, 'evidence' => ['session_start' => $start, 'session_save' => $save, 'unchanged_timer_ms' => $timer,
                'policy' => 'propagate missing fields from native sole-summary clocks and matching timers; an Activity timer copied from elapsed may be corrected only when the original Session/Lap timers and whole Record span corroborate each other; no new Lap, measurement, pause or Event'],
            'activity_validation' => 'not_performed',
        ]);
    }

    /**
     * @param array<int, array{index: int, message: PhysicalMessage}> $summaries
     * @param list<PhysicalMessage>                                   $events
     */
    private function observedExtent(EditableFitFile $file, array $summaries, array $events, int $first, int $last): RepairResult
    {
        $s = $summaries[18]['message'];
        $l = $summaries[19]['message'];
        $a = $summaries[34]['message'];
        $start = $s->integer(2);
        $elapsed = $s->integer(7);
        $timer = $s->integer(8);
        $activityExtent = null !== $start && $a->integer(253) === $last && $a->integer(0) === ($last - $start) * 1000
            && $elapsed === $timer;
        if (null === $start || $start < 0x10000000 || null === $elapsed || null === $timer || $timer <= 0 || $elapsed < $timer
            || $l->integer(2) !== $start || $l->integer(7) !== $elapsed || $l->integer(8) !== $timer || ($a->integer(0) !== $timer && !$activityExtent)
            || $s->integer(253) !== $start || $l->integer(253) !== $start || !in_array($a->integer(253), [$start, $last], true)
            || $first < $start || $first > $start + 1 || ($last - $start) * 1000 <= $elapsed || ($last - $start) * 1000 > 0xFFFFFFFE
            || 1 !== $a->integer(1) || !in_array($s->integer(26), [null, 1], true) || !in_array($s->integer(25), [null, 0], true)
            || !in_array($s->integer(254), [null, 0], true) || !in_array($l->integer(254), [null, 0], true)
            || count($events) > 1 || ([] !== $events && (0 !== $events[0]->integer(1) || $events[0]->integer(253) !== $start))) {
            throw new RepairFailed('Observed extent requires matching Lap/Session timers, a corroborating Activity counter, frozen native bounds and no contradictory STOP or pause trace.');
        }
        $this->assertIntervalMetadata($file);
        $local = $a->integer(5);
        if (null !== $local && ($local < 0x10000000 || $local - $last < -43200 || $local - $last > 50400 || 0 !== ($local - $last) % 900)) {
            throw new RepairFailed('The unchanged original local clock must remain compatible with the observed UTC endpoint.');
        }
        $changes = [];
        foreach ([18, 19] as $number) {
            $changes[] = ['message' => $summaries[$number]['index'], 'field' => 7, 'type' => 0x86, 'value' => ($last - $start) * 1000];
            $changes[] = ['message' => $summaries[$number]['index'], 'field' => 253, 'type' => 0x86, 'value' => $last];
        }
        if ($a->integer(253) !== $last) {
            $changes[] = ['message' => $summaries[34]['index'], 'field' => 253, 'type' => 0x86, 'value' => $last];
        }
        $output = $file->apply($changes);

        return new RepairResult($output, ['mode' => 'normalize_summary_clock', 'rule' => self::EXTENT_RULE,
            'input_sha256' => hash('sha256', $file->bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inference' => ['historical_accuracy' => 'not_proven', 'source' => 'native_summary_start_counters_and_observation_extent',
                'policy' => 'minimum observed elapsed; original active timers and local clock unchanged; actual finish and unrecorded pauses unknown; no STOP invented'],
            'evidence' => ['unchanged_lap_session_timer_ms' => $timer, 'unchanged_activity_timer_ms' => $a->integer(0),
                'activity_counter_matches_observed_extent' => $activityExtent],
            'preservation' => 'all native timers, physical Laps, measurements, Events and local clock retained', 'activity_validation' => 'not_performed']);
    }

    /**
     * @param array<int, array{index: int, message: PhysicalMessage}> $summaries
     * @param list<PhysicalMessage>                                   $events
     */
    private function missingTimers(EditableFitFile $file, array $summaries, array $events, int $first, int $last): RepairResult
    {
        $s = $summaries[18]['message'];
        $l = $summaries[19]['message'];
        $a = $summaries[34]['message'];
        $start = $s->integer(2);
        $save = $s->integer(253);
        $elapsed = $s->integer(7);
        if (null === $start || $start < 0x10000000 || null === $save || null === $elapsed || $elapsed <= 0
            || $first !== $start || $last > $save || abs(($save - $start) * 1000 - $elapsed) > 999
            || $l->integer(2) !== $start || $l->integer(253) !== $save || $l->integer(7) !== $elapsed || $a->integer(253) !== $save
            || null !== $l->integer(8) || null !== $s->integer(8) || null !== $a->integer(0) || 1 !== $a->integer(1)
            || !in_array($s->integer(26), [null, 1], true) || !in_array($s->integer(25), [null, 0], true)
            || !in_array($s->integer(254), [null, 0], true) || !in_array($l->integer(254), [null, 0], true)
            || [] === $events || count($events) > 4096 || !in_array($events[0]->integer(1), [1, 4], true)) {
            throw new RepairFailed('Missing timers require matching original whole-Lap clocks and a native trace beginning with STOP.');
        }
        $activeStart = $previous = $start;
        $timer = 0;
        foreach ($events as $event) {
            $seen = [];
            foreach ($event->fields as $f) {
                $n = $f->definition->fieldNumber;
                $types = match ($n) {
                    0, 1 => [0x00, 0x02],
                    4 => [0x02],
                    253 => [0x86],
                    default => null,
                };
                if (null !== $types && (isset($seen[$n]) || !in_array($f->definition->baseType->definitionByte(), $types, true)
                    || (253 === $n ? 4 : 1) !== $f->definition->size)) {
                    throw new RepairFailed('Native timer transitions require unique scalar clocks and kinds.');
                }
                $seen[$n] = true;
            }
            $t = $event->integer(253);
            $type = $event->integer(1);
            if (null === $t || $t < $previous || $t > $save || !in_array($event->integer(4), [null, 0], true)
                || !in_array($type, [0, 1, 4], true) || (0 === $type ? null !== $activeStart : null === $activeStart)) {
                throw new RepairFailed('Missing counters require a complete alternating native STOP/START trace.');
            }
            if (0 === $type) {
                $activeStart = $t;
            } else {
                $timer += ($t - $activeStart) * 1000;
                $activeStart = null;
            }
            $previous = $t;
        }
        if (null !== $activeStart || $timer <= 0 || $timer > $elapsed) {
            throw new RepairFailed('An open or contradictory timer trace cannot fill all missing counters.');
        }
        $this->assertIntervalMetadata($file);
        $changes = [];
        foreach ([18 => [8 => [0x86, $timer], 254 => [0x84, 0], 25 => [0x84, 0], 26 => [0x84, 1]],
            19 => [8 => [0x86, $timer], 254 => [0x84, 0]], 34 => [0 => [0x86, $timer]]] as $number => $values) {
            foreach ($values as $n => [$type, $value]) {
                if (null === $summaries[$number]['message']->integer($n)) {
                    $changes[] = ['message' => $summaries[$number]['index'], 'field' => $n, 'type' => $type, 'value' => $value];
                }
            }
        }
        $output = $file->apply($changes);

        return new RepairResult($output, ['mode' => 'preserve', 'rule' => self::STOP_TRACE_RULE,
            'input_sha256' => hash('sha256', $file->bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inference' => ['historical_accuracy' => 'not_proven', 'source' => 'identical_native_summary_starts_first_record_and_complete_stop_trace',
                'policy' => 'initial active interval begins at both native summary starts before the first STOP; original pause transitions retained; no START or STOP Event invented'],
            'preservation' => 'only missing timer counters and references filled; native elapsed, clocks, Events, Laps and measurements retained', 'activity_validation' => 'not_performed']);
    }

    private function assertIntervalMetadata(EditableFitFile $file): void
    {
        $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (0 === $m->number && (++$fileIds > 1 || 0 !== $index || !in_array($m->integer(0), [null, 4], true))) {
                throw new RepairFailed('Native interval evidence requires one original Activity FileId first.');
            }
            if (in_array($m->number, [101, 142, 225], true) || (21 === $m->number && null === $m->integer(0))) {
                throw new RepairFailed('Native interval evidence cannot replace detailed segmentation or ambiguous Events.');
            }
            $types = match ($m->number) {
                0 => [0 => [0x00, 0x02]],
                20 => [253 => [0x86]],
                21 => [0 => [0x00, 0x02], 1 => [0x00, 0x02], 4 => [0x02], 253 => [0x86]],
                default => [],
            };
            $seen = [];
            foreach ($m->fields as $f) {
                $n = $f->definition->fieldNumber;
                if (isset($types[$n])) {
                    if (isset($seen[$n]) || !in_array($f->definition->baseType->definitionByte(), $types[$n], true)
                        || (0x86 === $types[$n][0] ? 4 : 1) !== $f->definition->size) {
                        throw new RepairFailed('Native interval evidence requires unique scalar clocks and kinds.');
                    }
                    $seen[$n] = true;
                }
            }
        }
        if (1 !== $fileIds) {
            throw new RepairFailed('Native interval evidence requires an original Activity FileId.');
        }
    }

    /**
     * @param array<int, array{index: int, message: PhysicalMessage}> $summaries
     * @param list<PhysicalMessage>                                   $events
     * @param list<int>                                               $sports
     */
    private function emptySummaries(EditableFitFile $file, array $summaries, array $events, array $sports, int $first, int $last): RepairResult
    {
        $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (0 === $m->number && (++$fileIds > 1 || 0 !== $index || 4 !== $m->integer(0))) {
                throw new RepairFailed('Empty summary recovery requires one original Activity FileId first.');
            }
            if (in_array($m->number, [101, 142, 225], true) || (21 === $m->number && null === $m->integer(0))) {
                throw new RepairFailed('Pool, segment, set or ambiguous Event evidence cannot be replaced by one timer interval.');
            }
            $types = match ($m->number) {
                0, 12 => [0 => [0x00, 0x02]],
                20 => [253 => [0x86]],
                21 => [0 => [0x00, 0x02], 1 => [0x00, 0x02], 4 => [0x02], 253 => [0x86]],
                default => [],
            };
            $seen = [];
            foreach ($m->fields as $f) {
                $n = $f->definition->fieldNumber;
                if (isset($types[$n])) {
                    if (isset($seen[$n]) || !in_array($f->definition->baseType->definitionByte(), $types[$n], true)
                        || (0x86 === $types[$n][0] ? 4 : 1) !== $f->definition->size) {
                        throw new RepairFailed('Empty summary evidence requires unique scalar native fields.');
                    }
                    $seen[$n] = true;
                }
            }
        }
        $session = $summaries[18]['message'];
        $lap = $summaries[19]['message'];
        $activity = $summaries[34]['message'];
        if (1 !== $fileIds || count($events) < 2 || count($events) > 4096 || count(array_unique($sports)) > 1
            || !in_array($session->integer(26), [null, 1], true) || !in_array($session->integer(25), [null, 0], true)
            || !in_array($session->integer(254), [null, 0], true) || !in_array($lap->integer(254), [null, 0], true)
            || 1 !== $activity->integer(1) || null === $activity->integer(0)) {
            throw new RepairFailed('Empty native summaries require a complete sole-Lap timer trace and Activity total.');
        }
        $start = $finish = $activeStart = $previous = null;
        $active = 0;
        foreach ($events as $event) {
            $t = $event->integer(253);
            $type = $event->integer(1);
            if (null === $t || $t < 0x10000000 || (null !== $previous && $t < $previous)
                || !in_array($event->integer(4), [null, 0], true) || !in_array($type, [0, 1, 4], true)) {
                throw new RepairFailed('Every native timer transition must retain an ordered scalar clock and one timer group.');
            }
            if (0 === $type) {
                if (null !== $activeStart) {
                    throw new RepairFailed('Conflicting native START transitions cannot establish empty summary counters.');
                }
                $start ??= $t;
                $activeStart = $t;
            } elseif (null !== $activeStart) {
                $active += ($t - $activeStart) * 1000;
                $finish = $t;
                $activeStart = null;
            } elseif (null === $finish || $t > $finish + 2) {
                throw new RepairFailed('An unmatched STOP cannot establish a complete timer interval.');
            }
            $previous = $t;
        }
        $timer = $activity->integer(0);
        if (null === $finish || null !== $activeStart || $finish <= $start || $first < $start || $first > $start + 10
            || $last > $finish || $last < $finish - 10 || $timer <= 0 || abs($active - $timer) > 999
            || $timer > ($finish - $start) * 1000 || null === $activity->integer(253) || $activity->integer(253) < $finish
            || !in_array($session->integer(253), [null, $finish], true) || !in_array($lap->integer(253), [null, $finish], true)) {
            throw new RepairFailed('Activity timer, complete native transitions and Records must corroborate the sole interval.');
        }
        $changes = [];
        foreach ([18, 19] as $number) {
            $values = [2 => [0x86, $start], 253 => [0x86, $finish], 7 => [0x86, ($finish - $start) * 1000], 8 => [0x86, $timer], 254 => [0x84, 0]];
            if (18 === $number) {
                $values += [25 => [0x84, 0], 26 => [0x84, 1], 5 => [0x00, $sports[0] ?? 0]];
            }
            foreach ($values as $n => [$type, $value]) {
                if (null === $summaries[$number]['message']->integer($n)) {
                    $changes[] = ['message' => $summaries[$number]['index'], 'field' => $n, 'type' => $type, 'value' => $value];
                }
            }
        }
        $output = $file->apply($changes);

        return new RepairResult($output, ['mode' => 'preserve', 'rule' => self::RULE, 'input_sha256' => hash('sha256', $file->bytes),
            'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inference' => ['historical_accuracy' => 'not_proven', 'source' => 'complete_native_timer_trace_and_original_activity_timer',
                'sport_source' => null !== $session->integer(5) ? 'original_session' : ([] === $sports ? 'generic_placeholder_original_sport_unknown' : 'native_sport')],
            'preservation' => 'only missing fields filled in the existing sole Lap and Session; native Activity timer, all physical messages, measurements and pause Events retained',
            'activity_validation' => 'not_performed']);
    }
}
