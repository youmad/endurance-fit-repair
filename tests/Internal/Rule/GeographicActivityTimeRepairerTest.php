<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\GeographicActivityTimeRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class GeographicActivityTimeRepairerTest extends TestCase
{
    public function testUtcCompatibilityLabelsTheUnknownTimezoneAndPreservesRecordedUtc(): void
    {
        foreach ([0x40000000, 1000] as $utc) {
            foreach ([[], [[5, 0x86, pack('V', $utc < 0x10000000 ? 698070063 : 0)]]] as $fields) {
                $source = FitFixture::file(FitFixture::message(34, [[253, 0x86, pack('V', $utc)], ...$fields]));
                $repair = (new GeographicActivityTimeRepairer())->repairUtcCompatibility($source);
                $file = EditableFitFile::read($repair->bytes);
                $activity = PhysicalMessage::fromRaw($file->messages[0]['raw'], $file->header->headerSize);
                self::assertSame($utc, $activity->integer(253));
                self::assertSame($utc, $activity->integer(5));
                self::assertSame('normalize_local_clock', $repair->report['mode']);
                self::assertSame(GeographicActivityTimeRepairer::UTC_COMPATIBILITY_RULE, $repair->report['rule']);
                self::assertSame('explicit_utc_compatibility_fallback', $repair->report['inference']['source']);
                self::assertSame('unknown', $repair->report['inference']['physical_timezone']);
                self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
                self::assertSame($utc < 0x10000000 ? 'relative_origin_unknown_preserved' : 'original_absolute_utc_preserved', $repair->report['inference']['recording_epoch']);
            }
        }
    }

    public function testNativeContextUsesOnlyRecentConsistentClockPairsFromThisFile(): void
    {
        $utc = 0x40000000;
        $activity = FitFixture::message(34, [[253, 0x86, pack('V', $utc)], [5, 0x86, pack('V', 0xFFFFFFFF)]]);
        $clock = static fn (int $time, int $offset): string => FitFixture::message(162, [[253, 0x86, pack('V', $time)], [3, 0x86, pack('V', $time + $offset)]]);
        $source = FitFixture::file($clock($utc - 60, 7200).$activity);
        $repair = (new GeographicActivityTimeRepairer())->repairNativeContext($source);
        $file = EditableFitFile::read($repair->bytes);
        self::assertSame($utc + 7200, PhysicalMessage::fromRaw($file->messages[1]['raw'], 14)->integer(5));
        self::assertSame(GeographicActivityTimeRepairer::NATIVE_RULE, $repair->report['rule']);
        self::assertSame('same_file_recorded_device_clock', $repair->report['inference']['source']);
        self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
        self::assertSame(EditableFitFile::read($source)->messages[0]['raw']->standardFields()[0]->value->bytes(), $file->messages[0]['raw']->standardFields()[0]->value->bytes());
        foreach (['', $clock($utc - 86401, 7200), $clock($utc - 60, 7200).$clock($utc - 30, 3600)] as $pairs) {
            try {
                (new GeographicActivityTimeRepairer())->repairNativeContext(FitFixture::file($pairs.$activity));
                self::fail('Missing, distant or contradictory native clock evidence must decline.');
            } catch (RepairFailed) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUtcCompatibilityDeclinesKnownLocalTimeContradictoryClocksAndInvalidUtc(): void
    {
        $utc = 0x40000000;
        foreach ([
            FitFixture::message(34, [[253, 0x86, pack('V', $utc)], [5, 0x86, pack('V', $utc + 3600)]]),
            FitFixture::message(162, [[253, 0x86, pack('V', $utc)], [3, 0x86, pack('V', $utc + 3600)]])
                .FitFixture::message(34, [[253, 0x86, pack('V', $utc)]]),
            FitFixture::message(34, [[253, 0x86, pack('V', 0xFFFFFFFF)]]),
        ] as $data) {
            try {
                (new GeographicActivityTimeRepairer())->repairUtcCompatibility(FitFixture::file($data));
                self::fail('UTC compatibility must decline unusable or contradictory evidence.');
            } catch (RepairFailed) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('historicalOffsets')]
    public function testUsesHistoricalOffsetAtTheActivityUtcTime(string $date, string $zone, int $offset): void
    {
        $utc = (new \DateTimeImmutable($date))->getTimestamp() - 631_065_600;
        $source = FitFixture::file(FitFixture::message(34, [[253, 0x86, pack('V', $utc)]]));
        $repair = (new GeographicActivityTimeRepairer())->repair($source, $zone);
        $output = EditableFitFile::read($repair->bytes);
        self::assertSame($utc + $offset, PhysicalMessage::fromRaw($output->messages[0]['raw'], 14)->integer(5));
        self::assertSame($offset, $repair->report['inference']['offset_seconds']);
        self::assertSame($zone, $repair->report['inference']['timezone']);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function historicalOffsets(): iterable
    {
        yield 'before DST' => ['2024-03-31T00:59:59Z', 'Europe/Amsterdam', 3600];
        yield 'after DST' => ['2024-03-31T01:00:00Z', 'Europe/Amsterdam', 7200];

        yield 'quarter hour' => ['2024-07-01T12:00:00Z', 'Asia/Kathmandu', 20_700];
        yield 'negative' => ['2024-07-01T12:00:00Z', 'America/New_York', -14_400];
    }

    public function testPreservesOtherActivityFieldsDeveloperBytesAndFollowingMessages(): void
    {
        $timestamp = 0x40000020;
        $source = FitFixture::file(FitFixture::record($timestamp)
            .FitFixture::message(34, [[253, 0x86, pack('V', $timestamp)], [0, 0x86, pack('V', 12345)], [200, 0x0D, "\xAA\xBB"]], [[7, 0, "\xFE\xFD"]])
            ."\x41\x00\x00\x14\x00\x01\x03\x01\x02\xA1\x75");
        $before = EditableFitFile::read($source);
        $repair = (new GeographicActivityTimeRepairer())->repair($source, 'Europe/Amsterdam');
        $after = EditableFitFile::read($repair->bytes);
        self::assertCount(3, $after->messages);

        foreach ($before->messages as $index => $entry) {
            $input = $entry['raw'];
            $output = $after->messages[$index]['raw'];
            self::assertSame($input->recordHeaderByte(), $output->recordHeaderByte());
            self::assertSame($input->globalMessageNumber(), $output->globalMessageNumber());
            $inputFields = array_values(array_filter($input->standardFields(), static fn ($field): bool => 5 !== $field->definition->fieldNumber));
            $outputFields = array_values(array_filter($output->standardFields(), static fn ($field): bool => 5 !== $field->definition->fieldNumber));
            self::assertEquals($inputFields, $outputFields);
            self::assertEquals($input->developerFields(), $output->developerFields());
        }

        self::assertSame($timestamp + 1, PhysicalMessage::fromRaw($after->messages[2]['raw'], 14)->integer(253));
    }

    public function testReplacesAnInvalidExistingBigEndianValueWithoutExpandingThePacket(): void
    {
        $timestamp = 0x40000000;
        $source = FitFixture::file(FitFixture::message(34, [[5, 0x86, pack('N', 0)], [253, 0x86, pack('N', $timestamp)]], architecture: 1));
        $repair = (new GeographicActivityTimeRepairer())->repair($source, 'Europe/Amsterdam');
        self::assertSame(strlen($source), strlen($repair->bytes));
        $file = EditableFitFile::read($repair->bytes);
        self::assertSame($timestamp + $repair->report['inference']['offset_seconds'], PhysicalMessage::fromRaw($file->messages[0]['raw'], 14)->integer(5));
        self::assertSame(0, $repair->report['original_local_timestamp']);
    }

    public function testContradictoryClockPairBlocksInference(): void
    {
        $utc = (new \DateTimeImmutable('2024-07-01T12:00:00Z'))->getTimestamp() - 631_065_600;
        $source = FitFixture::file(FitFixture::message(162, [[253, 0x86, pack('V', $utc)], [3, 0x86, pack('V', $utc + 3600)]])
            .FitFixture::message(34, [[253, 0x86, pack('V', $utc)]]));
        $this->expectException(RepairFailed::class);
        $this->expectExceptionMessage('clock pair contradicts');
        (new GeographicActivityTimeRepairer())->repair($source, 'Europe/Amsterdam');
    }

    public function testPreservesAUsableExistingLocalTimeEvenIfThePlanZoneDisagrees(): void
    {
        $source = FitFixture::file(FitFixture::message(34, [[253, 0x86, pack('V', 0x40000000)], [5, 0x86, pack('V', 0x40000000 + 3600)]]));
        $this->expectException(RepairFailed::class);
        $this->expectExceptionMessage('must preserve it');
        (new GeographicActivityTimeRepairer())->repair($source, 'Asia/Tokyo');
    }

    public function testRejectsRelativeUtcTime(): void
    {
        $source = FitFixture::file(FitFixture::message(34, [[253, 0x86, pack('V', 1000)]]));
        $this->expectException(RepairFailed::class);
        (new GeographicActivityTimeRepairer())->repair($source, 'Europe/Amsterdam');
    }

    public function testRejectsMultipleActivities(): void
    {
        $activity = FitFixture::message(34, [[253, 0x86, pack('V', 0x40000000)]]);
        $this->expectException(RepairFailed::class);
        (new GeographicActivityTimeRepairer())->repair(FitFixture::file($activity.$activity), 'Europe/Amsterdam');
    }

    public function testRejectsDuplicatePhysicalLocalTime(): void
    {
        $source = FitFixture::file(FitFixture::message(34, [[253, 0x86, pack('V', 0x40000000)], [5, 0x86, "\xFF\xFF\xFF\xFF"], [5, 0x86, "\xFF\xFF\xFF\xFF"]]));
        $this->expectException(RepairFailed::class);
        (new GeographicActivityTimeRepairer())->repair($source, 'Europe/Amsterdam');
    }

    public function testRejectsACompressedActivitySummary(): void
    {
        $source = FitFixture::file(FitFixture::record(0x40000020)."\x41\x00\x00\x22\x00\x01\x01\x02\x84\xA1\x01\x00");
        $this->expectException(RepairFailed::class);
        $this->expectExceptionMessage('uncompressed Activity');
        (new GeographicActivityTimeRepairer())->repair($source, 'Europe/Amsterdam');
    }
}
