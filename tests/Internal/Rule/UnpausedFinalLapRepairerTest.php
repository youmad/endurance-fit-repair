<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\UnpausedFinalLapRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class UnpausedFinalLapRepairerTest extends TestCase
{
    public function testCopiesRecordedValuesAndRetainsEveryOtherByteInBothArchitectures(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = $this->file($architecture);
            $result = (new PreservingActivityRepairer())->repair($source, UnpausedFinalLapRepairer::RULE);
            self::assertSame([2, 7], array_column($result->report['changes'], 'field'));
            self::assertSame([996152027, 117370], array_column($result->report['changes'], 'value'));
            self::assertSame(3234850, $result->report['evidence']['repaired_elapsed_sum_ms']);
            self::assertSame(3233980, $result->report['evidence']['lap_timer_sum_ms']);
            self::assertSame(strlen($source), strlen($result->bytes));
            $masked = $source;

            foreach (['start_byte_offset', 'elapsed_byte_offset'] as $field) {
                $offset = $result->report['evidence'][$field];
                $masked = substr_replace($masked, substr($result->bytes, $offset, 4), $offset, 4);
            }

            self::assertSame(substr($masked, 0, -2), substr($result->bytes, 0, -2));
            $output = EditableFitFile::read($result->bytes);
            $lap = PhysicalMessage::fromRaw($output->messages[$result->report['changes'][0]['message']]['raw'], 0);
            self::assertSame(117370, $lap->integer(8));
            self::assertSame(996152144, $lap->integer(253));
            $automatic = (new ActivityRepairer())->repair($source);
            self::assertSame(UnpausedFinalLapRepairer::RULE, $automatic->report['steps'][0]['rule']);
            $repeat = (new ActivityRepairer())->repair($automatic->bytes);
            self::assertSame($automatic->bytes, $repeat->bytes);
            self::assertSame([], $repeat->report['steps']);
        }
    }

    public function testRestoresOnlyPausedFinalElapsedFromIndependentNativeTotals(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = $this->pausedSaveFile($architecture);
            $result = (new UnpausedFinalLapRepairer())->repair($source);
            self::assertSame('paused_elapsed_includes_late_save', $result->report['variant']);
            self::assertSame([7], array_column($result->report['changes'], 'field'));
            self::assertSame([23000], array_column($result->report['changes'], 'value'));
            self::assertSame(4000, $result->report['evidence']['final_lap_paused_ms']);
            self::assertSame(19000, $result->report['evidence']['final_lap_active_ms']);
            $offset = $result->report['evidence']['elapsed_byte_offset'];
            self::assertSame(substr($source, 0, $offset), substr($result->bytes, 0, $offset));
            self::assertSame(substr($source, $offset + 4, -2), substr($result->bytes, $offset + 4, -2));
            EditableFitFile::read($result->bytes);
        }

        foreach ([[18, 7, 32000, 0], [18, 8, 28000, 0], [19, 253, 0x40000020 + 34, 1], [21, 253, 0x40000020 + 31, 2], [20, 253, 0x40000020 + 32, 1]] as [$number, $field, $value, $occurrence]) {
            $this->assertDeclined($this->replace($this->pausedSaveFile(), $number, $field, $value, $occurrence));
        }
    }

    public function testDeclinesConflictingEvidenceAndOrdinaryRounding(): void
    {
        foreach ([
            [18, 7, 3236020, 0], // Unchanged Session elapsed cannot corroborate the replacement.
            [18, 8, 3231000, 0], // Session active time contradicts the uninterrupted trace.
            [18, 26, 12, 0], // An explicit partition cannot omit another Lap.
            [19, 8, 115000, 10], // Final timer contradicts both boundary and Session total.
            [19, 253, 996152024, 9], // The preceding recorded boundary is inconsistent.
            [19, 7, 149000, 10], // Original final clock and duration disagree.
            [19, 254, 10, 0], // Mixed or contradictory indexes cannot define a partition.
            [20, 253, 996152143, 1], // Records do not reach the corroborated timer stop.
        ] as [$number, $field, $value, $occurrence]) {
            $source = $this->replace($this->file(), $number, $field, $value, $occurrence);
            $this->assertDeclined($source);
        }

        // A final Lap with matching elapsed/timer needs no repair despite an overlapping start.
        $this->assertDeclined($this->replace($this->file(), 19, 8, 150430, 10));
    }

    public function testDeclinesPausesAndAmbiguousPhysicalFields(): void
    {
        $source = $this->file();
        $pause = FitFixture::message(21, [[253, 0x86, pack('V', 996150000)], [0, 0, "\x00"], [1, 0, "\x04"]]);
        $this->assertDeclined(FitFixture::file(substr($source, 14, -2).$pause));
        $file = EditableFitFile::read($source);
        $entry = array_values(array_filter($file->messages, static fn (array $entry): bool => 19 === $entry['raw']->globalMessageNumber()))[10];

        foreach ([false, true] as $array) {
            $fields = array_map(static fn ($field): array => [$field->definition->fieldNumber, $field->definition->baseType->definitionByte(), $field->value->bytes()], $entry['raw']->standardFields());

            if ($array) {
                $fields[0][2] .= pack('V', 996151993);
            } else {
                $fields[] = [2, 0x86, pack('V', 996151993)];
            }

            $replacement = FitFixture::message(19, $fields, [[8, 0, "\xDE\xAD"]]);
            $data = substr_replace(substr($source, 14, -2), $replacement, $entry['offset'] - 14 - strlen($entry['definition']), strlen($entry['definition']) + $entry['length']);
            $this->assertDeclined(FitFixture::file($data));
        }
    }

    public function testRetainsTheStartAndLateSaveWhenOnlyTheFinalElapsedIsExcessive(): void
    {
        foreach ([0, 1] as $architecture) {
            foreach ([
                [$this->file($architecture, true), 117370, 996152027, 996152177],
                [$this->roundedStopFile($architecture), 150000, 996149361, 996149530],
            ] as [$source, $timer, $start, $timestamp]) {
                $result = (new UnpausedFinalLapRepairer())->repair($source);
                self::assertSame('elapsed_includes_late_save', $result->report['variant']);
                self::assertSame([7], array_column($result->report['changes'], 'field'));
                self::assertSame([$timer], array_column($result->report['changes'], 'value'));
                $offset = $result->report['evidence']['elapsed_byte_offset'];
                $masked = substr_replace($source, substr($result->bytes, $offset, 4), $offset, 4);
                self::assertSame(strlen($source), strlen($result->bytes));
                self::assertSame(substr($masked, 0, -2), substr($result->bytes, 0, -2));
                $output = EditableFitFile::read($result->bytes);
                $lap = PhysicalMessage::fromRaw($output->messages[$result->report['changes'][0]['message']]['raw'], 0);
                self::assertSame($start, $lap->integer(2));
                self::assertSame($timestamp, $lap->integer(253));
                self::assertSame($timer, $lap->integer(8));
                $automatic = (new ActivityRepairer())->repair($source);
                self::assertSame('elapsed_includes_late_save', $automatic->report['steps'][0]['variant']);
                self::assertSame($automatic->bytes, (new ActivityRepairer())->repair($automatic->bytes)->bytes);
            }
        }

        $repairer = new PreservingActivityRepairer();
        foreach (['Session Message total_elapsed_time is Equal to the Sum of Lap Messages total_elapsed_time Values', 'Session Message Valid Message Index'] as $check) {
            self::assertContains(UnpausedFinalLapRepairer::RULE, $repairer->rulesForFailure([
                'status' => 'FAILED', 'errors' => [['message' => 'FIT failed Garmin required check "'.$check.'": got missing.']],
            ], null));
        }
    }

    public function testDeclinesLateSaveWhenTheRecordedTimerOrUnchangedBoundaryIsNotCorroborated(): void
    {
        foreach ([
            [19, 2, 996152030, 10], // A late save cannot also move an existing start.
            [19, 8, 115000, 10], // The timer cannot restore the unchanged Session totals.
            [19, 7, 148000, 10], // The original elapsed must reach the recorded save.
            [19, 253, 996152200, 9], // Earlier Laps cannot extend into the save delay.
            [18, 7, 3236020, 0], // The Session elapsed must corroborate the replacement.
            [20, 253, 996152143, 1], // Complete Records must reach STOP.
        ] as [$number, $field, $value, $occurrence]) {
            $this->assertDeclined($this->replace($this->file(lateSave: true), $number, $field, $value, $occurrence));
        }

        $pause = FitFixture::message(21, [[253, 0x86, pack('V', 996150000)], [0, 0, "\x00"], [1, 0, "\x04"]]);
        $this->assertDeclined(FitFixture::file(substr($this->file(lateSave: true), 14, -2).$pause));

        foreach ([
            [18, 7, 600001, 0], // A rounded STOP needs exact unchanged elapsed totals.
            [18, 8, 599999, 0], // Even a millisecond disagreement cannot select another endpoint.
            [34, 0, 599999, 0], // Activity must independently corroborate the exact timer sum.
            [19, 8, 150001, 1], // Every precise Lap timer must contribute to that sum.
            [19, 2, 996149362, 1], // Final end outside Session +1 is still rejected.
            [20, 253, 996149508, 1], // Records must still reach the actual STOP.
        ] as [$number, $field, $value, $occurrence]) {
            $this->assertDeclined($this->replace($this->roundedStopFile(), $number, $field, $value, $occurrence));
        }
    }

    private function assertDeclined(string $source): void
    {
        try {
            (new UnpausedFinalLapRepairer())->repair($source);
            self::fail('Ambiguous or contradictory evidence must be rejected.');
        } catch (RepairFailed $failure) {
            self::assertNotSame('', $failure->getMessage());
        }
    }

    private function replace(string $source, int $number, int $field, int $value, int $occurrence): string
    {
        $file = EditableFitFile::read($source);
        $entries = array_keys(array_filter($file->messages, static fn (array $entry): bool => $number === $entry['raw']->globalMessageNumber()));

        return $file->apply([['message' => $entries[$occurrence], 'field' => $field, 'type' => in_array($field, [26, 254], true) ? 0x84 : 0x86, 'value' => $value]]);
    }

    private function pausedSaveFile(int $architecture = 0): string
    {
        $base = 0x40000020;
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $summary = static fn (int $number, int $start, int $timestamp, int $elapsed, int $timer): string => FitFixture::message($number, [
            [2, 0x86, pack($word, $base + $start)], [253, 0x86, pack($word, $base + $timestamp)],
            [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $timer)],
        ], [[7, 0, "\xDE\xAD"]], $architecture);
        $event = static fn (int $timestamp, int $type): string => FitFixture::message(21, [
            [253, 0x86, pack($word, $base + $timestamp)], [0, 0, "\x00"], [1, 0, chr($type)], [4, 0x02, "\x00"],
        ], [], $architecture);
        $data = FitFixture::message(0, [[0, 0, "\x04"]])
            .FitFixture::message(34, [[253, 0x86, pack($word, $base)], [0, 0x86, pack($word, 33000)], [1, 0x84, pack($short, 1)]], [], $architecture)
            .$summary(18, 0, 33, 33000, 29000).$summary(19, 0, 10, 10000, 10000).$summary(19, 10, 38, 28000, 19000)
            .$event(0, 0).FitFixture::record($base).$event(25, 4).$event(29, 0).FitFixture::record($base + 33).$event(33, 4);

        return FitFixture::file($data);
    }

    private function file(int $architecture = 0, bool $lateSave = false): string
    {
        // Recorded clocks and millisecond totals from the failing 2021-07-25 COROS VERTIX 2.
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $data = FitFixture::message(0, [[0, 0, "\x04"]]);
        $data .= FitFixture::message(34, [
            [253, 0x86, pack($word, 996148910)], [5, 0x86, pack($word, 996156110)],
            [0, 0x86, pack($word, 3234020)], [1, 0x84, pack($short, 1)],
        ], [], $architecture);

        foreach ([[996148910, 0], [996152144, 4]] as [$timestamp, $type]) {
            $data .= FitFixture::message(21, [[253, 0x86, pack($word, $timestamp)], [0, 0, "\x00"], [1, 0, chr($type)], [4, 0x02, "\x00"]], [], $architecture);
            $data .= FitFixture::message(20, [[253, 0x86, pack($word, $timestamp)], [200, 0x0D, "\x11\x22"]], [[8, 0, "\xFA\xCE"]], $architecture);
        }

        foreach ([
            [996148910, 996149191, 280750, 279880], [996149190, 996149470, 279270, 279270],
            [996149469, 996149755, 285470, 285470], [996149755, 996150024, 268910, 268910],
            [996150024, 996150309, 284830, 284830], [996150309, 996150646, 336650, 336650],
            [996150645, 996150998, 352020, 352020], [996150997, 996151355, 357510, 357510],
            [996151355, 996151734, 378710, 378710], [996151733, 996152027, 293360, 293360],
            [$lateSave ? 996152027 : 996151993, $lateSave ? 996152177 : 996152144, 150430, 117370],
        ] as [$start, $timestamp, $elapsed, $timer]) {
            $data .= FitFixture::message(19, [
                [2, 0x86, pack($word, $start)], [253, 0x86, pack($word, $timestamp)],
                [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $timer)], [200, 0x0D, "\xAA\xBB"],
            ], [[8, 0, "\xDE\xAD"]], $architecture);
        }

        $data .= FitFixture::message(18, [
            [253, 0x86, pack($word, 996152144)], [2, 0x86, pack($word, 996148910)],
            [7, 0x86, pack($word, 3234020)], [8, 0x86, pack($word, 3234020)],
        ], [], $architecture);

        return FitFixture::file($data);
    }

    private function roundedStopFile(int $architecture = 0): string
    {
        // COROS PACE: STOP is one second before Session end; the final Lap
        // starts one second after the preceding clock. All duration sums are exact.
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $base = 996148910;
        $data = FitFixture::message(0, [[0, 0, "\x04"]]);
        $data .= FitFixture::message(34, [[253, 0x86, pack($word, $base)], [0, 0x86, pack($word, 600000)], [1, 0x84, pack($short, 1)]], [], $architecture);
        foreach ([[0, 0], [599, 4]] as [$at, $type]) {
            $data .= FitFixture::message(21, [[253, 0x86, pack($word, $base + $at)], [0, 0, "\x00"], [1, 0, chr($type)]], [], $architecture);
            $data .= FitFixture::message(20, [[253, 0x86, pack($word, $base + $at)], [200, 0x0D, "\xAB\xCD"]], [[8, 0, "\xDE\xAD"]], $architecture);
        }
        foreach ([[0, 450, 450000, 450000], [451, 620, 169000, 150000]] as [$start, $timestamp, $elapsed, $timer]) {
            $data .= FitFixture::message(19, [[2, 0x86, pack($word, $base + $start)], [253, 0x86, pack($word, $base + $timestamp)], [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $timer)]], [[8, 0, "\xDE\xAD"]], $architecture);
        }
        $data .= FitFixture::message(18, [[2, 0x86, pack($word, $base)], [253, 0x86, pack($word, $base + 600)], [7, 0x86, pack($word, 600000)], [8, 0x86, pack($word, 600000)]], [], $architecture);

        return FitFixture::file($data);
    }
}
