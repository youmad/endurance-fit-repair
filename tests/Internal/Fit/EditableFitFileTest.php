<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Fit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class EditableFitFileTest extends TestCase
{
    #[DataProvider('invalidMembers')]
    public function testRejectsInvalidSourceMembers(string $source): void
    {
        $this->expectException(RepairFailed::class);
        EditableFitFile::read($source);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMembers(): iterable
    {
        $source = FitFixture::file(FitFixture::record(0x10000020));
        $corrupt = $source;
        $corrupt[-1] = chr(ord($corrupt[-1]) ^ 1);

        yield 'bad CRC' => [$corrupt];
        yield 'concatenated members' => [$source.$source];
    }

    public function testMultipleFieldEditsAreAtomicAndSharedDefinitionIsRestored(): void
    {
        $first = FitFixture::message(18, [[25, 0x84, "\xFF\xFF"]], [[7, 0, "\xAA\xBB"]]);
        $source = FitFixture::file($first."\x00\xFF\xFF\xCC\xDD");
        $changes = [
            ['message' => 0, 'field' => 25, 'type' => 0x84, 'value' => 0],
            ['message' => 0, 'field' => 26, 'type' => 0x84, 'value' => 1],
        ];
        $result = EditableFitFile::read($source)->apply($changes);
        $output = EditableFitFile::read($result);
        self::assertCount(2, $output->messages);
        self::assertSame(0, PhysicalMessage::fromRaw($output->messages[0]['raw'], 14)->integer(25));
        self::assertSame(1, PhysicalMessage::fromRaw($output->messages[0]['raw'], 14)->integer(26));
        self::assertNull(PhysicalMessage::fromRaw($output->messages[1]['raw'], 14)->integer(25));
        self::assertNull(PhysicalMessage::fromRaw($output->messages[1]['raw'], 14)->integer(26));
        self::assertSame("\xAA\xBB", $output->messages[0]['raw']->developerFields()[0]->value->bytes());
        self::assertSame("\xCC\xDD", $output->messages[1]['raw']->developerFields()[0]->value->bytes());
        self::assertSame(EditableFitFile::read($source)->messages[1]['definition'], $output->messages[1]['definition']);
    }

    public function testInsertionPreservesTwelveByteHeaderAndFollowingCompressedTimestamp(): void
    {
        $timestamp = 0x10000020;
        $data = FitFixture::message(18, [[253, 0x86, pack('V', $timestamp)]])
            ."\x41\x00\x00\x14\x00\x01\x03\x01\x02\xA1\x75";
        $header = "\x0C\x10".pack('vV', 21_208, strlen($data)).'.FIT';
        $source = $header.$data.pack('v', FitCrc16::calculate($header.$data));
        $addition = (new PhysicalMessage(19))->withInteger(253, 0x86, $timestamp - 100);
        $output = EditableFitFile::read(EditableFitFile::read($source)->insertBefore(0, $addition));
        self::assertSame(12, $output->header->headerSize);
        self::assertSame(substr($source, 0, 4), substr($output->bytes, 0, 4));
        $record = $output->messages[2]['raw'];
        self::assertInstanceOf(RawCompressedTimestampDataMessage::class, $record);
        self::assertSame($timestamp + 1, $record->reconstructedTimestamp);
        self::assertStringContainsString($data, $output->bytes);
    }

    public function testInsertionRefusesACompressedTarget(): void
    {
        $file = EditableFitFile::read(FitFixture::file(FitFixture::record(0x10000020)
            ."\x41\x00\x00\x14\x00\x01\x03\x01\x02\xA1\x75"));
        $this->expectException(RepairFailed::class);
        $file->insertBefore(1, new PhysicalMessage(19));
    }

    public function testRejectsDuplicateChanges(): void
    {
        $file = EditableFitFile::read(FitFixture::file(FitFixture::message(18, [])));
        $change = ['message' => 0, 'field' => 25, 'type' => 0x84, 'value' => 0];
        $this->expectException(RepairFailed::class);
        $file->apply([$change, $change]);
    }

    public function testRejectsDuplicatedPhysicalField(): void
    {
        $file = EditableFitFile::read(FitFixture::file(FitFixture::message(18, [[25, 0x84, "\xFF\xFF"], [25, 0x84, "\xFF\xFF"]])));
        $this->expectException(RepairFailed::class);
        $file->apply([['message' => 0, 'field' => 25, 'type' => 0x84, 'value' => 0]]);
    }
}
