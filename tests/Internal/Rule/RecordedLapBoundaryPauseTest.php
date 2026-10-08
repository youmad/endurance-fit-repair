<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapBoundaryRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedLapBoundaryFixture;

final class RecordedLapBoundaryPauseTest extends TestCase
{
    /** @param array<string, int> $overrides */
    #[DataProvider('recordedPauses')]
    public function testRetainsPauseEventsAndDurationsWhenBothSpansCorroborateStart(array $overrides, int $architecture, int $pauseCount, int $pauseMs, int $timer): void
    {
        $source = RecordedLapBoundaryFixture::file($overrides, $architecture);
        $result = (new RecordedLapBoundaryRepairer())->repair($source);
        $offset = $result->report['evidence']['start_byte_offset'];
        self::assertSame(strlen($source), strlen($result->bytes));
        self::assertSame(substr($source, 0, $offset), substr($result->bytes, 0, $offset));
        self::assertSame(substr($source, $offset + 4, -2), substr($result->bytes, $offset + 4, -2));
        $evidence = $result->report['evidence']['final_lap_timer_evidence'];
        self::assertCount($pauseCount, $evidence['pauses']);
        self::assertSame($pauseMs, $evidence['paused_ms']);
        self::assertSame(40000 - $pauseMs, $evidence['active_ms']);
        self::assertSame(-650, $evidence['active_residual_ms']);
        self::assertSame(0, $evidence['pause_residual_ms']);
        $file = EditableFitFile::read($result->bytes);
        $lap = PhysicalMessage::fromRaw($file->messages[$result->report['changes'][0]['message']]['raw'], 0);
        self::assertSame(RecordedLapBoundaryFixture::START + 61, $lap->integer(2));
        self::assertSame(40650, $lap->integer(7));
        self::assertSame($timer, $lap->integer(8));
        self::assertSame("\xDE\xAD", $lap->developerFields[0]->value->bytes());
    }

    /** @return iterable<string, array{array<string, int>, int, int, int, int}> */
    public static function recordedPauses(): iterable
    {
        yield 'two pauses' => [['interiorTimer' => 1, 'secondPause' => 1, 'lastTimer' => 25650], 0, 2, 15000, 25650];
        yield 'earlier pause belongs to preceding Lap' => [['interiorTimer' => 1, 'lastTimer' => 30650, 'earlierPause' => 1, 'firstTimer' => 50250], 0, 1, 10000, 30650];
    }

    /** @param array<string, int> $overrides */
    #[DataProvider('contradictions')]
    public function testDeclinesUncorroboratedPauseAndActiveDurations(array $overrides): void
    {
        $this->expectException(RepairFailed::class);
        (new RecordedLapBoundaryRepairer())->repair(RecordedLapBoundaryFixture::file($overrides));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function contradictions(): iterable
    {
        yield 'active duration contradicts recorded timeline' => [['interiorTimer' => 1, 'lastTimer' => 33000]];

        yield 'pause crosses previous boundary' => [['interiorTimer' => 1, 'pauseStop' => 60, 'lastTimer' => 30650]];
        yield 'backward resume' => [['interiorTimer' => 1, 'pauseResume' => 69, 'lastTimer' => 30650]];
    }
}
