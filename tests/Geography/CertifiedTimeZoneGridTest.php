<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Geography;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Geography\CertifiedTimeZoneGrid;
use Youmad\Endurance\FitRepair\Geography\GeographicZoneUnresolved;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\GeographyFixture;

final class CertifiedTimeZoneGridTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fit-grid-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->directory);
    }

    public function testReadsSubtreesAndNegativeCoordinateCells(): void
    {
        $branch = pack('vVvv', 65535, 2, 1, 2);
        $path = GeographyFixture::write($this->directory, [GeographyFixture::tile(40, -10) => $branch]);
        $grid = CertifiedTimeZoneGrid::open($path);
        self::assertSame('Europe/Paris', $grid->zoneForCell(4500, -501));
        self::assertSame('Europe/Berlin', $grid->zoneForCell(4500, -500));
        self::assertSame('Europe/Paris', $grid->zoneForPosition((int) (45 * 2147483648 / 180), (int) (-5.01 * 2147483648 / 180)));
        self::assertSame('Europe/Berlin', $grid->zoneForCell(4900, -1));
    }

    public function testZeroIsNeverAnImplicitUtcZone(): void
    {
        $grid = CertifiedTimeZoneGrid::open(GeographyFixture::write($this->directory));
        $this->expectException(GeographicZoneUnresolved::class);
        $this->expectExceptionMessage('uncertified_coordinate_cell');
        $grid->zoneForCell(4500, 100);
    }

    public function testRejectsCorruptedCompressedData(): void
    {
        $path = GeographyFixture::write($this->directory);
        file_put_contents($this->directory.'/grid.gz', 'corrupted');
        $this->expectException(RepairFailed::class);
        $this->expectExceptionMessage('checksum mismatch');
        CertifiedTimeZoneGrid::open($path);
    }

    public function testRejectsAnIncorrectSubtreeLengthEvenWithMatchingHashes(): void
    {
        $path = GeographyFixture::write($this->directory, [0 => pack('vVvv', 65535, 3, 1, 2)]);
        $this->expectException(RepairFailed::class);
        $this->expectExceptionMessage('subtree length');
        CertifiedTimeZoneGrid::open($path);
    }
}
