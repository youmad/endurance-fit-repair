<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapBoundaryRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedLapBoundaryFixture;

final class RecordedLapBoundaryRepairerTest extends TestCase
{
    public function testChangesOnlyRecordedLapStartAndCrc(): void
    {
        $architecture = 1;
        $source = RecordedLapBoundaryFixture::file(architecture: $architecture);

        $body = "\x0C".substr($source, 1, 11).substr($source, 14, -2);
        $source = $body.pack('v', FitCrc16::calculate($body));

        $result = (new PreservingActivityRepairer())->repair($source, RecordedLapBoundaryRepairer::RULE);
        $offset = $result->report['evidence']['start_byte_offset'];
        self::assertSame(strlen($source), strlen($result->bytes));
        self::assertSame(substr($source, 0, $offset), substr($result->bytes, 0, $offset));
        self::assertSame(substr($source, $offset + 4, -2), substr($result->bytes, $offset + 4, -2));
        self::assertCount(1, $result->report['changes']);
        self::assertSame(RecordedLapBoundaryFixture::START + 62, $result->report['original_start_time']);
        self::assertSame(-1650, $result->report['evidence']['original_stop_residual_ms']);
        self::assertSame(-650, $result->report['evidence']['repaired_stop_residual_ms']);
        self::assertSame(750, $result->report['evidence']['previous_boundary_residual_ms']);
        $file = EditableFitFile::read($result->bytes);
        $lap = PhysicalMessage::fromRaw($file->messages[$result->report['changes'][0]['message']]['raw'], 0);
        self::assertSame(RecordedLapBoundaryFixture::START + 61, $lap->integer(2));
        self::assertSame(RecordedLapBoundaryFixture::START + 111, $lap->integer(253));
        self::assertSame(40650, $lap->integer(7));
        self::assertSame(40650, $lap->integer(8));
        self::assertSame("\xDE\xAD", $lap->developerFields[0]->value->bytes());
    }

    public function testUsesAnUninterruptedRecordedSessionTransitionAndDeclinesContradictions(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = RecordedLapBoundaryFixture::file(['transition' => 1], $architecture);
            $result = (new RecordedLapBoundaryRepairer())->repair($source);
            $offset = $result->report['evidence']['start_byte_offset'];
            self::assertSame(substr($source, 0, $offset), substr($result->bytes, 0, $offset));
            self::assertSame(substr($source, $offset + 4, -2), substr($result->bytes, $offset + 4, -2));
            self::assertSame(RecordedLapBoundaryFixture::START + 61, $result->report['changes'][0]['value']);
            self::assertNull($result->report['evidence']['timer_stop']);
            self::assertSame(101000, $result->report['evidence']['session_transition']['active_ms']);
            EditableFitFile::read($result->bytes);
        }

        foreach ([['nextStart' => 102], ['lastRecord' => 100], ['timerMismatch' => -1], ['earlierPause' => 1], ['firstLapIndex' => 1]] as $contradiction) {
            try {
                (new RecordedLapBoundaryRepairer())->repair(RecordedLapBoundaryFixture::file(['transition' => 1] + $contradiction));
            } catch (RepairFailed) {
                continue;
            }

            self::fail('Contradictory transition evidence was accepted.');
        }
    }

    /** @param array<string, int> $overrides */
    #[DataProvider('contradictions')]
    public function testDeclinesAmbiguousOrContradictoryEvidence(array $overrides): void
    {
        $this->expectException(RepairFailed::class);
        (new RecordedLapBoundaryRepairer())->repair(RecordedLapBoundaryFixture::file($overrides));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function contradictions(): iterable
    {
        yield 'original start already fits stop' => [['lastElapsed' => 39000]];
        yield 'corrected start does not fit stop' => [['lastElapsed' => 42001]];

        yield 'stop is not final Record' => [['stop' => 102]];

        yield 'wrong physical start type' => [['startType' => 0x85]];
    }

    public function testSelectsOnlyFailedTwoSecondLapContainmentCheck(): void
    {
        $repairer = new PreservingActivityRepairer();
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'failed Garmin required check "Lap Message Start Time and Timestamp are Valid": end delta=2 seconds (whole FIT seconds).']]];
        self::assertSame([PreservingActivityRepairer::RECORDED_LAP_PARTITION_START, PreservingActivityRepairer::PAUSED_LAP_PARTITION_START, RecordedLapBoundaryRepairer::RULE, PreservingActivityRepairer::RECORDED_LAP_END, PreservingActivityRepairer::FRONT_SUMMARY_CLOCKS, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapPartitionStartRepairer::NATIVE_RULE], $repairer->rulesForFailure($failure, null));
        $failure['status'] = 'PASS';
        self::assertSame([], $repairer->rulesForFailure($failure, null));
        $failure['status'] = 'FAILED';
        $failure['errors'][0]['message'] = str_replace('end delta=2 seconds', 'end delta=3 seconds', $failure['errors'][0]['message']);
        self::assertSame([PreservingActivityRepairer::RECORDED_LAP_PARTITION_START, PreservingActivityRepairer::PAUSED_LAP_PARTITION_START, RecordedLapBoundaryRepairer::RULE, PreservingActivityRepairer::RECORDED_LAP_END, PreservingActivityRepairer::FRONT_SUMMARY_CLOCKS, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapPartitionStartRepairer::NATIVE_RULE], $repairer->rulesForFailure($failure, null));
        $failure['errors'][0]['message'] = str_replace('end delta=3 seconds', 'end delta=4 seconds', $failure['errors'][0]['message']);
        self::assertSame([PreservingActivityRepairer::FRONT_SUMMARY_CLOCKS, PreservingActivityRepairer::IMPOSSIBLE_SINGLE_LAP_START, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapPartitionStartRepairer::NATIVE_RULE], $repairer->rulesForFailure($failure, null));
    }
}
