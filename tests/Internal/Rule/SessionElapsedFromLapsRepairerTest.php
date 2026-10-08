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
use Youmad\Endurance\FitRepair\Internal\Rule\SessionElapsedFromLapsRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\UnpausedFinalLapRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;
use Youmad\Endurance\FitRepair\Tests\Support\SessionElapsedFromLapsFixture;

final class SessionElapsedFromLapsRepairerTest extends TestCase
{
    #[DataProvider('encodings')]
    public function testCopiesPreciseLapSumAndRetainsEveryOtherByte(int $architecture, int $headerSize, bool $pause, bool $summaryFirst, int $eventEncoding, int $timerShift = 0, bool $recordedEnd = false): void
    {
        $source = SessionElapsedFromLapsFixture::file($architecture, $headerSize, $pause, $summaryFirst, $eventEncoding, $timerShift, $recordedEnd);
        $result = (new PreservingActivityRepairer())->repair($source, SessionElapsedFromLapsRepairer::RULE);
        self::assertSame($recordedEnd, $result->report['evidence']['recorded_final_end_corroborated']);
        self::assertCount(1, $result->report['changes']);
        self::assertSame(7, $result->report['changes'][0]['field']);
        self::assertSame(100500, $result->report['changes'][0]['value']);
        self::assertSame(120500, $result->report['original_session_elapsed_ms']);
        self::assertSame($pause ? 10000 : 0, $result->report['evidence']['timer_paused_ms']);
        self::assertSame($pause ? 2 : 1, $result->report['evidence']['timer_pairs']);
        self::assertSame(strlen($source), strlen($result->bytes));
        $offset = $result->report['evidence']['elapsed_byte_offset'];
        $masked = substr_replace($source, substr($result->bytes, $offset, 4), $offset, 4);
        self::assertSame(substr($masked, 0, -2), substr($result->bytes, 0, -2));
        $output = EditableFitFile::read($result->bytes);
        self::assertSame(100500, PhysicalMessage::fromRaw($output->messages[$result->report['changes'][0]['message']]['raw'], 0)->integer(7));
        if ($recordedEnd) {
            foreach ([[34, 0, 0x86, 0xFFFFFFFF], [19, 253, 0x86, SessionElapsedFromLapsFixture::START + 102]] as $change) {
                if (19 === $change[0]) {
                    // Change the final Lap timestamp, leaving all durations intact.
                    $original = EditableFitFile::read($source);
                    $index = array_find_key(array_reverse($original->messages, true), static fn (array $entry): bool => 19 === $entry['raw']->globalMessageNumber());
                    self::assertNotNull($index);
                    $bad = $original->apply([['message' => $index, 'field' => 253, 'type' => 0x86, 'value' => SessionElapsedFromLapsFixture::START + 102]]);
                } else {
                    $bad = $this->replaceField($source, ...$change);
                }
                try {
                    (new SessionElapsedFromLapsRepairer())->repair($bad);
                    self::fail('Recorded STOP and all three totals must corroborate the alternative boundary.');
                } catch (RepairFailed $failure) {
                    self::assertNotSame('', $failure->getMessage());
                }
            }
        }
    }

    /** @return iterable<string, array{int, int, bool, bool, int, int, bool}> */
    public static function encodings(): iterable
    {
        yield 'paused, big endian, no header CRC, summary first, uint8 events' => [1, 12, true, true, 2, 0, false];

        yield 'recorded final UTC independently agrees with STOP and three exact totals' => [0, 14, false, false, 0, 1, true];
    }

    public function testDefaultApiAppliesTheRuleOnceAndKeepsAZeroHeaderCrc(): void
    {
        $body = substr_replace(substr(SessionElapsedFromLapsFixture::file(), 0, -2), "\x00\x00", 12, 2);
        $source = $body.pack('v', FitCrc16::calculate($body));
        $result = (new ActivityRepairer())->repair($source);
        self::assertSame([SessionElapsedFromLapsRepairer::RULE], array_column($result->report['steps'], 'rule'));
        self::assertSame(substr($source, 0, 14), substr($result->bytes, 0, 14));
        self::assertSame($result->bytes, (new ActivityRepairer())->repair($result->bytes)->bytes);
        self::assertSame([], (new ActivityRepairer())->repair($result->bytes)->report['steps']);
    }

    public function testKeepsAnUnusableActivityTimerWithoutUsingItAsEvidence(): void
    {
        $source = $this->replaceField(SessionElapsedFromLapsFixture::file(pause: true), 34, 0, 0x86, 0xFFFFFFFF);
        $result = (new SessionElapsedFromLapsRepairer())->repair($source);
        self::assertNull($result->report['evidence']['activity_timer_ms']);
        self::assertSame(100500, $result->report['changes'][0]['value']);
        self::assertSame(90000, $result->report['evidence']['timer_active_ms']);
        $output = EditableFitFile::read($result->bytes);
        $activity = array_find($output->messages, static fn (array $entry): bool => 34 === $entry['raw']->globalMessageNumber());
        self::assertNotNull($activity);
        self::assertNull(PhysicalMessage::fromRaw($activity['raw'], 0)->integer(0));
    }

    /** @param array{int, int, int, int} $change */
    #[DataProvider('contradictions')]
    public function testDeclinesConflictingOrIncompleteEvidence(array $change): void
    {
        [$number, $field, $type, $value] = $change;
        $source = $this->replaceField(SessionElapsedFromLapsFixture::file(), $number, $field, $type, $value);
        $this->expectException(RepairFailed::class);
        (new SessionElapsedFromLapsRepairer())->repair($source);
    }

    /** @return iterable<string, array{array{int, int, int, int}}> */
    public static function contradictions(): iterable
    {
        yield 'incomplete Lap partition' => [[18, 26, 0x84, 3]];

        yield 'conflicting Lap timer sum' => [[19, 8, 0x86, 40000]];

        yield 'original Session elapsed also fits timer span' => [[18, 7, 0x86, 101000]];

        yield 'early Record' => [[20, 253, 0x86, SessionElapsedFromLapsFixture::START - 1]];
    }

    public function testDeclinesDuplicateFieldsAndExtraSummaries(): void
    {
        foreach ([
            FitFixture::message(18, []), FitFixture::message(19, []), FitFixture::message(34, []), FitFixture::message(0, [[0, 0, "\x04"]]),
        ] as $extra) {
            $source = SessionElapsedFromLapsFixture::file();
            $data = substr($source, 14, -2).$extra;

            try {
                (new SessionElapsedFromLapsRepairer())->repair(FitFixture::file($data));
                self::fail('Extra summary must be rejected.');
            } catch (RepairFailed $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }

        $source = SessionElapsedFromLapsFixture::file();
        // Add a duplicate elapsed descriptor and its payload to the Session definition.
        $file = EditableFitFile::read($source);
        $session = array_find($file->messages, static fn (array $entry): bool => 18 === $entry['raw']->globalMessageNumber());
        self::assertNotNull($session);
        $fields = array_map(static fn ($field): array => [$field->definition->fieldNumber, $field->definition->baseType->definitionByte(), $field->value->bytes()], $session['raw']->standardFields());
        $replacement = FitFixture::message(18, [...$fields, [7, 0x86, pack('V', 120500)]], [[7, 0, "\xBE\xEF"]]);
        $data = substr_replace(substr($source, 14, -2), $replacement, $session['offset'] - 14 - strlen($session['definition']), strlen($session['definition']) + $session['length']);
        $this->expectException(RepairFailed::class);
        (new SessionElapsedFromLapsRepairer())->repair(FitFixture::file($data));
    }

    public function testRejectsIncompleteTimerPairsAndInvalidRecordSpans(): void
    {
        $start = SessionElapsedFromLapsFixture::START;

        foreach ([
            FitFixture::message(21, [[0, 0, "\x00"], [1, 0, "\x00"], [253, 0x86, pack('V', $start + 100)]]),
            FitFixture::record($start + 99), FitFixture::record($start + 102), FitFixture::message(20, []),
        ] as $extra) {
            $source = FitFixture::file(substr(SessionElapsedFromLapsFixture::file(), 14, -2).$extra);

            try {
                (new SessionElapsedFromLapsRepairer())->repair($source);
                self::fail('Incomplete timer or contradictory Record must be rejected.');
            } catch (RepairFailed $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    public function testDeclinesAReplacementWhichWouldExcludeAnExistingRecordUnderTheStrictSessionCheck(): void
    {
        $source = SessionElapsedFromLapsFixture::file();

        foreach ([[19, 7, 39250], [19, 8, 39250], [18, 8, 99500], [34, 0, 99500]] as [$number, $field, $value]) {
            $source = $this->replaceField($source, $number, $field, 0x86, $value);
        }

        $source = FitFixture::file(substr($source, 14, -2).FitFixture::record(SessionElapsedFromLapsFixture::START + 101));
        $this->expectException(RepairFailed::class);
        (new SessionElapsedFromLapsRepairer())->repair($source);
    }

    public function testRoutesOnlyTheSessionElapsedSumFailure(): void
    {
        $repairer = new PreservingActivityRepairer();
        $message = 'FIT failed Garmin required check "Session Message total_elapsed_time is Equal to the Sum of Lap Messages total_elapsed_time Values".';
        self::assertSame([SessionElapsedFromLapsRepairer::RULE, UnpausedFinalLapRepairer::RULE, PreservingActivityRepairer::RECORDED_LENGTH_LAPS, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummaryDurationReconciler::RULE, PreservingActivityRepairer::RECORDED_LENGTH_BOUNDARIES, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapElapsedRepairer::RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSingleLapFieldsRepairer::RULE], $repairer->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => $message]]], null));
        self::assertSame([], $repairer->rulesForFailure(['status' => 'PASS', 'errors' => [['message' => $message]]], null));
        self::assertNotContains(SessionElapsedFromLapsRepairer::RULE, $repairer->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => str_replace('elapsed', 'timer', $message)]]], null));
    }

    private function replaceField(string $source, int $number, int $fieldNumber, int $type, int $value): string
    {
        $file = EditableFitFile::read($source);
        $entry = array_find($file->messages, static fn (array $entry): bool => $number === $entry['raw']->globalMessageNumber());
        self::assertNotNull($entry);
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
