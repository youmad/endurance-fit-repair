<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Corpus;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Corpus\FitFailureDiagnostics;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class FitFailureDiagnosticsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/fit-diagnostics-'.bin2hex(random_bytes(8)).'.fit';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testCollectsOnlyMetadataAndDoesNotModifyTheSource(): void
    {
        $start = 0x10000001;
        $source = FitFixture::file(FitFixture::record($start + 2).FitFixture::record($start + 1).FitFixture::record($start + 10)
            .FitFixture::message(21, [[253, 0x86, pack('V', $start)], [0, 0, "\x00"], [1, 0, "\x00"], [4, 0x02, "\x02"]])
            .FitFixture::message(21, [[253, 0x86, pack('V', $start + 10)], [0, 0, "\x00"], [1, 0, "\x04"], [4, 0x02, "\x02"]])
            .FitFixture::message(2, [[0, 0x02, "\x01"], [1, 0x86, pack('V', 0)], [2, 0x86, pack('V2', 1000, 2000)], [5, 0x01, "\xFE\x10\x7F"]])
            .FitFixture::summary(19, $start, 10_000, [[8, 0x86, pack('V', 7000)]])
            .FitFixture::summary(18, $start, 10_000, [[8, 0x86, pack('V', 8000)], [25, 0x84, "\x00\x00"], [26, 0x84, "\x01\x00"]])
            .FitFixture::message(34, [[253, 0x86, pack('V', $start + 10)]])
            .FitFixture::message(225, [[10, 0x84, pack('v', 2)], [254, 0x86, pack('V', $start + 3)], [6, 0x86, pack('V', $start)], [0, 0x86, pack('V', 3001)], [5, 0, "\x01"], [11, 0x84, pack('v', 4)]])
            .FitFixture::message(101, [[254, 0x84, pack('v', 3)], [253, 0x86, pack('V', $start + 10)], [2, 0x86, pack('V', $start)], [3, 0x86, pack('V', 10_001)], [4, 0x86, pack('V', 9001)], [0, 0, "\x1C"], [1, 0, "\x01"], [10, 0x02, "\x02"], [12, 0, "\x01"]])
            .FitFixture::message(50_000, [[100, 0x0D, "\xAA\xBB"]], [[7, 0, "\x12\x34"]]));
        $report = $this->collect($source);
        self::assertSame('complete', $report['status']);
        self::assertSame(12, $report['dataMessages']);
        self::assertSame(3, $report['records']['count']);
        self::assertSame(1, $report['records']['backwardTimestamp']);
        self::assertSame($start + 2, $report['records']['first'][2]);
        self::assertSame($start + 10, $report['records']['last'][2]);
        self::assertSame($start + 1, $report['records']['minimum'][2]);
        self::assertSame($start + 10, $report['records']['maximum'][2]);
        self::assertSame(['sum' => 8000, 'missing' => 0], $report['summaryTotals']['sessions']['total_timer_time_ms']);
        self::assertSame(['sum' => 7000, 'missing' => 0], $report['summaryTotals']['laps']['total_timer_time_ms']);
        self::assertSame([[0, 1], [4, 1]], $report['timerEventTypeCounts']);
        self::assertSame([1], $report['rows']['deviceSettings'][0][2]);
        self::assertSame([1000, 2000], $report['rows']['deviceSettings'][0][4]);
        self::assertSame([-2, 16, null], $report['rows']['deviceSettings'][0][5]);
        self::assertNull($report['rows']['activities'][0][3]);
        self::assertSame(['sequence', 'byteOffset', 'message_index', 'timestamp', 'start_time', 'duration_ms', 'set_type', 'wkt_step_index'], $report['columns']['sets']);
        self::assertSame([2, $start + 3, $start, 3001, 1, 4], array_slice($report['rows']['sets'][0], 2));
        self::assertSame(['sequence', 'byteOffset', 'message_index', 'timestamp', 'start_time', 'total_elapsed_time_ms', 'total_timer_time_ms', 'event', 'event_type', 'event_group', 'length_type'], $report['columns']['lengths']);
        self::assertSame([3, $start + 10, $start, 10_001, 9001, 28, 1, 2, 1], array_slice($report['rows']['lengths'][0], 2));
        self::assertSame([
            [10, 2, 0x84, '0200'], [254, 4, 0x86, '04000010'], [6, 4, 0x86, '01000010'],
            [0, 4, 0x86, 'b90b0000'], [5, 1, 0, '01'], [11, 2, 0x84, '0400'],
        ], $report['physical']['rows']['sets'][0][4]);
        self::assertSame([
            [254, 2, 0x84, '0300'], [253, 4, 0x86, '0b000010'], [2, 4, 0x86, '01000010'],
            [3, 4, 0x86, '11270000'], [4, 4, 0x86, '29230000'], [0, 1, 0, '1c'],
            [1, 1, 0, '01'], [10, 1, 0x02, '02'], [12, 1, 0, '01'],
        ], $report['physical']['rows']['lengths'][0][4]);
        self::assertSame([], $report['omittedRows']);
        self::assertSame($source, file_get_contents($this->path));
    }

    public function testRetainsFocusAndPredecessorBeyondRowLimitAndTotalsAllLaps(): void
    {
        $data = '';

        for ($index = 0; 130 > $index; ++$index) {
            $data .= FitFixture::summary(19, 0x10000001 + $index, 1000, [[254, 0x84, pack('v', $index)], [8, 0x86, pack('V', 500)]]);
        }

        $source = FitFixture::file($data);
        file_put_contents($this->path, $source);
        $report = (new FitFailureDiagnostics())->collect($this->path, hash('sha256', $source), focusSequence: 259);
        self::assertCount(128, $report['rows']['laps']);
        self::assertSame(130, $report['rowCounts']['laps']);
        self::assertSame(['laps' => 2], $report['omittedRows']);
        self::assertSame(['sum' => 130_000, 'missing' => 0], $report['summaryTotals']['laps']['total_elapsed_time_ms']);
        self::assertSame(['sum' => 65_000, 'missing' => 0], $report['summaryTotals']['laps']['total_timer_time_ms']);
        self::assertSame('laps', $report['focus']['table']);
        self::assertSame(259, $report['focus']['current'][0]);
        self::assertSame(129, $report['focus']['current'][2]);
        self::assertSame(128, $report['focus']['previous'][2]);
        self::assertCount(128, $report['physical']['rows']['laps']);
        self::assertSame(['laps' => 2], $report['physical']['omittedRows']);
        self::assertSame(259, $report['physical']['focus']['current'][0]);
        self::assertSame(257, $report['physical']['focus']['previous'][0]);
        self::assertSame([254, 2, 0x84, '8100'], $report['physical']['focus']['current'][4][3]);
    }

    public function testBadCrcIsUnavailableRatherThanPartialEvidence(): void
    {
        $source = FitFixture::file(FitFixture::record(0x10000001));
        $report = $this->collect(substr($source, 0, -2)."\x00\x00");
        self::assertSame('unavailable', $report['status']);
        self::assertStringContainsString('CRC mismatch', $report['reason']);
        self::assertArrayNotHasKey('rows', $report);
        self::assertArrayNotHasKey('physical', $report);
    }

    public function testPhysicalEvidenceDistinguishesMissingInvalidDuplicateAndZeroSizeFields(): void
    {
        $source = FitFixture::file(FitFixture::message(18, [
            [8, 0x86, "\xFF\xFF\xFF\xFF"], [25, 0x84, ''], [7, 0x86, pack('V', 1001)],
            [7, 0x86, pack('V', 1002)], [70, 0x84, pack('v', 2)], [200, 0x0D, "\xAB"],
        ], [[7, 0, "\xDE\xAD"]]));
        $report = $this->collect($source);
        self::assertSame('complete', $report['status']);
        self::assertSame([
            [8, 4, 0x86, 'ffffffff'], [25, 0, 0x84, ''], [7, 4, 0x86, 'e9030000'],
            [7, 4, 0x86, 'ea030000'], [70, 2, 0x84, '0200'],
        ], $report['physical']['rows']['sessions'][0][4]);
        self::assertSame($report['rows']['sessions'][0][0], $report['physical']['rows']['sessions'][0][0]);
        self::assertSame($report['rows']['sessions'][0][1], $report['physical']['rows']['sessions'][0][1]);
        self::assertSame($source, file_get_contents($this->path));
    }

    public function testRejectsAHashMismatch(): void
    {
        file_put_contents($this->path, FitFixture::file(FitFixture::record(0x10000001)));
        $this->expectException(RepairFailed::class);
        (new FitFailureDiagnostics())->collect($this->path, str_repeat('0', 64));
    }

    /** @return array<string, mixed> */
    private function collect(string $bytes): array
    {
        file_put_contents($this->path, $bytes);

        return (new FitFailureDiagnostics())->collect($this->path, hash('sha256', $bytes));
    }
}
