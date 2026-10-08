<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedObservationOrderRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class RecordedObservationOrderRepairerTest extends TestCase
{
    public function testStableOrderRetainsCompletePacketsAndEveryOtherByte(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = $this->fixture($architecture);
            $result = (new PreservingActivityRepairer())->repair($source, RecordedObservationOrderRepairer::RULE);
            $before = EditableFitFile::read($source);
            $after = EditableFitFile::read($result->bytes);
            self::assertSame(strlen($source), strlen($result->bytes));
            self::assertCount(count($before->messages), $after->messages);
            self::assertSame([], $result->report['changes']);
            $moves = [];

            foreach ($result->report['moved_messages'] as $move) {
                $moves[$move['toMessage']] = $move['fromOriginalMessage'];
            }

            $expected = $source;
            $heartRates = [];

            foreach ($after->messages as $index => $entry) {
                $original = $before->messages[$moves[$index] ?? $index];
                $packet = substr($source, $original['offset'], $original['length']);
                self::assertSame($packet, substr($result->bytes, $entry['offset'], $entry['length']));

                if (isset($moves[$index])) {
                    $target = $before->messages[$index];
                    $expected = substr_replace($expected, $packet, $target['offset'], $target['length']);
                }

                $message = PhysicalMessage::fromRaw($entry['raw'], 0);

                if (20 === $message->number) {
                    $heartRates[] = $message->integer(3);
                }
            }

            self::assertSame([100, 102, 104, 101, 103, 105], $heartRates);
            self::assertSame(substr($expected, 0, -2), substr($result->bytes, 0, -2));
            $this->assertDeclined($result->bytes);
        }

        self::assertSame([RecordedObservationOrderRepairer::RULE], (new PreservingActivityRepairer())->rulesForFailure(
            ['status' => 'FAILED', 'errors' => [['message' => 'Observation cannot be earlier than the latest activity event.']]], null,
        ));
    }

    public function testAmbiguousBoundsAndContextDependenciesAreDeclined(): void
    {
        foreach (['beforeSession', 'afterSession', 'relativeSession', 'missingStart', 'missingElapsed', 'zeroElapsed', 'duplicateStart',
            'twoSessions', 'earlySession', 'missingTimestamp', 'invalidTimestamp', 'duplicateTimestamp', 'wrongTimestampType',
            'accumulatedField', 'developerField', 'compressedMessage', 'interveningMessage', 'differentDefinition', 'implicitTimestampContext'] as $variant) {
            $this->assertDeclined($this->fixture(variant: $variant));
        }

        $file = EditableFitFile::read($this->fixture());

        foreach ([[2 => 3], [2 => 3, 3 => 3], [999 => 999], [0 => 0]] as $order) {
            try {
                $file->reorderRecords($order);
                self::fail('Invalid packet permutations must be declined.');
            } catch (RepairFailed $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    private function assertDeclined(string $bytes): void
    {
        try {
            (new RecordedObservationOrderRepairer())->repair($bytes);
            self::fail('Ambiguous or already chronological Records must be declined.');
        } catch (RepairFailed $failure) {
            self::assertNotSame('', $failure->getMessage());
        }
    }

    private function fixture(int $architecture = 0, string $variant = ''): string
    {
        $start = 'relativeSession' === $variant ? 100 : 1_000_000_000;
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $message = static fn (int $number, array $fields, array $developer = []): string => FitFixture::message($number, $fields, $developer, $architecture);
        $session = $message(18, [
            ...('missingStart' === $variant ? [] : [[2, 0x86, pack($word, $start)]]),
            ...('duplicateStart' === $variant ? [[2, 0x86, pack($word, $start)]] : []),
            ...('missingElapsed' === $variant ? [] : [[7, 0x86, pack($word, 'zeroElapsed' === $variant ? 0 : 10_000)]]),
        ], [[9, 0, "\x12\x34"]]);
        $data = $message(0, [[0, 0x00, "\x04"]]).$message(21, [[253, 0x86, pack($word, $start)]]);

        if ('earlySession' === $variant) {
            $data .= $session;
        }

        foreach ('implicitTimestampContext' === $variant ? [0, 4, 2, 4, 10, 3] : [0, 4, 2, 4, 3, 10] as $ordinal => $offset) {
            if (2 === $ordinal && 'interveningMessage' === $variant) {
                $data .= $message(200, [[9, 0x02, "\x7A"]], [[9, 0, "\x56\x78"]]);
            }

            $timestamp = $start + $offset;

            if (2 === $ordinal) {
                $timestamp = match ($variant) {
                    'beforeSession' => $start - 2,
                    'afterSession' => $start + 12,
                    'invalidTimestamp' => 0xFFFFFFFF,
                    default => $timestamp,
                };
            }

            $fields = [
                [0, 0x85, pack($word, $ordinal)], [1, 0x85, pack($word, $ordinal + 1)], [2, 0x84, pack($short, $ordinal + 2)],
                [3, 0x02, chr(100 + $ordinal)], [5, 0x86, pack($word, $ordinal * 100)], [6, 0x84, pack($short, $ordinal * 10)],
            ];

            if (2 !== $ordinal || 'missingTimestamp' !== $variant) {
                $fields[] = [253, 2 === $ordinal && 'wrongTimestampType' === $variant ? 0x85 : 0x86, pack($word, $timestamp)];
            }

            if (2 === $ordinal && 'duplicateTimestamp' === $variant) {
                $fields[] = [253, 0x86, pack($word, $timestamp)];
            }

            if (2 === $ordinal && 'accumulatedField' === $variant) {
                $fields[] = [8, 0x0D, "\x00\x01\x02"];
            }

            if (2 === $ordinal && 'differentDefinition' === $variant) {
                array_shift($fields);
            }

            $data .= $message(20, $fields, 2 === $ordinal && 'developerField' === $variant ? [[9, 0, "\xAA\xBB"]] : []);
        }

        if ('compressedMessage' === $variant) {
            $data .= substr($message(20, [[3, 0x02, "\x78"]]), 0, -2).chr(0x80 | (($start + 10) & 31))."\x78";
        }

        if ('earlySession' !== $variant) {
            $data .= $session;
        }

        return FitFixture::file($data.('twoSessions' === $variant ? $session : '').$message(34, [[1, 0x84, pack($short, 1)]]));
    }
}
