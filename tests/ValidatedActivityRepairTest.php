<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\GeographicActivityTimeRepairer;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class ValidatedActivityRepairTest extends TestCase
{
    public function testRevalidatesSuccessiveRepairsAndLeavesPassingInputUntouched(): void
    {
        $timestamp = 0x10000001;
        $record = FitFixture::record($timestamp);
        $bytes = FitFixture::file($record
            .FitFixture::message(0, [[0, 0, "\x04"]])
            .FitFixture::message(34, [[253, 0x86, pack('V', $timestamp)], [0, 0x86, pack('V', 0)], [1, 0x84, pack('v', 1)]]));
        $validations = 0;
        $validate = static function (string $candidate) use (&$validations): array {
            ++$validations;
            $file = EditableFitFile::read($candidate);
            $messages = array_map(static fn (array $entry): PhysicalMessage => PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize), $file->messages);
            $error = 0 !== $messages[0]->number ? '"FileId Message Is First"' : '';
            foreach ($messages as $message) {
                if ('' === $error && 34 === $message->number && null === $message->integer(5)) {
                    $error = '"Activity Message Local Timestamp is Valid"';
                }
            }

            return '' === $error ? ['status' => 'PASS'] : ['status' => 'FAILED', 'errors' => [['message' => $error]]];
        };
        $repairer = new ActivityRepairer();
        $progress = new class {
            public int $calls = 0;

            public function tick(): void
            {
                ++$this->calls;
            }
        };
        $result = $repairer->repairValidated($bytes, $validate, inferTimeZone: true, useUtcForUnknownLocalTime: true, onProgress: [$progress, 'tick']);
        self::assertSame('PASS', $result->report['activity_validation']);
        self::assertSame(3, $validations);
        self::assertCount(2, $result->report['steps']);
        self::assertGreaterThan(0, $progress->calls);
        self::assertStringContainsString($record, $result->bytes);
        $again = $repairer->repairValidated($result->bytes, $validate);
        self::assertSame($result->bytes, $again->bytes);
        self::assertSame([], $again->report['steps']);

        $limited = $repairer->repairValidated($bytes, $validate, maxRounds: 1, useUtcForUnknownLocalTime: true);
        self::assertSame('FAILED', $limited->report['activity_validation']);
        self::assertSame('round_limit', $limited->report['stop_reason']);
        $unsupported = $repairer->repairValidated($bytes, static fn (): array => ['status' => 'FAILED', 'errors' => [['message' => 'Unknown failure']]]);
        self::assertSame($bytes, $unsupported->bytes);
        self::assertSame('fixed_point', $unsupported->report['stop_reason']);
    }

    public function testClockContextPrecedesGeographyAndUnresolvedGeographyCanUseUtc(): void
    {
        $utc = (new \DateTimeImmutable('2024-07-01T12:00:00Z'))->getTimestamp() - 631_065_600;
        $position = static fn (float $lat, float $lon): string => FitFixture::message(20, [
            [253, 0x86, pack('V', $utc)],
            [0, 0x85, pack('V', (int) round($lat * 2147483648 / 180))],
            [1, 0x85, pack('V', (int) round($lon * 2147483648 / 180))],
        ]);
        $amsterdam = $position(52.37, 4.89);
        $clock = FitFixture::message(162, [[253, 0x86, pack('V', $utc)], [3, 0x86, pack('V', $utc + 3600)]]);
        $activity = FitFixture::message(34, [[253, 0x86, pack('V', $utc)]]);
        $validate = static function (string $bytes): array {
            $file = EditableFitFile::read($bytes);
            $entry = $file->messages[count($file->messages) - 1];

            return null !== PhysicalMessage::fromRaw($entry['raw'], 14)->integer(5)
                ? ['status' => 'PASS']
                : ['status' => 'FAILED', 'errors' => [['message' => '"Activity Message Local Timestamp is Valid"']]];
        };
        foreach ([
            [$clock.$amsterdam, 3600, GeographicActivityTimeRepairer::NATIVE_RULE],
            [$amsterdam, 7200, GeographicActivityTimeRepairer::RULE],
            [$position(0, 0), 0, GeographicActivityTimeRepairer::UTC_COMPATIBILITY_RULE],
        ] as [$prefix, $offset, $rule]) {
            $result = (new ActivityRepairer())->repairValidated(FitFixture::file($prefix.$activity), $validate, inferTimeZone: true, useUtcForUnknownLocalTime: true);
            self::assertSame('PASS', $result->report['activity_validation']);
            self::assertSame($rule, $result->report['steps'][0]['rule']);
            $file = EditableFitFile::read($result->bytes);
            $entry = $file->messages[count($file->messages) - 1];
            self::assertSame($utc + $offset, PhysicalMessage::fromRaw($entry['raw'], 14)->integer(5));
        }
    }

    public function testPropagatesValidatorAndProgressErrors(): void
    {
        $failure = new \RuntimeException('Operational failure');
        foreach ([false, true] as $progressFails) {
            try {
                (new ActivityRepairer())->repairValidated('',
                    static function () use ($failure, $progressFails): array {
                        if (!$progressFails) {
                            throw $failure;
                        }

                        return ['status' => 'PASS'];
                    },
                    onProgress: static function () use ($failure, $progressFails): void {
                        if ($progressFails) {
                            throw $failure;
                        }
                    },
                );
                self::fail('Operational errors must propagate.');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
        }
    }

    public function testRejectsToolErrorStatus(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new ActivityRepairer())->repairValidated('', static fn (): array => ['status' => 'TOOL_ERROR']);
    }
}
