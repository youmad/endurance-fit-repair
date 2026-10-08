<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedMovingTimerRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class RecordedMovingTimerRepairerTest extends TestCase
{
    private const int START = 1_000_000_000;

    public function testNativeMovingBoundsFillOnlyMissingTimersAndPreserveAllOriginalData(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = $this->fixture($architecture);
            $repair = (new RecordedMovingTimerRepairer())->repair($source);
            self::assertSame([8, 8, 0], array_column($repair->report['changes'], 'field'));
            self::assertSame([60_000, 60_000, 60_000], array_column($repair->report['changes'], 'value'));
            $before = EditableFitFile::read($source);
            $after = EditableFitFile::read($repair->bytes);
            self::assertCount(count($before->messages), $after->messages);
            foreach ($before->messages as $i => $entry) {
                $old = $entry['raw'];
                $new = $after->messages[$i]['raw'];
                self::assertSame($old->globalMessageNumber(), $new->globalMessageNumber());
                self::assertSame($old->definition()->architecture, $new->definition()->architecture);
                foreach ($old->standardFields() as $j => $field) {
                    self::assertEquals($field, $new->standardFields()[$j]);
                }
                self::assertEquals($old->developerFields(), $new->developerFields());
            }
            self::assertSame(60_000, $repair->report['evidence']['lap_moving_ms']);
            self::assertSame(60_000, $repair->report['evidence']['session_moving_ms']);
            $selector = new PreservingActivityRepairer();
            $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT lap at sequence 5 has no usable total_timer_time field.']]];
            self::assertContains(RecordedMovingTimerRepairer::RULE, $selector->rulesForFailure($failure, null));
            self::assertSame($repair->bytes, $selector->repair($source, RecordedMovingTimerRepairer::RULE)->bytes);
            self::assertSame([], $selector->rulesForFailure(['status' => 'PASS'], null, true));
            $failure['errors'][0]['message'] = 'FIT lap at sequence 5 has no usable total_elapsed_time field.';
            self::assertNotContains(RecordedMovingTimerRepairer::RULE, $selector->rulesForFailure($failure, null));
            $partial = $before->apply([['message' => 4, 'field' => 8, 'type' => 0x86, 'value' => 60_000]]);
            self::assertCount(2, (new RecordedMovingTimerRepairer())->repair($partial)->report['changes']);
            $this->assertDeclined($repair->bytes);
        }
    }

    public function testUnknownMovementPausesConflictingCountersAndLostScopesAreDeclined(): void
    {
        $source = $this->fixture();
        $file = EditableFitFile::read($source);
        foreach ([[4, 52, 59_999], [5, 59, 59_999], [4, 8, 59_000], [6, 0, 59_000],
            [5, 26, 2], [4, 254, 1], [5, 253, self::START + 62],
            [3, 253, self::START + 61], [2, 253, self::START - 1],
        ] as [$message, $field, $value]) {
            $this->assertDeclined($file->apply([['message' => $message, 'field' => $field,
                'type' => in_array($field, [26, 254], true) ? 0x84 : 0x86, 'value' => $value]]));
        }
        foreach (['missingMoving', 'invalidMoving', 'event', 'length', 'duplicateMoving', 'wrongType', 'secondLap', 'relativeClock'] as $variant) {
            $this->assertDeclined($this->fixture(variant: $variant));
        }
    }

    private function assertDeclined(string $bytes): void
    {
        try {
            (new RecordedMovingTimerRepairer())->repair($bytes);
            self::fail('Missing or contradictory moving-time evidence must not supply a timer.');
        } catch (RepairFailed) {
            self::addToAssertionCount(1);
        }
    }

    private function fixture(int $architecture = 0, string $variant = ''): string
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $start = 'relativeClock' === $variant ? 1000 : self::START;
        $u = static fn (int $field, int $value): array => [$field, 0x86, pack($word, $value)];
        $m = static fn (int $number, array $fields, array $developer = []): string => FitFixture::message($number, $fields, $developer, $architecture);
        $data = $m(0, [[0, 0x00, "\x04"]])
            .$m(233, [$u(253, $start + 5), [100, 0x0D, "\x01\x02\x03"]])
            .$m(20, [$u(253, $start), [3, 0x02, "\x80"]])
            .$m(20, [$u(253, $start + 59), [3, 0x02, "\x90"]]);
        $lap = [$u(2, $start), $u(253, $start + 60), $u(7, 60_000), $u(52, 60_000), [111, 0x0D, "\x11\x22"]];
        if ('duplicateMoving' === $variant) {
            $lap[] = $u(52, 60_000);
        }
        $data .= $m(19, $lap, [[3, 0, "\xA1\xB2"]]);
        $session = [$u(2, $start), $u(253, $start + 60), $u(7, 60_000), [5, 0x00, "\x00"]];
        if ('missingMoving' !== $variant) {
            $session[] = 'wrongType' === $variant ? [59, 0x84, pack($short, 60_000)] : $u(59, 'invalidMoving' === $variant ? 0xFFFFFFFF : 60_000);
        }
        $data .= $m(18, $session).$m(34, [$u(253, $start + 60), [1, 0x84, pack($short, 1)]]);
        $data .= match ($variant) {
            'event' => $m(21, [$u(253, $start + 20), [0, 0x00, "\x00"], [1, 0x00, "\x04"]]),
            'length' => $m(101, [$u(253, $start + 20)]),
            'secondLap' => $m(19, $lap),
            default => '',
        };

        return FitFixture::file($data);
    }
}
