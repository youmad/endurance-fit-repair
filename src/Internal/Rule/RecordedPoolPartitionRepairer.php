<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Restores pool references only when native counts, distance and every timer corroborate all physical Lengths. */
final readonly class RecordedPoolPartitionRepairer
{
    public const string RULE = 'pool_references_from_complete_native_length_counters';

    public function repair(string $bytes): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Native pool partition recovery is bounded to 4 MiB.');
        }
        $file = EditableFitFile::read($bytes);
        $sessions = $activities = $laps = $lengths = [];
        $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (0 === $m->number && (++$fileIds > 1 || 0 !== $index || 4 !== $this->scalar($m, 0, 0x00))) {
                throw new RepairFailed('Native pool recovery requires one Activity FileId first.');
            }
            if (!in_array($m->number, [18, 19, 34, 101], true)) {
                continue;
            }
            if ($m->wasCompressed) {
                throw new RepairFailed('Native pool references require explicit summary and Length clocks.');
            }
            if (18 === $m->number) {
                $sessions[$index] = $m;
            } elseif (34 === $m->number) {
                $activities[$index] = $m;
            } elseif (19 === $m->number) {
                $laps[$index] = $m;
            } else {
                $lengths[$index] = $m;
            }
            if (count($sessions) > 1 || count($activities) > 1 || count($laps) > 4096 || count($lengths) > 4096) {
                throw new RepairFailed('Native pool reference evidence exceeds its bounded partition.');
            }
        }
        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($activities) || [] === $laps || [] === $lengths) {
            throw new RepairFailed('Native pool references require one Session, Activity and complete original Laps and Lengths.');
        }
        $s = reset($sessions);
        $a = reset($activities);
        $start = $this->scalar($s, 2);
        $timer = $this->scalar($s, 8);
        $pool = $this->scalar($s, 44, 0x84);
        if (5 !== $this->scalar($s, 5, 0x00) || 17 !== $this->scalar($s, 6, 0x00) || null === $start || $start < 0x10000000
            || null === $timer || $timer <= 0 || null === $pool || $pool <= 0 || 0 !== $this->scalar($s, 46, 0x00)
            || !in_array($this->scalar($s, 254, 0x84), [null, 0], true) || !in_array($this->scalar($s, 25, 0x84), [null, 0], true)
            || !in_array($this->scalar($s, 26, 0x84), [null, count($laps)], true)
            || !in_array($this->scalar($a, 1, 0x84), [null, 1], true) || !in_array($this->scalar($a, 0), [null, $timer], true)) {
            throw new RepairFailed('Native pool Session identity, pool size, Activity timer and complete Lap count must corroborate the original member.');
        }
        $rows = array_values($lengths);
        $positions = array_keys($lengths);
        $cursor = $activeTotal = $lapTimerTotal = 0;
        $previousEnd = null;
        $changes = $evidence = [];
        foreach ($laps as $position => $lap) {
            $ordinal = count($evidence);
            $from = $this->scalar($lap, 2);
            $elapsed = $this->scalar($lap, 7);
            $lapTimer = $this->scalar($lap, 8);
            $active = $this->scalar($lap, 40, 0x84);
            $count = $this->scalar($lap, 32, 0x84);
            if ($ordinal !== $this->scalar($lap, 254, 0x84) || null === $from || $from < 0x10000000
                || null === $elapsed || $elapsed <= 0 || null === $lapTimer || $lapTimer <= 0 || $lapTimer > $elapsed
                || null === $active || $active < 0 || (0 === $ordinal && $from !== $start)
                || (null !== $previousEnd && abs($from * 1000 - $previousEnd) > 1999)
                || (0 === $active ? !in_array($count, [null, 0, 1], true) : $count !== $active)
                || $this->scalar($lap, 9) !== $active * $pool) {
                throw new RepairFailed('Every native pool Lap must independently define an abutting interval, active count and exact pool distance.');
            }
            $count = max(1, $active);
            $part = array_slice($rows, $cursor, $count);
            if (count($part) !== $count || $this->scalar($part[0], 2) !== $from) {
                throw new RepairFailed('Physical Length counts and original first clocks must corroborate every native Lap.');
            }
            $sum = 0;
            foreach ($part as $i => $length) {
                $id = $this->scalar($length, 254, 0x84);
                $e = $this->scalar($length, 3);
                $t = $this->scalar($length, 4);
                $clock = $this->scalar($length, 2);
                if (null === $id || $id > 4095 || null === $e || $e <= 0 || $t !== $e
                    || (0 === $active ? 0 : 1) !== $this->scalar($length, 12, 0x00)
                    || null === $clock || $clock < $from || $clock * 1000 > $from * 1000 + $elapsed + 999) {
                    throw new RepairFailed('Native pool partitions require all original positive unpaused Length timers and consistent physical kinds.');
                }
                $sum += $t;
                if ($id !== $cursor + $i) {
                    $changes[] = ['message' => $positions[$cursor + $i], 'field' => 254, 'type' => 0x84, 'value' => $cursor + $i];
                }
            }
            if (abs($sum - $lapTimer) > 999) {
                throw new RepairFailed('Each native Lap timer must independently corroborate every original Length in its physical partition.');
            }
            if ($this->scalar($lap, 35, 0x84) !== $cursor) {
                $changes[] = ['message' => $position, 'field' => 35, 'type' => 0x84, 'value' => $cursor];
            }
            $this->restoreRepeatedClocks($part, array_slice($positions, $cursor, $count), $from, $elapsed, $changes);
            $evidence[] = ['lap_index' => $ordinal, 'first_physical_length' => $cursor, 'physical_length_count' => $count,
                'native_active_count' => $active, 'native_timer_ms' => $lapTimer, 'length_timer_sum_ms' => $sum];
            $cursor += $count;
            $activeTotal += $active;
            $lapTimerTotal += $lapTimer;
            $previousEnd = $from * 1000 + $elapsed;
        }
        if ($cursor !== count($lengths) || abs($lapTimerTotal - $timer) > 999 || $this->scalar($s, 9) !== $activeTotal * $pool
            || !in_array($this->scalar($s, 47, 0x84), [null, $activeTotal], true)) {
            throw new RepairFailed('The original Session must corroborate all physical Lengths, exact active distance and complete native timer totals.');
        }
        foreach ([254 => 0, 25 => 0, 26 => count($laps)] as $field => $value) {
            if (null === $this->scalar($s, $field, 0x84)) {
                $changes[] = ['message' => array_key_first($sessions), 'field' => $field, 'type' => 0x84, 'value' => $value];
            }
        }
        foreach ([0 => [0x86, $timer], 1 => [0x84, 1]] as $field => [$type, $value]) {
            if (null === $this->scalar($a, $field, $type)) {
                $changes[] = ['message' => array_key_first($activities), 'field' => $field, 'type' => $type, 'value' => $value];
            }
        }
        if ([] === $changes) {
            throw new RepairFailed('Native pool references and local repeated clocks already agree with the complete physical partition.');
        }
        $output = $file->apply($changes);

        return new RepairResult($output, ['mode' => 'restore_native_pool_partition', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'evidence' => $evidence, 'inference' => ['historical_accuracy' => 'not_proven',
                'policy' => 'native Lap counts, exact pool distances and each original timer corroborate every physical Length; restore indexes and first-Length references without dropping duplicate packets; repair repeated starts only when adjacent retained clocks identify one whole-second solution; unknown pauses and original millisecond clocks remain unknown'],
            'preservation' => 'every original Length, Lap, timer, measurement and Event retained; only references, proven local repeated starts and absent aggregate fields changed',
            'activity_validation' => 'not_performed']);
    }

    /** @param list<PhysicalMessage> $part
     * @param list<int>                $positions
     * @param list<array<string, int>> $changes
     */
    private function restoreRepeatedClocks(array $part, array $positions, int $start, int $elapsed, array &$changes): void
    {
        for ($i = 0; $i < count($part) - 1; ++$i) {
            $from = $this->scalar($part[$i], 2);
            if ($from !== $this->scalar($part[$i + 1], 2)) {
                continue;
            }
            $first = $i;
            while (isset($part[$i + 1]) && $from === $this->scalar($part[$i + 1], 2)) {
                ++$i;
            }
            if (!isset($part[$i + 1])) {
                throw new RepairFailed('Repeated native Length clocks require an independently retained following Length.');
            }
            $end = $this->scalar($part[$i + 1], 2);
            $total = 0;
            for ($j = $first; $j <= $i; ++$j) {
                $total += $this->scalar($part[$j], 3);
            }
            $low = max(0, ($end - $from) * 1000 - $total);
            $high = min(999, ($end - $from + 1) * 1000 - $total - 1);
            if ($low > $high) {
                throw new RepairFailed('Repeated Length clocks contradict their retained neighboring endpoint.');
            }
            $prefix = $this->scalar($part[$first], 3);
            for ($j = $first + 1; $j <= $i; ++$j) {
                $minimum = $from + intdiv($prefix + $low, 1000);
                $maximum = $from + intdiv($prefix + $high, 1000);
                if ($minimum !== $maximum || $minimum <= $from || $minimum * 1000 + $this->scalar($part[$j], 3) > $start * 1000 + $elapsed + 999) {
                    throw new RepairFailed('Native repeated clocks require one unique start inside the original Lap interval.');
                }
                $changes[] = ['message' => $positions[$j], 'field' => 2, 'type' => 0x86, 'value' => $minimum];
                $prefix += $this->scalar($part[$j], 3);
            }
        }
    }

    private function scalar(PhysicalMessage $m, int $number, int $type = 0x86): ?int
    {
        $seen = false;
        foreach ($m->fields as $f) {
            if ($number !== $f->definition->fieldNumber) {
                continue;
            }
            if ($seen || !in_array($f->definition->baseType->definitionByte(), 0x00 === $type ? [0x00, 0x02] : [$type], true)
                || (0x86 === $type ? 4 : (0x84 === $type ? 2 : 1)) !== $f->definition->size) {
                throw new RepairFailed('Native pool proof requires unique scalar clocks, counters and references.');
            }
            $seen = true;
        }

        return $m->integer($number);
    }
}
