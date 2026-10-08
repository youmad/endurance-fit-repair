<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Geography;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Corpus\CorpusTimeZonePlan;
use Youmad\Endurance\FitRepair\Geography\CertifiedTimeZoneGrid;
use Youmad\Endurance\FitRepair\Geography\FitGeographicEvidence;
use Youmad\Endurance\FitRepair\Geography\GeographicZonePlanner;
use Youmad\Endurance\FitRepair\Geography\RecordedDeviceClockPlanner;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;
use Youmad\Endurance\FitRepair\Tests\Support\GeographyFixture;

final class RecordedDeviceClockPlannerTest extends TestCase
{
    private string $directory;
    private GeographicZonePlanner $geography;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fit-clock-context-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->geography = new GeographicZonePlanner(CertifiedTimeZoneGrid::open(GeographyFixture::write($this->directory)));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testContextOffsetPlanIsBoundToDeviceDateAndCurrentBytes(): void
    {
        $report = $this->report();
        $context = (new RecordedDeviceClockPlanner())->plan($report, $this->directory, $this->geography, static function (): void {});
        self::assertCount(1, $context['results']);
        self::assertSame(7200, $context['results'][0]['offsetSeconds']);
        self::assertSame('1:100:12345', $context['results'][0]['basis']['device']);
        self::assertSame('original_recorded_clock', $context['results'][0]['basis']['observations'][0]['source']);
        $plan = $this->geography->plan($report, str_repeat('a', 64));
        $plan['results'] = $context['results'];
        file_put_contents($this->directory.'/plan.json', json_encode($plan, JSON_THROW_ON_ERROR));
        $repair = CorpusTimeZonePlan::read($this->directory.'/plan.json')->repair('target.fit', (string) file_get_contents($this->directory.'/target.fit'));
        self::assertSame(7200, $repair->report['inference']['offset_seconds']);
        self::assertNull($repair->report['inference']['timezone']);
        self::assertSame($context['results'][0]['basis'], $repair->report['inference']['basis']);
    }

    public function testDeclinesExtrapolationAndPreviouslyInferredClockAnchors(): void
    {
        $report = $this->report();
        foreach ($report['results'] as &$row) {
            if ('target.fit' !== $row['path']) {
                $row['sourceSha256'] = str_repeat('b', 64);
            }
        }
        unset($row);
        $context = (new RecordedDeviceClockPlanner())->plan($report, $this->directory, $this->geography, static function (): void {});
        self::assertSame([], $context['results']);
        $report = $this->report();
        array_pop($report['results']);
        $context = (new RecordedDeviceClockPlanner())->plan($report, $this->directory, $this->geography, static function (): void {});
        self::assertSame([], $context['results']);
        $report = $this->report(['before' => '2024-01-15', 'target' => '2024-07-15', 'after' => '2024-12-15'], 3600);
        $context = (new RecordedDeviceClockPlanner())->plan($report, $this->directory, $this->geography, static function (): void {});
        self::assertSame([], $context['results']);
        self::assertSame('ambiguous_device_clock_context', $context['skipped']['target.fit']);
        self::assertSame($context['skipped']['target.fit'], $context['skippedDevices']['1:100:12345']['targets'][0]['reason']);
        self::assertSame(['before.fit', 'after.fit'], array_column($context['skippedDevices']['1:100:12345']['observations'], 'path'));
        self::assertSame([3600, 3600], array_column($context['skippedDevices']['1:100:12345']['observations'], 'offsetSeconds'));
        $report = $this->report(offset: 12345);
        $context = (new RecordedDeviceClockPlanner())->plan($report, $this->directory, $this->geography, static function (): void {});
        self::assertSame([], $context['results']);
        self::assertSame('inconsistent_device_clock_context', $context['skippedDevices']['1:100:12345']['targets'][0]['reason']);
        self::assertSame($report['results'][0]['sourceSha256'], $context['skippedDevices']['1:100:12345']['observations'][0]['sha256']);
    }

    public function testRechecksCoordinatesInsteadOfTrustingModifiedLocalClocks(): void
    {
        $this->geography = new GeographicZonePlanner(CertifiedTimeZoneGrid::open(GeographyFixture::write($this->directory, [GeographyFixture::tile(0, 0) => pack('v', 1)])));
        $report = $this->report(offset: 12345, positions: true);
        foreach ($report['results'] as &$row) {
            if ('target.fit' !== $row['path']) {
                $row['sourceSha256'] = str_repeat('b', 64);
            }
        }
        unset($row);
        $context = (new RecordedDeviceClockPlanner())->plan($report, $this->directory, $this->geography, static function (): void {});
        self::assertCount(1, $context['results']);
        self::assertSame(7200, $context['results'][0]['offsetSeconds']);
        self::assertSame('freshly_certified_coordinates', $context['results'][0]['basis']['observations'][0]['source']);
    }

    public function testScopesTravelToCertifiedEpisodesAndRetainsEveryConflictingNativeClock(): void
    {
        $this->geography = new GeographicZonePlanner(CertifiedTimeZoneGrid::open(GeographyFixture::write($this->directory,
            [GeographyFixture::tile(0, 0) => pack('v', 1), GeographyFixture::tile(0, 10) => pack('v', 2)],
            ['America/Chicago', 'America/New_York'])));
        $dates = ['tripBefore' => '2011-04-30', 'before' => '2011-05-07', 'target' => '2011-05-18', 'after' => '2011-05-27', 'tripAfter' => '2011-05-28'];
        $report = $this->report($dates, positions: true, nativeClocks: false, longitudes: ['tripBefore' => 10.05, 'tripAfter' => 10.05]);
        $context = (new RecordedDeviceClockPlanner())->plan($report, $this->directory, $this->geography, static function (): void {});
        self::assertCount(1, $context['results']);
        self::assertSame(-18000, $context['results'][0]['offsetSeconds']);
        self::assertSame('certified_geographic_episode', $context['results'][0]['basis']['scope']);
        self::assertSame(['before.fit', 'after.fit'], array_column($context['results'][0]['basis']['observations'], 'path'));
        self::assertSame(2, $context['results'][0]['basis']['observationsOutsideEpisode']);

        $report = $this->report(array_replace($dates, ['target' => '2011-05-02']), positions: true, nativeClocks: false,
            longitudes: ['tripBefore' => 10.05, 'tripAfter' => 10.05]);
        $context = (new RecordedDeviceClockPlanner())->plan($report, $this->directory, $this->geography, static function (): void {});
        self::assertSame([], $context['results']);

        $report = $this->report($dates, positions: true, nativeClocks: false, longitudes: ['tripBefore' => 10.05, 'tripAfter' => 10.05]);
        $utc = (new \DateTimeImmutable('2011-05-20T12:00:00Z'))->getTimestamp() - 631_065_600;
        $bytes = FitFixture::file(FitFixture::message(0, [[0, 0, "\x04"], [1, 0x84, pack('v', 1)], [2, 0x84, pack('v', 100)], [3, 0x8C, pack('V', 12345)]])
            .FitFixture::message(34, [[253, 0x86, pack('V', $utc)], [5, 0x86, pack('V', $utc)]]));
        file_put_contents($this->directory.'/clock.fit', $bytes);
        $sha = hash('sha256', $bytes);
        $report['results'][] = ['path' => 'clock.fit', 'sourceSha256' => $sha, 'currentSha256' => $sha, 'status' => 'PASS'];
        $context = (new RecordedDeviceClockPlanner())->plan($report, $this->directory, $this->geography, static function (): void {});
        self::assertSame([], $context['results']);
        self::assertContains('clock.fit', array_column($context['skippedDevices']['1:100:12345']['observations'], 'path'));
        $report = $this->report($dates + ['aConflict' => '2011-05-07'], positions: true, nativeClocks: false,
            longitudes: ['tripBefore' => 10.05, 'tripAfter' => 10.05, 'aConflict' => 10.05]);
        $context = (new RecordedDeviceClockPlanner())->plan($report, $this->directory, $this->geography, static function (): void {});
        self::assertSame([], $context['results']);
    }

    public function testRejectsAnUnboundInputSnapshot(): void
    {
        $report = $this->report();
        file_put_contents($this->directory.'/before.fit', 'changed');
        $this->expectException(RepairFailed::class);
        (new RecordedDeviceClockPlanner())->plan($report, $this->directory, $this->geography, static function (): void {});
    }

    /**
     * @param array<string, string> $dates
     * @param array<string, float>  $longitudes
     *
     * @return array<string, mixed>
     */
    private function report(array $dates = ['before' => '2024-07-01', 'target' => '2024-07-15', 'after' => '2024-08-01'], int $offset = 7200, bool $positions = false, bool $nativeClocks = true, array $longitudes = []): array
    {
        $rows = [];
        foreach ($dates as $name => $date) {
            $utc = (new \DateTimeImmutable($date.'T12:00:00Z'))->getTimestamp() - 631_065_600;
            $fields = [[253, 0x86, pack('V', $utc)]];
            if ('target' !== $name && $nativeClocks) {
                $fields[] = [5, 0x86, pack('V', $utc + $offset)];
            }
            $record = $positions && 'target' !== $name ? FitFixture::message(20, [[253, 0x86, pack('V', $utc)], [0, 0x85, pack('V', 596523)], [1, 0x85, pack('V', isset($longitudes[$name]) ? (int) round($longitudes[$name] * 2147483648 / 180) : 596523)]]) : '';
            $bytes = FitFixture::file(FitFixture::message(0, [[0, 0, "\x04"], [1, 0x84, pack('v', 1)], [2, 0x84, pack('v', 100)], [3, 0x8C, pack('V', 12345)]])
                .$record.FitFixture::message(34, $fields));
            $path = $name.'.fit';
            file_put_contents($this->directory.'/'.$path, $bytes);
            $sha = hash('sha256', $bytes);
            $rows[] = ['path' => $path, 'sourceSha256' => $sha, 'currentSha256' => $sha, 'status' => 'FAILED',
                'validation' => ['errors' => [['message' => 'failed Garmin required check "Activity Message Local Timestamp is Valid"']]],
                'geographicEvidence' => (new FitGeographicEvidence())->collect($this->directory.'/'.$path, $sha)];
        }

        return ['schemaVersion' => 1, 'mode' => 'activity-repair-corpus', 'corpusRoot' => '/corpus', 'validatorFingerprint' => 'validator', 'geographicSummary' => [], 'results' => $rows];
    }
}
