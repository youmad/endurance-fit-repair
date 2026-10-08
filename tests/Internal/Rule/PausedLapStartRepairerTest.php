<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PausedLapStartRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;
use Youmad\Endurance\FitRepair\Tests\Support\PausedLapFixture;

final class PausedLapStartRepairerTest extends TestCase
{
    public function testRestoresRecordedStartAndRetainsEveryOtherPhysicalByte(): void
    {
        $source = PausedLapFixture::file();
        $result = (new PreservingActivityRepairer())->repair($source, PausedLapStartRepairer::RULE);
        $input = EditableFitFile::read($source);
        $output = EditableFitFile::read($result->bytes);
        self::assertCount(count($input->messages), $output->messages);
        self::assertSame(strlen($source), strlen($result->bytes));
        $changed = $result->report['changes'][0]['message'];

        foreach ($input->messages as $index => $entry) {
            $after = $output->messages[$index];
            self::assertSame($entry['offset'], $after['offset']);
            self::assertSame($entry['length'], $after['length']);

            if ($index !== $changed) {
                self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $after['offset'], $after['length']));
            }
        }

        $lap = PhysicalMessage::fromRaw($output->messages[$changed]['raw'], 0);
        self::assertSame(PausedLapFixture::START + 60, $lap->integer(2));
        self::assertSame(40000, $lap->integer(7));
        self::assertSame(20000, $lap->integer(8));
        self::assertSame("\xDE\xAD", $lap->developerFields[0]->value->bytes());
        self::assertSame(PausedLapFixture::START + 80, $result->report['original_start_time']);
        self::assertSame(PausedLapFixture::START + 60, $result->report['evidence']['timer_stop']['timestamp']);
        self::assertSame(0, $result->report['evidence']['repaired_boundary_residual_ms']);
    }

    public function testScopesPauseRecoveryToTheExplicitSessionPartition(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $source = PausedLapFixture::file(['numSessions' => 2], architecture: $architecture);
            $file = EditableFitFile::read($source);
            $changes = [];
            $ordinal = 1;

            foreach ($file->messages as $index => $entry) {
                $number = $entry['raw']->globalMessageNumber();

                if (19 === $number) {
                    $changes[] = ['message' => $index, 'field' => 254, 'type' => 0x84, 'value' => $ordinal++];
                } elseif (18 === $number) {
                    $changes[] = ['message' => $index, 'field' => 254, 'type' => 0x84, 'value' => 1];
                    $changes[] = ['message' => $index, 'field' => 25, 'type' => 0x84, 'value' => 1];
                } elseif (34 === $number) {
                    $changes[] = ['message' => $index, 'field' => 0, 'type' => 0x86, 'value' => 90000];
                }
            }

            $source = $file->apply($changes);
            $fields = [[254, 0x84, pack($short, 0)], [2, 0x86, pack($word, PausedLapFixture::START - 10)],
                [253, 0x86, pack($word, PausedLapFixture::START)], [7, 0x86, pack($word, 10000)], [8, 0x86, pack($word, 10000)]];
            $prefix = FitFixture::message(19, $fields, [], $architecture)
                .FitFixture::message(18, [...$fields, [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, 1)]], [], $architecture);
            $first = $file->messages[0];
            $offset = $first['offset'] + $first['length'] - $file->header->headerSize;
            $source = FitFixture::file(substr_replace(substr($source, $file->header->headerSize, -2), $prefix, $offset, 0));
            $result = (new PausedLapStartRepairer())->repair($source);
            $output = EditableFitFile::read($result->bytes);
            $changed = $result->report['changes'][0]['message'];
            $lap = PhysicalMessage::fromRaw($output->messages[$changed]['raw'], 0);
            self::assertSame(2, $lap->integer(254));
            self::assertSame(PausedLapFixture::START + 60, $lap->integer(2));
            self::assertSame(40000, $lap->integer(7));
            self::assertSame(20000, $lap->integer(8));
            $position = $output->messages[$changed]['offset'] + 1;

            foreach ($output->messages[$changed]['raw']->standardFields() as $field) {
                if (2 === $field->definition->fieldNumber) {
                    break;
                }

                $position += $field->definition->size;
            }

            self::assertSame(substr_replace(substr($source, 0, -2), pack($word, PausedLapFixture::START + 60), $position, 4), substr($result->bytes, 0, -2));
        }
    }

    #[DataProvider('delayedSaves')]
    public function testUsesRecordedStopWhenPreviousLapWasSavedAfterStopping(int $saveDelay, int $architecture): void
    {
        $source = PausedLapFixture::file(['firstTimestamp' => 60 + $saveDelay], architecture: $architecture);
        $result = (new PausedLapStartRepairer())->repair($source);
        $file = EditableFitFile::read($source);
        $lap = $file->messages[$result->report['changes'][0]['message']];
        $position = $lap['offset'] + 1;

        foreach ($lap['raw']->standardFields() as $field) {
            if (2 === $field->definition->fieldNumber) {
                break;
            }

            $position += $field->definition->size;
        }

        $masked = substr_replace($source, substr($result->bytes, $position, 4), $position, 4);
        self::assertSame(substr($masked, 0, -2), substr($result->bytes, 0, -2));
        self::assertSame(PausedLapFixture::START + 60, $result->report['changes'][0]['value']);
        self::assertSame(PausedLapFixture::START + 60 + $saveDelay, $result->report['evidence']['previous_lap_timestamp']);
        self::assertSame($saveDelay, $result->report['evidence']['previous_lap_save_delay_seconds']);
        self::assertSame(60000, $result->report['evidence']['delayed_save_timer_evidence']['previous_active_ms']);
        self::assertSame(20000, $result->report['evidence']['delayed_save_timer_evidence']['lap_active_ms']);
        EditableFitFile::read($result->bytes);
    }

    /** @return iterable<string, array{int, int}> */
    public static function delayedSaves(): iterable
    {
        yield 'one-second delay' => [1, 0];
        yield 'big endian, long delay' => [19, 1];
    }

    /** @param array<string, int> $overrides */
    #[DataProvider('delayedSaveContradictions')]
    public function testDeclinesContradictoryDelayedSaveEvidence(array $overrides): void
    {
        $this->expectException(RepairFailed::class);
        (new PausedLapStartRepairer())->repair(PausedLapFixture::file($overrides + ['firstTimestamp' => 62]));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function delayedSaveContradictions(): iterable
    {
        yield 'save after resume' => [['firstTimestamp' => 81]];
        yield 'current active duration contradicts timer pairs' => [['secondTimer' => 17000]];

        yield 'timer trace has repeated start' => [['extraStart' => 1]];
    }

    #[DataProvider('fractionalBoundaries')]
    public function testRetainsFractionalDurationsAtWholeSecondBoundaries(int $firstElapsed, int $secondElapsed, int $prefixElapsed = 0): void
    {
        $result = (new PausedLapStartRepairer())->repair(PausedLapFixture::file([
            'firstElapsed' => $firstElapsed, 'secondElapsed' => $secondElapsed, 'prefixElapsed' => $prefixElapsed,
            'secondTimer' => 0 < $prefixElapsed ? 20300 : 20000,
        ]));
        $file = EditableFitFile::read($result->bytes);
        $index = $result->report['changes'][0]['message'];
        $lap = PhysicalMessage::fromRaw($file->messages[$index]['raw'], 0);
        self::assertSame(PausedLapFixture::START + 60, $lap->integer(2));
        self::assertSame($secondElapsed, $lap->integer(7));
        self::assertSame((0 < $prefixElapsed ? 20000 : 60000) - $firstElapsed, $result->report['evidence']['previous_boundary_residual_ms']);
        self::assertSame(40000 - $secondElapsed, $result->report['evidence']['repaired_boundary_residual_ms']);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function fractionalBoundaries(): iterable
    {
        yield 'rounded down recorded ends' => [60999, 40999, 0];
        yield 'rounded up recorded ends' => [59001, 39001, 0];
    }

    /** @param array<string, int> $overrides */
    #[DataProvider('contradictions')]
    public function testDeclinesConflictingEvidence(array $overrides): void
    {
        $this->expectException(RepairFailed::class);
        (new PausedLapStartRepairer())->repair(PausedLapFixture::file($overrides));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function contradictions(): iterable
    {
        yield 'elapsed does not span pause' => [['secondElapsed' => 20000]];

        yield 'references indicate another partition' => [['firstLapIndex' => 1]];
        yield 'already consistent start' => [['secondStart' => 60]];
    }

    public function testSelectsOnlyFailedLapAbutmentCheck(): void
    {
        $repairer = new PreservingActivityRepairer();
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT lap at sequence 25 and byte offset 500 is not sequential with the previous lap or does not abut it: start_time=123, previous_end_time=100, whole-second delta=23.']]];
        self::assertSame([PreservingActivityRepairer::RECORDED_LAP_PARTITION_START, PreservingActivityRepairer::PAUSED_LAP_PARTITION_START, PausedLapStartRepairer::RULE, PreservingActivityRepairer::RECORDED_LAP_END, PreservingActivityRepairer::UNPAUSED_FINAL_LAP, PreservingActivityRepairer::RECORDED_LENGTH_LAPS, PreservingActivityRepairer::FRONT_SUMMARY_CLOCKS, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapPartitionStartRepairer::NATIVE_RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummaryDurationReconciler::RULE, PreservingActivityRepairer::RECORDED_LENGTH_BOUNDARIES, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapElapsedRepairer::RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedObservationOrderRepairer::RULE], $repairer->rulesForFailure($failure, null));
        $failure['status'] = 'PASS';
        self::assertSame([], $repairer->rulesForFailure($failure, null));
        $failure['status'] = 'FAILED';
        $failure['errors'][0]['message'] = 'FIT session at sequence 25 is not sequential with the previous session or does not abut it: start_time=123.';
        self::assertSame([\Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummaryDurationReconciler::RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapElapsedRepairer::RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedObservationOrderRepairer::RULE], $repairer->rulesForFailure($failure, null));
    }
}
