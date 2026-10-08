<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Corpus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Corpus\CorpusRepairRunner;
use Youmad\Endurance\FitRepair\Corpus\CorpusTimeZonePlan;
use Youmad\Endurance\FitRepair\Corpus\CorpusValidator;
use Youmad\Endurance\FitRepair\Corpus\CorpusWorkspace;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;
use Youmad\Endurance\FitRepair\Tests\Support\ScenarioCorpusValidator;

final class CorpusRepairRunnerTest extends TestCase
{
    private string $directory;
    private string $original;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fit-corpus-repair-'.bin2hex(random_bytes(8));
        CorpusWorkspace::directory($this->directory.'/source/nested');
        $this->original = FitFixture::file(FitFixture::message(21, [[1, 0, "\x04"]]).FitFixture::message(19, [])
            .FitFixture::message(18, [[25, 0x84, "\x00\x00"], [26, 0x84, "\x01\x00"]]));
        file_put_contents($this->directory.'/source/nested/fail.fit', $this->original);
        file_put_contents($this->directory.'/source/pass.fit', FitFixture::file(FitFixture::record(0x10000001)));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isDir()) {
                unlink($file->getPathname());
            } else {
                rmdir($file->getPathname());
            }
        }

        rmdir($this->directory);
    }

    public function testUsesTwoRoundsKeepsIntermediateFailedVersionAndDoesNotRevalidatePass(): void
    {
        $validator = new ScenarioCorpusValidator();
        $report = $this->runCorpus($validator);
        self::assertSame('all_pass', $report['stopReason']);
        self::assertSame(2, $report['rounds']);
        self::assertSame(['pass' => 2, 'failed' => 0, 'toolError' => 0], $report['summary']);
        self::assertSame([2, 1, 1], array_map('count', $validator->batches));
        self::assertSame($this->original, file_get_contents($this->directory.'/source/nested/fail.fit'));
        self::assertSame(['FAILED', 'PASS'], array_column($report['results'][0]['history'], 'validationStatus'));
        self::assertFileExists($this->directory.'/work/ready/nested/fail.fit');
        self::assertCount(2, glob($this->directory.'/work/versions/*.fit') ?: []);
        $calls = count($validator->batches);
        $this->runCorpus($validator);
        self::assertCount($calls, $validator->batches);
    }

    public function testContinuesFromLastVersionAfterRoundLimit(): void
    {
        $validator = new ScenarioCorpusValidator();
        $first = $this->runCorpus($validator, 1);
        self::assertSame('round_limit', $first['stopReason']);
        self::assertSame(1, $first['summary']['failed']);
        self::assertFileDoesNotExist($this->directory.'/work/ready/nested/fail.fit');
        $second = $this->runCorpus($validator, 1);
        self::assertSame('all_pass', $second['stopReason']);
        self::assertSame(2, $second['rounds']);
        self::assertSame([2, 1, 1], array_map('count', $validator->batches));
    }

    public function testResumesPendingValidationAfterAnInterruption(): void
    {
        $validator = new ScenarioCorpusValidator();
        $validator->interruptOnCall = 2;

        try {
            $this->runCorpus($validator);
            self::fail('Expected simulated interruption.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Simulated interruption.', $failure->getMessage());
        }

        $state = CorpusWorkspace::readJson($this->directory.'/work/state.json');
        self::assertArrayHasKey('pending', $state['files']['nested/fail.fit']);
        $validator->interruptOnCall = null;
        $report = $this->runCorpus($validator);
        self::assertSame('all_pass', $report['stopReason']);
        self::assertSame(2, $report['rounds']);
        self::assertCount(2, $report['results'][0]['history']);
        self::assertSame([2, 1, 1, 1], array_map('count', $validator->batches));
    }

    public function testStopsAtFixedPointAndDoesNotRetryUnchangedUnsupportedFile(): void
    {
        $badReferences = FitFixture::file(FitFixture::message(19, []).FitFixture::message(18, []));
        file_put_contents($this->directory.'/source/nested/fail.fit', $badReferences);
        $validator = new ScenarioCorpusValidator();
        $report = $this->runCorpus($validator);
        self::assertSame('fixed_point', $report['stopReason']);
        self::assertSame(0, $report['rounds']);
        self::assertNotEmpty($report['results'][0]['attempts']);
        self::assertSame($badReferences, file_get_contents($this->directory.'/work/current/nested/fail.fit'));
        self::assertSame(1, $report['diagnosticSummary']['read']);
        self::assertSame('complete', $report['results'][0]['diagnostics']['status']);
        self::assertSame($report['results'][0]['currentSha256'], $report['results'][0]['diagnostics']['sha256']);
        self::assertArrayNotHasKey('diagnostics', $report['results'][1]);
        $resumed = $this->runCorpus($validator);
        self::assertSame('fixed_point', $resumed['stopReason']);
        self::assertSame(0, $resumed['diagnosticSummary']['read']);
        self::assertSame(1, $resumed['diagnosticSummary']['cached']);
        self::assertSame($report['results'][0]['diagnostics'], $resumed['results'][0]['diagnostics']);
        self::assertCount(1, $validator->batches);
    }

    public function testRepairsMissingReferencesBeforeRetryingIndexesAndResumesOldAttempts(): void
    {
        $source = FitFixture::file(FitFixture::summary(19, 0x10000001, 10_000)
            .FitFixture::summary(18, 0x10000001, 10_000));
        file_put_contents($this->directory.'/source/nested/fail.fit', $source);
        $validator = new ScenarioCorpusValidator();
        // Seed an earlier ruleset's checkpoint before any edit or validation.
        try {
            (new CorpusRepairRunner())->run($this->directory.'/source', new CorpusWorkspace($this->directory.'/work'), $validator, static function (string $message): void {
                throw new \RuntimeException('Checkpoint created.');
            }, baseline: $this->baseline());
            self::fail('Expected interruption after checkpoint creation.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Checkpoint created.', $failure->getMessage());
        }
        $state = CorpusWorkspace::readJson($this->directory.'/work/state.json');
        $file = &$state['files']['nested/fail.fit'];
        $file['currentSha'] = $file['sourceSha'];
        $file['seen'] = [$file['sourceSha']];
        $file['history'] = [];
        $file['result'] = ScenarioCorpusValidator::result('nested/fail.fit', $source);
        $file['attempts'] = ['old-rules:'.$file['sourceSha'].':session_message_index' => 'unsupported: missing references'];
        unset($file);
        $state['round'] = 0;
        (new CorpusWorkspace($this->directory.'/work'))->writeJson('state.json', $state);
        $validator = new ScenarioCorpusValidator();

        $report = $this->runCorpus($validator);
        self::assertSame('all_pass', $report['stopReason']);
        self::assertSame(3, $report['rounds']);
        self::assertSame([1, 1, 1], array_map('count', $validator->batches));
        self::assertSame(['session_lap_references', 'session_message_index', 'lap_message_index'], array_column(array_column($report['results'][0]['history'], 'repair'), 'rule'));
        self::assertSame(['FAILED', 'FAILED', 'PASS'], array_column($report['results'][0]['history'], 'validationStatus'));
        self::assertSame($source, file_get_contents($this->directory.'/source/nested/fail.fit'));
        $this->runCorpus($validator);
        self::assertCount(3, $validator->batches);
    }

    public function testChangingValidatorInvalidatesCachedResults(): void
    {
        $validator = new ScenarioCorpusValidator();
        $this->runCorpus($validator);
        $validator->version = 'two';
        $report = $this->runCorpus($validator);
        self::assertSame('all_pass', $report['stopReason']);
        self::assertSame([2, 1, 1, 2], array_map('count', $validator->batches));
    }

    public function testFreshBaselineSkipsTheInitialFullValidation(): void
    {
        $baseline = $this->baseline();
        $validator = new ScenarioCorpusValidator();
        $report = $this->runCorpus($validator, baseline: $baseline);
        self::assertSame('all_pass', $report['stopReason']);
        self::assertSame([1, 1], array_map('count', $validator->batches));
    }

    public function testRejectsAChangedOriginalRatherThanMixingCorpora(): void
    {
        $validator = new ScenarioCorpusValidator();
        $this->runCorpus($validator);
        file_put_contents($this->directory.'/source/pass.fit', FitFixture::file(FitFixture::record(0x10000002)));
        $this->expectException(RepairFailed::class);
        $this->runCorpus($validator);
    }

    public function testCollectsOnlyLocalTimeFailuresAndReusesValidationAndBothEvidenceCaches(): void
    {
        $source = FitFixture::file(FitFixture::message(20, [[253, 0x86, pack('V', 0x40000000)], [0, 0x85, pack('V', 1)], [1, 0x85, pack('V', 2)]])
            .FitFixture::message(34, [[253, 0x86, pack('V', 0x40000000)]]));
        file_put_contents($this->directory.'/source/nested/fail.fit', $source);
        file_put_contents($this->directory.'/source/other.fit', FitFixture::file(FitFixture::message(18, [])));
        $validator = new class implements CorpusValidator {
            public int $calls = 0;

            public function fingerprint(): string
            {
                return hash('sha256', 'geographic-fixture');
            }

            public function profileSha256(): ?string
            {
                return null;
            }

            public function validate(array $files, string $destination): array
            {
                ++$this->calls;
                $results = [];

                foreach ($files as $path => $file) {
                    $result = ['path' => $path, 'sha256' => hash_file('sha256', $file), 'status' => 'pass.fit' === $path ? 'PASS' : 'FAILED'];

                    if ('FAILED' === $result['status']) {
                        $check = 'nested/fail.fit' === $path ? 'Activity Message Local Timestamp is Valid' : 'Unsupported Fixture';
                        $result['errors'] = [['class' => 'TestValidationFailure', 'message' => 'FIT message failed Garmin required check "'.$check.'": missing.']];
                    }

                    $results[$path] = $result;
                }

                return $results;
            }
        };
        $runner = new CorpusRepairRunner();
        $workspace = new CorpusWorkspace($this->directory.'/work');
        $progress = static function (string $message): void {};
        $before = $runner->run($this->directory.'/source', $workspace, $validator, $progress);
        self::assertSame(2, $before['diagnosticSummary']['read']);
        self::assertArrayNotHasKey('geographicSummary', $before);
        $report = $runner->run($this->directory.'/source', $workspace, $validator, $progress, collectGeography: true);
        self::assertSame($before['summary'], $report['summary']);
        self::assertSame(0, $report['diagnosticSummary']['read']);
        self::assertSame(2, $report['diagnosticSummary']['cached']);
        self::assertSame(1, $report['geographicSummary']['candidates']);
        self::assertSame(1, $report['geographicSummary']['read']);
        self::assertSame(1, $report['geographicSummary']['withRecordPositions']);
        self::assertSame(1, $report['geographicSummary']['withAbsoluteActivityTime']);
        self::assertSame([], $report['results'][0]['history']);
        self::assertSame($before['results'][0]['diagnostics'], $report['results'][0]['diagnostics']);
        self::assertArrayNotHasKey('geographicEvidence', $report['results'][1]);
        self::assertArrayNotHasKey('geographicEvidence', $report['results'][2]);
        self::assertSame($source, file_get_contents($this->directory.'/source/nested/fail.fit'));
        self::assertSame($source, file_get_contents($this->directory.'/work/current/nested/fail.fit'));
        $resumed = $runner->run($this->directory.'/source', $workspace, $validator, $progress, collectGeography: true);
        self::assertSame(0, $resumed['geographicSummary']['read']);
        self::assertSame(1, $resumed['geographicSummary']['cached']);
        self::assertSame($report['results'][0]['geographicEvidence'], $resumed['results'][0]['geographicEvidence']);
        self::assertSame(1, $validator->calls);
        self::assertSame(0, $report['rounds']);
    }

    #[DataProvider('localClockModes')]
    public function testOptionalLocalClockModesRevalidateChangesRetainFailuresAndRespectZonePlans(bool $utcOnly): void
    {
        $missing = FitFixture::file(FitFixture::message(34, [[253, 0x86, pack('V', 0x40000000)]]));
        $invalid = FitFixture::file(FitFixture::message(34, [[253, 0x86, pack('V', 0x40000000)], [5, 0x86, pack('V', 0)]]));
        file_put_contents($this->directory.'/source/nested/fail.fit', $missing);
        file_put_contents($this->directory.'/source/other.fit', $invalid);
        $validator = new class implements CorpusValidator {
            /** @var list<list<string>> */
            public array $batches = [];

            public function fingerprint(): string
            {
                return hash('sha256', 'geographic-repair-fixture');
            }

            public function profileSha256(): ?string
            {
                return null;
            }

            public function validate(array $files, string $destination): array
            {
                $this->batches[] = array_keys($files);
                $results = [];

                foreach ($files as $path => $file) {
                    $bytes = file_get_contents($file);

                    if (false === $bytes) {
                        throw new \RuntimeException('Cannot read fixture.');
                    }

                    $result = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'status' => 'PASS'];

                    if ('pass.fit' !== $path) {
                        $decoded = EditableFitFile::read($bytes);
                        $activity = PhysicalMessage::fromRaw($decoded->messages[0]['raw'], $decoded->header->headerSize);
                        $utc = $activity->integer(253);
                        $local = $activity->integer(5);
                        $check = null;

                        if (null === $local || null === $utc || -43_200 > $local - $utc || 50_400 < $local - $utc) {
                            $check = 'Activity Message Local Timestamp is Valid';
                        } elseif ('other.fit' === $path) {
                            $check = 'Unsupported Fixture';
                        }

                        if (null !== $check) {
                            $result['status'] = 'FAILED';
                            $result['errors'] = [['message' => 'FIT message failed Garmin required check "'.$check.'": fixture.']];
                        }
                    }

                    $results[$path] = $result;
                }

                return $results;
            }
        };
        $runner = new CorpusRepairRunner();
        $workspace = new CorpusWorkspace($this->directory.'/work');
        $progress = static function (string $message): void {};
        $before = $runner->run($this->directory.'/source', $workspace, $validator, $progress);
        self::assertSame(['pass' => 1, 'failed' => 2, 'toolError' => 0], $before['summary']);
        self::assertSame(0, $before['rounds']);
        self::assertFalse($before['utcCompatibilityEnabled']);
        $plan = null;
        if (!$utcOnly) {
            $planPath = $this->directory.'/zones.json';
            file_put_contents($planPath, json_encode(['schemaVersion' => 1, 'mode' => 'fit-local-time-zones', 'corpusRoot' => $this->directory.'/source', 'validatorFingerprint' => $validator->fingerprint(), 'resolver' => ['dataset' => 'fixture'], 'results' => [
                ['path' => 'nested/fail.fit', 'sha256' => hash('sha256', $missing), 'zone' => 'Europe/Amsterdam', 'basis' => ['recordCells' => 1]],
                ['path' => 'other.fit', 'sha256' => hash('sha256', $invalid), 'zone' => 'Europe/Amsterdam', 'basis' => ['recordCells' => 1]],
            ]], JSON_THROW_ON_ERROR));
            $plan = CorpusTimeZonePlan::read($planPath);
        }
        $report = $runner->run($this->directory.'/source', $workspace, $validator, $progress, localTimeZones: $plan, useUtcForUnknownLocalTime: true);
        self::assertTrue($report['utcCompatibilityEnabled']);
        self::assertSame(['pass' => 2, 'failed' => 1, 'toolError' => 0], $report['summary']);
        self::assertSame(1, $report['rounds']);
        self::assertSame('PASS', $report['results'][0]['history'][0]['validationStatus']);
        self::assertSame('FAILED', $report['results'][1]['history'][0]['validationStatus']);
        $audit = $report['results'][0]['history'][0]['repair'];
        if ($utcOnly) {
            self::assertSame('normalize_local_clock', $audit['mode']);
            self::assertSame('unknown', $audit['inference']['physical_timezone']);
        } else {
            self::assertSame('geographic_coordinates', $audit['inference']['source']);
            self::assertSame($plan->fingerprint, $audit['inference']['plan_sha256']);
        }
        self::assertFileExists($this->directory.'/work/ready/nested/fail.fit');
        self::assertFileDoesNotExist($this->directory.'/work/ready/other.fit');
        self::assertSame([], $report['results'][2]['history']);
        self::assertSame($missing, file_get_contents($this->directory.'/source/nested/fail.fit'));
        self::assertSame($invalid, file_get_contents($this->directory.'/source/other.fit'));
        $runner->run($this->directory.'/source', $workspace, $validator, $progress, localTimeZones: $plan, useUtcForUnknownLocalTime: true);
        $disabled = $runner->run($this->directory.'/source', $workspace, $validator, $progress);
        self::assertFalse($disabled['utcCompatibilityEnabled']);
        self::assertSame($report['results'][0]['currentSha256'], $disabled['results'][0]['currentSha256']);
        self::assertSame([['nested/fail.fit', 'other.fit', 'pass.fit'], ['nested/fail.fit', 'other.fit']], $validator->batches);
    }

    /** @return iterable<string, array{bool}> */
    public static function localClockModes(): iterable
    {
        yield 'zone plan takes priority over UTC compatibility' => [false];
        yield 'explicit UTC compatibility without a plan' => [true];
    }

    /** @return array<string, mixed> */
    private function runCorpus(CorpusValidator $validator, int $limit = 20, ?string $baseline = null): array
    {
        return (new CorpusRepairRunner())->run($this->directory.'/source', new CorpusWorkspace($this->directory.'/work'), $validator, static function (string $message): void {}, baseline: $baseline, maxRounds: $limit);
    }

    private function baseline(): string
    {
        $results = [];

        foreach (['nested/fail.fit', 'pass.fit'] as $path) {
            $bytes = file_get_contents($this->directory.'/source/'.$path);
            self::assertIsString($bytes);
            $results[] = ScenarioCorpusValidator::result($path, $bytes);
        }

        $path = $this->directory.'/baseline.json';
        file_put_contents($path, json_encode(['schemaVersion' => 1, 'mode' => 'activity-validation', 'scope' => 'decode-crc-map-domain', 'phpVersion' => PHP_VERSION, 'results' => $results], JSON_THROW_ON_ERROR));

        return $path;
    }
}
