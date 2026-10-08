<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Geography;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Geography\CertifiedTimeZoneGrid;
use Youmad\Endurance\FitRepair\Geography\GeographicZoneUnresolved;

final class BundledTimeZoneGridTest extends TestCase
{
    private static CertifiedTimeZoneGrid $grid;

    public static function setUpBeforeClass(): void
    {
        self::$grid = CertifiedTimeZoneGrid::open();
    }

    public function testFullDatasetRetainsNamedZonesAndEnclaves(): void
    {
        foreach ([
            [4885, 235, 'Europe/Paris'],
            [5252, 1340, 'Europe/Berlin'],
            [-3387, 15121, 'Australia/Sydney'],
            [3587, -11063, 'America/Phoenix'],
            [3570, -10907, 'America/Denver'],
            [2526, 5629, 'Asia/Muscat'],
            [5150, -13, 'Europe/London'],
        ] as [$latitude, $longitude, $zone]) {
            self::assertSame($zone, self::$grid->zoneForCell($latitude, $longitude));
        }
    }

    public function testDeclinesAWholeCellAcrossBaarleEnclaveBoundaries(): void
    {
        $this->expectException(GeographicZoneUnresolved::class);
        $this->expectExceptionMessage('uncertified_coordinate_cell');
        self::$grid->zoneForCell(5144, 493);
    }
}
