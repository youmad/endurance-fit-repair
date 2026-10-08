<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapPartitionStartRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedLapBoundaryFixture;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedLapEndFixture;

final class RecordedLapPartitionStartRepairerTest extends TestCase
{
    public function testRecoversFutureStartsOnlyWhenEveryNativeSaveClockAndUnpausedTotalAgrees(): void
    {
        $base = RecordedLapBoundaryFixture::START;
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $packet = static fn (int $number, array $fields, array $developer = []): string => FitFixture::message($number, $fields, $developer, $architecture);
            $record = static fn (int $time): string => $packet(20, [[253, 0x86, pack($word, $base + $time)], [3, 0x02, "\x64"]]);
            $lap = static fn (int $index, int $elapsed, int $save): string => $packet(19, [
                [2, 0x86, pack($word, $base + 2_000_000_000 + $index * 60)],
                [253, 0x86, pack($word, $base + $save)], [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $elapsed)],
                ...(0 === $architecture ? [[254, 0x84, pack($short, $index)]] : []), [200, 0x0D, "\xAB\xCD"],
            ], [[7, 0, "\xFA\xCE"]]);
            $source = FitFixture::file($packet(0, [[0, 0, "\x04"]]).$record(2).$record(60).$lap(0, 60550, 60)
                .$record(100).$lap(1, 40200, 100).$packet(999, [[9, 0x0D, "\xFE\xFF"]])
                .$packet(18, [[2, 0x86, pack($word, $base)], [253, 0x86, pack($word, $base + 100)],
                    [7, 0x86, pack($word, 100750)], [8, 0x86, pack($word, 100750)],
                    ...(0 === $architecture ? [[254, 0x84, pack($short, 0)], [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, 2)]] : []),
                ]).$packet(34, [[253, 0x86, pack($word, $base + 100)], [1, 0x84, pack($short, 1)],
                    ...(0 === $architecture ? [[0, 0x86, pack($word, 100750)]] : []),
                ]));
            $repairer = new RecordedLapPartitionStartRepairer();
            $result = (new PreservingActivityRepairer())->repair($source, RecordedLapPartitionStartRepairer::NATIVE_RULE);
            self::assertSame([$base, $base + 60], array_column($result->report['evidence']['boundaries'], 'selected_start'));
            self::assertSame(100750, $result->report['evidence']['elapsed_sum_ms']);
            $before = EditableFitFile::read($source);
            $after = EditableFitFile::read($result->bytes);
            self::assertCount(count($before->messages), $after->messages);
            foreach ($before->messages as $index => $entry) {
                $old = PhysicalMessage::fromRaw($entry['raw'], 0);
                $new = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0);
                foreach ($old->fields as $field) {
                    if (19 === $old->number && 2 === $field->definition->fieldNumber) {
                        continue;
                    }
                    $retained = array_values(array_filter($new->fields, static fn ($candidate): bool => $candidate->definition->fieldNumber === $field->definition->fieldNumber));
                    self::assertCount(1, $retained);
                    self::assertSame($field->value->bytes(), $retained[0]->value->bytes());
                }
                self::assertSame(array_map(static fn ($field): string => $field->value->bytes(), $entry['raw']->developerFields()), array_map(static fn ($field): string => $field->value->bytes(), $after->messages[$index]['raw']->developerFields()));
            }
            $cases = [$result->bytes, FitFixture::file(substr($source, 14, -2).$packet(21, [[0, 0, "\x08"]])),
                FitFixture::file(substr($source, 14, -2).$packet(101, [[2, 0x86, pack($word, $base)]]))];
            foreach ([[3, 253, $base + 61], [3, 8, 60000], [5, 2, $base + 60], [4, 253, $base + 99],
                [7, 7, 100751], [8, 0, 100000], [1, 253, $base - 1]] as [$message, $field, $value]) {
                $cases[] = $before->apply([['message' => $message, 'field' => $field, 'type' => 0x86, 'value' => $value]]);
            }
            $cases[] = $before->apply([['message' => 3, 'field' => 254, 'type' => 0x84, 'value' => 0xFFFE]]);
            foreach ($cases as $candidate) {
                try {
                    $repairer->repairNativeSummaries($candidate);
                    self::fail('Valid starts or contradicted native evidence must be retained.');
                } catch (RepairFailed $failure) {
                    self::assertNotEmpty($failure->getMessage());
                }
            }
        }
        $selector = new PreservingActivityRepairer();
        self::assertContains(RecordedLapPartitionStartRepairer::NATIVE_RULE, $selector->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => '"Lap Message Start Time and Timestamp are Valid"']]], null));
        self::assertContains(RecordedLapPartitionStartRepairer::NATIVE_RULE, $selector->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => '"Session Message Valid Message Index": expected message_index=0; got missing.']]], null));
        self::assertSame([], $selector->rulesForFailure(['status' => 'PASS'], null));
    }

    public function testSelectsOneCompatibleOriginAndPreservesAllOtherBytes(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = RecordedLapBoundaryFixture::file(['firstElapsed' => 58500, 'lastElapsed' => 41800, 'previousTimestamp' => 60,
                'lastStart' => 61, 'lastRecord' => 100, 'stop' => 100], $architecture);
            $repair = (new PreservingActivityRepairer())->repair($source, RecordedLapPartitionStartRepairer::RULE);
            self::assertSame([0, 699], $repair->report['evidence']['fractional_origin_ms']);
            self::assertSame([RecordedLapBoundaryFixture::START + 58, RecordedLapBoundaryFixture::START + 59], $repair->report['evidence']['boundaries'][0]['admissible_starts']);
            self::assertSame(RecordedLapBoundaryFixture::START + 59, $repair->report['changes'][0]['value']);
            $offset = $repair->report['evidence']['boundaries'][0]['start_byte_offset'];
            self::assertSame(substr($source, 0, $offset), substr($repair->bytes, 0, $offset));
            self::assertSame(substr($source, $offset + 4, -2), substr($repair->bytes, $offset + 4, -2));
            self::assertCount(count(EditableFitFile::read($source)->messages), EditableFitFile::read($repair->bytes)->messages);
            try {
                (new RecordedLapPartitionStartRepairer())->repair($repair->bytes);
                self::fail('Already admissible clocks must be retained.');
            } catch (RepairFailed) {
            }
        }
    }

    public function testUsesTheRecordedStopToExcludeAOneSecondDelayedStart(): void
    {
        $source = RecordedLapBoundaryFixture::file(['firstElapsed' => 60050, 'lastElapsed' => 40850,
            'previousTimestamp' => 60, 'lastStart' => 61, 'stop' => 100, 'lastRecord' => 100]);
        $repair = (new RecordedLapPartitionStartRepairer())->repair($source);
        self::assertSame(RecordedLapBoundaryFixture::START + 60, $repair->report['changes'][0]['value']);
        self::assertSame([0, 99], $repair->report['evidence']['fractional_origin_ms']);
    }

    public function testRequiresEveryPausedLapCounterToFitTheCompleteTimerTrace(): void
    {
        $options = ['firstElapsed' => 58500, 'lastElapsed' => 41800, 'previousTimestamp' => 60,
            'lastStart' => 61, 'lastRecord' => 100, 'stop' => 100, 'interiorTimer' => 1, 'lastTimer' => 31800];
        $source = RecordedLapBoundaryFixture::file($options, 1);
        $repair = (new PreservingActivityRepairer())->repair($source, RecordedLapPartitionStartRepairer::PAUSED_RULE);
        self::assertSame(RecordedLapPartitionStartRepairer::PAUSED_RULE, $repair->report['rule']);
        self::assertSame(RecordedLapBoundaryFixture::START + 59, $repair->report['changes'][0]['value']);
        $offset = $repair->report['evidence']['boundaries'][0]['start_byte_offset'];
        self::assertSame(substr($source, 0, $offset), substr($repair->bytes, 0, $offset));
        self::assertSame(substr($source, $offset + 4, -2), substr($repair->bytes, $offset + 4, -2));
        $crossing = RecordedLapBoundaryFixture::file(array_replace($options, ['firstElapsed' => 75500, 'lastElapsed' => 24800,
            'firstTimer' => 70000, 'lastTimer' => 20300, 'previousTimestamp' => 77, 'lastStart' => 78, 'middleRecord' => 70]));
        $repair = (new RecordedLapPartitionStartRepairer())->repair($crossing, true);
        self::assertSame(RecordedLapBoundaryFixture::START + 76, $repair->report['changes'][0]['value']);
        // Keep Activity and Session totals consistent while moving a pause into the wrong Lap.
        $contradictory = RecordedLapBoundaryFixture::file(array_replace($options, ['firstTimer' => 48500, 'lastTimer' => 41800]));
        $this->expectException(RepairFailed::class);
        (new RecordedLapPartitionStartRepairer())->repair($contradictory, true);
    }

    public function testRequiresTheWholeIndependentPartitionProof(): void
    {
        foreach ([['stop' => 103, 'lastRecord' => 103], ['elapsedMismatch' => 1], ['firstTimer' => 57000], ['earlierPause' => 1],
            ['lastStart' => 63], ['lastIndex' => 2], ['duplicateStart' => 1], ['backwardRecord' => 1], ['stop' => 102], ['eventGroup' => 1]] as $contradiction) {
            $source = RecordedLapEndFixture::file($contradiction);
            try {
                (new RecordedLapPartitionStartRepairer())->repair($source);
                self::fail('Contradictory partition must be declined: '.json_encode($contradiction));
            } catch (RepairFailed $failure) {
                self::assertNotEmpty($failure->getMessage());
            }
        }
    }

    public function testRecoversFrozenFrontClocksOnlyFromTheCompleteRecordedPartition(): void
    {
        foreach ([0, 1] as $architecture) {
            foreach ([false, true] as $paused) {
                $source = $this->frontFile($architecture, $paused);
                $repair = (new PreservingActivityRepairer())->repair($source, RecordedLapPartitionStartRepairer::FRONT_RULE);
                self::assertSame([500, 999], $repair->report['evidence']['fractional_origin_ms']);
                self::assertSame(3600, $repair->report['evidence']['retained_local_offset_seconds']);
                $before = EditableFitFile::read($source);
                $after = EditableFitFile::read($repair->bytes);
                $restored = substr($repair->bytes, 0, -2);
                foreach ($repair->report['changes'] as $change) {
                    $entry = $before->messages[$change['message']];
                    $position = $entry['offset'] + 1;
                    foreach ($entry['raw']->standardFields() as $field) {
                        if ($field->definition->fieldNumber === $change['field']) {
                            $restored = substr_replace($restored, substr($source, $position, 4), $position, 4);
                            break;
                        }
                        $position += $field->definition->size;
                    }
                }
                self::assertSame(substr($source, 0, -2), $restored);
                self::assertCount(count($before->messages), $after->messages);
                self::assertSame(RecordedLapBoundaryFixture::START + 101, PhysicalMessage::fromRaw($after->messages[1]['raw'], 0)->integer(253));
                self::assertSame(RecordedLapBoundaryFixture::START + 41, PhysicalMessage::fromRaw($after->messages[4]['raw'], 0)->integer(2));
                self::assertSame($repair->bytes, (new ActivityRepairer())->repair($source)->bytes);
                self::assertSame([RecordedLapPartitionStartRepairer::FRONT_RULE, PreservingActivityRepairer::IMPOSSIBLE_SINGLE_LAP_START, RecordedLapPartitionStartRepairer::NATIVE_RULE], (new PreservingActivityRepairer())->rulesForFailure([
                    'status' => 'FAILED', 'errors' => [['message' => 'FIT activity stream failed Garmin required check "Lap Message Start Time and Timestamp are Valid": timestamp predates start.']],
                ], null));
                try {
                    (new RecordedLapPartitionStartRepairer())->repairFrontSummaries($repair->bytes);
                    self::fail('Recovered or valid clocks must be retained.');
                } catch (RepairFailed) {
                }
            }
        }
        foreach (['elapsedMismatch', 'wrongPause', 'lateStart', 'missingLocal', 'existingTimestamp', 'eventGroup', 'pool', 'compressedPrefix'] as $contradiction) {
            try {
                (new RecordedLapPartitionStartRepairer())->repairFrontSummaries($this->frontFile(0, true, $contradiction));
                self::fail('Front clock evidence must be complete: '.$contradiction);
            } catch (RepairFailed $failure) {
                self::assertNotEmpty($failure->getMessage());
            }
        }
    }

    private function frontFile(int $architecture, bool $paused, string $contradiction = ''): string
    {
        $base = RecordedLapBoundaryFixture::START;
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $pause = $paused ? 10000 : 0;
        $message = static function (int $number, array $fields) use ($word, $short, $architecture): string {
            $encoded = [];
            foreach ($fields as [$field, $type, $value]) {
                $encoded[] = [$field, $type, 0 === $type || 2 === $type ? chr($value) : pack(0x84 === $type ? $short : $word, $value)];
            }

            return FitFixture::message($number, [...$encoded, [200, 0x0D, "\xAA\xBB"]], [[7, 0, "\xDE\xAD"]], $architecture);
        };
        $event = static fn (int $at, int $type): string => $message(21, [[253, 0x86, $base + $at], [0, 0, 0], [1, 0, $type], [4, 2, 'eventGroup' === $contradiction ? 1 : 0]]);
        $data = $message(0, [[0, 0, 4]])
            .$message(34, [[253, 0x86, $base], [0, 0x86, 100500 - $pause], [1, 0x84, 1], ...('missingLocal' === $contradiction ? [] : [[5, 0x86, $base + 3600]])])
            .$message(18, [[253, 0x86, $base], [2, 0x86, $base], [7, 0x86, 100500 + ('elapsedMismatch' === $contradiction ? 1 : 0)], [8, 0x86, 100500 - $pause],
                [254, 0x84, 0], [25, 0x84, 0], [26, 0x84, 2], [5, 0, 'pool' === $contradiction ? 5 : 1]])
            .$message(19, [[253, 0x86, $base + ('existingTimestamp' === $contradiction ? 40 : 0)], [2, 0x86, $base], [7, 0x86, 40500], [8, 0x86, 40500 - ('wrongPause' === $contradiction ? 0 : $pause)], [254, 0x84, 0]])
            .$message(19, [[253, 0x86, $base], [2, 0x86, $base + ('lateStart' === $contradiction ? 44 : 42)], [7, 0x86, 60000], [8, 0x86, 60000 - ('wrongPause' === $contradiction ? $pause : 0)], [254, 0x84, 1]])
            .('compressedPrefix' === $contradiction ? "\x41\x00\x00\x14\x00\x01\x03\x01\x02".chr(0xA0 | (($base + 1) & 0x1F))."\x75" : '')
            .$event(0, 0).FitFixture::record($base)
            .($paused ? $event(20, 4).$event(30, 0) : '')
            .FitFixture::record($base + 70).FitFixture::record($base + 101).$event(101, 4);

        return FitFixture::file($data);
    }
}
