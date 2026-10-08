<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class FileIdOrderingRepairerTest extends TestCase
{
    public function testMovesOnlyTheFileIdAndPreservesDeveloperMetadataAndCompressedRecords(): void
    {
        $helpers = FitFixture::message(207, [[0, 0x0D, "\x11\x22"], [3, 0x02, "\x00"]])
            .FitFixture::message(206, [[0, 0x02, "\x00"], [1, 0x02, "\x07"]], [[7, 0, "\xAB\xCD"]]);
        $records = FitFixture::record(0x10000020)."\x41\x00\x00\x14\x00\x01\x03\x01\x02\xA1\x75";
        $fileId = FitFixture::message(0, [[0, 0x02, "\x04"], [4, 0x86, pack('N', 0x10000020)], [100, 0x0D, "\xFE\xDC"]], [], 1);
        $source = FitFixture::file($helpers.$fileId.$records);
        $original = EditableFitFile::read($source);
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::FILE_ID_FIRST);
        $output = EditableFitFile::read($result->bytes);
        self::assertSame([0, 207, 206, 20, 20], array_map(static fn (array $entry): int => $entry['raw']->globalMessageNumber(), $output->messages));
        self::assertStringContainsString($helpers, $result->bytes);
        self::assertStringContainsString($records, $result->bytes);
        self::assertSame($fileId, substr($result->bytes, 14, strlen($fileId)));
        self::assertSame([], $result->report['changes']);
        self::assertSame('verified', $result->report['preservation']);
        self::assertSame([[
            'globalMessageNumber' => 0,
            'fromOriginalMessage' => 2,
            'toMessage' => 0,
            'sourceSequence' => $original->messages[2]['raw']->sequence(),
            'sourceByteOffset' => $original->messages[2]['offset'],
            'basis' => 'sole_activity_file_id',
        ]], $result->report['moved_messages']);
        self::assertSame($result->bytes, (new PreservingActivityRepairer())->repair($result->bytes, PreservingActivityRepairer::FILE_ID_FIRST)->bytes);
        self::assertSame($result->bytes, (new ActivityRepairer())->repair($source)->bytes);
    }

    public function testAnAlreadyFirstNonPadFileIdIsUnchangedEvenWithLaterFileIds(): void
    {
        $source = FitFixture::file(FitFixture::message(105, []).self::fileId().FitFixture::record(0x10000020).self::fileId());
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::FILE_ID_FIRST);
        self::assertSame($source, $result->bytes);
        self::assertSame([], $result->report['moved_messages']);
    }

    public function testMovingRestoresDefinitionsForSharedPayloadsAndKeepsTheHeaderFormat(): void
    {
        $fileId = self::fileId();
        $data = FitFixture::message(50_000, [[2, 0x0D, "\xBB"]]).$fileId."\x00\x04";
        $header = "\x0C\x10".pack('vV', 21_208, strlen($data)).'.FIT';
        $source = $header.$data.pack('v', \Youmad\Endurance\Fit\Checksum\FitCrc16::calculate($header.$data));
        $output = EditableFitFile::read(EditableFitFile::read($source)->moveToFront(1));
        self::assertSame(12, $output->header->headerSize);
        self::assertSame(substr($source, 0, 4), substr($output->bytes, 0, 4));
        self::assertSame([0, 50_000, 0], array_map(static fn (array $entry): int => $entry['raw']->globalMessageNumber(), $output->messages));
        self::assertSame(4, PhysicalMessage::fromRaw($output->messages[2]['raw'], 12)->integer(0));
    }

    #[DataProvider('unsupportedSources')]
    public function testRefusesAnAmbiguousOrNonActivityFileId(string $data): void
    {
        $this->expectException(RepairFailed::class);
        (new PreservingActivityRepairer())->repair(FitFixture::file($data), PreservingActivityRepairer::FILE_ID_FIRST);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedSources(): iterable
    {
        $prefix = FitFixture::message(207, []);

        yield 'multiple' => [$prefix.self::fileId().self::fileId()];
        yield 'wrong type' => [$prefix.FitFixture::message(0, [[0, 0, "\x01"]])];
        yield 'developer metadata dependency' => [$prefix.FitFixture::message(0, [[0, 0, "\x04"]], [[7, 0, "\xAB\xCD"]])];

        // Relocating a custom timestamp must not change a later compressed Record.
        yield 'timestamp context changed' => [FitFixture::record(0x10000020)
            ."\x41\x00\x00\x14\x00\x01\x03\x01\x02\xA1\x75"
            .FitFixture::message(0, [[0, 0, "\x04"], [253, 0x86, pack('V', 0x10000060)]])
            ."\xA2\x76"];
    }

    private static function fileId(): string
    {
        return FitFixture::message(0, [[0, 0, "\x04"]]);
    }
}
