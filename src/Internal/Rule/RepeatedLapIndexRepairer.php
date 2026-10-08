<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Numbers a complete chronological Lap set whose every index was recorded as zero. */
final readonly class RepeatedLapIndexRepairer
{
    public const string RULE = 'repeated_zero_lap_indexes';

    public function repair(string $bytes): RepairResult
    {
        $file = EditableFitFile::read($bytes);
        $laps = $sessions = $activities = [];

        foreach ($file->messages as $index => $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);

            if (19 === $message->number) {
                $laps[$index] = $message;
            } elseif (18 === $message->number) {
                $sessions[] = $message;
            } elseif (34 === $message->number) {
                $activities[] = $message;
            }
        }

        if (count($laps) < 2 || count($laps) > 4096 || 1 !== count($sessions) || 1 !== count($activities)) {
            throw new RepairFailed('Repeated Lap index repair requires 2..4096 Laps, one Session and one Activity.');
        }

        $session = $sessions[0];

        if (0 !== $this->scalar($session, 254, 0x84, 2) || 0 !== $this->scalar($session, 25, 0x84, 2)
            || count($laps) !== $this->scalar($session, 26, 0x84, 2) || null !== $this->scalar($session, 70, 0x84, 2)
            || 1 !== $this->scalar($activities[0], 1, 0x84, 2)) {
            throw new RepairFailed('Complete Session references without an ambiguous best_lap_index must identify the repeated-zero Lap set.');
        }

        $start = $this->scalar($session, 2, 0x86, 4);
        $elapsed = $this->scalar($session, 7, 0x86, 4);
        $timer = $this->scalar($session, 8, 0x86, 4);
        $finish = $this->scalar($session, 253, 0x86, 4);

        if (null === $start || $start < 0x10000000 || null === $elapsed || $elapsed <= 0 || null === $timer || $timer > $elapsed
            || null === $finish || ($finish - $start) * 1000 !== $elapsed) {
            throw new RepairFailed('Repeated Lap indexes require exact, unchanged Session clock and duration evidence.');
        }

        $boundary = $start;
        $elapsedSum = $timerSum = 0;
        $changes = [];
        $ordinal = 0;

        foreach ($laps as $index => $lap) {
            $lapStart = $this->scalar($lap, 2, 0x86, 4);
            $lapFinish = $this->scalar($lap, 253, 0x86, 4);
            $lapElapsed = $this->scalar($lap, 7, 0x86, 4);
            $lapTimer = $this->scalar($lap, 8, 0x86, 4);

            if ($lap->wasCompressed || 0 !== $this->scalar($lap, 254, 0x84, 2) || $lapStart !== $boundary || null === $lapFinish
                || null === $lapElapsed || $lapElapsed <= 0 || ($lapFinish - $boundary) * 1000 !== $lapElapsed
                || null === $lapTimer || $lapTimer < 0 || $lapTimer > $lapElapsed || $lap->sourceSequence >= $session->sourceSequence) {
                throw new RepairFailed('Every Lap must have a unique scalar zero index and corroborate one exact chronological partition.');
            }

            if (0 < $ordinal) {
                $changes[] = ['message' => $index, 'field' => 254, 'type' => 0x84, 'value' => $ordinal];
            }

            $boundary = $lapFinish;
            $elapsedSum += $lapElapsed;
            $timerSum += $lapTimer;
            ++$ordinal;
        }

        if ($boundary !== $finish || $elapsedSum !== $elapsed || $timerSum !== $timer) {
            throw new RepairFailed('Lap endpoints and both duration sums must match the existing Session exactly.');
        }

        $output = $file->apply($changes);

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE, 'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes, 'evidence' => ['session_sequence' => $session->sourceSequence, 'num_laps' => count($laps),
                'elapsed_sum_ms' => $elapsedSum, 'timer_sum_ms' => $timerSum],
            'preservation' => 'all source bytes retained outside repeated Lap.message_index values and file CRC; references, membership, clocks and durations retained',
            'activity_validation' => 'not_performed',
        ]);
    }

    private function scalar(PhysicalMessage $message, int $number, int $type, int $size): ?int
    {
        $count = 0;

        foreach ($message->fields as $field) {
            if ($number === $field->definition->fieldNumber
                && (++$count > 1 || $field->definition->baseType->definitionByte() !== $type || $field->definition->size !== $size)) {
                throw new RepairFailed('Repeated Lap index evidence requires unique scalar fields of the expected type and size.');
            }
        }

        return $message->integer($number);
    }
}
