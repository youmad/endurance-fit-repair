<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\ActivityHeartRateTail;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Restores overlapping Length starts from unchanged, independently indexed Lap partitions. */
final readonly class RecordedLengthBoundaryRepairer
{
    public const string RULE = 'length_starts_from_recorded_lap_partition';

    public function repair(string $bytes): RepairResult
    {
        [$member, $tail] = ActivityHeartRateTail::split($bytes);
        if ('' !== $tail) {
            return ActivityHeartRateTail::append($bytes, $tail, $this->repair($member));
        }
        $this->requireBoundedPool($bytes);
        $file = EditableFitFile::read($bytes);
        $lengths = $laps = $sessions = $activities = $timerClocks = [];
        $running = null;
        $fileIds = $timerEvents = 0;
        foreach ($file->messages as $index => $entry) {
            $number = $entry['raw']->globalMessageNumber();
            if (!in_array($number, [0, 18, 19, 21, 34, 101], true)) {
                continue;
            }
            $message = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);
            if ($message->wasCompressed) {
                throw new RepairFailed('Length boundary evidence requires uncompressed summaries and Lengths.');
            }
            if (21 === $number) {
                $kind = $this->scalar($message, 0, [0x00, 0x02], 1);
                if (null === $kind) {
                    throw new RepairFailed('An unknown Event cannot exclude an original pause.');
                }
                if (0 === $kind) {
                    $type = $this->scalar($message, 1, [0x00, 0x02], 1);
                    $time = $this->scalar($message, 253, [0x86], 4);
                    if (!in_array($type, [0, 1, 4], true) || ++$timerEvents > 4096
                        || null === $time || !in_array($this->scalar($message, 4, [0x02], 1), [null, 0], true)) {
                        throw new RepairFailed('Original timer Events must not contradict the unpaused duration partition.');
                    }
                    if (([] !== $timerClocks && $time < $timerClocks[array_key_last($timerClocks)]['time'])
                        || (0 === $type && null !== $running) || (0 !== $type && null === $running)) {
                        throw new RepairFailed('Original timer Events must form complete ordered spans.');
                    }
                    $running = 0 === $type ? $time : null;
                    $timerClocks[] = ['time' => $time, 'type' => $type];
                }
            } elseif (0 === $number) {
                ++$fileIds;
                if (0 !== $index || 4 !== $this->scalar($message, 0, [0x00, 0x02], 1)) {
                    throw new RepairFailed('Length boundaries require one Activity FileId first.');
                }
            } elseif (18 === $number) {
                $sessions[] = $message;
            } elseif (34 === $number) {
                $activities[] = $message;
            } else {
                $id = $this->scalar($message, 254, [0x84], 2);
                if (null === $id || $id > 4095) {
                    throw new RepairFailed('Length boundaries require explicit plain message indexes.');
                }
                $row = ['message' => $index, 'physical' => $message, 'index' => $id,
                    'start' => $this->scalar($message, 2, [0x86], 4),
                    'elapsed' => $this->scalar($message, 101 === $number ? 3 : 7, [0x86], 4),
                    'timer' => $this->scalar($message, 101 === $number ? 4 : 8, [0x86], 4)];
                if (null === $row['start'] || $row['start'] < 0x10000000 || null === $row['elapsed'] || $row['elapsed'] < 0
                    || $row['elapsed'] !== $row['timer']) {
                    throw new RepairFailed('Length boundaries require positive unchanged unpaused durations and absolute starts.');
                }
                if (101 === $number) {
                    $row['kind'] = $this->scalar($message, 12, [0x00, 0x02], 1);
                    if (!in_array($row['kind'], [0, 1], true) || isset($lengths[$id])
                        || (0 === $row['elapsed'] && 0 !== $row['kind'])
                        || ([] !== $lengths && $id <= array_key_last($lengths))) {
                        throw new RepairFailed('Length identities and types must form an ordered, unambiguous partition.');
                    }
                    $lengths[$id] = $row;
                } else {
                    $row['first'] = $this->scalar($message, 35, [0x84], 2);
                    $row['active_lengths'] = $this->scalar($message, 40, [0x84], 2);
                    if ($id !== count($laps) || $row['elapsed'] <= 0) {
                        throw new RepairFailed('Lap identities must select original Length partitions.');
                    }
                    $laps[] = $row;
                }
            }
            if (1 < $fileIds || 1 < count($sessions) || 1 < count($activities) || 4096 < count($lengths) || 4096 < count($laps)) {
                throw new RepairFailed('Length boundary recovery requires bounded evidence for one Session.');
            }
        }
        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($activities) || [] === $lengths || [] === $laps) {
            throw new RepairFailed('Complete indexed Lengths, Laps, Session and Activity are required.');
        }
        $session = $sessions[0];
        $elapsed = $this->scalar($session, 7, [0x86], 4);
        $timer = $this->scalar($session, 8, [0x86], 4);
        $start = $this->scalar($session, 2, [0x86], 4);
        foreach ($laps as &$lap) {
            if (null === $lap['first'] && 0 === $lap['active_lengths']) {
                $idle = array_filter($lengths, static fn (array $row): bool => 0 === $row['kind'] && $row['start'] === $lap['start'] && $row['elapsed'] === $lap['elapsed']);
                if (1 === count($idle)) {
                    $lap['first'] = array_key_first($idle);
                }
            }
            if (null === $lap['first']) {
                throw new RepairFailed('Each Lap requires an explicit first Length or one uniquely matching native idle Length.');
            }
        }
        unset($lap);
        if (null === $elapsed || null === $timer || null === $start || $start < 0x10000000
            || 5 !== $this->scalar($session, 5, [0x00, 0x02], 1) || 17 !== $this->scalar($session, 6, [0x00, 0x02], 1)
            || 0 !== $this->scalar($session, 254, [0x84], 2) || 0 !== $this->scalar($session, 25, [0x84], 2)
            || count($laps) !== $this->scalar($session, 26, [0x84], 2)
            || !in_array($this->scalar($activities[0], 1, [0x84], 2), [] === $timerClocks ? [1] : [null, 1], true)
            || ([] === $timerClocks && $timer !== $this->scalar($activities[0], 0, [0x86], 4))
            || $laps[0]['start'] !== $start || reset($lengths)['start'] !== $start
            || ([] === $timerClocks && (abs($elapsed - $timer) > 1 || array_sum(array_column($laps, 'elapsed')) !== $elapsed || abs(array_sum(array_column($lengths, 'elapsed')) - $elapsed) > 1))) {
            throw new RepairFailed('Original Session, Activity, Lap references and precise duration totals must agree.');
        }
        if ([] !== $timerClocks && (null !== $running || 0 !== $timerClocks[0]['type'] || abs($timerClocks[0]['time'] - $start) > 1)) {
            throw new RepairFailed('Original timer clocks do not corroborate the unchanged Session durations.');
        }
        $changes = $evidence = [];
        if ([] !== $timerClocks) {
            $this->corroboratePartitionCounters($session, $laps, $lengths, $timerClocks, $changes, $evidence);
        }
        $overlaps = [];
        $previous = null;
        foreach ($lengths as $id => $row) {
            if (null !== $previous && ($row['start'] - $previous['start']) * 1000 - $previous['elapsed'] <= -1000) {
                $overlaps[$id] = true;
            }
            $previous = $row;
        }
        $finalLength = $lengths[array_key_last($lengths)];
        $finalOverflow = [] !== $timerClocks
            && $finalLength['start'] * 1000 + $finalLength['elapsed'] >= ($timerClocks[array_key_last($timerClocks)]['time'] + 1) * 1000;
        if ([] === $overlaps && !$finalOverflow) {
            throw new RepairFailed('No original Length overlap exceeds its recorded one-second resolution.');
        }
        foreach ($laps as $index => $lap) {
            $next = $laps[$index + 1] ?? null;
            $until = $next['first'] ?? array_key_last($lengths) + 1;
            if (!isset($lengths[$lap['first']]) || $until <= $lap['first'] || $lengths[$lap['first']]['start'] !== $lap['start']) {
                throw new RepairFailed('Original Lap references and first Length clocks must agree.');
            }
            $part = array_filter($lengths, static fn (array $row): bool => $lap['first'] <= $row['index'] && $row['index'] < $until);
            if (abs(array_sum(array_column($part, 'elapsed')) - $lap['elapsed']) > ([] === $timerClocks ? 1 : 999)) {
                throw new RepairFailed('Each original Lap must be independently corroborated by its Length duration sum.');
            }
            if (null !== $lap['active_lengths'] && count(array_filter($part, static fn (array $row): bool => 1 === $row['kind'])) !== $lap['active_lengths']) {
                throw new RepairFailed('Every native Lap active-Length count must agree with its original indexed partition.');
            }
            if ([] !== $timerClocks && ([] !== array_intersect_key($part, $overlaps) || isset($overlaps[$until]))
                && count(array_filter($part, static fn (array $row): bool => 1 === $row['kind'])) === count($part)) {
                $covered = false;
                foreach ($timerClocks as $eventIndex => $event) {
                    if (0 === $event['type'] && isset($timerClocks[$eventIndex + 1]) && 0 !== $timerClocks[$eventIndex + 1]['type']
                        && $event['time'] <= $lap['start'] + 1 && $timerClocks[$eventIndex + 1]['time'] * 1000 >= $lap['start'] * 1000 + $lap['elapsed'] - 999) {
                        $covered = true;
                    }
                }
                if (!$covered) {
                    throw new RepairFailed('A repaired active Length partition must fit inside one recorded running span.');
                }
            }
            $proof = ['lap_start' => $lap['start'], 'lap_elapsed_ms' => $lap['elapsed'],
                'length_elapsed_sum_ms' => array_sum(array_column($part, 'elapsed')), 'next_lap_start' => $next['start'] ?? null,
                'lengths' => array_values(array_map(static fn (array $row): array => ['index' => $row['index'],
                    'sequence' => $row['physical']->sourceSequence, 'start' => $row['start'], 'elapsed_ms' => $row['elapsed']], $part))];
            $duplicates = [];
            $previous = null;
            foreach ($part as $id => $row) {
                if (null !== $previous && $previous['elapsed'] > 0 && $row['start'] === $previous['start']) {
                    $duplicates[$id] = true;
                }
                $previous = $row;
            }
            $lastPartLength = $part[array_key_last($part)];
            $finalLengthOverflow = null === $next && [] !== $timerClocks
                && $lastPartLength['start'] * 1000 + $lastPartLength['elapsed'] >= ($timerClocks[array_key_last($timerClocks)]['time'] + 1) * 1000;
            if ([] === $duplicates && [] !== $timerClocks
                && ([] !== array_intersect_key($part, $overlaps) || isset($overlaps[$until]) || $finalLengthOverflow)
                && $this->recoverCorroboratedDrift($lap, $part, $timerClocks, $changes, $evidence)) {
                continue;
            }
            if ([] !== $duplicates) {
                $end = $next['start'] ?? null;
                if (null === $end && [] !== $timerClocks) {
                    $stops = array_filter($timerClocks, static fn (array $event): bool => 0 !== $event['type'] && abs(($event['time'] - $lap['start']) * 1000 - $lap['elapsed']) <= 999);
                    if (1 === count($stops)) {
                        $end = reset($stops)['time'];
                    }
                }
                if (null === $end) {
                    throw new RepairFailed('A final repeated Length requires an independently corroborating STOP.');
                }
                if (abs(($end - $lap['start']) * 1000 - $lap['elapsed']) > 1000 && [] !== $timerClocks
                    && count(array_filter($part, static fn (array $row): bool => 1 === $row['kind'])) === count($part)) {
                    $stops = array_filter($timerClocks, static fn (array $event): bool => 0 !== $event['type'] && abs(($event['time'] - $lap['start']) * 1000 - $lap['elapsed']) <= 1000);
                    if (1 === count($stops)) {
                        $end = reset($stops)['time'];
                    }
                }
                [$low, $high, $prefixes] = $this->origins($lap, $part, $duplicates, $end);
                if ($low > $high && [] !== $timerClocks && abs(array_sum(array_column($part, 'elapsed')) - $lap['elapsed']) <= 1) {
                    $this->recoverLocalRepeatedStarts($lap, $part, $duplicates, $end, $changes, $evidence);
                }
                if ($low <= $high) {
                    foreach ($duplicates as $id => $_) {
                        $minimum = $lap['start'] + intdiv($prefixes[$id] + $low, 1000);
                        $maximum = $lap['start'] + intdiv($prefixes[$id] + $high, 1000);
                        if ($minimum <= $lengths[$id]['start']) {
                            throw new RepairFailed('All feasible origins must exclude every repeated Length start.');
                        }
                        $changes[] = $this->change($lengths[$id], $minimum);
                        $evidence[] = $proof + ['lap_index' => $index, 'length_index' => $id, 'basis' => 'repeated_start_in_exact_lap_partition',
                            'original_start' => $lengths[$id]['start'], 'admissible_starts' => [$minimum, $maximum], 'phase_ms' => [$low, $high], 'corroborating_end' => $end];
                    }
                }
                continue;
            }
            if (null === $next || !isset($overlaps[$until]) || count($part) < 2 || !isset($lengths[$until])
                || 0 !== $lengths[$until]['kind'] || $lengths[$until]['start'] !== $next['start']
                || $lengths[$until]['elapsed'] !== $next['elapsed']) {
                continue;
            }
            $last = array_key_last($part);
            [$low, $high, $prefixes] = $this->origins($lap, $part, [$last => true], $next['start']);
            $minimum = $lap['start'] + intdiv($prefixes[$last] + $low, 1000);
            $maximum = $lap['start'] + intdiv($prefixes[$last] + $high, 1000);
            if ($low <= $high && $minimum === $maximum && $maximum < $lengths[$last]['start']) {
                $changes[] = $this->change($lengths[$last], $maximum);
                $evidence[] = $proof + ['lap_index' => $index, 'length_index' => $last, 'basis' => 'retained_idle_lap_boundary',
                    'original_start' => $lengths[$last]['start'], 'admissible_starts' => [$minimum, $maximum], 'phase_ms' => [$low, $high]];
                continue;
            }
            [$low, $high] = $this->origins($lap, $part, [], null);
            $minimum = $lap['start'] + intdiv($lap['elapsed'] + $low, 1000);
            $maximum = $lap['start'] + intdiv($lap['elapsed'] + $high, 1000);
            if ($low <= $high && $minimum === $maximum && $next['start'] + 1 === $minimum) {
                $changes[] = $this->change($lengths[$until], $minimum);
                $changes[] = $this->change($next, $minimum);
                $evidence[] = $proof + ['lap_index' => $index, 'length_index' => $until, 'basis' => 'early_idle_lap_boundary',
                    'original_start' => $next['start'], 'admissible_starts' => [$minimum, $maximum], 'phase_ms' => [$low, $high]];
            }
        }
        if ([] === $changes) {
            throw new RepairFailed('No corroborated Length boundary correction is available.');
        }
        $starts = array_column($lengths, 'start', 'message');
        foreach ($changes as $change) {
            if (2 === $change['field']) {
                $starts[$change['message']] = $change['value'];
            }
        }
        $previous = null;
        foreach ($lengths as $row) {
            $row['start'] = $starts[$row['message']];
            if (null !== $previous && ($row['start'] - $previous['start']) * 1000 - $previous['elapsed'] <= -1000) {
                throw new RepairFailed('Recovered starts must resolve all Length overlaps without moving another boundary.');
            }
            $previous = $row;
        }
        $output = $file->apply($changes);

        return new RepairResult($output, ['mode' => 'preserve', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'evidence' => ['boundaries' => $evidence, 'timer_events' => $timerClocks, 'policy' => 'original Lap references and native active-time counters corroborate indexed partitions; without timer Events require precise totals within one millisecond; complete timer spans corroborate copied aggregate counters, counts and buffered idle clocks; repeated clocks use whole partitions or immediate retained boundaries; drift uses native active-time sums and complete START/STOP endpoints, retaining the most original clocks before minimizing displacement; reject unresolved overlaps'],
            'inference' => ['historical_accuracy' => 'not_proven', 'resolution_seconds' => 1],
            'preservation' => 'all original messages and fields retained outside selected Length/Lap starts, independently corroborated Lap counters and zero-duration idle Length counters',
            'activity_validation' => 'not_performed']);
    }

    /**
     * @param list<array<string, mixed>>        $laps
     * @param array<int, array<string, mixed>>  $lengths
     * @param list<array{time: int, type: int}> $clocks
     * @param list<array<string, int>>          $changes
     * @param list<array<string, mixed>>        $evidence
     */
    private function corroboratePartitionCounters(PhysicalMessage $session, array &$laps, array &$lengths, array $clocks, array &$changes, array &$evidence): void
    {
        $active = array_filter($lengths, static fn (array $row): bool => 1 === $row['kind']);
        $activeTotal = array_sum(array_column($active, 'elapsed'));
        $sessionActive = $this->scalar($session, 78, [0x86], 4);
        foreach ($laps as $index => &$lap) {
            $until = $laps[$index + 1]['first'] ?? array_key_last($lengths) + 1;
            $part = array_filter($lengths, static fn (array $row): bool => $lap['first'] <= $row['index'] && $row['index'] < $until);
            if ([] === $part || reset($part)['start'] !== $lap['start']) {
                throw new RepairFailed('Native counters cannot replace ambiguous indexed Length partitions.');
            }
            $total = array_sum(array_column($part, 'elapsed'));
            $partActive = count(array_filter($part, static fn (array $row): bool => 1 === $row['kind']));
            $ownActive = $this->scalar($lap['physical'], 70, [0x86], 4);
            if (0 === $index && count($part) === $partActive && count($part) < count($active)
                && null !== $sessionActive && abs($sessionActive - $activeTotal) <= 1
                && $lap['elapsed'] === $sessionActive && abs(($ownActive ?? -1) - $total) <= 1
                && count($active) === $lap['active_lengths']
                && count($lengths) === $this->scalar($lap['physical'], 32, [0x84], 2)) {
                foreach ([7, 8] as $field) {
                    $changes[] = $this->counterChange($lap, $field, 0x86, $total);
                }
                $evidence[] = ['lap_index' => $index, 'basis' => 'session_active_counter_copied_into_first_lap',
                    'original_elapsed_ms' => $lap['elapsed'], 'session_active_ms' => $sessionActive,
                    'lap_active_ms' => $ownActive, 'partition_elapsed_ms' => $total];
                $lap['elapsed'] = $lap['timer'] = $total;
            }
            if (count($part) === $partActive && null !== $ownActive && abs($ownActive - $total) <= 1 && abs($lap['elapsed'] - $total) <= 1) {
                foreach ([32 => count($part), 40 => $partActive] as $field => $value) {
                    $original = $this->scalar($lap['physical'], $field, [0x84], 2);
                    if (null !== $original && $original !== $value) {
                        $changes[] = $this->counterChange($lap, $field, 0x84, $value);
                        $evidence[] = ['lap_index' => $index, 'basis' => 'native_active_time_corroborates_unique_length_count',
                            'field' => $field, 'original_count' => $original, 'partition_count' => $value];
                    }
                }
                $lap['active_lengths'] = $partActive;
            }
            if (1 !== count($part) || 0 !== $partActive || 0 !== $lap['active_lengths']) {
                continue;
            }
            $id = array_key_first($part);
            $row = $lengths[$id];
            if (0 === $row['elapsed']) {
                $save = $this->scalar($row['physical'], 253, [0x86], 4);
                $span = false;
                foreach ($clocks as $eventIndex => $event) {
                    if (0 === $event['type'] && $event['time'] === $lap['start']
                        && isset($clocks[$eventIndex + 1]) && 0 !== $clocks[$eventIndex + 1]['type']
                        && $clocks[$eventIndex + 1]['time'] === $save
                        && abs(($save - $lap['start']) * 1000 - $lap['elapsed']) <= 999) {
                        $span = true;
                    }
                }
                if ($span) {
                    foreach ([3, 4] as $field) {
                        $changes[] = $this->counterChange($row, $field, 0x86, $lap['elapsed']);
                    }
                    $lengths[$id]['elapsed'] = $lengths[$id]['timer'] = $lap['elapsed'];
                    $evidence[] = ['lap_index' => $index, 'length_index' => $id, 'basis' => 'zero_idle_length_counter_from_original_lap_and_timer_span',
                        'lap_elapsed_ms' => $lap['elapsed'], 'start' => $lap['start'], 'save' => $save];
                }
                continue;
            }
            if ($row['elapsed'] !== $lap['elapsed']) {
                continue;
            }
            $matches = [];
            foreach ($clocks as $eventIndex => $event) {
                $next = $clocks[$eventIndex + 1] ?? null;
                if (0 !== $event['type'] && null !== $next && 0 === $next['type']
                    && $event['time'] < $lap['start'] && $lap['start'] < $next['time']
                    && abs(($next['time'] - $event['time']) * 1000 - $lap['elapsed']) <= 999
                    && isset($laps[$index + 1]) && $laps[$index + 1]['start'] === $next['time']) {
                    $matches[] = $event['time'];
                }
            }
            if (1 === count($matches)) {
                $changes[] = $this->change($lap, $matches[0]);
                $changes[] = $this->change($row, $matches[0]);
                $evidence[] = ['lap_index' => $index, 'length_index' => $id, 'basis' => 'buffered_idle_start_from_original_pause',
                    'original_start' => $lap['start'], 'pause_start' => $matches[0], 'idle_elapsed_ms' => $lap['elapsed']];
                $lap['start'] = $lengths[$id]['start'] = $matches[0];
            }
        }
        unset($lap);
    }

    /**
     * @param array<string, mixed>             $lap
     * @param array<int, array<string, mixed>> $part
     * @param array<int, bool>                 $duplicates
     * @param list<array<string, int>>         $changes
     * @param list<array<string, mixed>>       $evidence
     */
    private function recoverLocalRepeatedStarts(array $lap, array $part, array $duplicates, int $end, array &$changes, array &$evidence): void
    {
        $rows = array_values($part);
        for ($i = 0; $i < count($rows) - 1; ++$i) {
            if (!isset($duplicates[$rows[$i + 1]['index']])) {
                continue;
            }
            $group = [$rows[$i]['index'] => $rows[$i]];
            while (isset($rows[$i + 1]) && isset($duplicates[$rows[$i + 1]['index']])) {
                ++$i;
                $group[$rows[$i]['index']] = $rows[$i];
            }
            $local = ['start' => reset($group)['start'], 'elapsed' => array_sum(array_column($group, 'elapsed'))];
            [$low, $high, $prefixes] = $this->origins($local, $group, $duplicates, $rows[$i + 1]['start'] ?? $end);
            if ($low > $high) {
                throw new RepairFailed('Repeated Length clocks contradict their immediate retained boundaries.');
            }
            foreach (array_intersect_key($group, $duplicates) as $id => $row) {
                $minimum = $local['start'] + intdiv($prefixes[$id] + $low, 1000);
                $maximum = $local['start'] + intdiv($prefixes[$id] + $high, 1000);
                if ($minimum !== $maximum || $minimum <= $row['start']) {
                    throw new RepairFailed('Local Length boundaries must identify one unique repeated-start correction.');
                }
                $changes[] = $this->change($row, $minimum);
                $evidence[] = ['lap_index' => $lap['index'], 'length_index' => $id,
                    'basis' => 'repeated_start_between_retained_local_boundaries', 'original_start' => $row['start'],
                    'recovered_start' => $minimum, 'phase_ms' => [$low, $high], 'partition_elapsed_ms' => $lap['elapsed']];
            }
        }
    }

    /**
     * @param array<string, mixed>              $lap
     * @param array<int, array<string, mixed>>  $part
     * @param list<array{time: int, type: int}> $clocks
     * @param list<array<string, int>>          $changes
     * @param list<array<string, mixed>>        $evidence
     */
    private function recoverCorroboratedDrift(array $lap, array $part, array $clocks, array &$changes, array &$evidence): bool
    {
        $total = array_sum(array_column($part, 'elapsed'));
        if (count(array_filter($part, static fn (array $row): bool => 1 === $row['kind'])) !== count($part)
            || abs(($this->scalar($lap['physical'], 70, [0x86], 4) ?? -1) - $total) > 1) {
            return false;
        }
        $ends = [];
        foreach ($clocks as $index => $event) {
            if (0 === $event['type'] && $event['time'] === $lap['start'] && isset($clocks[$index + 1])
                && 0 !== $clocks[$index + 1]['type'] && abs(($clocks[$index + 1]['time'] - $lap['start']) * 1000 - $lap['elapsed']) <= 999) {
                $ends[] = $clocks[$index + 1]['time'];
            }
        }
        if (1 !== count($ends)) {
            return false;
        }
        [$baseLow, $baseHigh] = $this->origins($lap, $part, array_fill_keys(array_keys($part), true), $ends[0]);
        $excluded = [];
        $prefix = 0;
        foreach ($part as $id => $row) {
            $low = ($row['start'] - $lap['start']) * 1000 - $prefix;
            $high = $low + 999;
            if ($low > $baseHigh || $high < $baseLow) {
                $excluded[$id] = true;
            }
            $prefix += $row['elapsed'];
        }
        if ([] === $excluded || count($excluded) === count($part)) {
            return false;
        }
        [$low, $high, $prefixes] = $this->origins($lap, $part, $excluded, $ends[0]);
        if ($low > $high) {
            if ($baseLow > $baseHigh) {
                return false;
            }
            $low = $baseLow;
            $high = $baseHigh;
            $excluded = array_fill_keys(array_keys($part), true);
        }
        $pending = [];
        $selectedPhase = $low;
        $bestDistance = PHP_INT_MAX;
        $bestCount = PHP_INT_MAX;
        for ($phase = $low; $phase <= $high; ++$phase) {
            $distance = $count = 0;
            foreach (array_intersect_key($part, $excluded) as $id => $row) {
                $delta = abs($lap['start'] + intdiv($prefixes[$id] + $phase, 1000) - $row['start']);
                $distance += $delta;
                $count += $delta > 0 ? 1 : 0;
            }
            if ($count < $bestCount || ($count === $bestCount && $distance < $bestDistance)) {
                $bestCount = $count;
                $bestDistance = $distance;
                $selectedPhase = $phase;
            }
        }
        foreach (array_intersect_key($part, $excluded) as $id => $row) {
            $value = $lap['start'] + intdiv($prefixes[$id] + $selectedPhase, 1000);
            if ($value === $row['start']) {
                continue;
            }
            $pending[] = $this->change($row, $value);
        }
        array_push($changes, ...$pending);
        $evidence[] = ['lap_index' => $lap['index'], 'basis' => 'clock_drift_from_native_active_time_and_timer_endpoints',
            'length_indexes' => array_keys($excluded), 'partition_elapsed_ms' => $total, 'phase_ms' => [$low, $high],
            'selected_phase_ms' => $selectedPhase, 'selection_policy' => 'one shared feasible phase preserving the most original starts, then minimizing total displacement; earliest phase breaks ties', 'recorded_stop' => $ends[0]];

        return true;
    }

    private function requireBoundedPool(string $bytes): void
    {
        if (4_194_304 < strlen($bytes)) {
            throw new RepairFailed('Length boundary recovery is bounded to 4 MiB.');
        }
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new RepairFailed('Cannot allocate Length boundary input.');
        }
        $messages = $fields = $sessions = $lengths = 0;
        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill Length boundary input.');
            }
            $file = (new \Youmad\Endurance\Fit\Decoder\FitFileReader())->open(new \Youmad\Endurance\Fit\IO\ResourceFitInput($stream));
            foreach ($file->messages() as $raw) {
                if (!$raw instanceof \Youmad\Endurance\Fit\Raw\RawDataRecord) {
                    continue;
                }
                $fields += count($raw->standardFields()) + count($raw->developerFields());
                if (++$messages > 20_000 || $fields > 100_000) {
                    throw new RepairFailed('Length boundary evidence exceeds its buffered-message budget.');
                }
                if (101 === $raw->globalMessageNumber()) {
                    ++$lengths;
                } elseif (18 === $raw->globalMessageNumber()) {
                    $message = PhysicalMessage::fromRaw($raw, 0);
                    if (++$sessions > 1 || 5 !== $this->scalar($message, 5, [0x00, 0x02], 1) || 17 !== $this->scalar($message, 6, [0x00, 0x02], 1)) {
                        throw new RepairFailed('Length boundaries require one original pool swimming Session.');
                    }
                }
            }
            $file->trailer();
        } finally {
            fclose($stream);
        }
        if (1 !== $sessions || 0 === $lengths) {
            throw new RepairFailed('Length boundaries require a pool swimming Session with native Lengths.');
        }
    }

    /**
     * @param array<string, mixed>             $lap
     * @param array<int, array<string, mixed>> $part
     * @param array<int, bool>                 $excluded
     *
     * @return array{int, int, array<int, int>}
     */
    private function origins(array $lap, array $part, array $excluded, ?int $end): array
    {
        $low = 0;
        $high = 999;
        if (null !== $end) {
            $low = max($low, ($end - $lap['start']) * 1000 - $lap['elapsed']);
            $high = min($high, ($end - $lap['start'] + 1) * 1000 - $lap['elapsed'] - 1);
            $total = array_sum(array_column($part, 'elapsed'));
            $low = max($low, ($end - $lap['start']) * 1000 - $total);
            $high = min($high, ($end - $lap['start'] + 1) * 1000 - $total - 1);
        }
        $prefix = 0;
        $prefixes = [];
        foreach ($part as $id => $row) {
            $prefixes[$id] = $prefix;
            if (!isset($excluded[$id])) {
                $low = max($low, ($row['start'] - $lap['start']) * 1000 - $prefix);
                $high = min($high, ($row['start'] - $lap['start'] + 1) * 1000 - $prefix - 1);
            }
            $prefix += $row['elapsed'];
        }

        return [$low, $high, $prefixes];
    }

    /** @param array<string, mixed> $row
     * @return array{message: int, field: int, type: int, value: int}
     */
    private function change(array $row, int $start): array
    {
        return ['message' => $row['message'], 'field' => 2, 'type' => 0x86, 'value' => $start];
    }

    /** @param array<string, mixed> $row
     * @return array{message: int, field: int, type: int, value: int}
     */
    private function counterChange(array $row, int $field, int $type, int $value): array
    {
        return ['message' => $row['message'], 'field' => $field, 'type' => $type, 'value' => $value];
    }

    /** @param non-empty-list<int> $types */
    private function scalar(PhysicalMessage $message, int $number, array $types, int $size): ?int
    {
        $found = false;
        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }
            if ($found || !in_array($field->definition->baseType->definitionByte(), $types, true) || $size !== $field->definition->size) {
                throw new RepairFailed('Length boundary evidence contains an ambiguous physical field.');
            }
            $found = true;
        }

        return $message->integer($number);
    }
}
