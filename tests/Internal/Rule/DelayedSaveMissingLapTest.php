<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\DelayedSaveLapFixture;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class DelayedSaveMissingLapTest extends TestCase
{
    /** @param array<string, int> $options */
    #[DataProvider('encodings')]
    public function testAddsTheSoleRecordedLapWithoutChangingAnyOriginalPacket(int $architecture, int $headerSize, array $options): void
    {
        $source = DelayedSaveLapFixture::file($options, $architecture, $headerSize);
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::MISSING_LAP);
        $before = EditableFitFile::read($source);
        $after = EditableFitFile::read($result->bytes);
        $index = array_find_key($before->messages, static fn (array $entry): bool => 18 === $entry['raw']->globalMessageNumber());
        self::assertNotNull($index);
        self::assertCount(count($before->messages) + 1, $after->messages);
        $lap = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0);
        self::assertSame([19, $architecture], [$lap->number, $lap->architecture->value]);
        self::assertSame([DelayedSaveLapFixture::START, DelayedSaveLapFixture::START + 100, 10250, $options['timer'] ?? (isset($options['noPause']) ? 10250 : 7250), 0, 10, 20, 123456], array_map($lap->integer(...), [2, 253, 7, 8, 254, 25, 39, 9]));
        self::assertSame([], $result->report['changes']);
        self::assertTrue($result->report['inserted_messages'][0]['synthetic']);
        self::assertSame('recorded_single_lap_and_timer_span_with_delayed_save', $result->report['evidence']['basis']);
        self::assertSame(90, $result->report['evidence']['save_delay_seconds']);
        self::assertSame(-250, $result->report['evidence']['elapsed_residual_ms']);
        self::assertSame(-250, $result->report['evidence']['timer_residual_ms']);
        self::assertSame(0, $result->report['evidence']['pause_residual_ms']);

        foreach ($before->messages as $ordinal => $entry) {
            $kept = $after->messages[$ordinal < $index ? $ordinal : $ordinal + 1];
            self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $kept['offset'], $kept['length']));
            self::assertSame($entry['definition'], $kept['definition']);

            if ($entry['raw'] instanceof RawCompressedTimestampDataMessage) {
                self::assertInstanceOf(RawCompressedTimestampDataMessage::class, $kept['raw']);
                self::assertSame($entry['raw']->reconstructedTimestamp, $kept['raw']->reconstructedTimestamp);
            }
        }

        self::assertSame($result->bytes, (new PreservingActivityRepairer())->repair($result->bytes, PreservingActivityRepairer::MISSING_LAP)->bytes);
        self::assertSame($result->bytes, (new ActivityRepairer())->repair($source)->bytes);
    }

    /** @return iterable<string, array{int, int, array<string, int>}> */
    public static function encodings(): iterable
    {
        yield 'unpaused, little endian' => [0, 14, ['noPause' => 1]];
        yield 'paused, big endian, no header CRC, uint8 STOP' => [1, 12, ['eventBaseType' => 2, 'stopType' => 1]];
    }

    public function testFillsOnlyMissingSessionIndexesAndRetainsTheRecordedCount(): void
    {
        $source = DelayedSaveLapFixture::file(['missingIndexes' => 1]);
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::MISSING_LAP);
        self::assertSame([254, 25], array_column($result->report['changes'], 'field'));
        $entry = array_find(EditableFitFile::read($result->bytes)->messages, static fn (array $entry): bool => 18 === $entry['raw']->globalMessageNumber());
        self::assertNotNull($entry);
        $session = PhysicalMessage::fromRaw($entry['raw'], 0);
        self::assertSame([0, 0, 1, DelayedSaveLapFixture::START + 100, 10250, 7250], array_map($session->integer(...), [254, 25, 26, 253, 7, 8]));
    }

    /** @param array<string, int> $options */
    #[DataProvider('contradictions')]
    public function testRefusesMissingOrContradictoryDelayedSaveEvidence(array $options): void
    {
        $this->expectException(RepairFailed::class);
        (new PreservingActivityRepairer())->repair(DelayedSaveLapFixture::file($options), PreservingActivityRepairer::MISSING_LAP);
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function contradictions(): iterable
    {
        yield 'lost multiple laps' => [['lapCount' => 2]];

        yield 'no final stop' => [['noStop' => 1]];

        yield 'active time contradicts timer' => [['timer' => 5000]];
    }

    /** @param list<int> $order */
    #[DataProvider('incompleteFiles')]
    public function testRefusesMissingDuplicatedOrReorderedEvidence(array $order): void
    {
        $file = EditableFitFile::read(DelayedSaveLapFixture::file(['plain' => 1, 'plainRecords' => 1]));
        $packets = array_map(static fn (array $entry): string => $entry['definition'].substr($file->bytes, $entry['offset'], $entry['length']), $file->messages);
        $source = FitFixture::file(implode('', array_map(static fn (int $index): string => $packets[$index], $order)));
        $this->expectException(RepairFailed::class);
        (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::MISSING_LAP);
    }

    /** @return iterable<string, array{list<int>}> */
    public static function incompleteFiles(): iterable
    {
        // FileId, timer start, Record, pause, resume, Record, stop, unknown, Session, Activity.

        yield 'duplicate Activity' => [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 9]];

        yield 'timer events backwards' => [[0, 1, 2, 4, 3, 5, 6, 7, 8, 9]];
    }
}
