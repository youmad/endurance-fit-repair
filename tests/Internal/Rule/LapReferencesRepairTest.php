<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class LapReferencesRepairTest extends TestCase
{
    public function testFillsBothMissingReferencesWithoutChangingTimesOrOtherFields(): void
    {
        $start = 0x10000001;
        $event = FitFixture::message(21, [[253, 0x86, pack('V', $start + 5)], [1, 0, "\x04"]]);
        $source = FitFixture::file($event.FitFixture::summary(19, $start, 10_000)
            .FitFixture::summary(19, $start + 10, 10_000)
            .FitFixture::summary(18, $start, 20_000, [[8, 0x86, pack('V', 12_000)], [9, 0x86, pack('V', 15_784_622)], [100, 0x0D, "\xAB\xCD"]], [[7, 0, "\x21\x22"]]));
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::SESSION_LAP_REFERENCES);
        $output = EditableFitFile::read($result->bytes);
        $session = PhysicalMessage::fromRaw($output->messages[3]['raw'], 14);
        self::assertSame(0, $session->integer(25));
        self::assertSame(2, $session->integer(26));
        self::assertNull($session->integer(254));
        self::assertSame(20_000, $session->integer(7));
        self::assertSame(12_000, $session->integer(8));
        self::assertSame(15_784_622, $session->integer(9));
        self::assertSame("\x21\x22", $output->messages[3]['raw']->developerFields()[0]->value->bytes());
        self::assertStringContainsString($event, $result->bytes);
        self::assertSame([25, 26], array_column($result->report['changes'], 'field'));
        self::assertSame('verified', $result->report['preservation']);
        self::assertSame($result->bytes, (new PreservingActivityRepairer())->repair($result->bytes, PreservingActivityRepairer::SESSION_LAP_REFERENCES)->bytes);
    }

    public function testRetainsTheExistingReferenceWhenOnlyOneIsMissing(): void
    {
        foreach ([[25, 0], [26, 1]] as [$field, $value]) {
            $source = FitFixture::file(FitFixture::summary(19, 0x10000001, 10_000)
                .FitFixture::summary(18, 0x10000001, 10_000, [[$field, 0x84, pack('v', $value)]]));
            $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::SESSION_LAP_REFERENCES);
            self::assertCount(1, $result->report['changes']);
            self::assertSame(51 - $field, $result->report['changes'][0]['field']);
        }
    }

    public function testDefaultApiAppliesReferencesThenBothIndexRules(): void
    {
        $source = FitFixture::file(FitFixture::summary(19, 0x10000001, 10_000)
            .FitFixture::summary(18, 0x10000001, 10_000));
        $result = (new ActivityRepairer())->repair($source);
        self::assertSame(['session_lap_references', 'session_message_index', 'lap_message_index'], array_column($result->report['steps'], 'rule'));
        self::assertSame($result->bytes, (new ActivityRepairer())->repair($result->bytes)->bytes);
    }

    public function testConflictingReferencesAndIndexesAreNeverOverwritten(): void
    {
        foreach ([[25, 1], [26, 0], [26, 2], [254, 1], [254, 0x7000]] as [$field, $value]) {
            $source = FitFixture::file(FitFixture::summary(19, 0x10000001, 10_000)
                .FitFixture::summary(18, 0x10000001, 10_000, [[$field, 0x84, pack('v', $value)]]));
            $result = (new ActivityRepairer())->repair($source);
            self::assertSame($source, $result->bytes);
            self::assertArrayHasKey(PreservingActivityRepairer::SESSION_LAP_REFERENCES, $result->report['unsupported']);
        }
    }

    public function testDoesNotInferReferencesForMultipleSessions(): void
    {
        $source = FitFixture::file(FitFixture::summary(19, 0x10000001, 10_000)
            .FitFixture::summary(18, 0x10000001, 10_000).FitFixture::summary(18, 0x10000001, 10_000));
        self::assertSame($source, (new ActivityRepairer())->repair($source)->bytes);
    }

    public function testRequiresEveryLapToFitSessionBoundaries(): void
    {
        foreach ([[0x10000000, 10_000], [0x10000001, 11_001], [0x10000012, 0]] as [$start, $elapsed]) {
            $source = FitFixture::file(FitFixture::summary(19, $start, $elapsed)
                .FitFixture::summary(18, 0x10000001, 10_000));
            self::assertSame($source, (new ActivityRepairer())->repair($source)->bytes);
        }
    }

    public function testAllowsOneSecondOfEndTimestampResolutionWithoutChangingElapsedTime(): void
    {
        $source = FitFixture::file(FitFixture::summary(19, 0x10000001, 11_000)
            .FitFixture::summary(18, 0x10000001, 10_000));
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::SESSION_LAP_REFERENCES);
        $after = EditableFitFile::read($result->bytes);
        self::assertSame(11_000, PhysicalMessage::fromRaw($after->messages[0]['raw'], 14)->integer(7));
        self::assertSame(10_000, PhysicalMessage::fromRaw($after->messages[1]['raw'], 14)->integer(7));
    }

    public function testMissingAndContradictoryBoundsRemainUnsupported(): void
    {
        foreach ([FitFixture::message(18, []), FitFixture::message(18, [[2, 0x86, pack('V', 0x10000001)], [7, 0x86, pack('V', 10_000)], [253, 0x86, pack('V', 0x0FFFFFFF)]])] as $session) {
            $source = FitFixture::file(FitFixture::summary(19, 0x10000001, 10_000).$session);
            self::assertSame($source, (new ActivityRepairer())->repair($source)->bytes);
        }
    }

    public function testWrongPhysicalReferenceTypeCannotBeReplaced(): void
    {
        $source = FitFixture::file(FitFixture::summary(19, 0x10000001, 10_000)
            .FitFixture::summary(18, 0x10000001, 10_000, [[25, 0x02, "\xFF"]]));
        self::assertSame($source, (new ActivityRepairer())->repair($source)->bytes);
    }

    public function testProvidesOrderedPrerequisitesOnlyForSupportedFailures(): void
    {
        $repairer = new PreservingActivityRepairer();
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT failed Garmin required check "Session Message Valid Message Index": expected message_index=0; got missing.']]];
        self::assertSame([PreservingActivityRepairer::SESSION_INDEX, PreservingActivityRepairer::ONE_BASED_LAP_INDEXES, PreservingActivityRepairer::SESSION_LAP_REFERENCES, PreservingActivityRepairer::UNPAUSED_FINAL_LAP, PreservingActivityRepairer::IMPOSSIBLE_SINGLE_LAP_START, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapPartitionStartRepairer::NATIVE_RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSingleLapFieldsRepairer::RULE], $repairer->rulesForFailure($failure, null));
        self::assertSame(PreservingActivityRepairer::SESSION_INDEX, $repairer->ruleForFailure($failure, null));
        $failure['errors'][0]['message'] = 'FIT failed Garmin required check "Session Message First Lap Index and Num Laps are Valid": missing first_lap_index.';
        self::assertSame([PreservingActivityRepairer::SESSION_LAP_REFERENCES, PreservingActivityRepairer::ONE_BASED_LAP_INDEXES, PreservingActivityRepairer::IMPOSSIBLE_SINGLE_LAP_START, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapPartitionStartRepairer::NATIVE_RULE], $repairer->rulesForFailure($failure, null));
        $failure['errors'][0]['message'] = 'FIT failed Garmin required check "Session Message Valid Message Index": expected message_index=0; got 28672.';
        self::assertSame([PreservingActivityRepairer::RESERVED_SESSION_INDEX], $repairer->rulesForFailure($failure, null));
        $failure['status'] = 'PASS';
        self::assertSame([], $repairer->rulesForFailure($failure, null));
    }
}
