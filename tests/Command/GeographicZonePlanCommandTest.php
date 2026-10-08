<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Command;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Command\GeographicZonePlanCommand;
use Youmad\Endurance\FitRepair\Corpus\CorpusWorkspace;
use Youmad\Endurance\FitRepair\Tests\Support\GeographyFixture;

final class GeographicZonePlanCommandTest extends TestCase
{
    private string $directory;
    private string $manifest;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fit-geo-cli-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->manifest = GeographyFixture::write($this->directory);
        $report = ['schemaVersion' => 1, 'mode' => 'activity-repair-corpus', 'corpusRoot' => '/corpus', 'validatorFingerprint' => 'validator', 'geographicSummary' => [], 'results' => []];
        file_put_contents($this->directory.'/report.json', json_encode($report, JSON_THROW_ON_ERROR));
        file_put_contents($this->directory.'/report.json.gz', gzencode(json_encode($report, JSON_THROW_ON_ERROR)));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->directory);
    }

    public function testReadsPlainAndCompressedReports(): void
    {
        foreach (['json', 'json.gz'] as $suffix) {
            $output = $this->directory.'/plan-'.$suffix.'.json';
            [$code] = $this->runCommand(['--report='.$this->directory.'/report.'.$suffix, '--output='.$output, '--database='.$this->manifest]);
            self::assertSame(0, $code);
            $plan = CorpusWorkspace::readJson($output);
            self::assertSame(['resolved' => 0, 'skipped' => 0, 'skipReasons' => []], $plan['summary']);
            self::assertSame(hash_file('sha256', $this->directory.'/report.'.$suffix), $plan['reportSha256']);
        }
    }

    public function testNeverOverwritesAnExistingPlan(): void
    {
        $output = $this->directory.'/existing.json';
        file_put_contents($output, 'retained');
        [$code, , $stderr] = $this->runCommand(['--report='.$this->directory.'/report.json', '--output='.$output]);
        self::assertSame(2, $code);
        self::assertStringContainsString('already exists', $stderr);
        self::assertSame('retained', file_get_contents($output));
    }

    public function testInvalidInputDoesNotPublishAPlan(): void
    {
        file_put_contents($this->directory.'/report.json', '{invalid');
        $output = $this->directory.'/plan.json';
        [$code] = $this->runCommand(['--report='.$this->directory.'/report.json', '--output='.$output]);
        self::assertSame(2, $code);
        self::assertFileDoesNotExist($output);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function runCommand(array $arguments): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        try {
            $code = (new GeographicZonePlanCommand())->run($arguments, $stdout, $stderr);
            rewind($stdout);
            rewind($stderr);

            return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
        } finally {
            fclose($stdout);
            fclose($stderr);
        }
    }
}
