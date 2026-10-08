<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Explicit clock inference; native Record clocks and duration counters are authoritative. */
final readonly class SummaryClockReconciler
{
    public const string RULE = 'summary_start_clocks_reconciled';

    public const string JOINT_RULE = 'summary_start_clocks_jointly_reconciled';

    public function repair(string $bytes, bool $joint = false): RepairResult
    {
        if (67_108_864 < strlen($bytes)) {
            throw new RepairFailed('Summary clock reconciliation is bounded to 64 MiB.');
        }
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new RepairFailed('Cannot allocate summary clock input.');
        }
        $laps = $sessions = [];
        $firstRecord = $lastRecord = null;
        $ordinal = $records = $activities = $fileIds = 0;
        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill summary clock input.');
            }
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;
            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Summary clock reconciliation requires one complete supported FIT member.');
            }
            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }
                $number = $raw->globalMessageNumber();
                if (0 === $ordinal && 0 !== $number) {
                    throw new RepairFailed('Summary clock reconciliation requires the original Activity FileId first.');
                }
                if (in_array($number, [0, 18, 19, 20, 34], true)) {
                    $message = PhysicalMessage::fromRaw($raw, 0);
                    if (0 === $number) {
                        ++$fileIds;
                        if (4 !== $this->scalar($message, 0, 0, 1)) {
                            throw new RepairFailed('Summary clock reconciliation requires an Activity FileId.');
                        }
                    } elseif (20 === $number) {
                        $timestamp = $this->scalar($message, 253, 0x86, 4);
                        if (null === $timestamp || $timestamp < 0x10000000 || (null !== $lastRecord && $timestamp < $lastRecord)) {
                            throw new RepairFailed('Original Records must have usable, ordered absolute timestamps.');
                        }
                        $firstRecord ??= $timestamp;
                        $lastRecord = $timestamp;
                        ++$records;
                    } elseif (34 === $number) {
                        ++$activities;
                        if (1 !== $this->scalar($message, 1, 0x84, 2)) {
                            throw new RepairFailed('Original Activity must explicitly reference one Session.');
                        }
                    } else {
                        if ($raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Summary clocks require uncompressed summaries with existing scalar start fields.');
                        }
                        $entry = ['message' => $ordinal, 'sequence' => $raw->sequence(), 'number' => $number,
                            'word' => FitArchitecture::LittleEndian === $raw->definition()->architecture ? 'V' : 'N',
                            'index' => $this->scalar($message, 254, 0x84, 2),
                            'start' => $this->scalar($message, 2, 0x86, 4),
                            'timestamp' => $this->scalar($message, 253, 0x86, 4),
                            'elapsed' => $this->scalar($message, 7, 0x86, 4),
                            'timer' => $this->scalar($message, 8, 0x86, 4)];
                        $position = $raw->byteOffset() + 1;
                        foreach ($raw->standardFields() as $field) {
                            if (2 === $field->definition->fieldNumber) {
                                $entry['offset'] = $position;
                            }
                            $position += $field->definition->size;
                        }
                        if (18 === $number) {
                            $entry['first_lap'] = $this->scalar($message, 25, 0x84, 2);
                            $entry['num_laps'] = $this->scalar($message, 26, 0x84, 2);
                            $sessions[] = $entry;
                        } else {
                            $laps[] = $entry;
                        }
                    }
                }
                if (count($sessions) > 1 || count($laps) > 4096 || $activities > 1 || $fileIds > 1) {
                    throw new RepairFailed('Summary clock reconciliation requires one Session/Activity and at most 4096 original Laps.');
                }
                ++$ordinal;
            }
            $file->trailer();
        } finally {
            fclose($stream);
        }
        if (1 !== count($sessions) || 1 !== $activities || 1 !== $fileIds || [] === $laps || $records < 2 || null === $firstRecord || null === $lastRecord) {
            throw new RepairFailed('Complete original summaries and at least two ordered Records are required.');
        }
        $session = $sessions[0];
        if (0 !== $session['index'] || 0 !== $session['first_lap'] || count($laps) !== $session['num_laps']) {
            throw new RepairFailed('Original Session must explicitly reference every sequential Lap once.');
        }
        $elapsedSum = $timerSum = 0;
        foreach ([$session, ...$laps] as $entry) {
            if (null === $entry['start'] || $entry['start'] < 0x10000000 || null === $entry['timestamp']
                || null === $entry['elapsed'] || $entry['elapsed'] <= 0 || null === $entry['timer']
                || ($joint ? $entry['timer'] - $entry['elapsed'] >= 1000 : $entry['timer'] > $entry['elapsed']) || !isset($entry['offset'])) {
                throw new RepairFailed('Original summary identities, clocks and native elapsed/timer counters must be complete.');
            }
        }
        foreach ($laps as $index => $lap) {
            if ($lap['index'] !== $index || ($index > 0 && $lap['sequence'] <= $laps[$index - 1]['sequence'])) {
                throw new RepairFailed('Lap identities and physical order must be explicit and sequential.');
            }
            $elapsedSum += $lap['elapsed'];
            $timerSum += $lap['timer'];
        }
        if (abs($elapsedSum - $session['elapsed']) > 1000 || abs($timerSum - $session['timer']) > 1000) {
            throw new RepairFailed('Native Lap/Session duration sums already contradict the SDK tolerance.');
        }

        // SDK containment and abutment use whole seconds, while native counters remain exact.
        if ($joint) {
            $solutions = [];
            for ($start = $session['start'] - 1; $start <= $session['start'] + 1; ++$start) {
                if ($start < 0x10000000 || $start > $firstRecord + 1 || $start + intdiv($session['elapsed'], 1000) < $lastRecord - 1 || $start > $session['timestamp']) {
                    continue;
                }
                try {
                    $solution = $this->lapSolution($laps, $start, $start + intdiv($session['elapsed'], 1000), true);
                } catch (RepairFailed) {
                    continue;
                }
                $solutions[] = $solution + ['start' => $start, 'total_cost' => $solution['cost'] + abs($start - $session['start'])];
            }
            if ([] === $solutions) {
                throw new RepairFailed('No compatible joint summary clock solution exists within one second of every original start.');
            }
            $cost = min(array_column($solutions, 'total_cost'));
            $best = array_values(array_filter($solutions, static fn (array $solution): bool => $solution['total_cost'] === $cost));
            if (1 !== count($best) || 1 !== $best[0]['count']) {
                throw new RepairFailed('Equally minimal joint summary clock solutions are ambiguous; reconciliation declined.');
            }
            $low = $best[0]['start'];
            $high = $low;
            $selected = $best[0]['starts'];
        } else {
            $low = $lastRecord - intdiv($session['elapsed'], 1000) - 1;
            $high = $firstRecord + 1;
            if ($low !== $high || abs($low - $session['start']) > 1 || $low > $session['timestamp']) {
                throw new RepairFailed('Record clocks and unchanged elapsed must establish one Session start within one second of the original.');
            }
            $solution = $this->lapSolution($laps, $low, $low + intdiv($session['elapsed'], 1000));
            if (1 !== $solution['count']) {
                throw new RepairFailed('Equally minimal Lap clock solutions are ambiguous; reconciliation declined.');
            }
            $selected = $solution['starts'];
        }
        $targets = [$session + ['selected' => $low]];
        foreach ($laps as $index => $lap) {
            $targets[] = $lap + ['selected' => $selected[$index]];
        }
        $body = substr($bytes, 0, -2);
        $changes = [];
        foreach ($targets as $target) {
            if ($target['selected'] === $target['start']) {
                continue;
            }
            $offset = $target['offset'];
            if ($offset < $header->headerSize || $offset + 4 > strlen($body)
                || substr($bytes, $offset, 4) !== pack($target['word'], $target['start'])) {
                throw new RepairFailed('Physical start offset does not match the original scalar clock.');
            }
            $body = substr_replace($body, pack($target['word'], $target['selected']), $offset, 4);
            $changes[] = ['message' => $target['message'], 'sequence' => $target['sequence'], 'global_message_number' => $target['number'],
                'byte_offset' => $offset, 'field' => 2, 'type' => 0x86, 'original_value' => $target['start'], 'value' => $target['selected']];
        }
        if ([] === $changes) {
            throw new RepairFailed('Original summary start clocks are already compatible; no reconciliation is needed.');
        }
        $restored = $body;
        foreach ($changes as $change) {
            $restored = substr_replace($restored, substr($bytes, $change['byte_offset'], 4), $change['byte_offset'], 4);
        }
        if ($restored !== substr($bytes, 0, -2)) {
            throw new RepairFailed('Summary clock reconciliation changed bytes outside the selected start fields.');
        }
        $output = $body.pack('v', FitCrc16::calculate($body));

        return new RepairResult($output, ['mode' => 'reconcile_clocks', 'rule' => $joint ? self::JOINT_RULE : self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inference' => ['kind' => 'summary_clock_compatibility', 'historical_accuracy' => 'not_proven', 'maximum_shift_seconds' => 1,
                'authority' => 'original Record timestamps and native elapsed/timer counters',
                'selection' => $joint ? 'unique joint minimum-total-absolute-shift Session/Lap solution; ties declined' : 'unique Session start and unique minimum-total-absolute-shift Lap solution; ties declined'],
            'evidence' => ['record_count' => $records, 'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                'admissible_session_starts' => [$low, $high], 'elapsed_sum_ms' => $elapsedSum, 'timer_sum_ms' => $timerSum,
                'original_lap_count' => count($laps), 'total_absolute_shift_seconds' => array_sum(array_map(static fn (array $c): int => abs($c['value'] - $c['original_value']), $changes))],
            'preservation' => 'all original bytes retained outside audited Session/Lap.start_time fields and file CRC; native durations, measurements, developer fields, timer events and message order/count retained',
            'activity_validation' => 'not_performed']);
    }

    /**
     * @param list<array<string, mixed>> $laps
     *
     * @return array{starts: list<int>, cost: int, count: int}
     */
    private function lapSolution(array $laps, int $start, int $end, bool $joint = false): array
    {
        /** @var list<array<int, array{cost: int, count: int, previous: ?int}>> $layers */
        $layers = [];
        foreach ($laps as $index => $lap) {
            $layer = [];
            for ($value = $lap['start'] - 1; $value <= $lap['start'] + 1; ++$value) {
                if ($value < $start || $value > $lap['timestamp'] || $value + intdiv($lap['elapsed'], 1000) > $end + 1 || (!$joint && 0 === $index && $value !== $start)) {
                    continue;
                }
                $shift = abs($value - $lap['start']);
                if (0 === $index) {
                    $layer[$value] = ['cost' => $shift, 'count' => 1, 'previous' => null];
                    continue;
                }
                foreach ($layers[$index - 1] as $previous => $state) {
                    if (abs($value - $previous - intdiv($laps[$index - 1]['elapsed'], 1000)) > 2) {
                        continue;
                    }
                    $cost = $state['cost'] + $shift;
                    if (!isset($layer[$value]) || $cost < $layer[$value]['cost']) {
                        $layer[$value] = ['cost' => $cost, 'count' => $state['count'], 'previous' => $previous];
                    } elseif ($cost === $layer[$value]['cost']) {
                        $layer[$value]['count'] = min(2, $layer[$value]['count'] + $state['count']);
                    }
                }
            }
            if ([] === $layer) {
                throw new RepairFailed('No compatible Lap partition exists with every original start shifted by at most one second.');
            }
            $layers[] = $layer;
        }
        $last = $layers[array_key_last($layers)];
        $cost = min(array_column($last, 'cost'));
        $final = null;
        $count = 0;
        foreach ($last as $value => $state) {
            if ($state['cost'] === $cost) {
                $final = $value;
                $count = min(2, $count + $state['count']);
            }
        }
        if (null === $final) {
            throw new RepairFailed('No final Lap clock solution exists.');
        }
        $selected = [];
        for ($index = count($layers) - 1; $index >= 0; --$index) {
            $selected[$index] = $final;
            $final = $layers[$index][$final]['previous'];
        }
        ksort($selected);

        return ['starts' => array_values($selected), 'cost' => $cost, 'count' => $count];
    }

    private function scalar(PhysicalMessage $message, int $number, int $type, int $size): ?int
    {
        $found = false;
        foreach ($message->fields as $field) {
            if ($field->definition->fieldNumber !== $number) {
                continue;
            }
            if ($found || $field->definition->size !== $size || $field->definition->baseType->definitionByte() !== $type) {
                throw new RepairFailed('Summary clock evidence requires unique scalar native fields of the expected type.');
            }
            $found = true;
        }

        return $message->integer($number);
    }
}
