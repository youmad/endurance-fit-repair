<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\UnpausedLapTimerRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;
use Youmad\Endurance\FitRepair\Tests\Support\UnpausedLapTimerFixture;

final class UnpausedLapTimerRepairerTest extends TestCase
{
    public function testCopiesTheExistingElapsedAndRetainsEveryOtherByte(): void
    {
        [$architecture, $headerSize, $stopType, $eventEncoding, $summaryFirst] = [1, 12, 4, 2, true];
        $source = UnpausedLapTimerFixture::file($architecture, $headerSize, $stopType, $eventEncoding, $summaryFirst);
        $result = (new PreservingActivityRepairer())->repair($source, UnpausedLapTimerRepairer::RULE);
        self::assertCount(1, $result->report['changes']);
        self::assertSame(8, $result->report['changes'][0]['field']);
        self::assertSame(40250, $result->report['changes'][0]['value']);
        self::assertSame(43000, $result->report['original_lap_timer_ms']);
        self::assertSame(2809, $result->report['evidence']['original_timer_sum_residual_ms']);
        self::assertSame(59, $result->report['evidence']['repaired_timer_sum_residual_ms']);
        self::assertSame(0, $result->report['evidence']['timer_paused_ms']);
        self::assertSame(strlen($source), strlen($result->bytes));
        $offset = $result->report['evidence']['timer_byte_offset'];
        $masked = substr_replace($source, substr($result->bytes, $offset, 4), $offset, 4);
        self::assertSame(substr($masked, 0, -2), substr($result->bytes, 0, -2));
        $output = EditableFitFile::read($result->bytes);
        $lap = PhysicalMessage::fromRaw($output->messages[$result->report['changes'][0]['message']]['raw'], 0);
        self::assertSame(40250, $lap->integer(7));
        self::assertSame(40250, $lap->integer(8));
    }

    public function testDefaultApiAppliesTheRuleOnceAndKeepsAZeroHeaderCrc(): void
    {
        $body = substr_replace(substr(UnpausedLapTimerFixture::file(), 0, -2), "\x00\x00", 12, 2);
        $source = $body.pack('v', FitCrc16::calculate($body));
        $result = (new ActivityRepairer())->repair($source);
        self::assertSame([UnpausedLapTimerRepairer::RULE], array_column($result->report['steps'], 'rule'));
        self::assertSame(substr($source, 0, 14), substr($result->bytes, 0, 14));
        $repeat = (new ActivityRepairer())->repair($result->bytes);
        self::assertSame($result->bytes, $repeat->bytes);
        self::assertSame([], $repeat->report['steps']);
    }

    public function testKeepsAnUnusableActivityTimerWithoutUsingItAsEvidence(): void
    {
        $source = $this->replaceField(UnpausedLapTimerFixture::file(), 34, 0, 0x86, 0xFFFFFFFF);
        $result = (new UnpausedLapTimerRepairer())->repair($source);
        self::assertNull($result->report['evidence']['activity_timer_ms']);
        $file = EditableFitFile::read($result->bytes);
        $activity = array_find($file->messages, static fn (array $entry): bool => 34 === $entry['raw']->globalMessageNumber());
        self::assertNotNull($activity);
        self::assertNull(PhysicalMessage::fromRaw($activity['raw'], 0)->integer(0));
    }

    public function testCanRepairAnImpossibleTimerOnALaterLap(): void
    {
        $source = $this->replaceField(UnpausedLapTimerFixture::file(), 19, 8, 0x86, 40200);
        $source = $this->replaceField($source, 19, 8, 0x86, 63000, 1);
        $result = (new UnpausedLapTimerRepairer())->repair($source);
        self::assertSame(1, $result->report['evidence']['lap_index']);
        self::assertSame(60250, $result->report['changes'][0]['value']);
        self::assertSame(-41, $result->report['evidence']['repaired_timer_sum_residual_ms']);
    }

    public function testCopiesWholeSessionElapsedWhenTimersCountInclusiveSamples(): void
    {
        [$architecture, $headerSize, $stopType, $eventEncoding, $summaryFirst] = [1, 12, 4, 2, true];
        foreach ([0, 23] as $saveDelay) {
            $source = UnpausedLapTimerFixture::file($architecture, $headerSize, $stopType, $eventEncoding, $summaryFirst, true, $saveDelay);
            $result = (new UnpausedLapTimerRepairer())->repair($source);
            self::assertSame('whole_session_impossible_timer', $result->report['variant']);
            self::assertSame([100000, 100000, 100000], array_column($result->report['changes'], 'value'));
            self::assertSame(101000, $result->report['evidence']['original_timer_ms']);
            self::assertSame(100000, $result->report['evidence']['recorded_span_ms']);
            self::assertSame(UnpausedLapTimerFixture::START + 100 + $saveDelay, $result->report['evidence']['timer_stop']);
            $masked = $source;

            foreach ($result->report['evidence']['timer_byte_offsets'] as $offset) {
                $masked = substr_replace($masked, substr($result->bytes, $offset, 4), $offset, 4);
            }

            self::assertSame(strlen($source), strlen($result->bytes));
            self::assertSame(substr($masked, 0, -2), substr($result->bytes, 0, -2));
            EditableFitFile::read($result->bytes);
            $automatic = (new ActivityRepairer())->repair($source);
            self::assertSame($result->bytes, $automatic->bytes);
            self::assertSame([UnpausedLapTimerRepairer::RULE], array_column($automatic->report['steps'], 'rule'));
            self::assertSame($result->bytes, (new ActivityRepairer())->repair($result->bytes)->bytes);
        }
    }

    public function testDeclinesWholeSessionTimersWithoutTheInclusiveSampleEvidence(): void
    {
        foreach ([
            [18, 7, 0x86, 100500, 0], [19, 7, 0x86, 100500, 0],
            [19, 8, 0x86, 102000, 0], [34, 0, 0x86, 0xFFFFFFFF, 0],
            [21, 1, 0x00, 1, 0], [21, 253, 0x86, UnpausedLapTimerFixture::START + 101, 1],
            [20, 253, 0x86, UnpausedLapTimerFixture::START + 49, 50],
            [18, 26, 0x84, 2, 0], [19, 253, 0x86, UnpausedLapTimerFixture::START + 99, 0],
        ] as [$number, $field, $type, $value, $occurrence]) {
            $source = $this->replaceField(UnpausedLapTimerFixture::file(wholeSession: true), $number, $field, $type, $value, $occurrence);

            try {
                (new UnpausedLapTimerRepairer())->repair($source);
                self::fail('Whole-session timer repair needs complete matching evidence.');
            } catch (RepairFailed $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    /** @param array{int, int, int, int, int} $change */
    #[DataProvider('contradictions')]
    public function testDeclinesConflictingOrIncompleteEvidence(array $change): void
    {
        [$number, $field, $type, $value, $occurrence] = $change;
        $source = $this->replaceField(UnpausedLapTimerFixture::file(), $number, $field, $type, $value, $occurrence);
        $this->expectException(RepairFailed::class);
        (new UnpausedLapTimerRepairer())->repair($source);
    }

    /** @return iterable<string, array{array{int, int, int, int, int}}> */
    public static function contradictions(): iterable
    {
        yield 'incomplete partition' => [[18, 26, 0x84, 3, 0]];

        yield 'one second can be rounding' => [[19, 8, 0x86, 41250, 0]];
        yield 'two impossible timers' => [[19, 8, 0x86, 64000, 1]];
        yield 'other Lap has unexplained inactive time' => [[19, 8, 0x86, 59000, 1]];

        yield 'Record beyond Session end' => [[20, 253, 0x86, UnpausedLapTimerFixture::START + 102, 1]];
    }

    public function testDeclinesPausesAdditionalSummariesAndMissingRecordClocks(): void
    {
        foreach ([
            FitFixture::message(21, [[0, 0, "\x00"], [1, 0, "\x04"], [253, 0x86, pack('V', UnpausedLapTimerFixture::START + 20)]]),
            FitFixture::message(18, []), FitFixture::message(19, []), FitFixture::message(34, []),
            FitFixture::message(0, [[0, 0, "\x04"]]), FitFixture::message(20, []),
        ] as $extra) {
            $source = FitFixture::file(substr(UnpausedLapTimerFixture::file(), 14, -2).$extra);

            try {
                (new UnpausedLapTimerRepairer())->repair($source);
                self::fail('Incomplete or conflicting evidence must be rejected.');
            } catch (RepairFailed $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    public function testRejectsDuplicateAndArrayTimerFields(): void
    {
        foreach ([false, true] as $array) {
            $source = UnpausedLapTimerFixture::file();
            $file = EditableFitFile::read($source);
            $lap = array_find($file->messages, static fn (array $entry): bool => 19 === $entry['raw']->globalMessageNumber());
            self::assertNotNull($lap);
            $fields = array_map(static fn ($field): array => [$field->definition->fieldNumber, $field->definition->baseType->definitionByte(), $field->value->bytes()], $lap['raw']->standardFields());

            if ($array) {
                $fields[0][2] .= pack('V', 43000);
            } else {
                $fields[] = [8, 0x86, pack('V', 43000)];
            }

            $replacement = FitFixture::message(19, $fields, [[8, 0, "\xDE\xAD"]]);
            $data = substr_replace(substr($source, 14, -2), $replacement, $lap['offset'] - 14 - strlen($lap['definition']), strlen($lap['definition']) + $lap['length']);

            try {
                (new UnpausedLapTimerRepairer())->repair(FitFixture::file($data));
                self::fail('Ambiguous timer field must be rejected.');
            } catch (RepairFailed $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    public function testRoutesOnlyTheSessionTimerSumFailure(): void
    {
        $repairer = new PreservingActivityRepairer();
        $message = 'FIT failed Garmin required check "Session Message total_timer_time is Equal to the Sum of Lap Messages total_timer_time Values".';
        self::assertSame([UnpausedLapTimerRepairer::RULE, PreservingActivityRepairer::RECORDED_LENGTH_LAPS, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummaryDurationReconciler::RULE, PreservingActivityRepairer::RECORDED_LENGTH_BOUNDARIES, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedObservationOrderRepairer::RULE], $repairer->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => $message]]], null));
        self::assertSame([], $repairer->rulesForFailure(['status' => 'PASS', 'errors' => [['message' => $message]]], null));
        self::assertNotContains(UnpausedLapTimerRepairer::RULE, $repairer->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => str_replace('timer', 'elapsed', $message)]]], null));
        self::assertSame([], $repairer->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => 'FIT session total_timer_time cannot exceed total_elapsed_time.']]], null));
        $message = 'FIT session total_timer_time (101000000 microseconds) cannot exceed total_elapsed_time (100000000 microseconds).';
        self::assertSame([UnpausedLapTimerRepairer::RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummaryDurationReconciler::RULE, PreservingActivityRepairer::RECORDED_LENGTH_BOUNDARIES], $repairer->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => $message]]], null));
        self::assertSame([], $repairer->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => str_replace('session', 'lap', $message)]]], null));
    }

    private function replaceField(string $source, int $number, int $fieldNumber, int $type, int $value, int $occurrence = 0): string
    {
        $file = EditableFitFile::read($source);
        $entries = array_values(array_filter($file->messages, static fn (array $entry): bool => $number === $entry['raw']->globalMessageNumber()));
        $entry = $entries[$occurrence];
        $offset = $entry['offset'] + 1;

        foreach ($entry['raw']->standardFields() as $index => $field) {
            if ($fieldNumber === $field->definition->fieldNumber) {
                $format = match ($field->definition->size) {
                    1 => 'C', 2 => 'v', 4 => 'V', default => throw new \LogicException('Unsupported fixture field size.'),
                };
                $source = substr_replace($source, pack($format, $value), $offset, $field->definition->size);
                $typeOffset = $entry['offset'] - strlen($entry['definition']) + 6 + 3 * $index + 2;
                $body = substr_replace(substr($source, 0, -2), chr($type), $typeOffset, 1);

                return $body.pack('v', FitCrc16::calculate($body));
            }

            $offset += $field->definition->size;
        }

        throw new \LogicException('Fixture field is missing.');
    }
}
