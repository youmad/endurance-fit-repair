<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Corpus;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Corpus\CorpusTimeZonePlan;
use Youmad\Endurance\FitRepair\RepairFailed;

final class CorpusTimeZonePlanTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/fit-time-zones-'.bin2hex(random_bytes(8)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAppliesOnlyToMatchingCurrentFailedLocalTimeBytes(): void
    {
        $plan = $this->readPlan();
        $validation = ['status' => 'FAILED', 'errors' => [['message' => 'FIT message failed Garmin required check "Activity Message Local Timestamp is Valid": missing.']]];
        $plan->assertContext('/corpus', 'validator');
        self::assertTrue($plan->applies('input.fit', str_repeat('a', 64), $validation));
        self::assertFalse($plan->applies('input.fit', str_repeat('b', 64), $validation));
        self::assertFalse($plan->applies('other.fit', str_repeat('a', 64), $validation));
        $validation['status'] = 'PASS';
        self::assertFalse($plan->applies('input.fit', str_repeat('a', 64), $validation));
    }

    public function testRejectsAnotherCorpus(): void
    {
        $plan = $this->readPlan();
        $this->expectException(RepairFailed::class);
        $plan->assertContext('/other', 'validator');
    }

    public function testRejectsAnotherValidator(): void
    {
        $plan = $this->readPlan();
        $this->expectException(RepairFailed::class);
        $plan->assertContext('/corpus', 'another-validator');
    }

    public function testRejectsRepairIfBytesChanged(): void
    {
        $plan = $this->readPlan();
        $this->expectException(RepairFailed::class);
        $plan->repair('input.fit', 'changed');
    }

    private function readPlan(): CorpusTimeZonePlan
    {
        file_put_contents($this->path, json_encode($this->data(), JSON_THROW_ON_ERROR));

        return CorpusTimeZonePlan::read($this->path);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['schemaVersion' => 1, 'mode' => 'fit-local-time-zones', 'corpusRoot' => '/corpus', 'validatorFingerprint' => 'validator', 'resolver' => ['dataset' => 'test'], 'results' => [
            ['path' => 'input.fit', 'sha256' => str_repeat('a', 64), 'zone' => 'Europe/Amsterdam', 'basis' => ['recordCells' => 1]],
        ]];
    }
}
