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
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummaryTimestampRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\RecordedSummaryTimestampFixture;

final class RecordedSummaryTimestampRepairerTest extends TestCase
{
    public function testFillsEarlySummariesFromRecordedStopAndKeepsTheirOriginalOrder(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = RecordedSummaryTimestampFixture::file(['front' => 1, 'pause' => 1, 'lastRecord' => 99], $architecture);
            $result = (new RecordedSummaryTimestampRepairer())->repair($source);
            $before = EditableFitFile::read($source);
            $after = EditableFitFile::read($result->bytes);
            self::assertCount(3, $result->report['changes']);
            self::assertSame([0, 34, 18, 19, 21], array_map(static fn (array $entry): int => $entry['raw']->globalMessageNumber(), array_slice($after->messages, 0, 5)));
            foreach ($before->messages as $index => $entry) {
                $a = PhysicalMessage::fromRaw($entry['raw'], 0);
                $b = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0);
                self::assertSame($a->number, $b->number);
                self::assertEquals($a->developerFields, $b->developerFields);
                foreach ($a->fields as $field) {
                    self::assertContainsEquals($field, $b->fields);
                }
                if (in_array($a->number, [18, 19, 34], true)) {
                    self::assertSame(RecordedSummaryTimestampFixture::START + 100, $b->integer(253));
                }
            }
        }
        foreach ([['noStop' => 1], ['lastRecord' => 98], ['lastRecord' => 101], ['lapTimestamp' => 1]] as $options) {
            try {
                (new RecordedSummaryTimestampRepairer())->repair(RecordedSummaryTimestampFixture::file(['front' => 1, ...$options]));
                self::fail('Incomplete or conflicting front summary evidence must be declined.');
            } catch (RepairFailed $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    public function testCopiesMissingLapClocksFromNextStartsAndMatchingExistingSaveClocks(): void
    {
        $options = ['savedEndpoint' => 1, 'noStop' => 1, 'sessionTimestamp' => 1, 'activityTimestamp' => 1,
            'sessionSave' => 101, 'activitySave' => 101, 'lastRecord' => 90, 'indexes' => 1];
        foreach ([0, 1] as $architecture) {
            $source = RecordedSummaryTimestampFixture::file($options, $architecture);
            $result = (new RecordedSummaryTimestampRepairer())->repair($source);
            self::assertSame([RecordedSummaryTimestampFixture::START + 40, RecordedSummaryTimestampFixture::START + 101], array_column($result->report['changes'], 'value'));
            self::assertNull($result->report['evidence']['timer_active_ms']);
            self::assertSame('matching_existing_session_and_activity_save_clocks', $result->report['evidence']['final_endpoint_source']);
            $before = EditableFitFile::read($source);
            $after = EditableFitFile::read($result->bytes);
            self::assertCount(count($before->messages), $after->messages);
            foreach ($before->messages as $index => $entry) {
                $a = PhysicalMessage::fromRaw($entry['raw'], 0);
                $b = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0);
                self::assertSame($a->number, $b->number);
                self::assertEquals($a->developerFields, $b->developerFields);
                foreach ($a->fields as $field) {
                    self::assertContainsEquals($field, $b->fields);
                }
                if (19 !== $a->number) {
                    self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $after->messages[$index]['offset'], $after->messages[$index]['length']));
                }
            }
        }
        foreach ([['activitySave' => 102], ['sessionSave' => 99, 'activitySave' => 99], ['lastRecord' => 39], ['pause' => 1], ['savedEndpoint' => null]] as $change) {
            $source = RecordedSummaryTimestampFixture::file(array_filter([...$options, ...$change], static fn ($value): bool => null !== $value));
            try {
                (new RecordedSummaryTimestampRepairer())->repair($source);
                self::fail('Incomplete or conflicting native SAVE evidence must be declined.');
            } catch (RepairFailed $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
    }

    /** @param array<string, int> $options */
    #[DataProvider('encodings')]
    public function testRestoresMissingTimestampsAndPreservesEveryOtherPhysicalField(int $architecture, int $headerSize, array $options): void
    {
        $source = RecordedSummaryTimestampFixture::file($options, $architecture, $headerSize);
        $result = (new RecordedSummaryTimestampRepairer())->repair($source);
        $before = EditableFitFile::read($source);
        $after = EditableFitFile::read($result->bytes);
        self::assertCount(count($before->messages), $after->messages);
        self::assertCount(4, $result->report['changes']);
        self::assertSame([253], array_unique(array_column($result->report['changes'], 'field')));

        foreach ($before->messages as $index => $entry) {
            $a = PhysicalMessage::fromRaw($entry['raw'], 0);
            $b = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0);
            self::assertSame($a->number, $b->number);
            self::assertSame($a->architecture, $b->architecture);
            $fields = static fn (PhysicalMessage $message): array => array_values(array_map(
                static fn ($field): array => [$field->definition, $field->value->bytes()],
                array_filter($message->fields, static fn ($field): bool => 253 !== $field->definition->fieldNumber || !in_array($message->number, [18, 19, 34], true)),
            ));
            self::assertEquals($fields($a), $fields($b));
            self::assertEquals($a->developerFields, $b->developerFields);

            if (!in_array($a->number, [18, 19, 34], true)) {
                self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $after->messages[$index]['offset'], $after->messages[$index]['length']));
            }
        }

        self::assertSame(RecordedSummaryTimestampFixture::START + 40, PhysicalMessage::fromRaw($after->messages[3]['raw'], 0)->integer(253));
        self::assertSame(100000, $result->report['evidence']['lap_elapsed_sum_ms']);
        self::assertSame(isset($options['pause']) ? 90000 : 100000, $result->report['evidence']['timer_active_ms']);
        $this->expectException(RepairFailed::class);
        (new RecordedSummaryTimestampRepairer())->repair($result->bytes);
    }

    /** @return iterable<string, array{int, int, array<string, int>}> */
    public static function encodings(): iterable
    {
        yield 'invalid sentinels, big endian, no header CRC' => [1, 12, ['sentinels' => 1]];
        yield 'pause, existing indexes, uint8 events' => [0, 14, ['pause' => 1, 'indexes' => 1, 'eventType' => 2]];
        yield 'first Record one second after recorded START' => [1, 14, ['firstRecord' => 1]];
    }

    public function testCopiesAnExistingDelayedSessionSaveIntoMissingActivityTimestamp(): void
    {
        $source = RecordedSummaryTimestampFixture::file(['lapTimestamp' => 1, 'sessionTimestamp' => 1, 'sessionSave' => 110]);
        $result = (new RecordedSummaryTimestampRepairer())->repair($source);
        self::assertCount(1, $result->report['changes']);
        self::assertSame(RecordedSummaryTimestampFixture::START + 110, $result->report['changes'][0]['value']);
        self::assertSame('existing_session_timestamp', $result->report['evidence']['timestamps'][0]['basis']);
    }

    /** @param array{int, int, int} $change */
    #[DataProvider('contradictions')]
    public function testRefusesContradictoryEvidence(array $change): void
    {
        [$messageNumber, $field, $value] = $change;
        $file = EditableFitFile::read(RecordedSummaryTimestampFixture::file(['indexes' => 1]));
        $index = array_find_key($file->messages, static fn (array $entry): bool => $messageNumber === $entry['raw']->globalMessageNumber());
        self::assertNotNull($index);
        $type = in_array($field, [254, 25, 26], true) || (34 === $messageNumber && 1 === $field) ? 0x84
            : (21 === $messageNumber && in_array($field, [0, 1], true) ? 0x00 : (21 === $messageNumber && 4 === $field ? 0x02 : 0x86));
        if (21 === $messageNumber && in_array($field, [0, 1, 4], true)) {
            $source = RecordedSummaryTimestampFixture::file([[0 => 'eventKind', 1 => 'timerType', 4 => 'eventGroup'][$field] => $value]);
        } else {
            $source = $file->apply([['message' => $index, 'field' => $field, 'type' => $type, 'value' => $value]]);
        }
        $this->expectException(RepairFailed::class);
        (new RecordedSummaryTimestampRepairer())->repair($source);
    }

    /** @return iterable<string, array{array{int, int, int}}> */
    public static function contradictions(): iterable
    {
        yield 'Session timer differs' => [[18, 8, 99000]];

        yield 'Lap index conflict' => [[19, 254, 2]];

        yield 'Lap timestamp contradicts span' => [[19, 253, RecordedSummaryTimestampFixture::START + 39]];

        yield 'first Record beyond START resolution' => [[20, 253, RecordedSummaryTimestampFixture::START + 2]];

        yield 'timer has wrong group' => [[21, 4, 1]];
    }

    /** @param array<string, int> $options */
    #[DataProvider('incompleteEvidence')]
    public function testRefusesIncompleteTimerAndAlreadyCompleteFile(array $options): void
    {
        $this->expectException(RepairFailed::class);
        (new RecordedSummaryTimestampRepairer())->repair(RecordedSummaryTimestampFixture::file($options));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function incompleteEvidence(): iterable
    {
        yield 'no stop' => [['noStop' => 1]];
        yield 'already complete' => [['existing' => 1]];
    }

    public function testPreservesCompressedRecordTimestampsWhenAnExplicitRecordReseedsThem(): void
    {
        $source = $this->compressedRecord(false);
        $result = (new RecordedSummaryTimestampRepairer())->repair($source);
        $before = array_find(EditableFitFile::read($source)->messages, static fn (array $entry): bool => $entry['raw'] instanceof RawCompressedTimestampDataMessage);
        $after = array_find(EditableFitFile::read($result->bytes)->messages, static fn (array $entry): bool => $entry['raw'] instanceof RawCompressedTimestampDataMessage);
        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame($before['raw']->reconstructedTimestamp, $after['raw']->reconstructedTimestamp);
        self::assertSame($before['raw']->standardFields()[0]->value->bytes(), $after['raw']->standardFields()[0]->value->bytes());
    }

    public function testRefusesWhenANewLapTimestampWouldReseedAnOriginalCompressedRecord(): void
    {
        $this->expectException(RepairFailed::class);
        $this->expectExceptionMessage('compressed timestamp');
        (new RecordedSummaryTimestampRepairer())->repair($this->compressedRecord(true));
    }

    private function compressedRecord(bool $afterLap): string
    {
        $source = RecordedSummaryTimestampFixture::file();
        $file = EditableFitFile::read($source);
        $entry = array_find($file->messages, static fn (array $entry): bool => ($afterLap ? 19 : 20) === $entry['raw']->globalMessageNumber());
        self::assertNotNull($entry);
        $packet = "\x41\x00\x00\x14\x00\x01\x03\x01\x02".chr(0xA0 | ((RecordedSummaryTimestampFixture::START + 1) & 0x1F))."\x75";
        $body = substr_replace(substr($source, 0, -2), $packet, $entry['offset'] + $entry['length'], 0);
        $body = substr_replace($body, pack('V', strlen($body) - 14), 4, 4);
        $body = substr_replace($body, pack('v', FitCrc16::calculate(substr($body, 0, 12))), 12, 2);

        return $body.pack('v', FitCrc16::calculate($body));
    }

    /** @param array<string, int> $options */
    #[DataProvider('incompatibleFields')]
    public function testRefusesAmbiguousPhysicalTimestampFields(array $options): void
    {
        $this->expectException(RepairFailed::class);
        (new RecordedSummaryTimestampRepairer())->repair(RecordedSummaryTimestampFixture::file(['sentinels' => 1, ...$options]));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function incompatibleFields(): iterable
    {
        yield 'wrong type' => [['timestampType' => 0x85]];

        yield 'duplicate' => [['duplicateTimestamp' => 1]];
    }

    public function testSelectsOnlyMissingSummaryTimestampFailures(): void
    {
        $repairer = new PreservingActivityRepairer();

        foreach (['lap', 'session', 'activity'] as $name) {
            $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT '.$name.' at sequence 42 and byte offset 500 has no usable timestamp field.']]];
            $expected = [RecordedSummaryTimestampRepairer::RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSingleLapFieldsRepairer::RULE];
            if ('lap' === $name) {
                $expected[] = \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummarySaveRepairer::RULE;
            }
            self::assertSame($expected, $repairer->rulesForFailure($failure, null));
            $result = $repairer->repair(RecordedSummaryTimestampFixture::file(), RecordedSummaryTimestampRepairer::RULE);
            self::assertSame(RecordedSummaryTimestampRepairer::RULE, $result->report['rule']);
        }

        foreach (['FIT length at sequence 42 has no usable timestamp field.', 'FIT event at sequence 42 has no usable timestamp field.', 'FIT lap at sequence 42 has no usable total_elapsed_time field.'] as $message) {
            self::assertNotContains(RecordedSummaryTimestampRepairer::RULE, $repairer->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => $message]]], null));
        }

        self::assertSame([], $repairer->rulesForFailure(['status' => 'PASS', 'errors' => [['message' => 'FIT lap at sequence 42 has no usable timestamp field.']]], null));
    }
}
