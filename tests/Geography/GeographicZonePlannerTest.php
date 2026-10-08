<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Geography;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Corpus\CorpusTimeZonePlan;
use Youmad\Endurance\FitRepair\Geography\CertifiedTimeZoneGrid;
use Youmad\Endurance\FitRepair\Geography\GeographicZonePlanner;
use Youmad\Endurance\FitRepair\Geography\GeographicZoneUnresolved;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\GeographyFixture;

final class GeographicZonePlannerTest extends TestCase
{
    private string $directory;
    private GeographicZonePlanner $planner;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fit-geo-plan-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $path = GeographyFixture::write($this->directory, [
            GeographyFixture::tile(0, 0) => pack('v', 1),
            GeographyFixture::tile(0, 10) => pack('v', 2),
        ]);
        $this->planner = new GeographicZonePlanner(CertifiedTimeZoneGrid::open($path));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->directory);
    }

    public function testResolvesCompleteCertifiedCells(): void
    {
        $resolved = $this->planner->resolve($this->evidence());
        self::assertSame('Europe/Paris', $resolved['zone']);
        self::assertSame(10, $resolved['basis']['recordPositions']);
    }

    public function testAllSummaryPositionsMustAgreeWithRecords(): void
    {
        $evidence = $this->evidence();
        $evidence['rows']['summaryPositions'] = [[1, 14, 18, 'start', 1, (int) (2147483648 / 180), (int) (11 * 2147483648 / 180)]];
        $evidence['rowCounts']['summaryPositions'] = 1;
        $this->expectException(GeographicZoneUnresolved::class);
        $this->expectExceptionMessage('route_crosses_zones');
        $this->planner->resolve($evidence);
    }

    public function testSupportsSummaryOnlyCoordinates(): void
    {
        $evidence = $this->evidence();
        $evidence['cells'] = [];
        $evidence['records']['usablePositions'] = 0;
        $evidence['rows']['summaryPositions'] = [[1, 14, 18, 'start', 1, (int) (2147483648 / 180), (int) (2147483648 / 180)]];
        $evidence['rowCounts']['summaryPositions'] = 1;
        self::assertSame('Europe/Paris', $this->planner->resolve($evidence)['zone']);
    }

    public function testDeclinesVirtualActivities(): void
    {
        $evidence = $this->evidence();
        $evidence['rows']['sessions'][0][5] = 58;
        $this->expectException(GeographicZoneUnresolved::class);
        $this->expectExceptionMessage('virtual_activity');
        $this->planner->resolve($evidence);
    }

    public function testDeclinesRelativeUtc(): void
    {
        $evidence = $this->evidence();
        $evidence['rows']['activities'][0][4] = false;
        $this->expectException(GeographicZoneUnresolved::class);
        $this->expectExceptionMessage('relative_or_multiple_activity_times');
        $this->planner->resolve($evidence);
    }

    public function testDeclinesMultipleActivities(): void
    {
        $evidence = $this->evidence();
        $evidence['rows']['activities'][] = $evidence['rows']['activities'][0];
        $this->expectException(GeographicZoneUnresolved::class);
        $this->planner->resolve($evidence);
    }

    public function testDeclinesTruncatedSummaryEvidence(): void
    {
        $evidence = $this->evidence();
        $evidence['omittedRows'] = ['summaryPositions' => 1];
        $this->expectException(GeographicZoneUnresolved::class);
        $this->planner->resolve($evidence);
    }

    public function testGeneratedPlanRetainsContextAndFitsExistingReader(): void
    {
        $report = $this->report();
        $report['results'][] = ['path' => 'pass.fit', 'status' => 'PASS', 'currentSha256' => str_repeat('a', 64), 'geographicEvidence' => $this->evidence()];
        $plan = $this->planner->plan($report, str_repeat('b', 64));
        self::assertSame(1, $plan['summary']['resolved']);
        self::assertSame(str_repeat('a', 64), $plan['results'][0]['sha256']);
        $path = $this->directory.'/plan.json';
        file_put_contents($path, json_encode($plan, JSON_THROW_ON_ERROR));
        $reader = CorpusTimeZonePlan::read($path);
        $reader->assertContext('/corpus', 'validator');
        self::assertTrue($reader->applies('candidate.fit', str_repeat('a', 64), ['status' => 'FAILED', 'errors' => [['message' => 'failed Garmin required check "Activity Message Local Timestamp is Valid"']]]));
    }

    public function testRejectsStaleGeographicEvidenceHash(): void
    {
        $report = $this->report();
        $report['results'][0]['currentSha256'] = str_repeat('c', 64);
        $this->expectException(RepairFailed::class);
        $this->expectExceptionMessage('hash differs');
        $this->planner->plan($report, str_repeat('b', 64));
    }

    /** @return array<string, mixed> */
    private function evidence(): array
    {
        return [
            'schemaVersion' => 1, 'status' => 'complete', 'sha256' => str_repeat('a', 64), 'omittedRows' => [], 'unrepresentedClockPairs' => 0,
            'records' => ['usablePositions' => 10, 'unrepresentedPositions' => 0], 'cells' => [[100, 100, 10]], 'cellSizeDegrees' => 0.01,
            'rows' => ['sessions' => [[1, 14, 1, 1, 2, 0]], 'activities' => [[2, 24, 0x40000000, null, true]], 'summaryPositions' => []],
            'rowCounts' => ['summaryPositions' => 0], 'cellColumns' => ['latitudeCell', 'longitudeCell', 'positionCount'],
            'columns' => [
                'summaryPositions' => ['sequence', 'byteOffset', 'globalMessageNumber', 'position', 'timestamp', 'latitude', 'longitude'],
                'sessions' => ['sequence', 'byteOffset', 'start_time', 'timestamp', 'sport', 'sub_sport'],
                'activities' => ['sequence', 'byteOffset', 'timestamp', 'local_timestamp', 'absoluteUtc'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return [
            'schemaVersion' => 1, 'mode' => 'activity-repair-corpus', 'corpusRoot' => '/corpus', 'validatorFingerprint' => 'validator', 'geographicSummary' => [],
            'results' => [['path' => 'candidate.fit', 'status' => 'FAILED', 'currentSha256' => str_repeat('a', 64), 'geographicEvidence' => $this->evidence()]],
        ];
    }
}
