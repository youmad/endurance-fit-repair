<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedLengthBoundaryRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class RecordedLengthBoundaryRepairerTest extends TestCase
{
    private const int START = 0x10000020;

    public function testCorroboratesEachBoundaryAndRetainsEveryOtherPhysicalField(): void
    {
        $repairer = new PreservingActivityRepairer();
        $error = 'Activity details in sequence pool_length cannot overlap beyond their timeline resolution.';
        self::assertSame([RecordedLengthBoundaryRepairer::RULE], $repairer->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => $error]]], null));
        self::assertSame([], $repairer->rulesForFailure(['status' => 'PASS', 'errors' => [['message' => $error]]], null));
        foreach (['early_idle_lap_boundary' => 2, 'retained_idle_lap_boundary' => 1, 'repeated_start_in_exact_lap_partition' => 1] as $case => $count) {
            foreach ([0, 1] as $architecture) {
                $source = $this->fixture($case, $architecture);
                $result = $repairer->repair($source, RecordedLengthBoundaryRepairer::RULE);
                self::assertSame($result->bytes, (new ActivityRepairer())->repair($source)->bytes);
                self::assertCount($count, $result->report['changes']);
                self::assertSame($case, $result->report['evidence']['boundaries'][0]['basis']);
                $before = EditableFitFile::read($source);
                $after = EditableFitFile::read($result->bytes);
                self::assertCount(count($before->messages), $after->messages);
                $edits = [];
                foreach ($result->report['changes'] as $change) {
                    self::assertSame(2, $change['field']);
                    $edits[$change['message']] = $change['value'];
                }
                foreach ($before->messages as $index => $entry) {
                    $original = PhysicalMessage::fromRaw($entry['raw'], 14);
                    $repaired = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 14);
                    self::assertSame($original->number, $repaired->number);
                    self::assertSame($original->architecture, $repaired->architecture);
                    foreach ($original->fields as $fieldIndex => $field) {
                        $other = $repaired->fields[$fieldIndex];
                        self::assertEquals($field->definition, $other->definition);
                        if (isset($edits[$index]) && 2 === $field->definition->fieldNumber) {
                            self::assertSame($edits[$index], $repaired->integer(2));
                        } else {
                            self::assertSame($field->value->bytes(), $other->value->bytes());
                        }
                    }
                    self::assertEquals($original->developerFields, $repaired->developerFields);
                }
                try {
                    $repairer->repair($result->bytes, RecordedLengthBoundaryRepairer::RULE);
                    self::fail('An already valid partition must remain untouched.');
                } catch (RepairFailed $failure) {
                    self::assertStringContainsString('No original Length overlap', $failure->getMessage());
                }
            }
        }
        $source = $this->fixture('early_idle_lap_boundary', 0);
        $file = EditableFitFile::read($source);
        $paused = $file->apply([['message' => 1, 'field' => 8, 'type' => 0x86, 'value' => 81000],
            ['message' => 2, 'field' => 0, 'type' => 0x86, 'value' => 81000]]);
        $trace = '';
        foreach ([[0, 0], [61, 4], [75, 0], [95, 4]] as [$clock, $type]) {
            $trace .= FitFixture::message(21, [[253, 0x86, pack('V', self::START + $clock)], [0, 0, "\x00"], [1, 0, chr($type)]]);
        }
        $paused = FitFixture::file(substr($paused, 14, -2).$trace);
        $result = $repairer->repair($paused, RecordedLengthBoundaryRepairer::RULE);
        self::assertCount(2, $result->report['changes']);
        self::assertSame('not_proven', $result->report['inference']['historical_accuracy']);
        self::assertSame(81000, PhysicalMessage::fromRaw(EditableFitFile::read($result->bytes)->messages[1]['raw'], 0)->integer(8));
        foreach ([[1, 8, 0x86, 94000], [1, 26, 0x84, 4], [3, 35, 0x84, 1], [6, 3, 0x86, 30501],
            [6, 4, 0x86, 30000], [6, 12, 0x00, 2], [7, 254, 0x84, 0], [9, 2, 0x86, self::START + 73]] as [$message, $field, $type, $value]) {
            try {
                $repairer->repair($file->apply([['message' => $message, 'field' => $field, 'type' => $type, 'value' => $value]]), RecordedLengthBoundaryRepairer::RULE);
                self::fail('Contradictory durations, references or neighbor clocks must not be repaired.');
            } catch (RepairFailed $failure) {
                self::assertNotEmpty($failure->getMessage());
            }
        }
        $event = FitFixture::message(21, [[253, 0x86, pack('V', self::START + 10)], [0, 0, "\x00"], [1, 0, "\x00"]]);
        try {
            $repairer->repair(FitFixture::file(substr($source, 14, -2).$event.$event), RecordedLengthBoundaryRepairer::RULE);
            self::fail('A contradictory timer trace must not be ignored.');
        } catch (RepairFailed $failure) {
            self::assertStringContainsString('timer Events', $failure->getMessage());
        }
    }

    public function testRestoresNativePoolCountersAndBufferedClocksWithoutLosingMeasurements(): void
    {
        foreach ([0, 1] as $architecture) {
            foreach (['copied_counter', 'buffered_idle', 'zero_idle', 'drift'] as $case) {
                $file = EditableFitFile::read($this->fixture('repeated_start_in_exact_lap_partition', $architecture));
                $changes = [];
                foreach ([[1, 78, 0x86, 60500], [1, 8, 0x86, 60500], [2, 0, 0x86, 60500],
                    [3, 70, 0x86, 40500], [3, 32, 0x84, 3], [3, 40, 0x84, 3],
                    [4, 40, 0x84, 0], [4, 2, 0x86, self::START + 41], [4, 253, 0x86, self::START + 51],
                    [5, 70, 0x86, 20000], [5, 40, 0x84, 1], [5, 2, 0x86, self::START + 51],
                    [9, 2, 0x86, self::START + 41], [9, 253, 0x86, self::START + 51], [10, 2, 0x86, self::START + 51]] as [$message, $field, $type, $value]) {
                    $changes[] = ['message' => $message, 'field' => $field, 'type' => $type, 'value' => $value];
                }
                $extra = match ($case) {
                    'copied_counter' => [[3, 7, 0x86, 60500], [3, 8, 0x86, 60500], [3, 32, 0x84, 5], [3, 40, 0x84, 4]],
                    'buffered_idle' => [[4, 2, 0x86, self::START + 44], [9, 2, 0x86, self::START + 44]],
                    'zero_idle' => [[9, 3, 0x86, 0], [9, 4, 0x86, 0]],
                    default => [[6, 3, 0x86, 10250], [7, 2, 0x86, self::START + 11], [8, 2, 0x86, self::START + 22]],
                };
                $base = $file->apply($changes);
                $changes = [];
                foreach ($extra as [$message, $field, $type, $value]) {
                    $changes[] = ['message' => $message, 'field' => $field, 'type' => $type, 'value' => $value];
                }
                $trace = '';
                $events = 'zero_idle' === $case ? [[0, 0], [41, 4], [41, 0], [51, 4], [51, 0], [71, 4]] : [[0, 0], [41, 4], [51, 0], [71, 4]];
                foreach ($events as [$time, $type]) {
                    $trace .= FitFixture::message(21, [[253, 0x86, pack('V', self::START + $time)], [0, 0, "\x00"], [1, 0, chr($type)]]);
                }
                $source = FitFixture::file(substr(EditableFitFile::read($base)->apply($changes), 14, -2).$trace);
                $repair = (new RecordedLengthBoundaryRepairer())->repair($source);
                $before = EditableFitFile::read($source);
                $after = EditableFitFile::read($repair->bytes);
                self::assertCount(count($before->messages), $after->messages);
                $audited = [];
                foreach ($repair->report['changes'] as $change) {
                    $audited[$change['message']][$change['field']] = $change['value'];
                }
                foreach ($before->messages as $index => $entry) {
                    $original = PhysicalMessage::fromRaw($entry['raw'], 14);
                    $updated = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 14);
                    foreach ($original->fields as $fieldIndex => $field) {
                        $number = $field->definition->fieldNumber;
                        if (isset($audited[$index][$number])) {
                            self::assertSame($audited[$index][$number], $updated->integer($number));
                        } else {
                            self::assertSame($field->value->bytes(), $updated->fields[$fieldIndex]->value->bytes());
                        }
                    }
                    self::assertEquals($original->developerFields, $updated->developerFields);
                }
                self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
                foreach ([[3, 70, 0x86, 35000], [1, 26, 0x84, 4]] as [$message, $field, $type, $value]) {
                    try {
                        (new RecordedLengthBoundaryRepairer())->repair(EditableFitFile::read($source)->apply([
                            ['message' => $message, 'field' => $field, 'type' => $type, 'value' => $value],
                        ]));
                        if ('copied_counter' === $case || 26 === $field) {
                            self::fail('Conflicting independent counters or ownership must decline the complete repair.');
                        }
                    } catch (RepairFailed) {
                        self::addToAssertionCount(1);
                    }
                }
            }
        }
    }

    private function fixture(string $case, int $architecture): string
    {
        [$durations, $starts, $lapDurations, $lapStarts, $firsts] = match ($case) {
            'early_idle_lap_boundary' => [[30500, 30500, 14000, 20000], [0, 31, 60, 75], [61000, 14000, 20000], [0, 60, 75], [0, 2, 3]],
            'retained_idle_lap_boundary' => [[37500, 35107, 23188, 20000], [0, 38, 72, 95], [72607, 23188, 20000], [0, 72, 95], [0, 2, 3]],
            default => [[10250, 10250, 20000, 10000, 20000], [0, 0, 20, 40, 50], [40500, 10000, 20000], [0, 40, 50], [0, 3, 4]],
        };
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $elapsed = array_sum($lapDurations);
        $packets = [FitFixture::message(0, [[0, 0, "\x04"]]),
            FitFixture::message(18, [[254, 0x84, pack($short, 0)], [253, 0x86, pack($word, self::START)], [2, 0x86, pack($word, self::START)],
                [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $elapsed)], [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, 3)],
                [5, 0, "\x05"], [6, 0, "\x11"]], architecture: $architecture),
            FitFixture::message(34, [[253, 0x86, pack('V', self::START)], [0, 0x86, pack('V', $elapsed)], [1, 0x84, pack('v', 1)]])];
        foreach ($lapDurations as $index => $duration) {
            $packets[] = FitFixture::message(19, [[254, 0x84, pack($short, $index)], [253, 0x86, pack($word, self::START)],
                [2, 0x86, pack($word, self::START + $lapStarts[$index])], [7, 0x86, pack($word, $duration)], [8, 0x86, pack($word, $duration)],
                [35, 0x84, pack($short, $firsts[$index])], [100, 0x0D, "\xAB\xCD"]], [[7, 0, "\x12\x34"]], $architecture);
        }
        foreach ($durations as $index => $duration) {
            $packets[] = FitFixture::message(101, [[254, 0x84, pack($short, $index)], [253, 0x86, pack($word, self::START)],
                [2, 0x86, pack($word, self::START + $starts[$index])], [3, 0x86, pack($word, $duration)], [4, 0x86, pack($word, $duration)],
                [12, 0, chr($index === $firsts[1] ? 0 : 1)], [5, 0x84, pack($short, 17)], [100, 0x0D, "\xEF\xFE"]], [[7, 0, "\x56\x78"]], $architecture);
        }
        $packets[] = FitFixture::record(self::START + 95);
        $packets[] = "\x41\x00\x00\x14\x00\x01\x03\x01\x02\xA2\x75";

        return FitFixture::file(implode('', $packets));
    }
}
