<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\MissingSessionLapRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedActivitySummaryRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapElapsedRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapReferenceRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedMissingSetLapRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedObservationOrderRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedPoolPartitionRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedRecordClockRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedSessionLapPartitionRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedSingleLapFieldsRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedSportRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummarySaveRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class NativeSummaryRepairersTest extends TestCase
{
    private const int START = 1_000_000_000;

    public function testElapsedPartitionRetainsNativeTimersPausesStartsAndEveryOtherField(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = $this->partition($architecture);
            $repair = (new RecordedLapElapsedRepairer())->repair($source);
            self::assertSame([20_000, 40_000], array_column($repair->report['changes'], 'value'));
            self::assertSame([7, 7], array_column($repair->report['changes'], 'field'));
            self::assertSame(strlen($source), strlen($repair->bytes));
            $this->assertOriginalFieldsRetained($source, $repair);
            $this->assertDeclined(new RecordedLapElapsedRepairer(), $repair->bytes);
        }
        $source = $this->partition(variant: 'observedExtent');
        $this->assertDeclined(new RecordedLapElapsedRepairer(), $source);
        $repair = (new RecordedLapElapsedRepairer())->repair($source, true);
        self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
        self::assertSame([7, 7, 7], array_column($repair->report['changes'], 'field'));
        $this->assertOriginalFieldsRetained($source, $repair);
        $selector = new PreservingActivityRepairer();
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT lap at sequence 4 does not abut it:']]];
        self::assertContains(RecordedLapElapsedRepairer::RULE, $selector->rulesForFailure($failure, null));
        self::assertNotContains(RecordedLapElapsedRepairer::CLOCK_RULE, $selector->rulesForFailure($failure, null));
        self::assertContains(RecordedLapElapsedRepairer::CLOCK_RULE, $selector->rulesForFailure($failure, null, true));
        self::assertSame([], $selector->rulesForFailure(['status' => 'PASS'], null, true));
        $s = self::START;
        $m = static fn (int $n, array $f): string => FitFixture::message($n, $f);
        $laps = '';
        for ($i = 0; $i < 7; ++$i) {
            $timer = $i < 4 ? 10_900 : ($i < 6 ? 5000 : 10_000);
            $laps .= $m(19, [[254, 0x84, pack('v', $i)], [2, 0x86, pack('V', $s + $i * 10)],
                [253, 0x86, pack('V', $s + ($i + 1) * 10)], [7, 0x86, pack('V', max(10_000, $timer))], [8, 0x86, pack('V', $timer)]]);
        }
        $source = FitFixture::file($m(0, [[0, 0x00, "\x04"]]).FitFixture::record($s).FitFixture::record($s + 70).$laps
            .$m(18, [[254, 0x84, pack('v', 0)], [25, 0x84, pack('v', 0)], [26, 0x84, pack('v', 6)],
                [2, 0x86, pack('V', $s)], [253, 0x86, pack('V', $s + 60)], [7, 0x86, pack('V', 63_600)], [8, 0x86, pack('V', 53_600)]])
            .$m(18, [[254, 0x84, pack('v', 1)], [25, 0x84, pack('v', 6)], [26, 0x84, pack('v', 1)],
                [2, 0x86, pack('V', $s + 60)], [253, 0x86, pack('V', $s + 70)], [7, 0x86, pack('V', 10_000)], [8, 0x86, pack('V', 10_000)]])
            .$m(34, [[253, 0x86, pack('V', $s + 70)], [0, 0x86, pack('V', 63_600)], [1, 0x84, pack('v', 2)]]));
        $repair = (new RecordedLapElapsedRepairer())->repair($source, true);
        self::assertSame([8001, 8399, 60_000], array_column($repair->report['changes'], 'value'));
        self::assertSame([7, 7, 7], array_column($repair->report['changes'], 'field'));
        $this->assertOriginalFieldsRetained($source, $repair);

        $event = static fn (int $t, int $kind): string => $m(21, [[253, 0x86, pack('V', $s + $t)], [0, 0x00, "\x00"], [1, 0x00, chr($kind)]]);
        $data = $m(0, [[0, 0x00, "\x04"]]).$event(0, 0).FitFixture::record($s).$event(10, 4).$event(21, 0).FitFixture::record($s + 21);
        foreach ([[0, 0, 10, 10_000], [1, 20, 21, 425], [2, 20, 30, 9000]] as [$id, $from, $save, $duration]) {
            $data .= $m(19, [[254, 0x84, pack('v', $id)], [2, 0x86, pack('V', $s + $from)], [253, 0x86, pack('V', $s + $save)],
                [7, 0x86, pack('V', $duration)], [8, 0x86, pack('V', $duration)]]);
        }
        $source = FitFixture::file($data.FitFixture::record($s + 30).$event(30, 4)
            .$m(18, [[254, 0x84, pack('v', 0)], [25, 0x84, pack('v', 0)], [26, 0x84, pack('v', 3)],
                [2, 0x86, pack('V', $s)], [253, 0x86, pack('V', $s + 30)], [7, 0x86, pack('V', 19_425)], [8, 0x86, pack('V', 19_425)]])
            .$m(34, [[253, 0x86, pack('V', $s + 30)], [0, 0x86, pack('V', 19_425)], [1, 0x84, pack('v', 1)]]));
        $this->assertDeclined(new RecordedLapElapsedRepairer(), $source);
        $repair = (new RecordedLapElapsedRepairer())->repair($source, true);
        self::assertSame([20_000, 10_000, 30_425], array_column($repair->report['changes'], 'value'));
        $this->assertOriginalFieldsRetained($source, $repair);
        $conflicting = EditableFitFile::read($source)->apply([['message' => 7, 'field' => 7, 'type' => 0x86, 'value' => 1500]]);
        try {
            (new RecordedLapElapsedRepairer())->repair($conflicting, true);
            self::fail('Equal starts are permitted only for an original subsecond Lap.');
        } catch (RepairFailed) {
            self::addToAssertionCount(1);
        }
    }

    public function testSummaryOnlyRecoveryRetainsEmptyObservationsAndNativeCountersWithoutGuessingFinish(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $m = static fn (int $n, array $f): string => FitFixture::message($n, $f, [], $architecture);
            $u = static fn (int $n, int $v): array => [$n, 0x86, pack($word, $v)];
            $source = FitFixture::file($m(0, [[0, 0x02, "\x04"], $u(4, self::START)])
                .$m(18, [$u(2, self::START), $u(253, self::START), $u(7, 60_000_000), $u(8, 59_000_000), [5, 0x02, "\x01"]]));
            $selector = new PreservingActivityRepairer();
            $failure = ['status' => 'FAILED', 'errors' => [['message' => '"Activity Message Exists"']]];
            self::assertNotContains(RecordedActivitySummaryRepairer::SUMMARY_ONLY_RULE, $selector->rulesForFailure($failure, null));
            self::assertContains(RecordedActivitySummaryRepairer::SUMMARY_ONLY_RULE, $selector->rulesForFailure($failure, null, true));
            $repair = $selector->repair($source, RecordedActivitySummaryRepairer::SUMMARY_ONLY_RULE);
            $this->assertOriginalFieldsRetained($source, $repair);
            self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
            self::assertTrue($repair->report['inserted_messages'][0]['synthetic']);
            $messages = array_map(static fn (array $entry): PhysicalMessage => PhysicalMessage::fromRaw($entry['raw'], 0), EditableFitFile::read($repair->bytes)->messages);
            self::assertSame([0, 19, 18, 34], array_column($messages, 'number'));
            self::assertSame(self::START, $messages[1]->integer(253));
            self::assertSame(60_000_000, $messages[1]->integer(7));
            self::assertSame(59_000_000, $messages[1]->integer(8));
            self::assertSame(59_000_000, $messages[3]->integer(0));
            foreach ([$repair->bytes, FitFixture::file($m(0, [[0, 0x00, "\x04"], $u(4, self::START - 1)]).$m(18, [$u(2, self::START), $u(253, self::START), $u(7, 10_000), $u(8, 10_000), [5, 0x00, "\x01"]])),
                EditableFitFile::read($source)->apply([['message' => 1, 'field' => 26, 'type' => 0x84, 'value' => 2]]),
                EditableFitFile::read($source)->apply([['message' => 1, 'field' => 8, 'type' => 0x86, 'value' => 60_000_001]]),
                FitFixture::file($m(0, [[0, 0x00, "\x04"], $u(4, self::START)]).$m(20, [$u(253, self::START)]).$m(18, [$u(2, self::START)])),
            ] as $declined) {
                try {
                    (new RecordedActivitySummaryRepairer())->repairSummaryOnly($declined);
                    self::fail('Unknown clocks, lost Laps, conflicting counters and observed activities cannot use summary-only recovery.');
                } catch (RepairFailed) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function testNativeMultisessionPairingAndStableRecordOrderRetainAllTimersLapsAndMeasurements(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $m = static fn (int $n, array $f): string => FitFixture::message($n, $f, [], $architecture);
            $u = static fn (int $n, int $v): array => [$n, 0x86, pack($word, $v)];
            $data = $m(0, [[0, 0x00, "\x04"]]);
            foreach ([0, 5, 4, 10, 20] as $i => $t) {
                $data .= $m(20, [$u(253, self::START + $t), [3, 0x02, chr(100 + $i)], $u(5, $i * 100)]);
            }
            foreach ([19, 18] as $n) {
                foreach ([0, 1] as $i) {
                    $data .= $m($n, [$u(2, self::START + 10 * $i + (18 === $n && 0 === $i ? 3 : 0)), $u(253, self::START + 10 * ($i + 1)),
                        $u(7, 18 === $n && 0 === $i ? 8240 : 10_000), $u(8, 0 === $i ? 8240 : 9000),
                        ...(19 === $n ? [[254, 0x84, pack($short, 0)]] : []),
                    ]);
                }
            }
            $source = FitFixture::file($data.$m(34, [$u(253, self::START + 20), $u(0, 9000), [1, 0x84, pack($short, 2)]]));
            $repairer = new RecordedSessionLapPartitionRepairer();
            $repair = $repairer->repair($source);
            $this->assertOriginalFieldsRetained($source, $repair);
            self::assertNotContains(8, array_column($repair->report['changes'], 'field'));
            self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
            $file = EditableFitFile::read($repair->bytes);
            foreach ([8, 9] as $i) {
                $s = PhysicalMessage::fromRaw($file->messages[$i]['raw'], 0);
                self::assertSame($i - 8, $s->integer(254));
                self::assertSame($i - 8, $s->integer(25));
                self::assertSame(1, $s->integer(26));
            }
            self::assertSame(self::START, PhysicalMessage::fromRaw($file->messages[8]['raw'], 0)->integer(2));
            self::assertSame(10_000, PhysicalMessage::fromRaw($file->messages[8]['raw'], 0)->integer(7));
            $ordered = (new PreservingActivityRepairer())->repair($repair->bytes, RecordedObservationOrderRepairer::MULTI_SESSION_RULE);
            self::assertSame([], $ordered->report['changes']);
            self::assertCount(2, $ordered->report['moved_messages']);
            $moves = array_column($ordered->report['moved_messages'], 'fromOriginalMessage', 'toMessage');
            foreach (EditableFitFile::read($ordered->bytes)->messages as $i => $entry) {
                $old = $file->messages[$moves[$i] ?? $i];
                self::assertSame(substr($repair->bytes, $old['offset'], $old['length']), substr($ordered->bytes, $entry['offset'], $entry['length']));
            }
            foreach ([$repair->bytes, EditableFitFile::read($source)->apply([['message' => 8, 'field' => 26, 'type' => 0x84, 'value' => 2]]),
                EditableFitFile::read($source)->apply([['message' => 8, 'field' => 8, 'type' => 0x86, 'value' => 6000]]),
                EditableFitFile::read($source)->apply([['message' => 7, 'field' => 2, 'type' => 0x86, 'value' => self::START + 15]]),
            ] as $declined) {
                try {
                    $repairer->repair($declined);
                    self::fail('Incomplete or contradictory whole-session Lap evidence must decline.');
                } catch (RepairFailed) {
                    self::addToAssertionCount(1);
                }
            }
            foreach ([$source, $ordered->bytes, $file->apply([['message' => 9, 'field' => 25, 'type' => 0x84, 'value' => 0]])] as $declined) {
                try {
                    (new RecordedObservationOrderRepairer())->repair($declined, true);
                    self::fail('Record ordering requires complete native references and a real inversion.');
                } catch (RepairFailed) {
                    self::addToAssertionCount(1);
                }
            }
        }
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'Observation cannot be earlier than the latest activity event.']]];
        $selector = new PreservingActivityRepairer();
        self::assertNotContains(RecordedObservationOrderRepairer::MULTI_SESSION_RULE, $selector->rulesForFailure($failure, null));
        self::assertContains(RecordedObservationOrderRepairer::MULTI_SESSION_RULE, $selector->rulesForFailure($failure, null, true));
    }

    public function testMissingSummariesAndFieldsUseNativeEvidenceAndLabelUnknownSegmentationAndSport(): void
    {
        $start = self::START;
        $m = static fn (int $n, array $fields): string => FitFixture::message($n, $fields);
        $id = $m(0, [[0, 0x00, "\x04"]]);
        $event = static fn (int $t, int $kind, int $type = 0x00): string => FitFixture::message(21, [[253, 0x86, pack('V', $t)], [0, $type, "\x00"], [1, $type, chr($kind)]]);
        $records = FitFixture::record($start).FitFixture::record($start + 10);
        $activity = $m(34, [[253, 0x86, pack('V', $start + 10)], [0, 0x86, pack('V', 10_000)], [1, 0x84, pack('v', 1)]]);
        $lap = FitFixture::summary(19, $start, 10_000, [[254, 0x84, pack('v', 0)], [8, 0x86, pack('V', 10_000)]]);
        foreach ([[$id, $event($start, 0).$records.$activity], [$id, $event($start, 0).$records.$event($start + 10, 4).$activity],
            [$id, $event($start, 0).$records.$event($start + 10, 4)], [$id, $records.$lap],
            [$m(0, [[0, 0x02, "\x04"]]), $event($start, 0, 0x02).$records.$event($start + 10, 4, 0x02).$activity],
        ] as [$fileId, $data]) {
            $source = FitFixture::file($fileId.$data);
            $repair = (new RecordedActivitySummaryRepairer())->repair($source);
            $this->assertOriginalFieldsRetained($source, $repair);
            self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
            self::assertSame('generic_placeholder_original_sport_unknown', $repair->report['inference']['sport_source']);
            $summaries = [];
            foreach (EditableFitFile::read($repair->bytes)->messages as $entry) {
                $p = PhysicalMessage::fromRaw($entry['raw'], 0);
                if (in_array($p->number, [18, 19, 34], true)) {
                    $summaries[$p->number] = $p;
                }
            }
            self::assertSame(0, $summaries[18]->integer(5));
            self::assertSame(10_000, $summaries[18]->integer(8));
            self::assertSame(1, $summaries[18]->integer(26));
            $this->assertDeclined(new RecordedActivitySummaryRepairer(), $repair->bytes);
        }
        $source = FitFixture::file($id.$m(12, [[0, 0x00, "\x02"]]).$records
            .$m(19, [[8, 0x86, pack('V', 9000)]])
            .FitFixture::summary(18, $start, 10_000, [[8, 0x86, pack('V', 9000)], [26, 0x84, pack('v', 1)]])
            .$m(34, [[253, 0x86, pack('V', $start + 10)], [0, 0x86, pack('V', 9000)], [1, 0x84, pack('v', 1)]]));
        $repair = (new RecordedSingleLapFieldsRepairer())->repair($source);
        $this->assertOriginalFieldsRetained($source, $repair);
        $sport = (new RecordedSportRepairer())->repair($repair->bytes);
        self::assertSame([['message' => 5, 'field' => 5, 'type' => 0x00, 'value' => 2]], $sport->report['changes']);
        $this->assertOriginalFieldsRetained($repair->bytes, $sport);
        $unknown = FitFixture::file($id.$m(18, [[5, 0x00, "\xFF"]]));
        $generic = (new RecordedSportRepairer())->repair($unknown, true);
        self::assertSame('generic_placeholder_original_sport_unknown', $generic->report['inference']['sport_source']);
        $this->assertOriginalFieldsRetained($unknown, $generic);
        try {
            (new RecordedSportRepairer())->repair(FitFixture::file($id.$m(18, [[5, 0x00, "\x34"]])), true);
            self::fail('Unsupported native sport codes must be retained.');
        } catch (RepairFailed) {
            self::addToAssertionCount(1);
        }
        $empty = FitFixture::file($id.$event($start, 0).$event($start + 3, 4).$event($start + 5, 0).$records.$event($start + 10, 4)
            .$m(19, []).$m(18, []).$m(34, [[253, 0x86, pack('V', $start + 10)], [0, 0x86, pack('V', 8000)], [1, 0x84, pack('v', 1)]]));
        $filled = (new RecordedSingleLapFieldsRepairer())->repair($empty);
        $this->assertOriginalFieldsRetained($empty, $filled);
        self::assertSame(8000, PhysicalMessage::fromRaw(EditableFitFile::read($filled->bytes)->messages[7]['raw'], 0)->integer(8));
    }

    public function testCounterSaveRecoveryAndExplicitMinimumElapsedRetainNativeTimersAndUnknownHistory(): void
    {
        $s = self::START;
        $m = static fn (int $n, array $fields): string => FitFixture::message($n, $fields);
        $id = $m(0, [[0, 0x00, "\x04"]]);
        foreach (['missingLap', 'missingSave', 'minimum'] as $variant) {
            $minimum = 'minimum' === $variant;
            $duration = $minimum ? 0 : 10_000;
            $save = $minimum || 'missingLap' === $variant ? $s : $s + 5;
            $session = $m(18, [[2, 0x86, pack('V', $s)], [253, 0x86, pack('V', $save)], [7, 0x86, pack('V', $duration)], [8, 0x86, pack('V', 10_000)]]);
            $activity = $m(34, [[253, 0x86, pack('V', 'missingLap' === $variant ? $s + 10 : $save)], [0, 0x86, pack('V', 10_000)], [1, 0x84, pack('v', 1)]]);
            $lap = 'missingLap' === $variant ? '' : $m(19, [[2, 0x86, pack('V', $s)], [7, 0x86, pack('V', $duration)], [8, 0x86, pack('V', 10_000)], ...($minimum ? [[253, 0x86, pack('V', $s)]] : [])]);
            $records = 'missingSave' === $variant ? FitFixture::record($s).FitFixture::record($s + 8) : '';
            $source = FitFixture::file($id.$records.$lap.$session.$activity);
            $repair = (new RecordedSummarySaveRepairer())->repair($source, $minimum);
            $this->assertOriginalFieldsRetained($source, $repair);
            self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
            self::assertNotContains(8, array_column($repair->report['changes'], 'field'));
            if ($minimum) {
                self::assertContains(7, array_column($repair->report['changes'], 'field'));
                $this->assertDeclined(new RecordedSummarySaveRepairer(), $source);
            }
            $this->assertDeclined(new RecordedSummarySaveRepairer(), $repair->bytes);
        }
        $lost = FitFixture::file($id.$m(18, [[2, 0x86, pack('V', $s)], [253, 0x86, pack('V', $s)], [7, 0x86, pack('V', 10_000)], [8, 0x86, pack('V', 10_000)], [26, 0x84, pack('v', 68)]])
            .$m(34, [[253, 0x86, pack('V', $s + 10)], [0, 0x86, pack('V', 10_000)], [1, 0x84, pack('v', 1)]]));
        $this->assertDeclined(new RecordedSummarySaveRepairer(), $lost);
        $event = static fn (?int $t, int $kind): string => FitFixture::message(21, [
            [253, 0x86, pack('V', $t ?? 0xFFFFFFFF)], [0, 0x00, "\x00"], [1, 0x00, chr($kind)], [4, 0x02, "\x00"],
        ]);
        $lap = $m(19, [[2, 0x86, pack('V', $s)], [253, 0x86, pack('V', $s + 10)], [7, 0x86, pack('V', 8000)], [8, 0x86, pack('V', 8000)], [254, 0x84, pack('v', 0)]]);
        $session = $m(18, [[2, 0x86, pack('V', $s)], [253, 0x86, pack('V', $s + 10)], [7, 0x86, pack('V', 8000)], [8, 0x86, pack('V', 8000)],
            [254, 0x84, pack('v', 0)], [25, 0x84, pack('v', 0)], [26, 0x84, pack('v', 1)]]);
        $source = FitFixture::file($id.$event(null, 0).FitFixture::record($s + 2).FitFixture::record($s + 10).$event($s + 10, 4).$lap.$session
            .$m(34, [[253, 0x86, pack('V', $s + 10)], [0, 0x86, pack('V', 8000)], [1, 0x84, pack('v', 1)]]));
        $selector = new PreservingActivityRepairer();
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT event at sequence 3 and byte offset 20 has no usable timestamp field.']]];
        self::assertNotContains(PreservingActivityRepairer::SUMMARY_TIMER_START, $selector->rulesForFailure($failure, null));
        self::assertContains(PreservingActivityRepairer::SUMMARY_TIMER_START, $selector->rulesForFailure($failure, null, true));
        $repair = $selector->repair($source, PreservingActivityRepairer::SUMMARY_TIMER_START);
        self::assertSame([['message' => 1, 'field' => 253, 'type' => 0x86, 'value' => $s]], $repair->report['changes']);
        self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
        $this->assertOriginalFieldsRetained($source, $repair);
        $contradictory = EditableFitFile::read($source)->apply([['message' => 6, 'field' => 2, 'type' => 0x86, 'value' => $s + 1]]);
        try {
            $selector->repair($contradictory, PreservingActivityRepairer::SUMMARY_TIMER_START);
            self::fail('Conflicting native starts cannot repair a missing timer clock.');
        } catch (RepairFailed) {
            self::addToAssertionCount(1);
        }
    }

    public function testIncompleteContradictoryAndParallelEvidenceCannotBeReplaced(): void
    {
        foreach (['wrongTimer', 'lostLap', 'overlappingStarts', 'lateRecord', 'duplicateStart', 'duplicateActivityTimer'] as $variant) {
            $this->assertDeclined(new RecordedLapElapsedRepairer(), $this->partition(variant: $variant));
        }
        $id = FitFixture::message(0, [[0, 0x00, "\x04"]]);
        $start = FitFixture::message(21, [[253, 0x86, pack('V', self::START)], [0, 0x00, "\x00"], [1, 0x00, "\x00"]]);
        $records = FitFixture::record(self::START).FitFixture::record(self::START + 10);
        foreach ([$records, $start.$records, $start.$records.FitFixture::message(34, [[0, 0x86, pack('V', 9000)], [253, 0x86, pack('V', self::START + 10)], [1, 0x84, pack('v', 1)]]),
            $records.FitFixture::summary(18, self::START, 10_000, [[26, 0x84, pack('v', 68)]])] as $data) {
            $this->assertDeclined(new RecordedActivitySummaryRepairer(), FitFixture::file($id.$data));
        }
    }

    public function testRecordClockPhasesRequireCorroboratedCountersAndRetainPhysicalMeasurements(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $m = static fn (int $n, array $f): string => FitFixture::message($n, $f, [], $architecture);
            foreach ([false, true] as $initial) {
                $s = self::START;
                $nativeStart = $s + ($initial ? 3 : 0);
                $times = $initial ? [$s + 3, $s + 4, $s + 2, $s + 10] : [$s, $s + 1, $s + 86402, $s + 86403, $s + 4, $s + 10];
                $body = $m(0, [[0, 0x00, "\x04"], [4, 0x86, pack($word, $nativeStart)]])
                    .$m(21, [[253, 0x86, pack($word, $nativeStart)], [0, 0x00, "\x00"], [1, 0x00, "\x00"]]);
                foreach ($times as $i => $t) {
                    $body .= $m(20, [[253, 0x86, pack($word, $t)], [3, 0x02, chr(100 + $i)], [5, 0x86, pack($word, $i * 100)]]);
                }
                $body .= $m(21, [[253, 0x86, pack($word, $s + 10)], [0, 0x00, "\x00"], [1, 0x00, "\x04"]]);
                foreach ([19, 18] as $n) {
                    $body .= $m($n, [[2, 0x86, pack($word, $nativeStart)], [253, 0x86, pack($word, $s + 10)], [7, 0x86, pack($word, 10_000)], [8, 0x86, pack($word, 10_000)]]);
                }
                $source = FitFixture::file($body.$m(34, [[253, 0x86, pack($word, $s + 10)], [0, 0x86, pack($word, 10_000)], [1, 0x84, pack($short, 1)]]));
                $repairer = new RecordedRecordClockRepairer();
                $repair = $repairer->repair($source, $initial);
                $this->assertOriginalFieldsRetained($source, $repair);
                self::assertSame($initial ? -3 : -86400, $repair->report['inference']['offset_seconds']);
                self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
                self::assertNotContains(8, array_column($repair->report['changes'], 'field'));
                $bad = EditableFitFile::read($source)->apply([['message' => count(EditableFitFile::read($source)->messages) - 1, 'field' => 0, 'type' => 0x86, 'value' => 9000]]);
                foreach ([$bad, $repair->bytes] as $declined) {
                    try {
                        $repairer->repair($declined, $initial);
                        self::fail('Conflicting native totals and already repaired clocks must decline.');
                    } catch (RepairFailed) {
                        self::addToAssertionCount(1);
                    }
                }
                if (!$initial) {
                    $mixed = EditableFitFile::read($source)->apply([['message' => 5, 'field' => 253, 'type' => 0x86, 'value' => $s + 172803]]);
                    try {
                        $repairer->repair($mixed);
                        self::fail('Different date shifts cannot corroborate one device clock phase.');
                    } catch (RepairFailed) {
                        self::addToAssertionCount(1);
                    }
                }
            }
        }
    }

    public function testIncompleteNativeIntervalsRetainPausesTimersAndUnknownFinish(): void
    {
        $s = self::START;
        $m = static fn (int $n, array $f): string => FitFixture::message($n, $f);
        $id = $m(0, [[0, 0x00, "\x04"]]);
        $event = static fn (int $t, int $kind): string => FitFixture::message(21, [[253, 0x86, pack('V', $t)], [0, 0x00, "\x00"], [1, 0x00, chr($kind)]]);
        $records = FitFixture::record($s).FitFixture::record($s + 10);
        $prefix = FitFixture::file($id.$event($s, 0).FitFixture::record($s + 2).$event($s + 3, 4).$event($s + 5, 0).FitFixture::record($s + 10));
        $this->assertDeclined(new RecordedActivitySummaryRepairer(), $prefix);
        $repair = (new RecordedActivitySummaryRepairer())->repair($prefix, true);
        $this->assertOriginalFieldsRetained($prefix, $repair);
        $after = EditableFitFile::read($repair->bytes);
        self::assertSame(8000, PhysicalMessage::fromRaw($after->messages[array_key_last($after->messages)]['raw'], 0)->integer(0));
        self::assertCount(3, array_filter($after->messages, static fn (array $x): bool => 21 === $x['raw']->globalMessageNumber()));
        self::assertStringContainsString('original finish', $repair->report['inference']['finish_policy']);
        $fields = [[2, 0x86, pack('V', $s)], [253, 0x86, pack('V', $s + 10)], [7, 0x86, pack('V', 10_000)]];
        $source = FitFixture::file($id.FitFixture::record($s).$event($s + 3, 4).$event($s + 5, 0).$event($s + 8, 4).FitFixture::record($s + 10)
            .$m(19, $fields).$m(18, $fields).$m(34, [[253, 0x86, pack('V', $s + 10)], [1, 0x84, pack('v', 1)]]));
        $repair = (new RecordedSingleLapFieldsRepairer())->repair($source, true);
        $this->assertOriginalFieldsRetained($source, $repair);
        self::assertSame(6000, PhysicalMessage::fromRaw(EditableFitFile::read($repair->bytes)->messages[6]['raw'], 0)->integer(8));
        $native = [[2, 0x86, pack('V', $s)], [253, 0x86, pack('V', $s)], [7, 0x86, pack('V', 8000)], [8, 0x86, pack('V', 8000)]];
        $source = FitFixture::file($id.$event($s, 0).$records.$m(19, $native).$m(18, $native)
            .$m(34, [[253, 0x86, pack('V', $s)], [5, 0x86, pack('V', $s + 10)], [0, 0x86, pack('V', 8000)], [1, 0x84, pack('v', 1)]]));
        $repair = (new RecordedSingleLapFieldsRepairer())->repair($source, observedExtent: true);
        $this->assertOriginalFieldsRetained($source, $repair);
        self::assertNotContains(8, array_column($repair->report['changes'], 'field'));
        $extent = EditableFitFile::read($source)->apply([
            ['message' => 6, 'field' => 253, 'type' => 0x86, 'value' => $s + 10],
            ['message' => 6, 'field' => 0, 'type' => 0x86, 'value' => 10_000],
        ]);
        $repair = (new RecordedSingleLapFieldsRepairer())->repair($extent, observedExtent: true);
        $this->assertOriginalFieldsRetained($extent, $repair);
        $after = EditableFitFile::read($repair->bytes);
        self::assertSame(10_000, PhysicalMessage::fromRaw($after->messages[5]['raw'], 0)->integer(7));
        self::assertSame(8000, PhysicalMessage::fromRaw($after->messages[5]['raw'], 0)->integer(8));
        self::assertSame(10_000, PhysicalMessage::fromRaw($after->messages[6]['raw'], 0)->integer(0));
        self::assertTrue($repair->report['evidence']['activity_counter_matches_observed_extent']);
        foreach ([
            [['message' => 6, 'field' => 0, 'type' => 0x86, 'value' => 9500]],
            [['message' => 6, 'field' => 253, 'type' => 0x86, 'value' => $s]],
            [['message' => 4, 'field' => 7, 'type' => 0x86, 'value' => 9000], ['message' => 5, 'field' => 7, 'type' => 0x86, 'value' => 9000]],
        ] as $edits) {
            try {
                (new RecordedSingleLapFieldsRepairer())->repair(EditableFitFile::read($extent)->apply($edits), observedExtent: true);
                self::fail('Different counters require exact native Activity and Record extent corroboration.');
            } catch (RepairFailed) {
                self::addToAssertionCount(1);
            }
        }
        $body = $id.$records;
        foreach ([0, 1] as $i) {
            $body .= $m(18, [[2, 0x86, pack('V', $s + $i * 5)], [253, 0x86, pack('V', $s + ($i + 1) * 5)], [7, 0x86, pack('V', 5000)]]);
        }
        $source = FitFixture::file($body.$m(34, [[253, 0x86, pack('V', $s + 10)], [0, 0x86, pack('V', 10_000)], [1, 0x84, pack('v', 2)]]));
        $repair = (new RecordedActivitySummaryRepairer())->repairSessionTimers($source);
        $this->assertOriginalFieldsRetained($source, $repair);
        self::assertCount(2, $repair->report['inserted_messages']);
        $lost = EditableFitFile::read($source)->apply([['message' => 3, 'field' => 26, 'type' => 0x84, 'value' => 68]]);
        try {
            (new RecordedActivitySummaryRepairer())->repairSessionTimers($lost);
            self::fail('A whole-Session aggregate cannot replace a lost native Lap set.');
        } catch (RepairFailed) {
            self::addToAssertionCount(1);
        }
    }

    public function testFrozenSoleSessionCopiesOnlyNativeActivityTimerAndRetainsRecordTail(): void
    {
        foreach ([0, 1] as $architecture) {
            $s = self::START;
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $m = static fn (int $n, array $f): string => FitFixture::message($n, $f, [], $architecture);
            $u = static fn (int $n, int $v): array => [$n, 0x86, pack($word, $v)];
            $r = static fn (int $n, int $v): array => [$n, 0x84, pack($short, $v)];
            $tail = $m(20, [$u(253, $s + 1), [3, 0x02, "\x70"]])
                .$m(20, [$u(253, $s + 20), [3, 0x02, "\x80"]]);
            $body = $m(0, [[0, 0x00, "\x04"], $u(4, $s)])
                .$m(34, [$u(253, $s), $u(5, $s + 7200), $u(0, 8000), $r(1, 1)])
                .$m(18, [$u(2, $s), $u(253, $s), $u(7, 8000), $u(9, 12345), [5, 0x00, "\x02"]]);
            $source = FitFixture::file($body.$tail);
            $repairer = new RecordedActivitySummaryRepairer();
            $repair = $repairer->repairObservedSessionTimer($source);
            $this->assertOriginalFieldsRetained($source, $repair);
            self::assertStringEndsWith($tail, substr($repair->bytes, 0, -2));
            $after = EditableFitFile::read($repair->bytes);
            self::assertSame(8000, PhysicalMessage::fromRaw($after->messages[1]['raw'], 0)->integer(0));
            self::assertSame($s + 7220, PhysicalMessage::fromRaw($after->messages[1]['raw'], 0)->integer(5));
            self::assertSame(8000, PhysicalMessage::fromRaw($after->messages[3]['raw'], 0)->integer(8));
            self::assertSame(20_000, PhysicalMessage::fromRaw($after->messages[3]['raw'], 0)->integer(7));
            self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
            self::assertTrue($repair->report['inserted_messages'][0]['synthetic']);
            foreach ([
                $repair->bytes,
                EditableFitFile::read($source)->apply([['message' => 1, 'field' => 0, 'type' => 0x86, 'value' => 9000]]),
                EditableFitFile::read($source)->apply([['message' => 2, 'field' => 26, 'type' => 0x84, 'value' => 2]]),
                EditableFitFile::read($source)->apply([['message' => 2, 'field' => 8, 'type' => 0x86, 'value' => 8000]]),
                EditableFitFile::read($source)->apply([['message' => 0, 'field' => 4, 'type' => 0x86, 'value' => $s + 1]]),
                FitFixture::file($body.$m(21, [$u(253, $s), [0, 0x00, "\x00"], [1, 0x00, "\x00"]]).$tail),
                FitFixture::file($body.$m(19, [$u(2, $s), $u(8, 8000)]).$tail),
                FitFixture::file($body.$m(20, [$u(253, $s + 20)]).$m(20, [$u(253, $s + 1)])),
            ] as $declined) {
                try {
                    $repairer->repairObservedSessionTimer($declined);
                    self::fail('Existing timers, segmentation, transitions or conflicting native evidence take precedence.');
                } catch (RepairFailed) {
                    self::addToAssertionCount(1);
                }
            }
        }
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT session at sequence 9 has no usable total_timer_time field.']]];
        $selector = new PreservingActivityRepairer();
        self::assertNotContains(RecordedActivitySummaryRepairer::OBSERVED_SESSION_RULE, $selector->rulesForFailure($failure, null));
        self::assertContains(RecordedActivitySummaryRepairer::OBSERVED_SESSION_RULE, $selector->rulesForFailure($failure, null, true));
        self::assertSame([], $selector->rulesForFailure(['status' => 'PASS'], null, true));
    }

    private function partition(int $architecture = 0, string $variant = ''): string
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $message = static fn (int $n, array $fields): string => FitFixture::message($n, $fields, [], $architecture);
        $event = static fn (int $t, int $type): string => $message(21, [[253, 0x86, pack($word, $t)], [0, 0x00, "\x00"], [1, 0x00, chr($type)]]);
        $s = self::START;
        $lap = static fn (int $index, int $start, int $end, int $elapsed, int $timer): string => $message(19, [
            [254, 0x84, pack($short, $index)], [2, 0x86, pack($word, $start)], [253, 0x86, pack($word, $end + ('observedExtent' === $variant ? 3600 : 0))],
            [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $timer)],
            ...('duplicateStart' === $variant && 0 === $index ? [[2, 0x86, pack($word, $start)]] : []),
        ]);

        return FitFixture::file($message(0, [[0, 0x00, "\x04"]]).$event($s, 0)
            .$message(20, [[253, 0x86, pack($word, $s)], [3, 0x02, "\x64"]])
            .$event($s + 15, 4).$event($s + 20, 0)
            .$message(20, [[253, 0x86, pack($word, $s + 20)], [3, 0x02, "\x70"]])
            .$lap(0, $s, $s + 20, 10_000, 15_000)
            .$message(20, [[253, 0x86, pack($word, $s + ('lateRecord' === $variant ? 45 : 40))], [3, 0x02, "\x78"]])
            .$event($s + 40, 'observedExtent' === $variant ? 6 : 4).$lap(1, $s + ('overlappingStarts' === $variant ? 15 : 20), $s + 40, 'observedExtent' === $variant ? 15_000 : 20_000, 20_000)
            .$message(18, [[254, 0x84, pack($short, 0)], [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, 'lostLap' === $variant ? 3 : 2)],
                [2, 0x86, pack($word, $s)], [253, 0x86, pack($word, $s + ('observedExtent' === $variant ? 3640 : 40))], [7, 0x86, pack($word, 'observedExtent' === $variant ? 35_000 : 30_000)], [8, 0x86, pack($word, 'wrongTimer' === $variant ? 36_000 : 35_000)]])
            .$message(34, [[253, 0x86, pack($word, $s + ('observedExtent' === $variant ? 3640 : 40))], [0, 0x86, pack($word, 'wrongTimer' === $variant ? 36_000 : 35_000)], [1, 0x84, pack($short, 1)],
                ...('duplicateActivityTimer' === $variant ? [[0, 0x86, pack($word, 35_000)]] : []),
            ]));
    }

    private function assertOriginalFieldsRetained(string $source, RepairResult $repair): void
    {
        $before = EditableFitFile::read($source);
        $after = EditableFitFile::read($repair->bytes);
        $edits = [];
        foreach ($repair->report['changes'] as $change) {
            $edits[$change['message']][$change['field']] = true;
        }
        $shift = 0;
        foreach ($before->messages as $i => $entry) {
            foreach ($repair->report['inserted_messages'] ?? [] as $insert) {
                if (($insert['beforeOriginalMessage'] ?? null) === $i) {
                    ++$shift;
                }
            }
            self::assertSame($entry['raw']->globalMessageNumber(), $after->messages[$i + $shift]['raw']->globalMessageNumber());
            foreach ($entry['raw']->standardFields() as $field) {
                if (isset($edits[$i][$field->definition->fieldNumber])) {
                    continue;
                }
                $match = array_filter($after->messages[$i + $shift]['raw']->standardFields(), static fn ($other): bool => $other->definition->fieldNumber === $field->definition->fieldNumber);
                self::assertCount(1, $match);
                self::assertSame($field->value->bytes(), reset($match)->value->bytes());
            }
        }
    }

    public function testMissingSetRangesRestoreIndividualLapsWithNativeTimersAndRecordedPauses(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $start = self::START;
            $m = static fn (int $n, array $f): string => FitFixture::message($n, $f, [], $architecture);
            $u = static fn (int $n, int $v): array => [$n, 0x86, pack($word, $v)];
            $r = static fn (int $n, int $v): array => [$n, 0x84, pack($short, $v)];
            $event = static fn (int $t, int $kind): string => $m(21, [$u(253, $start + $t), [0, 0x00, "\x00"], [1, 0x00, chr($kind)]]);
            $session = static fn (int $id, int $first, int $count, int $from, int $elapsed, int $timer, int $sport): string => $m(18, [
                $r(254, $id), $r(25, $first), $r(26, $count), $u(2, $start + $from), $u(253, $start), $u(7, $elapsed), $u(8, $timer),
                [5, 0x00, chr($sport)], [6, 0x00, chr(10 === $sport ? 20 : 0)],
            ]);
            $data = $m(0, [[0, 0x00, "\x04"]]).$event(0, 0).$event(10, 4).$event(20, 0).$event(28, 4).$event(40, 0).$event(52, 4)
                .$session(0, 0, 2, 0, 12_000, 10_000, 10).$session(1, 2, 1, 20, 10_000, 8000, 1).$session(2, 3, 2, 40, 14_000, 12_000, 10)
                .$m(19, [$r(254, 2), $u(2, $start + 20), $u(253, $start), $u(7, 15_000), $u(8, 8000)]);
            foreach ([[0, 0, 4000], [1, 4, 6000], [3, 40, 5000], [4, 45, 7000]] as [$id, $from, $timer]) {
                $data .= $m(225, [$r(10, $id), $u(6, $start + $from), $u(254, $start), $u(0, $timer), [5, 0x00, "\x01"], [3, 0x84, pack($short, 12)]]);
            }
            $source = FitFixture::file($data.$m(20, [$u(253, $start), [3, 0x02, "\x70"]]).$m(20, [$u(253, $start + 52), [3, 0x02, "\x79"]])
                .$m(34, [$r(1, 3), $u(0, 30_000), $u(253, $start)]));
            $repairer = new RecordedMissingSetLapRepairer();
            $repair = $repairer->repair($source);
            self::assertCount(4, $repair->report['inserted_messages']);
            self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
            $this->assertOriginalFieldsRetained($source, $repair);
            $laps = [];
            foreach (EditableFitFile::read($repair->bytes)->messages as $entry) {
                $p = PhysicalMessage::fromRaw($entry['raw'], 0);
                if (19 === $p->number) {
                    $laps[] = [$p->integer(254), $p->integer(7), $p->integer(8)];
                }
            }
            self::assertSame([[0, 4000, 4000], [1, 16_000, 6000], [2, 20_000, 8000], [3, 5000, 5000], [4, 9000, 7000]], $laps);
            $file = EditableFitFile::read($source);
            $bad = $file->apply([['message' => count($file->messages) - 1, 'field' => 0, 'type' => 0x86, 'value' => 30_001]]);
            foreach ([$repair->bytes, $bad] as $declined) {
                try {
                    $repairer->repair($declined);
                    self::fail('Incomplete or contradictory native Set ranges must decline.');
                } catch (RepairFailed) {
                    self::addToAssertionCount(1);
                }
            }
        }
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT session at sequence 3 does not abut it:']]];
        $selector = new PreservingActivityRepairer();
        self::assertNotContains(RecordedMissingSetLapRepairer::RULE, $selector->rulesForFailure($failure, null));
        self::assertContains(RecordedMissingSetLapRepairer::RULE, $selector->rulesForFailure($failure, null, true));
    }

    public function testMissingLapReferencesRequireCompleteUnchangedNativeCountersAndIntervals(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $start = self::START;
            $m = static fn (int $n, array $f): string => FitFixture::message($n, $f, [], $architecture);
            $u = static fn (int $n, int $v): array => [$n, 0x86, pack($word, $v)];
            $source = FitFixture::file($m(0, [[0, 0x00, "\x04"]]).$m(20, [$u(253, $start)]).$m(20, [$u(253, $start + 20)])
                .$m(19, [$u(2, $start), $u(253, $start + 10), $u(7, 10_000), $u(8, 9000), $u(9, 5000)])
                .$m(19, [$u(2, $start + 10), $u(253, $start + 20), $u(7, 10_000), $u(8, 9000), $u(9, 5000)])
                .$m(18, [$u(2, $start), $u(253, $start + 18), $u(7, 18_000), $u(8, 18_000), $u(9, 10_000)])
                .$m(34, [[1, 0x84, pack($short, 1)], $u(0, 18_000), $u(253, $start)]));
            $repairer = new RecordedLapReferenceRepairer();
            $repair = $repairer->repair($source);
            $this->assertOriginalFieldsRetained($source, $repair);
            self::assertSame([0, 1, 20_000, 0, 0, 2], array_column($repair->report['changes'], 'value'));
            foreach ([
                $repair->bytes,
                EditableFitFile::read($source)->apply([['message' => 5, 'field' => 26, 'type' => 0x84, 'value' => 3]]),
                EditableFitFile::read($source)->apply([['message' => 5, 'field' => 8, 'type' => 0x86, 'value' => 17_000]]),
                EditableFitFile::read($source)->apply([['message' => 4, 'field' => 2, 'type' => 0x86, 'value' => $start + 1]]),
            ] as $declined) {
                try {
                    $repairer->repair($declined);
                    self::fail('Incomplete or conflicting native Lap references must decline.');
                } catch (RepairFailed) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    private function assertDeclined(RecordedLapElapsedRepairer|RecordedActivitySummaryRepairer|RecordedSummarySaveRepairer $repairer, string $source): void
    {
        try {
            $repairer->repair($source);
            self::fail('Incomplete or contradictory evidence must decline.');
        } catch (RepairFailed) {
            self::addToAssertionCount(1);
        }
    }

    public function testPoolPartitionRecoveryRetainsRepeatedLengthsAndRequiresEveryNativeCounterAndDistance(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $m = static fn (int $n, array $f): string => FitFixture::message($n, $f, [], $architecture);
            $u = static fn (int $n, int $v): array => [$n, 0x86, pack($word, $v)];
            $r = static fn (int $n, int $v): array => [$n, 0x84, pack($short, $v)];
            $s = self::START;
            $body = $m(0, [[0, 0x00, "\x04"]]);
            foreach ([[0, 0, 10_000, 1], [0, 0, 10_000, 1], [3, 20, 10_000, 1], [4, 30, 5000, 0]] as [$id, $from, $time, $kind]) {
                $body .= $m(101, [$r(254, $id), $u(2, $s + $from), $u(3, $time), $u(4, $time), [12, 0x00, chr($kind)], [5, 0x84, pack($short, 12)]]);
            }
            $body .= $m(19, [$r(254, 0), $r(35, 1), $r(32, 3), $r(40, 3), $u(2, $s), $u(7, 30_000), $u(8, 30_000), $u(9, 7500)])
                .$m(19, [$r(254, 1), $r(35, 4), $r(32, 0), $r(40, 0), $u(2, $s + 30), $u(7, 5000), $u(8, 5000), $u(9, 0)])
                .$m(18, [$r(254, 0), $r(25, 0), $r(26, 2), $r(47, 3), $r(44, 2500), [46, 0x00, "\x00"], [5, 0x00, "\x05"], [6, 0x00, "\x11"],
                    $u(2, $s), $u(7, 35_000), $u(8, 35_000), $u(9, 7500)])
                .$m(34, [$u(253, $s + 35)]);
            $source = FitFixture::file($body);
            $repairer = new RecordedPoolPartitionRepairer();
            $repair = $repairer->repair($source);
            $this->assertOriginalFieldsRetained($source, $repair);
            self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
            $messages = EditableFitFile::read($repair->bytes)->messages;
            self::assertCount(9, $messages);
            foreach ([1, 2, 3, 4] as $i) {
                self::assertSame($i - 1, PhysicalMessage::fromRaw($messages[$i]['raw'], 0)->integer(254));
            }
            self::assertSame($s + 10, PhysicalMessage::fromRaw($messages[2]['raw'], 0)->integer(2));
            self::assertSame(0, PhysicalMessage::fromRaw($messages[5]['raw'], 0)->integer(35));
            self::assertSame(3, PhysicalMessage::fromRaw($messages[6]['raw'], 0)->integer(35));
            self::assertSame(35_000, PhysicalMessage::fromRaw($messages[8]['raw'], 0)->integer(0));
            $file = EditableFitFile::read($source);
            foreach ([$repair->bytes,
                $file->apply([['message' => 5, 'field' => 32, 'type' => 0x84, 'value' => 4]]),
                $file->apply([['message' => 5, 'field' => 8, 'type' => 0x86, 'value' => 28_000]]),
                $file->apply([['message' => 7, 'field' => 9, 'type' => 0x86, 'value' => 10_000]]),
                $file->apply([['message' => 3, 'field' => 2, 'type' => 0x86, 'value' => $s + 22]]),
            ] as $declined) {
                try {
                    $repairer->repair($declined);
                    self::fail('Incomplete counts, contradictory timers/distance and ambiguous repeated starts must decline.');
                } catch (RepairFailed) {
                    self::addToAssertionCount(1);
                }
            }
        }
        $selector = new PreservingActivityRepairer();
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'Activity details in sequence pool_length cannot overlap beyond their timeline resolution.']]];
        self::assertNotContains(RecordedPoolPartitionRepairer::RULE, $selector->rulesForFailure($failure, null));
        self::assertContains(RecordedPoolPartitionRepairer::RULE, $selector->rulesForFailure($failure, null, true));
    }

    public function testMissingLapStartsRequireCompleteNativeSavedPartitionAndRetainActiveTimersAndLocalTime(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $m = static fn (int $n, array $f): string => FitFixture::message($n, $f, [], $architecture);
            $u = static fn (int $n, int $v): array => [$n, 0x86, pack($word, $v)];
            $s = self::START;
            $body = $m(0, [[0, 0x00, "\x04"]]).$m(20, [$u(253, $s), [3, 0x02, "\x70"]]).$m(20, [$u(253, $s + 9)])
                .$m(19, [$u(253, $s + 10), $u(7, 0), $u(8, 9000), $u(9, 5000)])
                .$m(20, [$u(253, $s + 11)]).$m(20, [$u(253, $s + 19)])
                .$m(19, [$u(253, $s + 20), $u(7, 0), $u(8, 8000), $u(9, 5000)])
                .$m(18, [$u(2, $s), $u(253, $s + 17), $u(7, 17_000), $u(8, 17_000), $u(9, 10_000), [26, 0x84, pack($short, 2)]])
                .$m(34, [$u(253, $s + 17), $u(0, 17_000), $u(5, $s + 7220), [1, 0x84, pack($short, 1)]]);
            $source = FitFixture::file($body);
            $repairer = new RecordedLapReferenceRepairer();
            $selector = new PreservingActivityRepairer();
            $repair = $selector->repair($source, RecordedLapReferenceRepairer::SAVED_BOUNDARIES_RULE);
            $this->assertOriginalFieldsRetained($source, $repair);
            self::assertNotContains(8, array_column($repair->report['changes'], 'field'));
            self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
            $messages = EditableFitFile::read($repair->bytes)->messages;
            self::assertSame($s, PhysicalMessage::fromRaw($messages[3]['raw'], 0)->integer(2));
            self::assertSame($s + 10, PhysicalMessage::fromRaw($messages[6]['raw'], 0)->integer(2));
            self::assertSame(20_000, PhysicalMessage::fromRaw($messages[7]['raw'], 0)->integer(7));
            self::assertSame($s + 20, PhysicalMessage::fromRaw($messages[8]['raw'], 0)->integer(253));
            self::assertSame($s + 7220, PhysicalMessage::fromRaw($messages[8]['raw'], 0)->integer(5));
            $file = EditableFitFile::read($source);
            $roundedDistance = $file->apply([
                ['message' => 5, 'field' => 5, 'type' => 0x86, 'value' => 10_000],
                ['message' => 6, 'field' => 9, 'type' => 0x86, 'value' => 5007],
            ]);
            $rounded = $repairer->repair($roundedDistance, true);
            $this->assertOriginalFieldsRetained($roundedDistance, $rounded);
            self::assertSame(7, $rounded->report['evidence']['distance_residual_cm']);
            foreach ([$repair->bytes,
                $file->apply([['message' => 3, 'field' => 7, 'type' => 0x86, 'value' => 9000]]),
                $file->apply([['message' => 6, 'field' => 8, 'type' => 0x86, 'value' => 7000]]),
                EditableFitFile::read($roundedDistance)->apply([['message' => 6, 'field' => 9, 'type' => 0x86, 'value' => 5101]]),
                EditableFitFile::read($roundedDistance)->apply([['message' => 5, 'field' => 5, 'type' => 0x86, 'value' => 9999]]),
                $file->apply([['message' => 3, 'field' => 253, 'type' => 0x86, 'value' => $s + 8]]),
                FitFixture::file($body.$m(21, [[0, 0x00, "\x00"], [1, 0x00, "\x00"], $u(253, $s)])),
            ] as $declined) {
                try {
                    $repairer->repair($declined, true);
                    self::fail('Existing elapsed, conflicting native timers, uncovered Records and timer traces cannot use saved-boundary inference.');
                } catch (RepairFailed) {
                    self::addToAssertionCount(1);
                }
            }
            $aggregateBody = $m(0, [[0, 0x00, "\x04"], $u(4, $s)])
                .$m(21, [[0, 0x00, "\x00"], [1, 0x00, "\x00"], $u(253, $s + 1)])
                .$m(20, [$u(253, $s + 1)]).$m(20, [$u(253, $s + 8)])
                .$m(18, [$u(2, $s), $u(253, $s + 8), $u(7, 10_000), $u(8, 6000), [5, 0x00, "\x02"], [26, 0x84, pack($short, 0)]])
                .$m(34, [$u(253, $s + 8), $u(0, 10_000), [1, 0x84, pack($short, 1)]]);
            $aggregateSource = FitFixture::file($aggregateBody);
            $aggregate = $selector->repair($aggregateSource, MissingSessionLapRepairer::NATIVE_SUMMARY_RULE);
            $this->assertOriginalFieldsRetained($aggregateSource, $aggregate);
            self::assertTrue($aggregate->report['inserted_messages'][0]['synthetic']);
            self::assertSame('not_proven', $aggregate->report['inference']['historical_accuracy']);
            $messages = EditableFitFile::read($aggregate->bytes)->messages;
            self::assertSame(6000, PhysicalMessage::fromRaw($messages[4]['raw'], 0)->integer(8));
            self::assertSame(10_000, PhysicalMessage::fromRaw($messages[6]['raw'], 0)->integer(0));
            self::assertSame(7, PhysicalMessage::fromRaw($messages[4]['raw'], 0)->integer(25));
            $file = EditableFitFile::read($aggregateSource);
            foreach ([$aggregate->bytes,
                $file->apply([['message' => 0, 'field' => 4, 'type' => 0x86, 'value' => $s - 1]]),
                $file->apply([['message' => 4, 'field' => 26, 'type' => 0x84, 'value' => 2]]),
                $file->apply([['message' => 4, 'field' => 8, 'type' => 0x86, 'value' => 10_000]]),
                FitFixture::file($aggregateBody.$m(21, [[0, 0x00, "\x00"], [1, 0x00, "\x04"], $u(253, $s + 8)])),
            ] as $declined) {
                try {
                    $selector->repair($declined, MissingSessionLapRepairer::NATIVE_SUMMARY_RULE);
                    self::fail('A native early-save aggregate requires corroborated original clocks without lost Laps or a contradictory timer trace.');
                } catch (RepairFailed) {
                    self::addToAssertionCount(1);
                }
            }
        }
        $selector = new PreservingActivityRepairer();
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT lap at sequence 3 has no usable start_time field.']]];
        self::assertNotContains(RecordedLapReferenceRepairer::SAVED_BOUNDARIES_RULE, $selector->rulesForFailure($failure, null));
        self::assertContains(RecordedLapReferenceRepairer::SAVED_BOUNDARIES_RULE, $selector->rulesForFailure($failure, null, true));
        $failure = ['status' => 'FAILED', 'errors' => [['message' => '"Lap Message Exists"']]];
        self::assertNotContains(MissingSessionLapRepairer::NATIVE_SUMMARY_RULE, $selector->rulesForFailure($failure, null));
        self::assertContains(MissingSessionLapRepairer::NATIVE_SUMMARY_RULE, $selector->rulesForFailure($failure, null, true));
    }
}
