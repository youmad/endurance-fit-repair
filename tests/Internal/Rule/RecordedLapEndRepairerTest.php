<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapEndRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedLapBoundaryFixture;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedLapEndFixture;

final class RecordedLapEndRepairerTest extends TestCase
{
    /** @param array<string, int> $overrides */
    #[DataProvider('encodings')]
    public function testCopiesRecordedBoundaryAndPreservesEveryOtherByte(int $architecture, int $headerSize, array $overrides): void
    {
        $source = RecordedLapEndFixture::file($overrides, $architecture);

        if (12 === $headerSize) {
            $body = "\x0C".substr($source, 1, 11).substr($source, 14, -2);
            $source = $body.pack('v', FitCrc16::calculate($body));
        }

        $result = (new PreservingActivityRepairer())->repair($source, RecordedLapEndRepairer::RULE);
        $offset = $result->report['evidence']['start_byte_offset'];
        self::assertSame(strlen($source), strlen($result->bytes));
        self::assertSame(substr($source, 0, $offset), substr($result->bytes, 0, $offset));
        self::assertSame(substr($source, $offset + 4, -2), substr($result->bytes, $offset + 4, -2));
        self::assertCount(1, $result->report['changes']);
        self::assertSame(RecordedLapBoundaryFixture::START + 61, $result->report['original_start_time']);
        self::assertSame(-1400, $result->report['evidence']['original_elapsed_residual_ms']);
        self::assertSame(-400, $result->report['evidence']['repaired_elapsed_residual_ms']);
        self::assertSame(-1400, $result->report['evidence']['original_active_residual_ms']);
        self::assertSame(-400, $result->report['evidence']['repaired_active_residual_ms']);
        self::assertSame(700, $result->report['evidence']['session_elapsed_residual_ms']);
        self::assertSame(700, $result->report['evidence']['session_active_residual_ms']);
        $file = EditableFitFile::read($result->bytes);
        $lap = PhysicalMessage::fromRaw($file->messages[$result->report['changes'][0]['message']]['raw'], 0);
        self::assertSame(RecordedLapBoundaryFixture::START + 60, $lap->integer(2));
        self::assertSame(RecordedLapBoundaryFixture::START + 101, $lap->integer(253));
        self::assertSame(41400, $lap->integer(7));
        self::assertSame($overrides['lastTimer'] ?? 41400, $lap->integer(8));
        self::assertSame("\xDE\xAD", $lap->developerFields[0]->value->bytes());
        self::assertSame(RecordedLapEndRepairer::RULE, $result->report['rule']);
        $this->expectException(RepairFailed::class);
        (new RecordedLapEndRepairer())->repair($result->bytes);
    }

    /** @return iterable<string, array{int, int, array<string, int>}> */
    public static function encodings(): iterable
    {
        yield 'current pause, big endian, no header CRC' => [1, 12, ['interiorTimer' => 1, 'lastTimer' => 31400]];
        yield 'earlier pause' => [0, 14, ['earlierPause' => 1, 'firstTimer' => 48900]];
    }

    /** @param array<string, int> $overrides */
    #[DataProvider('contradictions')]
    public function testRefusesUncorroboratedEvidence(array $overrides): void
    {
        $this->expectException(RepairFailed::class);
        (new RecordedLapEndRepairer())->repair(RecordedLapEndFixture::file($overrides));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function contradictions(): iterable
    {
        yield 'already recorded start' => [['lastStart' => 60]];

        yield 'whole active span contradicts timer' => [['firstTimer' => 57000]];

        yield 'Lap SAVE exceeds Session SAVE' => [['lastTimestamp' => 112]];

        yield 'timer is not complete' => [['stopType' => 0]];
    }
}
