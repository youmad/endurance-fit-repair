<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class LapReferenceSummaryClockTest extends TestCase
{
    private const int START = 0x40000020;

    #[DataProvider('summaryClocks')]
    public function testUsesRecordedIntervalsAndPreservesOriginalSummaryClocks(int $architecture, int $headerSize, bool $summaryFirst): void
    {
        $source = self::source($architecture, $headerSize, $summaryFirst);
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::SESSION_LAP_REFERENCES);
        $before = EditableFitFile::read($source);
        $after = EditableFitFile::read($result->bytes);
        self::assertCount(count($before->messages), $after->messages);
        self::assertSame([25, 26], array_column($result->report['changes'], 'field'));

        foreach ($before->messages as $index => $entry) {
            $kept = $after->messages[$index];
            $a = PhysicalMessage::fromRaw($entry['raw'], 0);
            $b = PhysicalMessage::fromRaw($kept['raw'], 0);
            self::assertSame($a->number, $b->number);
            self::assertSame($a->architecture, $b->architecture);
            self::assertSame($a->integer(253), $b->integer(253));
            self::assertEquals($entry['raw']->developerFields(), $kept['raw']->developerFields());

            foreach ($a->fields as $field) {
                $other = array_find($b->fields, static fn ($candidate): bool => $field->definition->fieldNumber === $candidate->definition->fieldNumber);
                self::assertNotNull($other);
                self::assertSame($field->value->bytes(), $other->value->bytes());
                self::assertEquals($field->definition, $other->definition);
            }

            if (18 === $a->number) {
                self::assertSame([0, 2, self::START, 20000], array_map($b->integer(...), [25, 26, 2, 7]));
            } else {
                self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $kept['offset'], $kept['length']));
            }

            if ($entry['raw'] instanceof RawCompressedTimestampDataMessage) {
                self::assertInstanceOf(RawCompressedTimestampDataMessage::class, $kept['raw']);
                self::assertSame($entry['raw']->reconstructedTimestamp, $kept['raw']->reconstructedTimestamp);
            }
        }

        self::assertSame($result->bytes, (new PreservingActivityRepairer())->repair($result->bytes, PreservingActivityRepairer::SESSION_LAP_REFERENCES)->bytes);
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function summaryClocks(): iterable
    {
        yield 'summary first, little endian' => [0, 14, true];
        yield 'late save, big endian, no header CRC' => [1, 12, false];
    }

    /** @param array{int, int, int, int} $change */
    #[DataProvider('contradictions')]
    public function testRetainsBoundaryAndReferenceRequirements(array $change): void
    {
        [$number, $field, $type, $value] = $change;
        $file = EditableFitFile::read(self::source(0, 14, true));
        $index = array_find_key($file->messages, static fn (array $entry): bool => $number === $entry['raw']->globalMessageNumber());
        self::assertNotNull($index);
        if (0xFFFFFFFF === $value) {
            $offset = $file->messages[$index]['offset'] + 1;

            foreach ($file->messages[$index]['raw']->standardFields() as $physical) {
                if ($field === $physical->definition->fieldNumber) {
                    break;
                }

                $offset += $physical->definition->size;
            }

            $body = substr_replace(substr($file->bytes, 0, -2), "\xFF\xFF\xFF\xFF", $offset, 4);
            $source = $body.pack('v', FitCrc16::calculate($body));
        } else {
            $source = $file->apply([['message' => $index, 'field' => $field, 'type' => $type, 'value' => $value]]);
        }
        $this->expectException(RepairFailed::class);
        (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::SESSION_LAP_REFERENCES);
    }

    /** @return iterable<string, array{array{int, int, int, int}}> */
    public static function contradictions(): iterable
    {
        yield 'Lap exceeds unchanged Session interval' => [[19, 7, 0x86, 21001]];
        yield 'invalid elapsed' => [[19, 7, 0x86, 0xFFFFFFFF]];
        yield 'relative timestamp' => [[19, 253, 0x86, 0x0FFFFFFF]];
    }

    private static function source(int $architecture, int $headerSize, bool $summaryFirst): string
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $summary = static fn (int $number, int $start, int $time, int $elapsed, array $extra = [], array $developer = []): string => FitFixture::message($number, [
            [2, 0x86, pack($word, $start)], [253, 0x86, pack($word, $time)],
            [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $elapsed)], [200, 0x0D, "\xDE\xAD"], ...$extra,
        ], $developer, $architecture);
        $session = $summary(18, self::START, self::START + ($summaryFirst ? 0 : 100), 20000, [
            [254, 0x84, pack($short, 0)], [5, 0, "\x02"],
        ], [[7, 0, "\xAB\xCD"]]);
        $laps = $summary(19, self::START, self::START + ($summaryFirst ? 0 : 80), 10000)
            .$summary(19, self::START + 10, self::START + ($summaryFirst ? 0 : 90), 10000);
        $telemetry = FitFixture::record(self::START)
            ."\x41\x00\x00\x14\x00\x01\x03\x01\x02".chr(0xA0 | ((self::START + 1) & 0x1F))."\x75"
            .FitFixture::record(self::START + 20)
            .FitFixture::message(50000, [[100, 0x0D, "\xAA\xBB"]], [[7, 0, "\x12\x34"]]);
        $bytes = FitFixture::file(FitFixture::message(0, [[0, 0, "\x04"]])
            .($summaryFirst ? $session.$laps.$telemetry : $telemetry.$laps.$session));

        if (12 === $headerSize) {
            $body = "\x0C".substr($bytes, 1, 11).substr($bytes, 14, -2);
            $bytes = $body.pack('v', FitCrc16::calculate($body));
        }

        return $bytes;
    }
}
