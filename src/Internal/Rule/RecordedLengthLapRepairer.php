<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\Exception\FitDecodeException;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Restores damaged Lap durations from an independently corroborated Length partition. */
final readonly class RecordedLengthLapRepairer
{
    public const string RULE = 'lap_durations_from_recorded_lengths';

    public function repair(string $bytes): RepairResult
    {
        $this->requirePoolSession($bytes);
        $file = EditableFitFile::read($bytes);
        $sessions = $activities = $laps = $lengths = $events = [];
        $fileIds = $recordCount = 0;
        $firstRecord = $lastRecord = null;

        foreach ($file->messages as $index => $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);

            switch ($message->number) {
                case 0:
                    ++$fileIds;

                    if (0 !== $index || 4 !== $this->scalar($message, 0, [0x00, 0x02], 1)) {
                        throw new RepairFailed('Length recovery requires one Activity FileId as the first message.');
                    }

                    break;

                case 18:
                    $sessions[] = $message;

                    break;

                case 19:
                    $laps[$index] = $message;

                    break;

                case 20:
                    $time = $this->scalar($message, 253, [0x86], 4);

                    if (null === $time || $time < 0x10000000 || (null !== $lastRecord && $time < $lastRecord)) {
                        throw new RepairFailed('Length recovery requires complete ordered absolute Record timestamps.');
                    }

                    $firstRecord ??= $time;
                    $lastRecord = $time;
                    ++$recordCount;

                    break;

                case 21:
                    $kind = $this->scalar($message, 0, [0x00, 0x02], 1);

                    if (null === $kind) {
                        throw new RepairFailed('A missing Event kind cannot exclude a timer contradiction.');
                    }

                    if (0 === $kind) {
                        $events[] = $message;
                    }

                    break;

                case 34:
                    $activities[] = $message;

                    break;

                case 101:
                    $lengths[] = $message;

                    break;
            }

            if (1 < count($sessions) || 1 < count($activities) || 2 < count($events)
                || 4096 < count($laps) || 16384 < count($lengths)) {
                throw new RepairFailed('Length recovery requires bounded evidence for one unpaused Session.');
            }
        }

        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($activities) || 2 !== count($events)
            || [] === $laps || [] === $lengths || $recordCount < 2) {
            throw new RepairFailed('Length recovery requires complete summaries, Lengths, Records and one START/STOP pair.');
        }

        $session = $sessions[0];
        $activity = $activities[0];
        $start = $this->scalar($session, 2, [0x86], 4);
        $elapsed = $this->scalar($session, 7, [0x86], 4);
        $timestamp = $this->scalar($session, 253, [0x86], 4);
        [$begin, $stop] = $events;
        $beginTime = $this->scalar($begin, 253, [0x86], 4);
        $stopTime = $this->scalar($stop, 253, [0x86], 4);

        if ($session->wasCompressed || null === $start || $start < 0x10000000 || null === $elapsed || $elapsed <= 0
            || $elapsed !== $this->scalar($session, 8, [0x86], 4)
            || 0 !== $this->scalar($session, 254, [0x84], 2) || 0 !== $this->scalar($session, 25, [0x84], 2)
            || count($laps) !== $this->scalar($session, 26, [0x84], 2)
            || 5 !== $this->scalar($session, 5, [0x00, 0x02], 1) || 17 !== $this->scalar($session, 6, [0x00, 0x02], 1)
            || null === $timestamp || $timestamp < $start || $timestamp !== $this->scalar($activity, 253, [0x86], 4)
            || 1 !== $this->scalar($activity, 1, [0x84], 2) || $elapsed !== $this->scalar($activity, 0, [0x86], 4)
            || null === $beginTime || $beginTime < $start || $beginTime > $start + 1
            || null === $stopTime || $stopTime <= $beginTime || abs(($stopTime - $start) * 1000 - $elapsed) > 1000
            || abs(($stopTime - $beginTime) * 1000 - $elapsed) > 1000
            || 0 !== $this->scalar($begin, 1, [0x00, 0x02], 1) || !in_array($this->scalar($stop, 1, [0x00, 0x02], 1), [1, 4], true)
            || !in_array($this->scalar($begin, 4, [0x02], 1), [null, 0], true)
            || !in_array($this->scalar($stop, 4, [0x02], 1), [null, 0], true)
            || $firstRecord < $start || $firstRecord > $start + 1 || $lastRecord !== $stopTime) {
            throw new RepairFailed('Unchanged Session, Activity, Records and timer trace do not establish one unpaused pool swim.');
        }

        $end = $start + intdiv($elapsed, 1000);
        $partition = [];
        $previousIndex = $previousStart = $previousEnd = null;
        $sum = 0;

        foreach ($lengths as $length) {
            $lengthIndex = $this->scalar($length, 254, [0x84], 2);
            $lengthStart = $this->scalar($length, 2, [0x86], 4);
            $duration = $this->scalar($length, 3, [0x86], 4);
            $writeTime = $this->scalar($length, 253, [0x86], 4);

            if (null === $lengthIndex || null === $lengthStart || $lengthStart < $start || $lengthStart > $end
                || null === $duration || $duration <= 0 || $duration !== $this->scalar($length, 4, [0x86], 4)
                || null === $writeTime || $writeTime < $start || 28 !== $this->scalar($length, 0, [0x00, 0x02], 1)
                || 1 !== $this->scalar($length, 1, [0x00, 0x02], 1)
                || !in_array($this->scalar($length, 12, [0x00, 0x02], 1), [0, 1], true)
                || (null !== $previousIndex && $lengthIndex <= $previousIndex)
                || (null !== $previousStart && $lengthStart <= $previousStart)
                || (null !== $previousEnd && abs($lengthStart - $previousEnd) > 2)
                || $lengthStart + intdiv($duration, 1000) > $end + 1) {
                throw new RepairFailed('Recorded Length indexes, boundaries or exact durations contradict the Session.');
            }

            $partition[] = ['index' => $lengthIndex, 'start' => $lengthStart, 'duration' => $duration, 'sequence' => $length->sourceSequence];
            $sum += $duration;
            $previousIndex = $lengthIndex;
            $previousStart = $lengthStart;
            $previousEnd = $lengthStart + intdiv($duration, 1000);
        }

        if ($sum !== $elapsed || 0 !== $partition[0]['index'] || $partition[0]['start'] !== $start
            || abs($previousEnd - $stopTime) > 1 || $lastRecord > $end + 1) {
            throw new RepairFailed('Exact recorded Length totals and final boundaries must cover the unchanged Session.');
        }

        $changes = $evidence = [];
        $cursor = $ordinal = $repairedSum = 0;
        $previousEnd = null;
        $lapMessages = array_values($laps);

        foreach ($laps as $index => $lap) {
            $lapStart = $this->scalar($lap, 2, [0x86], 4);
            $first = $this->scalar($lap, 35, [0x84], 2);
            $next = isset($lapMessages[$ordinal + 1]) ? $this->scalar($lapMessages[$ordinal + 1], 35, [0x84], 2) : 65535;
            $original = $this->scalar($lap, 7, [0x86], 4);

            if ($lap->wasCompressed || $ordinal !== $this->scalar($lap, 254, [0x84], 2)
                || null === $lapStart || null === $first || null === $next || $first >= $next
                || null === $original || $original !== $this->scalar($lap, 8, [0x86], 4)
                || 5 !== $this->scalar($lap, 25, [0x00, 0x02], 1) || 17 !== $this->scalar($lap, 39, [0x00, 0x02], 1)
                || ($partition[$cursor]['index'] ?? null) !== $first || $partition[$cursor]['start'] !== $lapStart) {
                throw new RepairFailed('Each original Lap must identify its first Length by both index and recorded start.');
            }

            $duration = 0;
            $sources = [];

            while (isset($partition[$cursor]) && $partition[$cursor]['index'] < $next) {
                $length = $partition[$cursor++];

                if ($length['start'] < $lapStart || (isset($lapMessages[$ordinal + 1])
                    && $length['start'] >= $this->scalar($lapMessages[$ordinal + 1], 2, [0x86], 4))) {
                    throw new RepairFailed('Length references and recorded Lap boundaries select different partitions.');
                }

                $duration += $length['duration'];
                $sources[] = $length['sequence'];
            }

            $damaged = abs($original - $duration) > 1000;
            $repaired = $damaged ? $duration : $original;

            if ((!$damaged && abs($original - $duration) > 1) || $repaired <= 0
                || (null !== $previousEnd && abs($lapStart - $previousEnd) > 2)
                || $lapStart + intdiv($repaired, 1000) > $end + 1) {
                throw new RepairFailed('Recorded Length durations must restore an abutting Lap partition without rounding.');
            }

            if ($damaged) {
                foreach ([7, 8] as $field) {
                    $changes[] = ['message' => $index, 'field' => $field, 'type' => 0x86, 'value' => $duration];
                }

                $evidence[] = ['lap_sequence' => $lap->sourceSequence, 'original_duration_ms' => $original,
                    'recorded_length_duration_sum_ms' => $duration, 'length_sequences' => $sources];
            }

            $repairedSum += $repaired;
            $previousEnd = $lapStart + intdiv($repaired, 1000);
            ++$ordinal;
        }

        if ([] === $changes || $cursor !== count($partition) || abs($repairedSum - $elapsed) > 1 || abs($previousEnd - $stopTime) > 1) {
            throw new RepairFailed('Damaged Lap durations and an independently complete recorded Length partition are required.');
        }

        $output = $file->apply($changes);

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes, 'evidence' => [
                'length_duration_sum_ms' => $sum, 'session_elapsed_ms' => $elapsed, 'session_timer_ms' => $elapsed,
                'timer_start' => $beginTime, 'timer_stop' => $stopTime, 'repaired_laps' => $evidence,
                'policy' => 'sum unchanged exact Length durations selected independently by original Lap references and start boundaries; require an unpaused timer trace and exact Session totals; retain all timestamps, references, Lengths, Records and measurements',
            ],
            'preservation' => 'verified', 'activity_validation' => 'not_performed',
        ]);
    }

    /** Decline unrelated large activities before materializing their Record payloads. */
    private function requirePoolSession(string $bytes): void
    {
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new RepairFailed('Cannot allocate Length recovery input.');
        }
        $sessions = $lengths = 0;
        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill Length recovery input.');
            }
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }
                if (101 === $raw->globalMessageNumber()) {
                    ++$lengths;
                } elseif (18 === $raw->globalMessageNumber()) {
                    $message = PhysicalMessage::fromRaw($raw, 0);
                    if (++$sessions > 1 || 5 !== $this->scalar($message, 5, [0x00, 0x02], 1)
                        || 17 !== $this->scalar($message, 6, [0x00, 0x02], 1)) {
                        throw new RepairFailed('Length recovery requires one pool swimming Session.');
                    }
                }
            }
            $file->trailer();
        } catch (FitDecodeException $failure) {
            throw new RepairFailed('Length recovery requires a valid source FIT: '.$failure->getMessage(), previous: $failure);
        } finally {
            fclose($stream);
        }
        if (1 !== $sessions || 0 === $lengths) {
            throw new RepairFailed('Length recovery requires one pool swimming Session with recorded Lengths.');
        }
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
                throw new RepairFailed('Length recovery evidence contains an ambiguous or incompatible physical field.');
            }

            $found = true;
        }

        return $message->integer($number);
    }
}
