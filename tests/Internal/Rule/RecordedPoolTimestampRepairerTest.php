<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedPoolTimestampRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class RecordedPoolTimestampRepairerTest extends TestCase
{
    private const int START = 0x10000020;

    public function testInfersOnlyMissingPairedClocksAndPreservesNativeMetricsPausesAndIndexGaps(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = self::file($architecture);
            $result = (new RecordedPoolTimestampRepairer())->repair($source);
            self::assertSame('not_proven', $result->report['inference']['historical_accuracy']);
            self::assertSame([self::START + 10, self::START + 10, self::START + 10, self::START + 10, self::START + 50, self::START + 50], array_column($result->report['changes'], 'value'));
            self::assertSame([self::START + 10, self::START + (1 === $architecture ? 11 : 10)], $result->report['evidence']['pairs'][0]['admissible_timestamp']);
            self::assertSame(1 === $architecture ? [2] : [], $result->report['evidence']['absent_length_indexes']);
            $before = EditableFitFile::read($source);
            $after = EditableFitFile::read($result->bytes);
            self::assertCount(count($before->messages), $after->messages);
            foreach ($before->messages as $index => $entry) {
                $old = PhysicalMessage::fromRaw($entry['raw'], 0);
                $new = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0);
                self::assertSame($old->number, $new->number);
                self::assertSame($old->architecture, $new->architecture);
                self::assertEquals($old->developerFields, $new->developerFields);
                foreach ($old->fields as $field) {
                    if (in_array($old->number, [20, 101], true) && 253 === $field->definition->fieldNumber) {
                        continue;
                    }
                    self::assertContainsEquals($field, $new->fields);
                }
                if (!in_array($old->number, [20, 101], true)) {
                    self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $after->messages[$index]['offset'], $after->messages[$index]['length']));
                }
            }
            self::assertCount(6, $result->report['changes']);
            self::assertSame([253], array_unique(array_column($result->report['changes'], 'field')));
            self::declines($result->bytes);
        }
    }

    public function testDeclinesUnpairedOrContradictoryEvidenceAndSelectsOnlyMissingClockFailures(): void
    {
        $file = EditableFitFile::read(self::file(0));
        foreach ([[20, 5, 0x86, 2499], [20, 6, 0x84, 999], [101, 3, 0x86, 0],
            [101, 254, 0x84, 1], [18, 9, 0x86, 5001], [18, 33, 0x84, 4], [19, 35, 0x84, 1],
            [20, 253, 0x86, self::START + 10], [101, 253, 0x86, self::START + 10]] as [$number, $field, $type, $value]) {
            $index = array_find_key($file->messages, static fn (array $entry): bool => $number === $entry['raw']->globalMessageNumber());
            self::assertNotNull($index);
            self::declines($file->apply([['message' => $index, 'field' => $field, 'type' => $type, 'value' => $value]]));
        }
        foreach ([[101, 12, 2], [18, 5, 1], [21, 1, 4]] as [$number, $fieldNumber, $value]) {
            $index = array_find_key($file->messages, static fn (array $entry): bool => $number === $entry['raw']->globalMessageNumber());
            self::assertNotNull($index);
            $entry = $file->messages[$index];
            $position = $entry['offset'] + 1 - $file->header->headerSize;
            foreach ($entry['raw']->standardFields() as $candidate) {
                if ($fieldNumber === $candidate->definition->fieldNumber) {
                    break;
                }
                $position += $candidate->definition->size;
            }
            $data = substr($file->bytes, $file->header->headerSize, $file->header->dataSize);
            self::declines(FitFixture::file(substr_replace($data, chr($value), $position, 1)));
        }
        self::declines(self::file(0, unpaired: true));
        $selector = new PreservingActivityRepairer();
        foreach (['FIT length at sequence 8 and byte offset 44 has no usable timestamp field.',
            '"Record Message Timestamps Fall Within Session Message Times": first Session start_time and first Record timestamp are required.'] as $message) {
            self::assertContains(RecordedPoolTimestampRepairer::RULE, $selector->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => $message]]], null));
        }
        self::assertSame([], $selector->rulesForFailure(['status' => 'PASS'], null));
        self::assertNotContains(RecordedPoolTimestampRepairer::RULE, $selector->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => '"Activity Message Local Timestamp is Valid"']]], null));
    }

    private static function declines(string $bytes): void
    {
        try {
            (new RecordedPoolTimestampRepairer())->repair($bytes);
            self::fail('Uncorroborated or already timed pool pairs must remain unchanged.');
        } catch (RepairFailed $failure) {
            self::assertNotSame('', $failure->getMessage());
        }
    }

    private static function file(int $architecture, bool $unpaired = false): string
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $enum = 0 === $architecture ? 0x00 : 0x02;
        $message = static fn (int $n, array $fields, array $developer = []): string => FitFixture::message($n, $fields, $developer, $architecture);
        $event = static fn (int $time, int $type): string => $message(21, [[253, 0x86, pack($word, self::START + $time)], [0, $enum, "\x00"], [1, $enum, chr($type)]]);
        $timestamps = 1 === $architecture ? [[253, 0x86, "\xFF\xFF\xFF\xFF"]] : [];
        $record = static fn (int $distance, ?int $speed): string => $message(20, [...$timestamps, [5, 0x86, pack($word, $distance)], [6, 0x84, pack($short, $speed ?? 0xFFFF)]], [[3, 7, "\x80\xFE"]]);
        $length = static fn (int $index, int $start, int $elapsed, int $type, ?int $speed): string => $message(101, [...$timestamps,
            [254, 0x84, pack($short, $index)], [2, 0x86, pack($word, self::START + $start)],
            [3, 0x86, pack($word, $elapsed)], [4, 0x86, pack($word, $elapsed)], [0, $enum, "\x1C"], [1, $enum, "\x01"],
            [12, $enum, chr($type)], [6, 0x84, pack($short, $speed ?? 0xFFFF)]]);
        $lap = static fn (int $index, int $first, int $count): string => $message(19, [[254, 0x84, pack($short, $index)], [35, 0x84, pack($short, $first)], [32, 0x84, pack($short, $count)],
            [2, 0x86, pack($word, self::START + $index * 30)], [253, 0x86, pack($word, self::START + 60)], [7, 0x86, pack($word, 30000)], [8, 0x86, pack($word, 15000 + $index * 15000)]]);
        $gap = 1 === $architecture ? 1 : 0;
        $data = $message(0, [[0, $enum, "\x04"]]).$event(0, 0)
            .$record(2500, 1000).($unpaired ? '' : $length(0, 0, 10000 + $gap * 500, 1, 1000))
            .$record(2500, null).$length(1, 10, 0, 0, null).$lap(0, 0, 2 + $gap)
            .$event(15, 4).$event(30, 0).$record(5000, 1250).$event(60, 4).$length(2 + $gap, 30, 20000, 1, 1250).$lap(1, 2 + $gap, 1)
            .$message(18, [[254, 0x84, pack($short, 0)], [2, 0x86, pack($word, self::START)], [253, 0x86, pack($word, self::START + 60)],
                [5, $enum, "\x05"], [6, $enum, "\x11"], [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, 2)],
                [33, 0x84, pack($short, 3 + $gap)], [47, 0x84, pack($short, 2 + $gap)], [44, 0x84, pack($short, 2500)], [9, 0x86, pack($word, 5000)],
                [7, 0x86, pack($word, 60000)], [8, 0x86, pack($word, 45000)]])
            .$message(34, [[1, 0x84, pack($short, 1)], [0, 0x86, pack($word, 45000)], [253, 0x86, pack($word, self::START + 60)]]);

        return FitFixture::file($data);
    }
}
