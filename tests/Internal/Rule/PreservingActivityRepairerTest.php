<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class PreservingActivityRepairerTest extends TestCase
{
    public function testCopiesOnlyMissingTimerStartWhenAllRecordedClocksAndDurationsAgree(): void
    {
        $start = 0x40000020;
        $event = static fn (int $timestamp, int $type, int $group): string => FitFixture::message(21, [
            [253, 0x86, pack('V', $timestamp)], [0, 0, "\x00"], [1, 0, chr($type)], [4, 0x02, chr($group)],
        ], [[7, 0, "\xDE\xAD"]]);
        $source = FitFixture::file(FitFixture::message(0, [[0, 0, "\x04"]])
            .$event(0xFFFFFFFF, 0, 1).FitFixture::record($start).FitFixture::record($start + 100).$event($start + 100, 4, 1)
            .FitFixture::summary(19, $start, 100000, [[8, 0x86, pack('V', 100000)], [254, 0x84, "\x00\x00"]])
            .FitFixture::summary(18, $start, 100000, [[8, 0x86, pack('V', 100000)], [254, 0x84, "\x00\x00"], [25, 0x84, "\x00\x00"], [26, 0x84, "\x01\x00"]])
            .FitFixture::message(34, [[253, 0x86, pack('V', $start + 100)], [0, 0x86, pack('V', 100000)], [1, 0x84, "\x01\x00"]]));
        $repairer = new PreservingActivityRepairer();
        self::assertSame([PreservingActivityRepairer::RECORDED_TIMER_START], $repairer->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => 'FIT event at sequence 3 and byte offset 20 has no usable timestamp field.']]], null));
        $result = $repairer->repair($source, PreservingActivityRepairer::RECORDED_TIMER_START);
        self::assertSame([['message' => 1, 'field' => 253, 'type' => 0x86, 'value' => $start]], $result->report['changes']);
        self::assertSame($result->bytes, (new ActivityRepairer())->repair($source)->bytes);
        $file = EditableFitFile::read($source);
        $offset = $file->messages[1]['offset'] + 1;
        self::assertSame(substr(substr_replace($source, pack('V', $start), $offset, 4), 0, -2), substr($result->bytes, 0, -2));
        foreach ([[1, 253, 0x86, $start], [2, 253, 0x86, $start + 1], [4, 4, 0x02, 0], [5, 8, 0x86, 99000], [6, 7, 0x86, 100001]] as [$message, $field, $type, $value]) {
            try {
                $repairer->repair($file->apply([['message' => $message, 'field' => $field, 'type' => $type, 'value' => $value]]), PreservingActivityRepairer::RECORDED_TIMER_START);
                self::fail('Existing clocks, conflicting groups and durations must prevent recovery.');
            } catch (RepairFailed $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    public function testAddsOnlyMissingIndexesAndPreservesPausesDistanceAndUnknownFields(): void
    {
        $event = FitFixture::message(21, [[253, 0x86, pack('V', 0x10000001)], [0, 0, "\x00"], [1, 0, "\x04"]]);
        $unknown = FitFixture::message(50_000, [[100, 0x0D, "\xAA\xBB"]], [[7, 0, "\x21\x22"]]);
        $lap = FitFixture::message(19, [[8, 0x86, pack('V', 12_604_197)], [9, 0x86, pack('V', 15_784_622)]]);
        $session = FitFixture::message(18, [[25, 0x84, "\x00\x00"], [26, 0x84, "\x01\x00"], [8, 0x86, pack('V', 12_604_197)], [9, 0x86, pack('V', 15_784_622)]]);
        $source = FitFixture::file($event.$unknown.$lap.$session);
        $result = (new ActivityRepairer())->repair($source);
        $output = EditableFitFile::read($result->bytes);
        $messages = array_map(static fn (array $entry): PhysicalMessage => PhysicalMessage::fromRaw($entry['raw'], $output->header->headerSize), $output->messages);

        self::assertSame([21, 50_000, 19, 18], array_column($messages, 'number'));
        self::assertStringContainsString($event.$unknown, $result->bytes);
        self::assertSame(0, $messages[2]->integer(254));
        self::assertSame(0, $messages[3]->integer(254));
        self::assertSame(12_604_197, $messages[3]->integer(8));
        self::assertSame(15_784_622, $messages[3]->integer(9));
        self::assertSame('verified', $result->report['preservation']);
        self::assertSame('not_performed', $result->report['activity_validation']);
        self::assertSame($result->bytes, (new ActivityRepairer())->repair($result->bytes)->bytes);
    }

    public function testRestoresSharedDefinitionsAndPreservesCompressedRecordBytes(): void
    {
        $records = FitFixture::record(0x10000020)."\x41\x00\x00\x14\x00\x01\x03\x01\x02\xA1\x75";
        // Two empty Lap messages share the first definition.
        $source = FitFixture::file($records.FitFixture::message(19, [])."\x00".FitFixture::message(18, [[25, 0x84, "\x00\x00"], [26, 0x84, "\x02\x00"]]));
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::LAP_INDEX);
        $output = EditableFitFile::read($result->bytes);
        self::assertStringContainsString($records, $result->bytes);
        self::assertCount(5, $output->messages);
        self::assertSame(0, PhysicalMessage::fromRaw($output->messages[2]['raw'], 14)->integer(254));
        self::assertSame(1, PhysicalMessage::fromRaw($output->messages[3]['raw'], 14)->integer(254));
    }

    public function testWritesBigEndianIndexAndRetainsDeveloperBytes(): void
    {
        $source = FitFixture::file(FitFixture::message(19, [], [[1, 0, "\xAB\xCD"]], 1)
            .FitFixture::message(18, [[25, 0x84, "\x00\x00"], [26, 0x84, "\x00\x01"]], [], 1));
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::LAP_INDEX);
        $output = EditableFitFile::read($result->bytes);
        self::assertSame(0, PhysicalMessage::fromRaw($output->messages[0]['raw'], 14)->integer(254));
        self::assertSame("\xAB\xCD", $output->messages[0]['raw']->developerFields()[0]->value->bytes());
    }

    public function testReplacesInvalidSentinelWithoutChangingTheDefinition(): void
    {
        $source = FitFixture::file(FitFixture::message(19, [[254, 0x84, "\x00\x00"]])
            .FitFixture::message(18, [[25, 0x84, "\x00\x00"], [26, 0x84, "\x01\x00"], [254, 0x84, "\xFF\xFF"]]));
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::SESSION_INDEX);
        self::assertSame(strlen($source), strlen($result->bytes));
        self::assertSame(0, PhysicalMessage::fromRaw(EditableFitFile::read($result->bytes)->messages[1]['raw'], 14)->integer(254));
    }

    public function testMissingLocalTimeIsPreservedUnlessAnOffsetIsExplicit(): void
    {
        $source = FitFixture::file(FitFixture::message(34, [[253, 0x86, pack('V', 0x10000001)]]));
        self::assertSame($source, (new ActivityRepairer())->repair($source)->bytes);
        $result = (new ActivityRepairer())->repair($source, 3600);
        self::assertSame(0x10000001 + 3600, PhysicalMessage::fromRaw(EditableFitFile::read($result->bytes)->messages[0]['raw'], 14)->integer(5));
    }

    public function testRejectsBadCrcInsteadOfRecoveringAPrefix(): void
    {
        $source = FitFixture::file(FitFixture::record(0x10000001));
        $this->expectException(RepairFailed::class);
        (new ActivityRepairer())->repair(substr($source, 0, -2)."\x00\x00");
    }
}
