<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapBoundaryRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapEndRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedFinalLapFixture;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedLapBoundaryFixture;
use Youmad\Endurance\FitRepair\Tests\Support\SessionElapsedFromLapsFixture;
use Youmad\Endurance\FitRepair\Tests\Support\UnpausedLapTimerFixture;

final class ActivityRepairerTest extends TestCase
{
    private const int START = 0x10000001;

    public function testRetainsChainedHeartRatesAndSharedDefinitionsAroundScalarRepairs(): void
    {
        $tail = FitFixture::file(FitFixture::message(132, [
            [253, 0x86, pack('V', SessionElapsedFromLapsFixture::START + 103)], [6, 0x02, "\x78"],
        ], [[7, 0, "\xAA\xBB"]]))
            .FitFixture::file("\x00".pack('V', SessionElapsedFromLapsFixture::START + 104)."\x7B\xCC\xDD");

        foreach ([
            [SessionElapsedFromLapsFixture::file(), new ActivityRepairer()],
            [UnpausedLapTimerFixture::file(wholeSession: true, saveDelay: 23), new ActivityRepairer()],
            [RecordedFinalLapFixture::file(['firstElapsed' => 41000, 'middleElapsed' => 28400]), new RecordedLapEndRepairer()],
            [RecordedLapBoundaryFixture::file(), new RecordedLapBoundaryRepairer()],
        ] as [$member, $repairer]) {
            $source = $member.$tail;
            $expected = $repairer->repair($member);
            $result = $repairer->repair($source);
            self::assertNotSame($member, $expected->bytes);
            self::assertSame($expected->bytes.$tail, $result->bytes);
            self::assertSame(strlen($source), strlen($result->bytes));
            self::assertSame(hash('sha256', $source), $result->report['input_sha256']);
            self::assertSame(hash('sha256', $result->bytes), $result->report['output_sha256']);
            $heartRates = fn (string $bytes): array => array_values(array_filter($this->decode($bytes), static fn (PhysicalMessage $message): bool => 132 === $message->number));
            self::assertCount(2, $heartRates($source));
            self::assertEquals($heartRates($source), $heartRates($result->bytes));
        }

        foreach ([
            substr($tail, 0, -1), substr($tail, 0, -1).chr(ord($tail[strlen($tail) - 1]) ^ 1),
            $tail."\x00", FitFixture::file(FitFixture::record(SessionElapsedFromLapsFixture::START + 103)),
            SessionElapsedFromLapsFixture::file(),
        ] as $invalidTail) {
            try {
                (new ActivityRepairer())->repair(SessionElapsedFromLapsFixture::file().$invalidTail);
                self::fail('Broken tails and additional activity messages must prevent repair.');
            } catch (RepairFailed $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    public function testReconstructsBoundariesAndReportsOriginalSummariesAndEvents(): void
    {
        $data = FitFixture::message(0, [[0, 0, "\x04"], [3, 0x8C, pack('V', 42)]])
            .FitFixture::record(self::START)
            .FitFixture::message(20, [[253, 0x86, pack('V', self::START + 30)], [5, 0x86, pack('V', 12_345)]])
            .FitFixture::message(19, [[25, 0, "\x01"], [39, 0, "\x00"]])
            .FitFixture::message(18, [])
            .FitFixture::message(21, [])
            .FitFixture::message(34, [[253, 0x86, pack('V', self::START + 30)], [5, 0x86, pack('V', self::START + 3630)]]);
        $original = FitFixture::file($data);
        $result = (new ActivityRepairer())->repair($original, reconstruct: true);
        $messages = $this->decode($result->bytes);

        self::assertSame([0, 23, 20, 20, 19, 18, 34], array_map(static fn (PhysicalMessage $message): int => $message->number, $messages));
        self::assertSame(42, $messages[0]->integer(3));
        self::assertNull($messages[1]->integer(3));
        self::assertSame(self::START, $messages[4]->integer(2));
        self::assertSame(self::START + 30, $messages[4]->integer(253));
        self::assertSame(30_000, $messages[4]->integer(7));
        self::assertSame(30_000, $messages[4]->integer(8));
        self::assertSame(12_345, $messages[4]->integer(9));
        self::assertSame(0, $messages[5]->integer(254));
        self::assertSame(0, $messages[5]->integer(25));
        self::assertSame(1, $messages[5]->integer(26));
        self::assertSame(1, $messages[5]->integer(5));
        self::assertSame(self::START + 3630, $messages[6]->integer(5));
        self::assertSame('source_activity', $result->report['local_time_offset_source']);
        self::assertSame('source_lap', $result->report['sport_source']);
        self::assertCount(4, $result->report['discarded_source_messages']);
        self::assertSame(hash('sha256', $original), $result->report['input_sha256']);
        self::assertSame(hash('sha256', $result->bytes), $result->report['output_sha256']);
        self::assertSame('not_performed', $result->report['activity_validation']);
    }

    public function testFiltersInvalidBackwardAndPostGapRecordsAndKeepsEqualTimestamps(): void
    {
        $data = '';

        foreach ([1, 0xFFFFFFFF, self::START, self::START - 1, self::START, self::START + 172_800, self::START + 1] as $timestamp) {
            $data .= FitFixture::record($timestamp);
        }

        $result = (new ActivityRepairer())->repair(FitFixture::file($data), 0, reconstruct: true);
        self::assertSame(2, $result->report['retained_records']);
        self::assertSame([
            'invalid_record_timestamp',
            'invalid_record_timestamp',
            'backward_record_timestamp',
            'gap_at_least_48_hours',
            'after_gap_cutoff',
        ], array_column($result->report['discarded_source_messages'], 'reason'));
        self::assertSame(0, $this->decode($result->bytes)[4]->integer(7));
    }

    public function testCompressedTimestampRetainsContextFromADiscardedEvent(): void
    {
        $base = self::START - 1;
        $data = FitFixture::record($base)
            .FitFixture::message(21, [[253, 0x86, pack('V', $base + 64)]])
            ."\x41\x00\x00\x14\x00\x01\x03\x01\x02"
            ."\xA1\x75";
        $result = (new ActivityRepairer())->repair(FitFixture::file($data), 0, reconstruct: true);
        $messages = $this->decode($result->bytes);

        self::assertSame($base + 65, $messages[3]->integer(253));
        self::assertSame(117, $messages[3]->integer(3));
        self::assertCount(1, $result->report['normalized_compressed_sequences']);
    }

    public function testCompressedHeaderWinsOverPhysicalTimestampButPhysicalTimestampSeedsNextHeader(): void
    {
        $base = self::START - 1;
        $data = FitFixture::record($base)
            ."\x41\x00\x00\x14\x00\x01\xFD\x04\x86"
            ."\xA1".pack('V', $base + 64)
            ."\xA2".pack('V', $base + 96);
        $result = (new ActivityRepairer())->repair(FitFixture::file($data), 0, reconstruct: true);
        $messages = $this->decode($result->bytes);

        self::assertSame($base + 1, $messages[3]->integer(253));
        self::assertSame($base + 66, $messages[4]->integer(253));
    }

    public function testPreservesBigEndianDuplicateUnknownFieldsAndDeveloperBytes(): void
    {
        $data = FitFixture::message(207, [[3, 2, "\x00"]])
            .FitFixture::message(206, [[0, 2, "\x00"], [1, 2, "\x08"]])
            .FitFixture::message(20, [[253, 0x86, pack('N', self::START)], [7, 2, "\xAB"], [7, 2, "\xCD"], [199, 31, "\x01\x02"]], [[8, 0, "\xEF\xAA"]], 1);
        $result = (new ActivityRepairer())->repair(FitFixture::file($data), 0, reconstruct: true);
        $messages = $this->decode($result->bytes);

        self::assertSame([0, 23, 207, 206, 20, 19, 18, 34], array_map(static fn (PhysicalMessage $message): int => $message->number, $messages));
        self::assertSame(1, $messages[4]->architecture->value);
        self::assertSame([pack('N', self::START), "\xAB", "\xCD", "\x01\x02"], array_map(static fn ($field): string => $field->value->bytes(), $messages[4]->fields));
        self::assertSame("\xEF\xAA", $messages[4]->developerFields[0]->value->bytes());
    }

    public function testBadCrcIsReportedAndReplaced(): void
    {
        $bytes = FitFixture::file(FitFixture::record(self::START));
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
        $result = (new ActivityRepairer())->repair($bytes, 0, reconstruct: true);

        self::assertContains('file_crc_mismatch', $result->report['source_issues']);
        self::assertCount(6, $this->decode($result->bytes));
    }

    public function testTruncatedPayloadRetainsOnlyCompleteRecords(): void
    {
        $bytes = FitFixture::file(FitFixture::record(self::START).FitFixture::record(self::START + 1));
        $result = (new ActivityRepairer())->repair(substr($bytes, 0, -4), 0, reconstruct: true);

        self::assertSame(1, $result->report['retained_records']);
        self::assertContains('truncated_file', $result->report['source_issues']);
        self::assertLessThan($result->report['available_data_bytes'], $result->report['complete_data_bytes']);
        self::assertCount(6, $this->decode($result->bytes));
    }

    public function testRequiresExplicitOffsetWhenTheSourceDoesNotProvideOne(): void
    {
        $this->expectException(RepairFailed::class);
        $this->expectExceptionMessage('No unambiguous source local time offset');
        (new ActivityRepairer())->repair(FitFixture::file(FitFixture::record(self::START)), reconstruct: true);
    }

    public function testConflictingSourceOffsetsRequireExplicitChoice(): void
    {
        $data = FitFixture::record(self::START);

        foreach ([0, 3600] as $offset) {
            $data .= FitFixture::message(34, [[253, 0x86, pack('V', self::START)], [5, 0x86, pack('V', self::START + $offset)]]);
        }

        $this->expectException(RepairFailed::class);
        (new ActivityRepairer())->repair(FitFixture::file($data), reconstruct: true);
    }

    public function testExplicitOffsetIsIndependentOfProcessTimezone(): void
    {
        $bytes = FitFixture::file(FitFixture::record(self::START));
        $previous = date_default_timezone_get();

        try {
            date_default_timezone_set('Pacific/Auckland');
            $first = (new ActivityRepairer())->repair($bytes, -3600, reconstruct: true);
            date_default_timezone_set('America/New_York');
            $second = (new ActivityRepairer())->repair($bytes, -3600, reconstruct: true);
            self::assertSame($first->bytes, $second->bytes);
            self::assertSame($first->reportJson(), $second->reportJson());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testRejectsNonActivityFileId(): void
    {
        $this->expectException(RepairFailed::class);
        (new ActivityRepairer())->repair(FitFixture::file(FitFixture::message(0, [[0, 0, "\x01"]]).FitFixture::record(self::START)), 0, reconstruct: true);
    }

    public function testRejectsChainedFiles(): void
    {
        $bytes = FitFixture::file(FitFixture::record(self::START));
        $this->expectException(RepairFailed::class);
        $this->expectExceptionMessage('Chained FIT members');
        (new ActivityRepairer())->repair($bytes.$bytes, 0, reconstruct: true);
    }

    public function testRejectsInputWithoutUsableRecords(): void
    {
        $this->expectException(RepairFailed::class);
        (new ActivityRepairer())->repair(FitFixture::file(FitFixture::record(1)), 0, reconstruct: true);
    }

    public function testRejectsLocalTimestampOverflowInsteadOfWrapping(): void
    {
        $this->expectException(RepairFailed::class);
        (new ActivityRepairer())->repair(FitFixture::file(FitFixture::record(0xFFFFFFFE)), 1, reconstruct: true);
    }

    public function testRejectsDurationOverflowInsteadOfWrapping(): void
    {
        $data = '';

        for ($index = 0; $index < 28; ++$index) {
            $data .= FitFixture::record(self::START + $index * 172_799);
        }

        $this->expectException(RepairFailed::class);
        (new ActivityRepairer())->repair(FitFixture::file($data), 0, reconstruct: true);
    }

    /** @return list<PhysicalMessage> */
    private function decode(string $bytes): array
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);

        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $messages = [];

            foreach ($file->messages() as $message) {
                if ($message instanceof RawDataRecord) {
                    $messages[] = PhysicalMessage::fromRaw($message, $file->header->headerSize);
                }
            }

            return $messages;
        } finally {
            fclose($stream);
        }
    }
}
