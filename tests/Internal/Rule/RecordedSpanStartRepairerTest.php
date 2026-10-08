<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Fit\Exception\FitDecodeException;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedSpanStartRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedSpanFixture;

final class RecordedSpanStartRepairerTest extends TestCase
{
    public function testRecoversThreeSharedStartsAndRetainsAllOtherBytes(): void
    {
        $source = RecordedSpanFixture::file();
        $result = (new PreservingActivityRepairer())->repair($source, RecordedSpanStartRepairer::RULE);
        $input = EditableFitFile::read($source);
        $output = EditableFitFile::read($result->bytes);
        self::assertSame(strlen($source), strlen($result->bytes));
        self::assertCount(count($input->messages), $output->messages);
        self::assertCount(3, $result->report['changes']);
        $changes = array_column($result->report['changes'], null, 'message');

        foreach ($input->messages as $index => $entry) {
            $after = $output->messages[$index];
            self::assertSame($entry['offset'], $after['offset']);
            self::assertSame($entry['length'], $after['length']);

            if (!isset($changes[$index])) {
                self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $after['offset'], $after['length']));

                continue;
            }

            $change = $changes[$index];
            $message = PhysicalMessage::fromRaw($after['raw'], 0);
            self::assertSame(RecordedSpanFixture::START, $message->integer($change['field']));
            self::assertSame(RecordedSpanFixture::START + 80, $change['original_value']);
            self::assertSame($message->number, $change['global_message_number']);
            self::assertSame(PhysicalMessage::fromRaw($entry['raw'], 0)->developerFields[0]->value->bytes(), $message->developerFields[0]->value->bytes());

            if (21 === $message->number) {
                self::assertSame(0, $message->integer(0));
                self::assertSame(0, $message->integer(1));
                self::assertSame(0, $message->integer(4));
            } else {
                self::assertSame(100000, $message->integer(7));
                self::assertSame(99000, $message->integer(8));
            }
        }

        $expected = substr($source, 0, -2);

        foreach ($result->report['changes'] as $change) {
            $expected = substr_replace($expected, pack('V', RecordedSpanFixture::START), $change['byte_offset'], 4);
        }

        self::assertSame($expected, substr($result->bytes, 0, -2));
        self::assertSame(5, $result->report['evidence']['record_count']);
        self::assertSame(0, $result->report['evidence']['recovered_boundary_residual_ms']);
    }

    #[DataProvider('quantizedEndpoints')]
    public function testRetainsOriginalFractionalDurations(int $elapsed): void
    {
        $result = (new RecordedSpanStartRepairer())->repair(RecordedSpanFixture::file(['elapsed' => $elapsed]));
        self::assertSame($elapsed, $result->report['evidence']['elapsed_ms']);
        self::assertSame(100000 - $elapsed, $result->report['evidence']['recovered_boundary_residual_ms']);
        $file = EditableFitFile::read($result->bytes);

        foreach ($result->report['changes'] as $change) {
            if (2 === $change['field']) {
                self::assertSame($elapsed, PhysicalMessage::fromRaw($file->messages[$change['message']]['raw'], 0)->integer(7));
            }
        }
    }

    /** @return iterable<string, array{int}> */
    public static function quantizedEndpoints(): iterable
    {
        yield 'fraction below whole seconds' => [99500];

        yield 'one-second endpoint quantization' => [101000];
    }

    /** @param array<string, int> $overrides */
    #[DataProvider('contradictions')]
    public function testDeclinesUnsupportedOrConflictingEvidence(array $overrides): void
    {
        $this->expectException(RepairFailed::class);
        (new RecordedSpanStartRepairer())->repair(RecordedSpanFixture::file($overrides));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function contradictions(): iterable
    {
        yield 'Record before an authentic in-stream start' => [['earlyEvents' => 1]];
        yield 'wrong elapsed span' => [['elapsed' => 102000]];

        yield 'compressed unknown packet depends on the original timer timestamp' => [['lateCompressed' => 1]];

        yield 'Session lap references disagree' => [['numLaps' => 2]];
    }

    public function testRejectsInvalidCrcBeforeEditing(): void
    {
        $source = RecordedSpanFixture::file();
        $this->expectException(FitDecodeException::class);
        (new RecordedSpanStartRepairer())->repair(substr($source, 0, -2)."\x00\x00");
    }

    public function testSelectsOnlyFailedFirstRecordBoundary(): void
    {
        $repairer = new PreservingActivityRepairer();
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT message at sequence 12 failed Garmin required check "Record Message Timestamps Fall Within Session Message Times": first Record timestamp=100 is earlier than first Session start_time=200 by 100 seconds; maximum lead is 1 second.']]];
        self::assertSame([RecordedSpanStartRepairer::RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummaryDurationReconciler::RULE, PreservingActivityRepairer::RECORDED_LENGTH_BOUNDARIES, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapElapsedRepairer::RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedObservationOrderRepairer::RULE], $repairer->rulesForFailure($failure, null));
        $failure['status'] = 'PASS';
        self::assertSame([], $repairer->rulesForFailure($failure, null));
        $failure['status'] = 'FAILED';
        $failure['errors'][0]['message'] = 'FIT message failed Garmin required check "Record Message Timestamps Fall Within Session Message Times": last Record timestamp=300 is later than last Session calculated end=200 by 100 seconds.';
        self::assertNotContains(RecordedSpanStartRepairer::RULE, $repairer->rulesForFailure($failure, null));
    }
}
