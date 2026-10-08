<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RepeatedLapIndexRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\SegmentLapElapsedRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class RecordedMetadataRepairTest extends TestCase
{
    public function testSegmentElapsedPreservesEveryOtherPhysicalField(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = $this->fixture(true, $architecture);
            $result = (new PreservingActivityRepairer())->repair($source, SegmentLapElapsedRepairer::RULE);
            self::assertSame([7, 7], array_column($result->report['changes'], 'field'));
            self::assertSame([10_000, 6000], array_column($result->report['changes'], 'value'));
            $this->assertPreserved($source, $result->bytes, $result->report['changes']);
            $this->assertDeclined($result->bytes, SegmentLapElapsedRepairer::RULE);
        }

        foreach (['pausedSegment', 'missingEndpoint'] as $variant) {
            $source = $this->fixture(true, variant: $variant);
            $result = (new SegmentLapElapsedRepairer())->repair($source, true);
            self::assertSame(SegmentLapElapsedRepairer::CLOCK_RULE, $result->report['rule']);
            self::assertSame('not_proven', $result->report['inference']['historical_accuracy']);
            $this->assertPreserved($source, $result->bytes, $result->report['changes']);
        }

        self::assertSame([SegmentLapElapsedRepairer::RULE], (new PreservingActivityRepairer())->rulesForFailure(
            ['status' => 'FAILED', 'errors' => [['message' => 'FIT segment_lap at sequence 12 and byte offset 300 has no usable total_elapsed_time field.']]], null,
        ));
    }

    public function testSegmentElapsedDeclinesContradictoryOrMissingEvidence(): void
    {
        foreach (['timerMismatch', 'missingTimer', 'pausedSegment', 'missingEndpoint', 'duplicateElapsed', 'wrongTimerType', 'arrayTimer', 'missingEventKind', 'unfinishedTimer'] as $variant) {
            $this->assertDeclined($this->fixture(true, variant: $variant), SegmentLapElapsedRepairer::RULE);
            if (!in_array($variant, ['pausedSegment', 'missingEndpoint'], true)) {
                $this->assertDeclined($this->fixture(true, variant: $variant), SegmentLapElapsedRepairer::CLOCK_RULE);
            }
        }
    }

    public function testRepeatedIndexesPreserveThePartitionAndAllOtherBytes(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = $this->fixture(false, $architecture);
            $result = (new PreservingActivityRepairer())->repair($source, RepeatedLapIndexRepairer::RULE);
            self::assertSame([254, 254], array_column($result->report['changes'], 'field'));
            self::assertSame([1, 2], array_column($result->report['changes'], 'value'));
            self::assertSame(strlen($source), strlen($result->bytes));
            $this->assertPreserved($source, $result->bytes, $result->report['changes']);
            $this->assertDeclined($result->bytes, RepeatedLapIndexRepairer::RULE);
        }
    }

    public function testRepeatedIndexesDeclineAmbiguousOrIncompleteSets(): void
    {
        foreach (['mixedIndexes', 'bestLap', 'wrongCount', 'clockGap', 'durationSum', 'duplicateIndex', 'wrongIndexType'] as $variant) {
            $this->assertDeclined($this->fixture(false, variant: $variant), RepeatedLapIndexRepairer::RULE);
        }
    }

    /** @param list<array{message: int, field: int, type: int, value: int}> $changes */
    private function assertPreserved(string $source, string $output, array $changes): void
    {
        $before = EditableFitFile::read($source);
        $after = EditableFitFile::read($output);
        self::assertCount(count($before->messages), $after->messages);
        $edited = [];

        foreach ($changes as $change) {
            $edited[$change['message']][$change['field']] = $change['value'];
        }

        foreach ($before->messages as $index => $entry) {
            $old = PhysicalMessage::fromRaw($entry['raw'], 0);
            $new = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0);
            self::assertSame($old->number, $new->number);
            self::assertEquals($old->developerFields, $new->developerFields);

            foreach ($old->fields as $field) {
                $number = $field->definition->fieldNumber;

                if (isset($edited[$index][$number])) {
                    self::assertSame($edited[$index][$number], $new->integer($number));
                } else {
                    $matches = array_values(array_filter($new->fields, static fn ($candidate): bool => $candidate->definition->fieldNumber === $number));
                    self::assertEquals($field, $matches[0]);
                }
            }

            if (!isset($edited[$index])) {
                $other = $after->messages[$index];
                self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($output, $other['offset'], $other['length']));
            }
        }
    }

    private function assertDeclined(string $bytes, string $rule): void
    {
        try {
            (new PreservingActivityRepairer())->repair($bytes, $rule);
            self::fail('Ambiguous or already repaired evidence must be declined.');
        } catch (RepairFailed $failure) {
            self::assertNotSame('', $failure->getMessage());
        }
    }

    private function fixture(bool $segments, int $architecture = 0, string $variant = ''): string
    {
        $start = 1_000_000_000;
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $message = static fn (int $number, array $fields, array $developer = []): string => FitFixture::message($number, $fields, $developer, $architecture);
        $event = static fn (int $offset, int $type): string => $message(21, [
            [253, 0x86, pack($word, $start + $offset)], [0, 0x02, chr('missingEventKind' === $variant ? 255 : 0)], [1, 0x02, chr($type)],
        ]);
        $data = $message(0, [[0, 0x00, "\x04"]]).$event(0, 0);

        foreach ([0, 10, 12, 18, 20, 25, 60] as $offset) {
            if (20 === $offset && 'missingEndpoint' === $variant) {
                continue;
            }

            $data .= $message(20, [[253, 0x86, pack($word, $start + $offset)], [3, 0x02, "\x78"]]);

            if (12 === $offset) {
                $data .= substr($message(20, [[3, 0x02, "\x79"]]), 0, -2)
                    .chr(0x80 | (($start + 13) & 31))."\x79";
            }
        }

        $data .= $event('pausedSegment' === $variant ? 15 : 20, 1).$event(25, 0);

        if ('unfinishedTimer' !== $variant) {
            $data .= $event(60, 4);
        }

        if ($segments) {
            foreach ([[10, 20], [12, 18]] as $index => [$from, $to]) {
                $fields = [[2, 0x86, pack($word, $start + $from)], [253, 0x86, pack($word, $start + $to)]];

                if ('missingTimer' !== $variant) {
                    $fields[] = [8, 'wrongTimerType' === $variant ? 0x85 : 0x86,
                        pack($word, 'timerMismatch' === $variant ? 9999 : ($to - $from) * 1000).('arrayTimer' === $variant ? pack($word, 10_000) : '')];
                }

                if (1 === $index || 'duplicateElapsed' === $variant) {
                    $fields[] = [7, 0x86, pack($word, 0xFFFFFFFF)];
                }

                if ('duplicateElapsed' === $variant) {
                    $fields[] = [7, 0x86, pack($word, 0xFFFFFFFF)];
                }

                $data .= $message(142, $fields, [[9, 0, "\x12\x34"]]);
            }
        }

        foreach ($segments ? [[0, 60]] : [[0, 10], [10, 20], [20, 60]] as $index => [$from, $to]) {
            $fields = [[2, 0x86, pack($word, $start + $from + ('clockGap' === $variant && 1 === $index ? 1 : 0))],
                [253, 0x86, pack($word, $start + $to)], [7, 0x86, pack($word, ($to - $from) * 1000)],
                [8, 0x86, pack($word, ($to - $from) * 1000 - (60 === $to ? 5000 : 0))],
                [254, 'wrongIndexType' === $variant ? 0x83 : 0x84, pack($short, 'mixedIndexes' === $variant ? $index : 0)]];

            if ('duplicateIndex' === $variant) {
                $fields[] = [254, 0x84, pack($short, 0)];
            }

            $data .= $message(19, $fields, [[9, 0, "\x56\x78"]]);
        }

        $sessionFields = [[254, 0x84, pack($short, 0)], [2, 0x86, pack($word, $start)], [253, 0x86, pack($word, $start + 60)],
            [7, 0x86, pack($word, 60_000)], [8, 0x86, pack($word, 'durationSum' === $variant ? 54_000 : 55_000)],
            [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, 'wrongCount' === $variant ? 2 : ($segments ? 1 : 3))]];

        if ('bestLap' === $variant) {
            $sessionFields[] = [70, 0x84, pack($short, 0)];
        }

        return FitFixture::file($data.$message(18, $sessionFields).$message(34, [[1, 0x84, pack($short, 1)]]));
    }
}
