<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Geography;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Geography\FitGeographicEvidence;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class FitGeographicEvidenceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/fit-geography-'.bin2hex(random_bytes(8)).'.fit';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testCollectsSignedCoordinatesAndClockPairsWithoutChangingBytes(): void
    {
        $time = 0x40000000;
        $source = FitFixture::file($this->record($time, 0x20000000, -0x40000000)
            .$this->record($time + 1, -0x10000000, 0x30000000, 1)
            .FitFixture::summary(18, $time, 1000, [
                [3, 0x85, pack('V', 1)], [4, 0x85, pack('V', 2)],
                [38, 0x85, pack('V', 3)], [39, 0x85, pack('V', 4)],
                [5, 0, "\x02"], [6, 0, "\x3A"],
            ])
            .FitFixture::summary(19, $time, 1000, [[5, 0x85, pack('V', 5)], [6, 0x85, pack('V', 6)]])
            .FitFixture::message(34, [[253, 0x86, pack('V', $time + 1)]])
            .FitFixture::message(162, [[253, 0x86, pack('V', $time)], [3, 0x86, pack('V', $time + 7200)]])
            .FitFixture::message(103, [[253, 0x86, pack('V', $time)], [0, 0x86, pack('V', $time - 3600)]])
            .FitFixture::message(55, [[253, 0x86, pack('V', $time)], [11, 0x86, pack('V', $time + 7200)]]));
        $evidence = $this->collect($source);
        self::assertSame('complete', $evidence['status']);
        self::assertSame(8, $evidence['dataMessages']);
        self::assertSame(2, $evidence['records']['usablePositions']);
        self::assertSame([0x20000000, -0x40000000], array_slice($evidence['records']['first'], 3));
        self::assertSame([-0x10000000, 0x30000000], array_slice($evidence['records']['last'], 3));
        self::assertSame([-0x10000000, -0x40000000, 0x20000000, 0x30000000], $evidence['records']['bounds']);
        self::assertSame([[4500, -9000, 1], [-2250, 6750, 1]], $evidence['cells']);
        self::assertSame([[1, 2], [3, 4], [5, 6]], array_map(static fn (array $row): array => array_slice($row, 5), $evidence['rows']['summaryPositions']));
        self::assertSame([2, 58], array_slice($evidence['rows']['sessions'][0], 4));
        self::assertSame(1, $evidence['absoluteActivityTimes']);
        self::assertNull($evidence['rows']['activities'][0][3]);
        self::assertSame(3, $evidence['usableClockPairs']);
        self::assertSame([[-3600, 1], [7200, 2]], $evidence['clockOffsetCounts']);
        self::assertSame($source, file_get_contents($this->path));
    }

    public function testRejectsIncompleteInvalidOutOfRangeAndWronglyTypedPositions(): void
    {
        $evidence = $this->collect(FitFixture::file(
            FitFixture::record(0x40000000)
            .FitFixture::message(20, [[0, 0x85, pack('V', 1)]])
            .$this->record(0x40000001, 0x7FFFFFFF, 0)
            .$this->record(0x40000002, 0x40000001, 0)
            .$this->record(0x40000003, 0, 0x7FFFFFFF)
            .FitFixture::message(20, [[0, 0x86, pack('V', 1)], [1, 0x85, pack('V', 2)]])
            .FitFixture::message(20, [[0, 0x85, pack('V2', 1, 2)], [1, 0x85, pack('V', 2)]])
            .$this->record(0x40000004, -0x40000000, -0x80000000)));
        self::assertSame(8, $evidence['records']['count']);
        self::assertSame(7, $evidence['records']['unusablePositions']);
        self::assertSame(1, $evidence['records']['usablePositions']);
        self::assertSame([[-9000, -18000, 1]], $evidence['cells']);
    }

    public function testDoesNotTreatRelativeTimesOrInvalidClockOffsetsAsUsable(): void
    {
        $time = 0x40000000;
        $evidence = $this->collect(FitFixture::file(
            FitFixture::message(34, [[253, 0x86, pack('V', 1000)]])
            .FitFixture::message(34, [[253, 0x86, "\xFF\xFF\xFF\xFF"]])
            .FitFixture::message(162, [[253, 0x86, pack('V', 1000)], [3, 0x86, pack('V', 4600)]])
            .FitFixture::message(162, [[253, 0x86, pack('V', $time)], [3, 0x86, pack('V', $time + 50_401)]])
            .FitFixture::message(162, [[253, 0x86, pack('V', $time)], [3, 0x86, pack('V', $time - 43_201)]])
            .FitFixture::message(162, [[253, 0x86, pack('V', $time)]])
            .FitFixture::message(162, [[253, 0x86, pack('V', 0x10000000)], [3, 0x86, pack('V', 0x10000000)]])));
        self::assertSame(0, $evidence['absoluteActivityTimes']);
        self::assertSame(1, $evidence['relativeActivityTimes']);
        self::assertSame(1, $evidence['usableClockPairs']);
        self::assertSame([[0, 1]], $evidence['clockOffsetCounts']);
        self::assertSame([null, null, null, null, 0], array_column($evidence['rows']['clockPairs'], 5));
    }

    public function testBoundsCellsCountsOmittedPositionsAndContinuesCountingRetainedCells(): void
    {
        $data = '';

        for ($index = 0; $index < 2050; ++$index) {
            $data .= $this->record(0x40000000 + $index, (int) round(($index * 0.02 + 0.005) * 2147483648 / 180), 0);
        }

        $data .= $this->record(0x40001000, (int) round(0.005 * 2147483648 / 180), 0);
        $evidence = $this->collect(FitFixture::file($data));
        self::assertCount(2048, $evidence['cells']);
        self::assertSame(2051, $evidence['records']['usablePositions']);
        self::assertSame(2, $evidence['records']['unrepresentedPositions']);
        self::assertSame(2, $evidence['cells'][0][2]);
        self::assertSame(2049, array_sum(array_column($evidence['cells'], 2)));
        self::assertGreaterThan($evidence['records']['last'][3], $evidence['records']['bounds'][2]);
    }

    public function testKeepsBothSidesOfTheAntimeridianAsSeparateCells(): void
    {
        $evidence = $this->collect(FitFixture::file($this->record(0x40000000, 1, -2147483647).$this->record(0x40000001, 1, 2147483646)));
        self::assertSame([[0, -18000, 1], [0, 17999, 1]], $evidence['cells']);
    }

    public function testRetainsSummaryCoordinatesWithoutRecordsAndBoundsRows(): void
    {
        $data = '';

        for ($index = 0; $index < 130; ++$index) {
            $data .= FitFixture::summary(18, 0x40000000 + $index, 1000, [[3, 0x85, pack('V', $index)], [4, 0x85, pack('V', 0)]]);
        }

        $evidence = $this->collect(FitFixture::file($data));
        self::assertSame(0, $evidence['records']['count']);
        self::assertSame(130, $evidence['rowCounts']['summaryPositions']);
        self::assertCount(128, $evidence['rows']['summaryPositions']);
        self::assertSame(['summaryPositions' => 2, 'sessions' => 2], $evidence['omittedRows']);
    }

    public function testAmbiguousDuplicateCoordinateFieldsAreUnusable(): void
    {
        $evidence = $this->collect(FitFixture::file(FitFixture::message(20, [[0, 0x85, pack('V', 1)], [0, 0x85, pack('V', 1)], [1, 0x85, pack('V', 2)]])));
        self::assertSame('complete', $evidence['status']);
        self::assertSame(1, $evidence['records']['unusablePositions']);
        self::assertSame([], $evidence['cells']);
    }

    public function testBadCrcDiscardsPartialEvidence(): void
    {
        $source = FitFixture::file($this->record(0x40000000, 1, 2));
        $evidence = $this->collect(substr($source, 0, -2)."\x00\x00");
        self::assertSame('unavailable', $evidence['status']);
        self::assertStringContainsString('CRC mismatch', $evidence['reason']);
        self::assertArrayNotHasKey('records', $evidence);
    }

    public function testRejectsChangedBytesBeforeCollection(): void
    {
        file_put_contents($this->path, FitFixture::file($this->record(0x40000000, 1, 2)));
        $this->expectException(RepairFailed::class);
        (new FitGeographicEvidence())->collect($this->path, str_repeat('0', 64));
    }

    private function record(int $time, int $latitude, int $longitude, int $architecture = 0): string
    {
        $word = 0 === $architecture ? 'V' : 'N';

        return FitFixture::message(20, [[253, 0x86, pack($word, $time)], [0, 0x85, pack($word, $latitude)], [1, 0x85, pack($word, $longitude)]], architecture: $architecture);
    }

    /** @return array<string, mixed> */
    private function collect(string $bytes): array
    {
        file_put_contents($this->path, $bytes);

        return (new FitGeographicEvidence())->collect($this->path, hash('sha256', $bytes));
    }
}
