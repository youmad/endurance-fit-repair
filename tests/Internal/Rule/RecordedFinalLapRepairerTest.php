<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapEndRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedFinalLapFixture;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedLapBoundaryFixture;

final class RecordedFinalLapRepairerTest extends TestCase
{
    /** @param array<string, int> $overrides */
    #[DataProvider('encodings')]
    public function testFinalStopSupportsOnlyTheRecordedStartWithoutChangingSaveOrDurations(int $architecture, int $headerSize, array $overrides): void
    {
        $source = RecordedFinalLapFixture::file($overrides, $architecture);

        if (12 === $headerSize) {
            $body = "\x0C".substr($source, 1, 11).substr($source, 14, -2);
            $source = $body.pack('v', FitCrc16::calculate($body));
        }

        $result = (new RecordedLapEndRepairer())->repair($source);
        $offset = $result->report['evidence']['start_byte_offset'];
        self::assertSame(strlen($source), strlen($result->bytes));
        self::assertSame(substr($source, 0, $offset), substr($result->bytes, 0, $offset));
        self::assertSame(substr($source, $offset + 4, -2), substr($result->bytes, $offset + 4, -2));
        self::assertCount(1, $result->report['changes']);
        self::assertSame('final_timer_stop', $result->report['evidence']['finish_basis']);
        self::assertSame(RecordedLapBoundaryFixture::START + 101, $result->report['evidence']['finish_timestamp']);
        self::assertSame(-1400, $result->report['evidence']['original_elapsed_residual_ms']);
        self::assertSame(-400, $result->report['evidence']['repaired_elapsed_residual_ms']);
        self::assertSame(-1400, $result->report['evidence']['original_active_residual_ms']);
        self::assertSame(-400, $result->report['evidence']['repaired_active_residual_ms']);
        self::assertSame(200, $result->report['evidence']['session_elapsed_residual_ms']);
        self::assertSame(200, $result->report['evidence']['session_active_residual_ms']);
        self::assertSame(2, $result->report['evidence']['original_session_end_excess_seconds']);
        self::assertSame(1, $result->report['evidence']['repaired_session_end_excess_seconds']);
        self::assertSame(isset($overrides['finalPause']) ? 10000 : 0, $result->report['evidence']['final_lap_paused_ms']);
        $file = EditableFitFile::read($result->bytes);
        $lap = PhysicalMessage::fromRaw($file->messages[$result->report['changes'][0]['message']]['raw'], 0);
        self::assertSame(RecordedLapBoundaryFixture::START + 70, $lap->integer(2));
        self::assertSame(RecordedLapBoundaryFixture::START + ($overrides['save'] ?? 111), $lap->integer(253));
        self::assertSame(31400, $lap->integer(7));
        self::assertSame($overrides['finalTimer'] ?? 31400, $lap->integer(8));
        self::assertSame("\xDE\xAD", $lap->developerFields[0]->value->bytes());
        $this->expectException(RepairFailed::class);
        (new RecordedLapEndRepairer())->repair($result->bytes);
    }

    /** @return iterable<string, array{int, int, array<string, int>}> */
    public static function encodings(): iterable
    {
        yield 'delayed save, big endian, no header CRC, final pause' => [1, 12, ['finalPause' => 1, 'finalTimer' => 21400]];

        yield 'final abutment failure with delayed SAVE' => [0, 14, ['firstElapsed' => 41000, 'middleElapsed' => 28400]];
    }

    /** @param array<string, int> $overrides */
    #[DataProvider('contradictions')]
    public function testRefusesUnsupportedFinalBoundaries(array $overrides): void
    {
        $this->expectException(RepairFailed::class);
        (new RecordedLapEndRepairer())->repair(RecordedFinalLapFixture::file($overrides));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function contradictions(): iterable
    {
        yield 'repeated timer start' => [['extraStart' => 1]];

        yield 'old active span already corroborated' => [['finalTimer' => 30000]];

        yield 'boundary is inside a pause' => [['finalPause' => 1, 'pauseStop' => 70, 'pauseResume' => 80, 'finalTimer' => 21400]];
        yield 'final SAVE precedes stop' => [['save' => 100]];
    }
}
