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
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummaryDurationReconciler;
use Youmad\Endurance\FitRepair\Internal\Rule\SingleLapDurationRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\SingleLapDurationFixture;

final class SingleLapDurationRepairerTest extends TestCase
{
    public function testCopiesExactRecordedDurationsAndPreservesEveryOtherField(): void
    {
        $architecture = 1;
        $source = SingleLapDurationFixture::file(architecture: $architecture);

        $body = "\x0C".substr($source, 1, 11).substr($source, 14, -2);
        $source = $body.pack('v', FitCrc16::calculate($body));

        $result = (new PreservingActivityRepairer())->repair($source, SingleLapDurationRepairer::RULE);
        self::assertCount(3, $result->report['changes']);
        self::assertSame([7, 8, 8], array_column($result->report['changes'], 'field'));
        self::assertSame([100500, 100500, 100500], array_column($result->report['changes'], 'value'));
        $input = EditableFitFile::read($source);
        $output = EditableFitFile::read($result->bytes);
        self::assertCount(count($input->messages), $output->messages);
        $allowed = [];

        foreach ($result->report['changes'] as $change) {
            $allowed[$change['message']][$change['field']] = true;
        }

        foreach ($input->messages as $index => $entry) {
            $before = $entry['raw'];
            $after = $output->messages[$index]['raw'];
            self::assertSame($before->globalMessageNumber(), $after->globalMessageNumber());
            self::assertSame($before->recordHeaderByte(), $after->recordHeaderByte());
            $unchanged = static fn ($raw): array => array_values(array_map(static fn ($f): array => [$f->definition->fieldNumber, $f->value->bytes()], array_filter($raw->standardFields(), static fn ($f): bool => !isset($allowed[$index][$f->definition->fieldNumber]))));
            self::assertSame($unchanged($before), $unchanged($after));
            self::assertSame(array_map(static fn ($f): string => $f->value->bytes(), $before->developerFields()), array_map(static fn ($f): string => $f->value->bytes(), $after->developerFields()));

            if ($before instanceof RawCompressedTimestampDataMessage) {
                self::assertInstanceOf(RawCompressedTimestampDataMessage::class, $after);
                self::assertSame($before->reconstructedTimestamp, $after->reconstructedTimestamp);
            }

            foreach ($allowed[$index] ?? [] as $field => $_) {
                self::assertSame(100500, PhysicalMessage::fromRaw($after, 0)->integer($field));
            }
        }
    }

    public function testRestoresStopwatchElapsedFromRecordedIdenticalLapTimer(): void
    {
        $result = (new SingleLapDurationRepairer())->repair(SingleLapDurationFixture::file(['lapTimer' => 100500, 'sessionTimer' => 100500, 'noEvents' => 1, 'sparseRecords' => 1]));
        self::assertCount(1, $result->report['changes']);
        self::assertSame(7, $result->report['changes'][0]['field']);
        self::assertSame(100500, $result->report['changes'][0]['value']);
    }

    public function testCopiesRecordedActivityTimerFromSoleSessionWhenItIsMissing(): void
    {
        $result = (new SingleLapDurationRepairer())->repair(SingleLapDurationFixture::file(['sessionTimer' => 100500, 'missingActivityTimer' => 1]));
        self::assertSame([7, 8, 0], array_column($result->report['changes'], 'field'));
    }

    public function testPhysicalTypeRefusalIdentifiesTheActualField(): void
    {
        $this->expectException(RepairFailed::class);
        $this->expectExceptionMessage('global_message_number=18, sequence=11, field=8, expected_type=0x86, expected_size=4, actual_type=0x85, actual_size=4, duplicate=no');
        (new SingleLapDurationRepairer())->repair(SingleLapDurationFixture::file(['sessionTimer' => 100500, 'timerType' => 0x85], decorate: false));
    }

    public function testRetainsRecordedPauseAndDoesNotRoundItsTimerDuration(): void
    {
        $result = (new SingleLapDurationRepairer())->repair(SingleLapDurationFixture::file(['completeTimer' => 1, 'pause' => 1, 'timer' => 90500, 'sessionTimer' => 90500]));
        self::assertSame([100500, 90500], array_column($result->report['changes'], 'value'));
        self::assertSame(91000, $result->report['evidence']['timer_active_ms']);
        self::assertSame(10000, $result->report['evidence']['timer_paused_ms']);
    }

    public function testSupportsRecordedTimerStopAfterLapAndMissingReferences(): void
    {
        foreach ([102, 0] as $activityTimestamp) {
            $source = SingleLapDurationFixture::file(['completeTimer' => 1, 'stopAfterLap' => 1, 'sessionTimer' => 100500, 'missingReferences' => 1, 'activityTimestamp' => $activityTimestamp]);
            $result = (new SingleLapDurationRepairer())->repair($source);
            self::assertSame([7, 8], array_column($result->report['changes'], 'field'));
            $input = EditableFitFile::read($source);
            $output = EditableFitFile::read($result->bytes);
            $before = array_values(array_filter($input->messages, static fn (array $entry): bool => 34 === $entry['raw']->globalMessageNumber()))[0];
            $after = array_values(array_filter($output->messages, static fn (array $entry): bool => 34 === $entry['raw']->globalMessageNumber()))[0];
            self::assertSame($before['definition'], $after['definition']);
            self::assertSame(substr($source, $before['offset'], $before['length']), substr($result->bytes, $after['offset'], $after['length']));
        }
    }

    /** @param array<string, int> $overrides */
    #[DataProvider('uint8Events')]
    public function testReadsUint8EventEnumsAndPreservesTheirPhysicalBytes(array $overrides, int $architecture): void
    {
        $source = SingleLapDurationFixture::file($overrides, architecture: $architecture);
        $result = (new SingleLapDurationRepairer())->repair($source);
        $input = EditableFitFile::read($source);
        $output = EditableFitFile::read($result->bytes);
        self::assertCount(count($input->messages), $output->messages);
        self::assertStringContainsString('original Event definitions and payloads are retained', $result->report['evidence']['event_encoding_policy']);
        $events = 0;

        foreach ($input->messages as $index => $entry) {
            if (21 !== $entry['raw']->globalMessageNumber()) {
                continue;
            }

            ++$events;
            $after = $output->messages[$index];
            self::assertSame($entry['definition'], $after['definition']);
            self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $after['offset'], $after['length']));
        }

        self::assertGreaterThan(0, $events);
        $expectedTimer = $overrides['timer'] ?? 100500;

        foreach ($result->report['changes'] as $change) {
            self::assertSame(7 === $change['field'] ? 100500 : $expectedTimer, $change['value']);
        }
    }

    /** @return iterable<string, array{array<string, int>, int}> */
    public static function uint8Events(): iterable
    {
        yield 'big endian, uint8 kind, enum type, complete pairs' => [['eventBaseType' => 2, 'eventTypeBaseType' => 0, 'completeTimer' => 1], 1];
        yield 'enum kind, uint8 type, paused' => [['eventBaseType' => 0, 'eventTypeBaseType' => 2, 'completeTimer' => 1, 'pause' => 1, 'timer' => 90500, 'sessionTimer' => 90500], 0];
    }

    /** @param array<string, int> $overrides */
    #[DataProvider('contradictions')]
    public function testDeclinesAmbiguousOrConflictingRecordedValues(array $overrides): void
    {
        $this->expectException(RepairFailed::class);
        (new SingleLapDurationRepairer())->repair(SingleLapDurationFixture::file($overrides));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function contradictions(): iterable
    {
        yield 'no recorded timer source' => [['missingActivityTimer' => 1]];
        yield 'timer sources disagree' => [['sessionTimer' => 100499]];

        yield 'no stopwatch timer for sparse Records' => [['noEvents' => 1, 'sparseRecords' => 1]];

        yield 'Record beyond end' => [['lastRecord' => 103]];

        yield 'mixed duplicate Event type' => [['duplicateEventType' => 1]];

        yield 'incomplete pause trace' => [['pause' => 1, 'timer' => 90500]];
    }

    public function testSelectsOnlyFailedMissingLapDurations(): void
    {
        $repairer = new PreservingActivityRepairer();
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT lap at sequence 25 and byte offset 100 has no usable total_elapsed_time field.']]];
        self::assertSame([SingleLapDurationRepairer::RULE, RecordedSummaryDurationReconciler::RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSingleLapFieldsRepairer::RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedActivitySummaryRepairer::RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedObservationOrderRepairer::RULE], $repairer->rulesForFailure($failure, null));
        $failure['status'] = 'PASS';
        self::assertSame([], $repairer->rulesForFailure($failure, null));
        $failure['status'] = 'FAILED';
        $failure['errors'][0]['message'] = 'FIT segment_lap at sequence 25 has no usable total_elapsed_time field.';
        self::assertSame([PreservingActivityRepairer::SEGMENT_LAP_ELAPSED], $repairer->rulesForFailure($failure, null));
        $failure['errors'][0]['message'] = 'FIT lap at sequence 25 has no usable start_time field.';
        self::assertSame([SingleLapDurationRepairer::START_RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSingleLapFieldsRepairer::RULE], $repairer->rulesForFailure($failure, null));
    }

    public function testCopiesTheMissingStartWithoutChangingDurationsOrDeferredEvents(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = SingleLapDurationFixture::file(['missingLapStart' => 1, 'deferredEvents' => 1, 'completeTimer' => 1,
                'elapsed' => 107500, 'finish' => 108, 'lapElapsed' => 100500, 'lapTimer' => 100500, 'sessionTimer' => 100500,
                'timer' => 100500, 'activityTimestamp' => 108], $architecture, decorate: false);
            $result = (new PreservingActivityRepairer())->repair($source, SingleLapDurationRepairer::START_RULE);
            self::assertSame([2], array_column($result->report['changes'], 'field'));
            self::assertSame([SingleLapDurationFixture::START], array_column($result->report['changes'], 'value'));
            $before = EditableFitFile::read($source);
            $after = EditableFitFile::read($result->bytes);
            self::assertCount(count($before->messages), $after->messages);
            foreach ($before->messages as $index => $entry) {
                $a = PhysicalMessage::fromRaw($entry['raw'], 0);
                $b = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0);
                self::assertSame($a->number, $b->number);
                $fields = static fn (PhysicalMessage $m): array => array_values(array_map(static fn ($f): array => [$f->definition, $f->value->bytes()], array_filter($m->fields, static fn ($f): bool => 19 !== $m->number || 2 !== $f->definition->fieldNumber)));
                self::assertEquals($fields($a), $fields($b));
                self::assertEquals($a->developerFields, $b->developerFields);
                if (19 !== $a->number) {
                    self::assertSame($entry['definition'], $after->messages[$index]['definition']);
                    self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $after->messages[$index]['offset'], $after->messages[$index]['length']));
                }
            }
            $this->assertStartDeclined($result->bytes);
            foreach ([
                ['timerStart' => 1], ['firstRecord' => 1], ['sessionTimer' => 100499],
                ['lapElapsed' => 97000], ['firstLap' => 1], ['numLaps' => 2], ['completeTimer' => 0, 'stop' => 90],
            ] as $conflict) {
                $this->assertStartDeclined(SingleLapDurationFixture::file(['missingLapStart' => 1, 'completeTimer' => 1,
                    'lapElapsed' => 100500, 'lapTimer' => 100500, 'sessionTimer' => 100500, ...$conflict], $architecture, decorate: false));
            }
        }
    }

    public function testRestoresOnlyAnImpossibleWholeSessionLapStartWithCorroboratingCountersAndRecords(): void
    {
        $base = ['lapStart' => 5000, 'lapElapsed' => 100500, 'lapTimer' => 100500, 'sessionTimer' => 100500,
            'distance' => 100000, 'noEvents' => 1, 'activityTimestamp' => 101, 'missingReferences' => 1];
        foreach ([0, 1] as $architecture) {
            $source = SingleLapDurationFixture::file($base, $architecture);
            $repairer = new PreservingActivityRepairer();
            $result = $repairer->repair($source, SingleLapDurationRepairer::IMPOSSIBLE_START_RULE);
            self::assertSame([2], array_column($result->report['changes'], 'field'));
            self::assertSame([SingleLapDurationFixture::START], array_column($result->report['changes'], 'value'));
            $before = EditableFitFile::read($source);
            $after = EditableFitFile::read($result->bytes);
            self::assertCount(count($before->messages), $after->messages);
            foreach ($before->messages as $index => $entry) {
                $a = PhysicalMessage::fromRaw($entry['raw'], 0);
                $b = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0);
                $fields = static fn (PhysicalMessage $m): array => array_values(array_filter($m->fields, static fn ($f): bool => 19 !== $m->number || 2 !== $f->definition->fieldNumber));
                self::assertSame($a->number, $b->number);
                self::assertEquals($fields($a), $fields($b));
                self::assertEquals($a->developerFields, $b->developerFields);
                self::assertSame($a->integer(253), $b->integer(253));
            }
            foreach ([['lapStart' => 0], ['lapDistance' => 99999], ['lapElapsed' => 99000], ['numLaps' => 2, 'missingReferences' => 0], ['lastRecord' => 103], ['noEvents' => 0], ['extraLap' => 1]] as $conflict) {
                // These fixture switches use presence, so remove them when disabled.
                $values = array_replace($base, $conflict);
                foreach (['noEvents', 'missingReferences'] as $switch) {
                    if (0 === ($values[$switch] ?? null)) {
                        unset($values[$switch]);
                    }
                }
                try {
                    $repairer->repair(SingleLapDurationFixture::file($values, $architecture), SingleLapDurationRepairer::IMPOSSIBLE_START_RULE);
                    self::fail('Contradictory or valid original Lap starts must remain untouched.');
                } catch (RepairFailed) {
                    self::addToAssertionCount(1);
                }
            }
        }
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT failed Garmin required check "Lap Message Start Time and Timestamp are Valid".']]];
        self::assertSame([PreservingActivityRepairer::FRONT_SUMMARY_CLOCKS, SingleLapDurationRepairer::IMPOSSIBLE_START_RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapPartitionStartRepairer::NATIVE_RULE], (new PreservingActivityRepairer())->rulesForFailure($failure, null));
    }

    private function assertStartDeclined(string $source): void
    {
        try {
            (new SingleLapDurationRepairer())->repair($source, recoverStart: true);
            self::fail('Ambiguous or already recorded starts must remain untouched.');
        } catch (RepairFailed $failure) {
            self::assertNotSame('', $failure->getMessage());
        }
    }
}
