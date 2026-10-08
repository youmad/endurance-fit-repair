<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummaryDurationReconciler;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class RecordedSummaryDurationReconcilerTest extends TestCase
{
    private const int START = 0x10000020;

    public function testExplicitMultipleSessionOwnershipRetainsPausedTailsAndEveryOriginalClock(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $message = static fn (int $number, array $fields): string => FitFixture::message($number, $fields, architecture: $architecture);
            $data = $message(0, [[0, 0, "\x04"]]);
            foreach ([[0, 0], [20, 4], [40, 0], [60, 4]] as [$offset, $type]) {
                $data .= $message(21, [[253, 0x86, pack($word, self::START + $offset)], [0, 0, "\x00"], [1, 0, chr($type)]]);
                $data .= $message(20, [[253, 0x86, pack($word, self::START + $offset)], [3, 2, "\x64"]]);
            }
            foreach ([0, 30] as $index => $offset) {
                $data .= $message(19, [[254, 0x84, pack($short, $index)], [253, 0x86, pack($word, self::START)], [2, 0x86, pack($word, self::START + $offset)],
                    [7, 0x86, pack($word, 20000)], [8, 0x86, pack($word, 20000)]]);
                $data .= $message(18, [[254, 0x84, pack($short, $index)], [253, 0x86, pack($word, self::START)], [2, 0x86, pack($word, self::START + $offset)],
                    [7, 0x86, pack($word, 20000)], [8, 0x86, pack($word, 20000)], [25, 0x84, pack($short, $index)], [26, 0x84, pack($short, 1)]]);
            }
            $data .= $message(34, [[253, 0x86, pack($word, self::START)], [0, 0x86, pack($word, 39000)], [1, 0x84, pack($short, 2)]]);
            $source = FitFixture::file($data);
            $repairer = new RecordedSummaryDurationReconciler();
            $result = $repairer->repair($source);
            self::assertSame($result->bytes, $repairer->repair($source, true)->bytes);
            self::assertSame([30000, 30000], array_column($result->report['evidence']['session_partition'], 'elapsed_ms'));
            self::assertSame([10000, 10000], array_column($result->report['evidence']['session_partition'], 'paused_ms'));
            self::assertSame([20000, 20000], array_column($result->report['evidence']['lap_partition'], 'timer_ms'));
            $restored = substr($result->bytes, 0, -2);
            foreach ($result->report['changes'] as $change) {
                self::assertContains([$change['global_message_number'], $change['field']], [[18, 7], [18, 8], [19, 7], [19, 8], [34, 0]]);
                $restored = substr_replace($restored, substr($source, $change['byte_offset'], 4), $change['byte_offset'], 4);
            }
            self::assertSame(substr($source, 0, -2), $restored);
            $file = EditableFitFile::read($source);
            foreach ([[10, 25, 0x84, 1], [12, 254, 0x84, 0], [11, 254, 0x84, 0], [12, 2, 0x86, self::START + 31], [13, 1, 0x84, 1],
                [3, 1, 0, 0], [7, 1, 0, 0], [8, 253, 0x86, self::START + 62]] as [$ordinal, $field, $type, $value]) {
                try {
                    $repairer->repair($file->apply([['message' => $ordinal, 'field' => $field, 'type' => $type, 'value' => $value]]), true);
                    self::fail('Contradictory ownership or incomplete timer evidence must not be reconciled.');
                } catch (RepairFailed) {
                }
            }
            try {
                $repairer->repair($result->bytes);
                self::fail('Compatible counters must remain untouched.');
            } catch (RepairFailed) {
            }
        }
    }

    public function testScaledLapClocksRequireEveryNativeIntervalAndRetainTheRecordedStream(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $message = static fn (int $number, array $fields): string => FitFixture::message($number, $fields, architecture: $architecture);
            $data = $message(0, [[0, 0, "\x04"]]);
            foreach ([[self::START, 0], [self::START + 330, 4]] as [$time, $type]) {
                $data .= $message(21, [[253, 0x86, pack($word, $time)], [0, 0, "\x00"], [1, 0, chr($type)]]);
                $data .= $message(20, [[253, 0x86, pack($word, $time)], [3, 2, "\x64"]]);
            }
            foreach ([100000, 200000, 300000] as $index => $offset) {
                $data .= $message(19, [[254, 0x84, pack($short, $index)], [253, 0x86, pack($word, self::START + $offset)],
                    [2, 0x86, pack($word, self::START + $offset - 100)], [7, 0x86, pack($word, 100000)], [8, 0x86, pack($word, 100000)]]);
            }
            $data .= $message(18, [[254, 0x84, pack($short, 0)], [253, 0x86, pack($word, self::START + 330)], [2, 0x86, pack($word, self::START)],
                [7, 0x86, pack($word, 330000)], [8, 0x86, pack($word, 330000)], [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, 3)]]);
            $data .= $message(34, [[253, 0x86, pack($word, self::START)], [0, 0x86, pack($word, 330000)], [1, 0x84, pack($short, 1)]]);
            $source = FitFixture::file($data);
            $repairer = new RecordedSummaryDurationReconciler();
            $result = $repairer->repair($source, true);
            self::assertSame([100000, 100000, 130000], array_column($result->report['evidence']['lap_partition'], 'elapsed_ms'));
            $restore = substr($result->bytes, 0, -2);
            foreach ($result->report['changes'] as $change) {
                self::assertSame(19, $change['global_message_number']);
                $restore = substr_replace($restore, substr($source, $change['byte_offset'], 4), $change['byte_offset'], 4);
            }
            self::assertSame(substr($source, 0, -2), $restore);
            $file = EditableFitFile::read($source);
            foreach ([[5, 2, self::START + 200000 - 101], [5, 253, self::START + 201000], [8, 8, 329000]] as [$ordinal, $field, $value]) {
                try {
                    $repairer->repair($file->apply([['message' => $ordinal, 'field' => $field, 'type' => 0x86, 'value' => $value]]), true);
                    self::fail('One conflicting native interval or Session total must invalidate the entire scale inference.');
                } catch (RepairFailed) {
                }
            }
        }
    }

    public function testRecordedPartitionKeepsClocksCompressedRecordsAndPhysicalPayloads(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = self::file(['uint8Enums' => 1 === $architecture, 'earlySummaryWrite' => 1 === $architecture, 'duplicateRecordClock' => true], architecture: $architecture);
            $result = (new RecordedSummaryDurationReconciler())->repair($source);
            self::assertSame('reconcile_durations', $result->report['mode']);
            self::assertSame('not_proven', $result->report['inference']['historical_accuracy']);
            self::assertSame(10000, $result->report['evidence']['paused_ms']);
            self::assertCount(3, $result->report['changes']);
            $restore = substr($result->bytes, 0, -2);
            foreach ($result->report['changes'] as $change) {
                self::assertSame(bin2hex(substr($source, $change['byte_offset'], 4)), $change['original_bytes']);
                $restore = substr_replace($restore, substr($source, $change['byte_offset'], 4), $change['byte_offset'], 4);
            }
            self::assertSame(substr($source, 0, -2), $restore);
            $before = EditableFitFile::read($source);
            $after = EditableFitFile::read($result->bytes);
            self::assertCount(count($before->messages), $after->messages);
            foreach ($before->messages as $index => $entry) {
                $old = PhysicalMessage::fromRaw($entry['raw'], 0);
                $new = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0);
                self::assertSame($old->integer(253), $new->integer(253));
                self::assertSame($old->integer(2), $new->integer(2));
                if (in_array($old->number, [20, 21, 999], true)) {
                    self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $after->messages[$index]['offset'], $after->messages[$index]['length']));
                }
            }
        }
        $source = self::file(['missingElapsed' => true]);
        $result = (new RecordedSummaryDurationReconciler())->repair($source);
        self::assertSame([30000, 30000], array_column($result->report['evidence']['lap_partition'], 'elapsed_ms'));
        self::assertCount(2, array_filter($result->report['changes'], static fn (array $change): bool => 7 === $change['field'] && null === $change['original_value']));
        $source = self::file(['missingActivityTimer' => true, 'missingReferences' => true, 'missingActivitySessions' => true]);
        $result = (new RecordedSummaryDurationReconciler())->repair($source);
        $before = EditableFitFile::read($source);
        $messages = EditableFitFile::read($result->bytes)->messages;
        self::assertSame(50000, PhysicalMessage::fromRaw($messages[array_key_last($messages)]['raw'], 0)->integer(0));
        self::assertSame(1, PhysicalMessage::fromRaw($messages[array_key_last($messages)]['raw'], 0)->integer(1));
        self::assertNull($result->report['changes'][array_key_last($result->report['changes'])]['byte_offset']);
        $lapIndex = 0;
        foreach ($messages as $index => $entry) {
            $old = PhysicalMessage::fromRaw($before->messages[$index]['raw'], 0);
            $new = PhysicalMessage::fromRaw($entry['raw'], 0);
            foreach ($old->fields as $field) {
                if (!in_array($old->number, [18, 19, 34], true) || !in_array($field->definition->fieldNumber, [7, 8, 0], true)) {
                    $retained = array_values(array_filter($new->fields, static fn ($candidate): bool => $candidate->definition->fieldNumber === $field->definition->fieldNumber));
                    self::assertCount(1, $retained);
                    self::assertSame($field->value->bytes(), $retained[0]->value->bytes());
                }
            }
            if (19 === $new->number) {
                self::assertSame($lapIndex++, $new->integer(254));
            } elseif (18 === $new->number) {
                self::assertSame(0, $new->integer(254));
                self::assertSame(0, $new->integer(25));
                self::assertSame(2, $new->integer(26));
            }
        }
    }

    public function testDeclinesCompatibleOrUncorroboratedDataAndSelectsOnlyRelevantFailures(): void
    {
        foreach ([
            ['compatible' => true], ['missingStop' => true], ['unsupportedStop' => true], ['badGroup' => true],
            ['unsupportedDisable' => true],
            ['duplicateStart' => true], ['badLapIndex' => true], ['multipleSessions' => true],
            ['backwardRecord' => true], ['earlyRecord' => true], ['relativeRecord' => true], ['lapAfterStop' => true], ['lengthOverlap' => true],
            ['repeatedTimerStart' => true], ['recordAfterStop' => true, 'earlySummaryWrite' => true], ['startTooLate' => true], ['outOfOrderStop' => true], ['badLapCount' => true],
            ['invalidActivitySessions' => true],
            ['conflictingRecordClock' => true],
            ['nativeUnpaused' => true, 'nativePause' => true], ['nativeUnpaused' => true, 'lapPause' => true], ['nativeUnpaused' => true, 'missingElapsed' => true],
            ['nativeUnpaused' => true, 'sessionEndMismatch' => true], ['nativeUnpaused' => true, 'sparseFinalRecord' => true],
            ['terminalSessionStop' => true, 'missingSessionEnd' => true], ['terminalSessionStop' => true, 'sessionEndMismatch' => true],
            ['terminalSessionStop' => true, 'sparseFinalRecord' => true], ['terminalSessionStop' => true, 'earlySummaryWrite' => true],
            ['terminalSessionStop' => true, 'nativeElapsedMismatch' => true], ['terminalSessionStop' => true, 'nativeTimerMismatch' => true],
            ['terminalSessionStop' => true, 'activityTimerMismatch' => true], ['terminalSessionStop' => true, 'badSessionEndGroup' => true],
            ['terminalSessionStop' => true, 'earlySessionEnd' => true], ['terminalSessionStop' => true, 'repeatedTimerStart' => true],
        ] as $variant) {
            try {
                (new RecordedSummaryDurationReconciler())->repair(self::file($variant));
                self::fail('Compatible or uncorroborated summaries must be retained: '.json_encode($variant));
            } catch (RepairFailed) {
            }
        }
        $selector = new PreservingActivityRepairer();
        foreach ([
            'FIT lap at sequence 8 is not sequential with the previous lap or does not abut it:',
            'FIT session at sequence 8 is not sequential with the previous session or does not abut it:',
            '"Record Message Timestamps Fall Within Session Message Times"',
            '"Session Message total_timer_time is Equal to the Sum of Lap Messages total_timer_time Values"',
            '"Session Message total_elapsed_time is Equal to the Sum of Lap Messages total_elapsed_time Values"',
            '"Lap Message Valid Message Index"',
            'Final activity session cannot finish before the latest event.',
            'Final activity session cannot finish before the latest activity detail.',
            'FIT session total_timer_time (2000 microseconds) cannot exceed total_elapsed_time (0 microseconds).',
        ] as $failure) {
            self::assertContains(RecordedSummaryDurationReconciler::RULE, $selector->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => $failure]]], null));
        }
        self::assertSame([], $selector->rulesForFailure(['status' => 'PASS'], null));
        self::assertNotContains(RecordedSummaryDurationReconciler::RULE, $selector->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => '"Activity Message Local Timestamp is Valid"']]], null));
    }

    public function testSparseRecordsAndRedundantStopAllRetainEveryTimerEventAndThePausedTail(): void
    {
        foreach ([[], ['redundantStop' => true], ['redundantSingleStop' => true], ['zeroSpan' => true], ['disabledStop' => true], ['lengthTail' => true], ['recordAfterStop' => true]] as $variant) {
            $source = self::file($variant + ['startOneSecondLate' => true, 'sparseFinalRecord' => !($variant['recordAfterStop'] ?? false)]);
            $result = (new RecordedSummaryDurationReconciler())->repair($source);
            self::assertSame($variant['redundantStop'] ?? false ? 1 : 0, $result->report['evidence']['redundant_stop_all_count']);
            self::assertSame($variant['redundantSingleStop'] ?? false ? 1 : 0, $result->report['evidence']['redundant_stop_count']);
            self::assertSame($variant['disabledStop'] ?? false ? 1 : 0, $result->report['evidence']['redundant_stop_disable_all_count']);
            self::assertSame(($variant['redundantStop'] ?? false) || ($variant['redundantSingleStop'] ?? false) ? 70000 : ($variant['lengthTail'] ?? false ? 60500 : 60000), $result->report['evidence']['elapsed_ms']);
            self::assertSame($variant['recordAfterStop'] ?? false ? 47000 : 49000, $result->report['evidence']['timer_ms']);
            $restored = substr($result->bytes, 0, -2);
            foreach ($result->report['changes'] as $change) {
                $restored = substr_replace($restored, substr($source, $change['byte_offset'], 4), $change['byte_offset'], 4);
            }
            self::assertSame(substr($source, 0, -2), $restored);
        }
    }

    public function testObservedStoppedIntervalsAndNativeEmptyLapsKeepTheOriginalStream(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = self::file(architecture: $architecture);
            $file = EditableFitFile::read($source);
            $lapOrdinals = array_keys(array_filter($file->messages, static fn (array $entry): bool => 19 === $entry['raw']->globalMessageNumber()));
            $sessionOrdinal = array_key_first(array_filter($file->messages, static fn (array $entry): bool => 18 === $entry['raw']->globalMessageNumber()));
            $activityOrdinal = array_key_last($file->messages);
            $last = $lapOrdinals[1];
            $edit = static fn (int $message, int $field, int $value): array => ['message' => $message, 'field' => $field, 'type' => 0x86, 'value' => $value];
            $variants = [
                [$file->apply([$edit($last, 2, self::START + 60), $edit($last, 253, self::START + 60), $edit($last, 7, 0), $edit($last, 8, 0)]), 60000, 50000, 0],
                [$file->apply([$edit($last, 2, self::START + 62), $edit($last, 253, self::START + 62), $edit($last, 7, 4), $edit($last, 8, 4),
                    $edit($sessionOrdinal, 253, self::START + 62), $edit($activityOrdinal, 253, self::START + 62), $edit($sessionOrdinal, 7, 27004)]), 62004, 50000, 4],
                [self::file(['earlyRecord' => true], $architecture), 63000, 50000, 30000],
                [self::file(['invalidSessionStart' => true], $architecture), 60000, 50000, 30000],
                [self::file(['emptyFirstMissingClocks' => true], $architecture), 60000, 50000, 60000],
                [self::file(['nativeUnpaused' => true, 'noTimerStart' => true, 'timestampOnlyStart' => true, 'frozenSessionEnd' => true, 'reservedSessionIndex' => true], $architecture), 60500, 60500, 30500],
            ];
            foreach ($variants as [$bytes, $elapsed, $timer, $lastElapsed]) {
                $result = (new RecordedSummaryDurationReconciler())->repair($bytes, true);
                self::assertSame($elapsed, $result->report['evidence']['elapsed_ms']);
                self::assertSame($timer, $result->report['evidence']['timer_ms']);
                self::assertSame($lastElapsed, $result->report['evidence']['lap_partition'][1]['elapsed_ms']);
                self::assertCount(2, $result->report['evidence']['lap_partition']);
                $restored = substr($result->bytes, 0, -2);
                foreach ($result->report['changes'] as $change) {
                    $size = 0x84 === $change['type'] ? 2 : 4;
                    self::assertSame(bin2hex(substr($bytes, $change['byte_offset'], $size)), $change['original_bytes']);
                    $restored = substr_replace($restored, substr($bytes, $change['byte_offset'], $size), $change['byte_offset'], $size);
                }
                self::assertSame(substr($bytes, 0, -2), $restored);
            }
            foreach ([
                $file->apply([$edit($last, 2, self::START + 60), $edit($last, 253, self::START + 60), $edit($last, 7, 0)]),
                $file->apply([$edit($last, 2, self::START + 62), $edit($last, 253, self::START + 62), $edit($last, 7, 4), $edit($last, 8, 4),
                    $edit($sessionOrdinal, 253, self::START + 62), $edit($activityOrdinal, 253, self::START + 62), $edit($sessionOrdinal, 7, 27005)]),
                self::file(['earlyRecord' => true, 'earlySummaryWrite' => true], $architecture),
                self::file(['nativeUnpaused' => true, 'noTimerStart' => true, 'timestampOnlyStart' => true, 'frozenSessionEnd' => true, 'nativePause' => true], $architecture),
                self::file(['nativeUnpaused' => true, 'noTimerStart' => true, 'timestampOnlyStart' => true, 'frozenSessionEnd' => true, 'unknownTimerType' => true], $architecture),
                self::file(['nativeUnpaused' => true, 'noTimerStart' => true, 'timestampOnlyStart' => true, 'frozenSessionEnd' => true, 'selectedSessionIndex' => true], $architecture),
            ] as $bytes) {
                try {
                    (new RecordedSummaryDurationReconciler())->repair($bytes, true);
                    self::fail('Conflicting counters, unknown Event semantics or unbounded Records must invalidate the inference.');
                } catch (RepairFailed) {
                }
            }
        }
    }

    public function testRecordedSessionEndCorroboratesNativeTotalsWithoutAddingTimerEvents(): void
    {
        foreach ([[], ['noTimerStart' => true], ['missingSessionEnd' => true], ['missingSessionEnd' => true, 'noTimerStart' => true], ['missingSessionEnd' => true, 'noTimerStart' => true, 'frozenSaves' => true]] as $variant) {
            $source = self::file($variant + ['nativeUnpaused' => true]);
            $result = (new RecordedSummaryDurationReconciler())->repair($source);
            self::assertSame($variant['missingSessionEnd'] ?? false ? 'last_record_and_native_unpaused_totals' : 'recorded_session_end_and_native_unpaused_totals', $result->report['evidence']['end_source']);
            self::assertSame(60500, $result->report['evidence']['elapsed_ms']);
            self::assertSame(60500, $result->report['evidence']['timer_ms']);
            self::assertSame(0, $result->report['evidence']['paused_ms']);
            self::assertSame([30000, 30500], array_column($result->report['evidence']['lap_partition'], 'elapsed_ms'));
            $restored = substr($result->bytes, 0, -2);
            foreach ($result->report['changes'] as $change) {
                self::assertSame(19, $change['global_message_number']);
                self::assertContains($change['field'], [7, 8]);
                $restored = substr_replace($restored, substr($source, $change['byte_offset'], 4), $change['byte_offset'], 4);
            }
            self::assertSame(substr($source, 0, -2), $restored);
        }
        foreach ([[], ['terminalZeroSpan' => true, 'uint8Enums' => true]] as $variant) {
            $source = self::file($variant + ['terminalSessionStop' => true], architecture: $variant['uint8Enums'] ?? false ? 1 : 0);
            $result = (new RecordedSummaryDurationReconciler())->repair($source);
            self::assertSame('recorded_session_end_closing_timer_trace', $result->report['evidence']['end_source']);
            self::assertSame(60000, $result->report['evidence']['elapsed_ms']);
            self::assertSame(50000, $result->report['evidence']['timer_ms']);
            self::assertSame(10000, $result->report['evidence']['paused_ms']);
            self::assertSame($variant['terminalZeroSpan'] ?? false ? 3 : 2, count($result->report['evidence']['active_spans']));
            $restored = substr($result->bytes, 0, -2);
            foreach ($result->report['changes'] as $change) {
                self::assertSame(bin2hex(substr($source, $change['byte_offset'], 4)), $change['original_bytes']);
                $restored = substr_replace($restored, substr($source, $change['byte_offset'], 4), $change['byte_offset'], 4);
            }
            self::assertSame(substr($source, 0, -2), $restored);
        }
    }

    public function testIncompleteClocksRequireOptInAndCorroboratedTimerPartition(): void
    {
        foreach ([0, 1] as $architecture) {
            foreach ([['missingClocks' => true], ['missingSave' => true]] as $variant) {
                $source = self::file($variant + ['compatible' => true], $architecture);
                $result = (new RecordedSummaryDurationReconciler())->repair($source, true);
                self::assertSame(RecordedSummaryDurationReconciler::CLOCK_RULE, $result->report['rule']);
                self::assertSame('not_proven', $result->report['inference']['historical_accuracy']);
                self::assertGreaterThan(0, $result->report['evidence']['inferred_clock_count']);
                $before = EditableFitFile::read($source);
                $after = EditableFitFile::read($result->bytes);
                self::assertCount(count($before->messages), $after->messages);
                foreach ($before->messages as $index => $entry) {
                    $old = PhysicalMessage::fromRaw($entry['raw'], 0);
                    $new = PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0);
                    foreach ($old->fields as $field) {
                        $retained = array_values(array_filter($new->fields, static fn ($candidate): bool => $candidate->definition->fieldNumber === $field->definition->fieldNumber));
                        self::assertCount(1, $retained);
                        self::assertSame($field->value->bytes(), $retained[0]->value->bytes());
                    }
                    self::assertEquals($old->developerFields, $new->developerFields);
                }
            }
        }
        foreach ([['collapsedLap' => true], ['collapsedLap' => true, 'zeroCollapsedCounters' => true], ['sessionOneSecondLate' => true]] as $variant) {
            $source = self::file($variant + ['compatible' => true]);
            $result = (new RecordedSummaryDurationReconciler())->repair($source, true);
            self::assertSame(1, $result->report['evidence']['inferred_clock_count']);
            self::assertSame(self::START, $result->report['evidence']['clock_inferences'][0]['value']);
            $restored = substr($result->bytes, 0, -2);
            foreach ($result->report['changes'] as $change) {
                self::assertSame(bin2hex(substr($source, $change['byte_offset'], 4)), $change['original_bytes']);
                $restored = substr_replace($restored, substr($source, $change['byte_offset'], 4), $change['byte_offset'], 4);
            }
            self::assertSame(substr($source, 0, -2), $restored);
        }
        foreach ([['collapsedLap' => true, 'badCollapsedCounters' => true], ['missingClocks' => true, 'nativeElapsedMismatch' => true], ['missingClocks' => true, 'missingStop' => true],
            ['missingSave' => true, 'recordAfterStop' => true], ['missingClocks' => true, 'repeatedTimerStart' => true]] as $variant) {
            try {
                (new RecordedSummaryDurationReconciler())->repair(self::file($variant + ['compatible' => true]), true);
                self::fail('Incomplete clocks need a corroborated complete trace.');
            } catch (RepairFailed) {
            }
        }
        try {
            (new RecordedSummaryDurationReconciler())->repair(self::file(['invalidSave' => true, 'compressedAfterMissingSave' => true]), true);
            self::fail('A new SAVE must not reseed later compressed Record clocks.');
        } catch (RepairFailed $error) {
            self::assertSame('Inferred summary SAVEs would change original data or compressed Record clocks.', $error->getMessage());
        }
        $selector = new PreservingActivityRepairer();
        $failure = ['status' => 'FAILED', 'errors' => [['message' => 'FIT lap at sequence 8 has no usable start_time field.']]];
        self::assertNotContains(RecordedSummaryDurationReconciler::CLOCK_RULE, $selector->rulesForFailure($failure, null));
        self::assertContains(RecordedSummaryDurationReconciler::CLOCK_RULE, $selector->rulesForFailure($failure, null, true));
        self::assertNotContains(RecordedSummaryDurationReconciler::CLOCK_RULE, $selector->rulesForFailure(['status' => 'PASS'], null, true));
        self::assertNotContains(RecordedSummaryDurationReconciler::CLOCK_RULE, $selector->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => '"Activity Message Local Timestamp is Valid"']]], null, true));
    }

    public function testBufferedPoolSummariesRetainTheStoppedRecordTail(): void
    {
        $data = FitFixture::message(0, [[0, 0, "\x04"]]);
        $data .= FitFixture::message(34, [[253, 0x86, pack('V', self::START + 70)], [0, 0x86, pack('V', 60000)], [1, 0x84, pack('v', 1)]]);
        $data .= FitFixture::message(18, [[254, 0x84, pack('v', 0)], [253, 0x86, pack('V', self::START + 70)],
            [2, 0x86, pack('V', self::START)], [7, 0x86, pack('V', 60000)], [8, 0x86, pack('V', 60000)],
            [25, 0x84, pack('v', 0)], [26, 0x84, pack('v', 1)], [5, 0, "\x05"], [6, 0, "\x11"]]);
        $data .= FitFixture::record(self::START);
        foreach ([[0, 0], [60, 4]] as [$offset, $type]) {
            $data .= FitFixture::message(21, [[253, 0x86, pack('V', self::START + $offset)], [0, 0, "\x00"], [1, 0, chr($type)]]);
        }
        $data .= FitFixture::message(101, [[254, 0x84, pack('v', 0)], [253, 0x86, pack('V', self::START + 64)],
            [2, 0x86, pack('V', self::START)], [3, 0x86, pack('V', 60000)], [4, 0x86, pack('V', 60000)], [12, 0, "\x01"]]);
        $data .= FitFixture::record(self::START + 64);
        $data .= FitFixture::message(19, [[254, 0x84, pack('v', 0)], [253, 0x86, pack('V', self::START + 64)],
            [2, 0x86, pack('V', self::START)], [7, 0x86, pack('V', 60000)], [8, 0x86, pack('V', 60000)]]);
        $source = FitFixture::file($data);
        $repairer = new RecordedSummaryDurationReconciler();
        $result = $repairer->repair($source);
        self::assertSame(64000, $result->report['evidence']['elapsed_ms']);
        self::assertSame(60000, $result->report['evidence']['timer_ms']);
        self::assertSame(4000, $result->report['evidence']['paused_ms']);
        self::assertTrue($result->report['evidence']['stopped_record_tail_buffered_pool_corroboration']);
        $restore = substr($result->bytes, 0, -2);
        foreach ($result->report['changes'] as $change) {
            $restore = substr_replace($restore, substr($source, $change['byte_offset'], 4), $change['byte_offset'], 4);
        }
        self::assertSame(substr($source, 0, -2), $restore);
        foreach ([[2, 26, 0x84, 2], [6, 253, 0x86, self::START + 63], [8, 253, 0x86, self::START + 63], [1, 253, 0x86, self::START + 60]] as [$message, $field, $type, $value]) {
            try {
                $repairer->repair(EditableFitFile::read($source)->apply([['message' => $message, 'field' => $field, 'type' => $type, 'value' => $value]]));
                self::fail('Buffered pool ownership and both final native SAVEs must corroborate the retained tail.');
            } catch (RepairFailed) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAdditionalNativeEvidenceRetainsPausesAndDeclinesConflictingCorroboration(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';
            $message = static fn (int $number, array $fields): string => FitFixture::message($number, $fields, architecture: $architecture);
            $event = static fn (int $offset, int $kind, int $type): string => $message(21, [[253, 0x86, pack($word, self::START + $offset)], [0, 0, chr($kind)], [1, 0, chr($type)], [4, 2, chr(8 === $kind && 9 === $type ? 1 : 0)]]);
            foreach (['single_start', 'missing_timers', 'gapped_indices', 'without_laps', 'frozen_stop', 'stopped_tail'] as $variant) {
                $frozen = 'frozen_stop' === $variant;
                $tail = 'stopped_tail' === $variant;
                $noLaps = 'without_laps' === $variant;
                $missing = 'missing_timers' === $variant;
                $elapsed = $frozen ? 61000 : ($tail ? 60500 : ($noLaps ? 59000 : 60000));
                $timer = $frozen ? 52000 : ($tail ? 58000 : 60000);
                $save = $frozen ? 0 : ($tail ? 58 : 60);
                $summaries = '';
                if (!$noLaps) {
                    foreach ([0, 1] as $index) {
                        $fields = [[254, 0x84, pack($short, 'gapped_indices' === $variant && 1 === $index ? 2 : $index)],
                            [2, 0x86, pack($word, self::START + $index * 30)], [253, 0x86, pack($word, self::START + ($frozen ? 0 : ($index + 1) * 30))],
                            [7, 0x86, pack($word, 1 === $index ? $elapsed - 30000 : 30000)]];
                        if (!$missing) {
                            $fields[] = [8, 0x86, pack($word, $frozen ? (0 === $index ? 21000 : 31000) : ($tail ? 0 : ('single_start' === $variant && 0 === $index ? 20000 : 30000)))];
                        }
                        $summaries .= $message(19, $fields);
                    }
                }
                $summaries .= $message(18, [[254, 0x84, pack($short, 0)], [2, 0x86, pack($word, self::START)], [253, 0x86, pack($word, self::START + $save)],
                    [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $timer)], [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, $noLaps ? 1 : 2)]]);
                $data = $message(0, [[0, 0, "\x04"]]).($frozen ? $summaries : '');
                $data .= $missing ? '' : $event(0, 0, 0);
                $data .= $message(20, [[253, 0x86, pack($word, self::START)], [3, 2, "\x64"]]);
                if ($frozen) {
                    $data .= $event(15, 0, 4).$event(25, 0, 0).$event(25, 0, 0);
                } elseif ($tail) {
                    $data .= $event(58, 0, 4);
                }
                $data .= $message(20, [[253, 0x86, pack($word, self::START + 60)], [3, 2, "\x65"]]);
                if ($frozen || 'single_start' === $variant) {
                    $data .= $event(60, 8, $frozen ? 9 : 4);
                } elseif (!$tail && !$missing) {
                    $data .= $event(60, 0, 4);
                }
                $data .= $frozen ? '' : $summaries;
                $data .= $message(34, [[253, 0x86, pack($word, self::START + $save)], [1, 0x84, pack($short, 1)], ...($missing ? [] : [[0, 0x86, pack($word, $frozen ? 60000 : $timer)]])]);
                $source = FitFixture::file($data);
                $repairer = new RecordedSummaryDurationReconciler();
                $result = $repairer->repair($source);
                self::assertSame(60000, $result->report['evidence']['elapsed_ms'], $variant);
                self::assertSame($frozen ? 50000 : ($tail ? 58000 : 60000), $result->report['evidence']['timer_ms'], $variant);
                self::assertSame('not_proven', $result->report['inference']['historical_accuracy']);
                $before = EditableFitFile::read($source);
                $after = EditableFitFile::read($result->bytes);
                self::assertCount(count($before->messages), $after->messages);
                foreach ($before->messages as $ordinal => $entry) {
                    $old = PhysicalMessage::fromRaw($entry['raw'], 0);
                    $new = PhysicalMessage::fromRaw($after->messages[$ordinal]['raw'], 0);
                    self::assertSame($old->integer(253), $new->integer(253));
                    if (in_array($old->number, [18, 19], true)) {
                        self::assertSame($old->integer(2), $new->integer(2));
                    } elseif (in_array($old->number, [20, 21], true)) {
                        self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $after->messages[$ordinal]['offset'], $after->messages[$ordinal]['length']));
                    }
                }
                $session = $activity = $lastLap = null;
                foreach ($before->messages as $ordinal => $entry) {
                    $number = PhysicalMessage::fromRaw($entry['raw'], 0)->number;
                    if (18 === $number) {
                        $session = $ordinal;
                    } elseif (34 === $number) {
                        $activity = $ordinal;
                    } elseif (19 === $number) {
                        $lastLap = $ordinal;
                    }
                }
                $conflict = match ($variant) {
                    'single_start' => ['message' => $activity, 'field' => 0, 'type' => 0x86, 'value' => 59000],
                    'missing_timers' => ['message' => $session, 'field' => 8, 'type' => 0x86, 'value' => 59000],
                    'gapped_indices' => ['message' => $lastLap, 'field' => 254, 'type' => 0x84, 'value' => 0],
                    'without_laps' => ['message' => $session, 'field' => 26, 'type' => 0x84, 'value' => 2],
                    'frozen_stop' => ['message' => $lastLap, 'field' => 8, 'type' => 0x86, 'value' => 35000],
                    'stopped_tail' => ['message' => $session, 'field' => 7, 'type' => 0x86, 'value' => 62000],
                };
                $conflicts = [$conflict];
                if ($frozen) {
                    $conflicts[] = ['message' => $lastLap - 1, 'field' => 8, 'type' => 0x86, 'value' => 17000];
                }
                try {
                    $repairer->repair($before->apply($conflicts));
                    self::fail('Conflicting native corroboration must decline '.$variant);
                } catch (RepairFailed) {
                }
            }
        }
    }

    /** @param array<string, bool> $variant */
    private static function file(array $variant = [], int $architecture = 0): string
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $enum = $variant['uint8Enums'] ?? false ? 0x02 : 0x00;
        $unpaused = $variant['nativeUnpaused'] ?? false;
        $terminal = $variant['terminalSessionStop'] ?? false;
        $save = ($variant['earlySummaryWrite'] ?? false) || ($variant['frozenSaves'] ?? false) || ($variant['frozenSessionEnd'] ?? false) ? 0 : ($unpaused || $terminal ? 60 : 80);
        $sessionElapsed = $variant['nativeElapsedMismatch'] ?? false ? 61000 : ($unpaused || $terminal ? 60500 : ($variant['compatible'] ?? false ? 60000 : 57000));
        $sessionTimer = $unpaused ? ($variant['nativePause'] ?? false ? 59500 : 60500) : ($terminal ? ($variant['nativeTimerMismatch'] ?? false ? 52000 : 50500) : 50000);
        $message = static fn (int $n, array $fields, array $developer = []): string => FitFixture::message($n, $fields, $developer, $architecture);
        $event = static fn (int $time, int $type): string => $message(21, [[253, 0x86, pack($word, self::START + $time)], [0, $enum, "\x00"], [1, $enum, chr($type)], [4, 0x02, chr($variant['badGroup'] ?? false ? 1 : 0)]]);
        $record = static fn (int $time): string => $message(20, array_merge([[253, 0x86, pack($word, self::START + $time)], [3, 0x02, "\x64"]],
            ($variant['duplicateRecordClock'] ?? false) || ($variant['conflictingRecordClock'] ?? false)
                ? [[253, 0x86, pack($word, self::START + $time + ($variant['conflictingRecordClock'] ?? false ? 1 : 0))]] : []));
        $begin = $variant['startTooLate'] ?? false ? 2 : ($variant['startOneSecondLate'] ?? false ? 1 : 0);
        $data = $message(0, [[0, $enum, "\x04"]]).($variant['noTimerStart'] ?? false ? '' : $event($begin, 0))
            .($variant['timestampOnlyStart'] ?? false ? $message(21, [[253, 0x86, pack($word, self::START)], ...($variant['unknownTimerType'] ?? false ? [[1, $enum, "\x00"]] : [])]) : '')
            .($variant['zeroSpan'] ?? false ? $event($begin, 4).$event($begin, 0) : '')
            .($variant['repeatedTimerStart'] ?? false ? $event(2, 0) : '')
            .$record($variant['earlyRecord'] ?? false ? -3 : 0)
            .($unpaused ? '' : $event(15, 4).$event(25, 0)).$record($variant['backwardRecord'] ?? false ? -1 : 40);
        // A fresh Record definition omits timestamp; the compressed header retains its reconstructed clock.
        $recordEnd = $variant['sparseFinalRecord'] ?? false ? 50 : 60;
        if ($terminal && ($variant['earlySessionEnd'] ?? false)) {
            $data .= $message(21, [[253, 0x86, pack($word, self::START + 60)], [0, $enum, "\x08"], [1, $enum, "\x04"]]);
        }
        $data .= "\x40\x00".chr($architecture).pack($short, 20)."\x01\x03\x01\x02".chr(0x80 | ((self::START + $recordEnd) & 31))."\x65";
        if (!$unpaused && !$terminal && !($variant['missingStop'] ?? false)) {
            $data .= $event($variant['recordAfterStop'] ?? false ? 58 : ($variant['compressedAfterMissingSave'] ?? false ? 120 : 60), $variant['unsupportedStop'] ?? false ? 3 : ($variant['unsupportedDisable'] ?? false ? 9 : 4));
        }
        if ($variant['disabledStop'] ?? false) {
            $data .= $event(60, 9);
        }
        if ($variant['terminalZeroSpan'] ?? false) {
            $data .= $event(60, 4).$event(60, 0);
        }
        if ($variant['frozenSessionEnd'] ?? false) {
            $data .= $message(21, [[253, 0x86, pack($word, self::START)], [0, $enum, "\x08"], [1, $enum, "\x09"]]);
        } elseif (($unpaused || $terminal) && !($variant['missingSessionEnd'] ?? false) && !($variant['earlySessionEnd'] ?? false)) {
            $data .= $message(21, [[253, 0x86, pack($word, self::START + ($variant['sessionEndMismatch'] ?? false ? 59 : 60))], [0, $enum, "\x08"], [1, $enum, "\x04"], [4, 0x02, chr($variant['badSessionEndGroup'] ?? false ? 1 : 0)]]);
        }
        if ($variant['redundantStop'] ?? false) {
            $data .= $event(70, 4);
        }
        if ($variant['redundantSingleStop'] ?? false) {
            $data .= $event(70, 1);
        }
        if ($variant['outOfOrderStop'] ?? false) {
            $data .= $event(59, 4);
        }
        if ($variant['relativeRecord'] ?? false) {
            $data .= $message(20, [[253, 0x86, pack($word, 60)]]);
        }
        $data .= $message(999, [[99, 0x0D, "\x80\xFE"]], [[5, 7, "\x90\x00\xFF"]]);
        if ($variant['lengthOverlap'] ?? false) {
            $data .= $message(101, [[2, 0x86, pack($word, self::START)], [3, 0x86, pack($word, 20000)]])
                .$message(101, [[2, 0x86, pack($word, self::START + 18)], [3, 0x86, pack($word, 20000)]]);
        }
        if ($variant['lengthTail'] ?? false) {
            $data .= $message(101, [[2, 0x86, pack($word, self::START + 50)], [3, 0x86, pack($word, 10500)], [4, 0x86, pack($word, 10500)], [12, 0, "\x01"]]);
        }
        foreach ($variant['collapsedLap'] ?? false ? [0] : [0, 1] as $index) {
            $fields = [[254, 0x84, pack($short, $variant['badLapIndex'] ?? false ? 2 : $index)],
                [2, 0x86, pack($word, self::START + ($index * ($variant['lapAfterStop'] ?? false ? 70 : 30)))],
                [253, 0x86, pack($word, self::START + ($variant['frozenSaves'] ?? false ? $index * 30 : ($variant['earlySummaryWrite'] ?? false ? 0 : 80)))],
                [8, 0x86, pack($word, $unpaused ? (0 === $index ? ($variant['lapPause'] ?? false ? 26000 : 27000) : 30000) : (0 === $index && !($variant['compatible'] ?? false) ? 18000 : (0 === $index ? 20000 : 30000)))]];
            if (!($variant['missingElapsed'] ?? false)) {
                $fields[] = [7, 0x86, pack($word, 0 === $index && !($variant['compatible'] ?? false) ? 27000 : 30000)];
            }
            if ($variant['collapsedLap'] ?? false) {
                $fields[1] = [2, 0x86, pack($word, self::START + 60)];
                $fields[2] = [253, 0x86, pack($word, self::START + 60)];
                $fields[3] = [8, 0x86, pack($word, $variant['zeroCollapsedCounters'] ?? false ? 0 : $sessionTimer)];
                $fields[4] = [7, 0x86, pack($word, $variant['zeroCollapsedCounters'] ?? false ? 0 : $sessionElapsed + ($variant['badCollapsedCounters'] ?? false ? 1 : 0))];
            }
            if ($variant['missingClocks'] ?? false) {
                $fields = array_values(array_filter($fields, static fn (array $field): bool => !in_array($field[0], [2, 7, 8], true)));
                $fields[1] = [253, 0x86, pack($word, self::START + (0 === $index ? 30 : 60))];
            }
            if ($variant['emptyFirstMissingClocks'] ?? false) {
                $fields[1][2] = pack($word, 0 === $index ? 0xFFFFFFFF : self::START);
                if (0 === $index) {
                    $fields[2][2] = pack($word, self::START);
                    $fields[3][2] = $fields[4][2] = pack($word, 0);
                }
            }
            if ($variant['missingSave'] ?? false) {
                $fields = array_values(array_filter($fields, static fn (array $field): bool => 253 !== $field[0]));
            }
            if ($variant['invalidSave'] ?? false) {
                foreach ($fields as &$field) {
                    if (253 === $field[0]) {
                        $field[2] = pack($word, 0xFFFFFFFF);
                    }
                }
                unset($field);
            }
            if ($variant['duplicateStart'] ?? false) {
                $fields[] = [2, 0x86, pack($word, self::START + $index * 30)];
            }
            if ($variant['missingReferences'] ?? false) {
                array_shift($fields);
            }
            $data .= $message(19, $fields);
            if (0 === $index && ($variant['compressedAfterMissingSave'] ?? false)) {
                $data .= "\x40\x00".chr($architecture).pack($short, 20)."\x01\x03\x01\x02".chr(0x80 | ((self::START + 121) & 31))."\x65";
            }
        }
        $sessionFields = [[2, 0x86, pack($word, ($variant['invalidSessionStart'] ?? false) || ($variant['emptyFirstMissingClocks'] ?? false) ? 0xFFFFFFFF : self::START + ($variant['sessionOneSecondLate'] ?? false ? 1 : 0))], [253, 0x86, pack($word, self::START + $save)],
            [7, 0x86, pack($word, $sessionElapsed)], [8, 0x86, pack($word, $sessionTimer)]];
        if (!($variant['missingReferences'] ?? false)) {
            $sessionFields = [...$sessionFields, [254, 0x84, pack($short, $variant['selectedSessionIndex'] ?? false ? 0x8000 : ($variant['reservedSessionIndex'] ?? false ? 0x7000 : 0))], [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, $variant['badLapCount'] ?? false ? 3 : ($variant['collapsedLap'] ?? false ? 1 : 2))]];
        }
        if ($variant['missingSave'] ?? false) {
            $sessionFields = array_values(array_filter($sessionFields, static fn (array $field): bool => 253 !== $field[0]));
        }
        if ($variant['invalidSave'] ?? false) {
            $sessionFields[1][2] = pack($word, 0xFFFFFFFF);
        }
        $session = $message(18, $sessionFields);
        $data .= $session.($variant['multipleSessions'] ?? false ? $session : '');
        $fields = [[253, 0x86, pack($word, self::START + $save)]];
        if (!($variant['missingActivitySessions'] ?? false)) {
            $fields[] = [1, 0x84, pack($short, $variant['invalidActivitySessions'] ?? false ? 0xFFFF : 1)];
        }
        if (!($variant['missingActivityTimer'] ?? false)) {
            $fields[] = [0, 0x86, pack($word, $variant['activityTimerMismatch'] ?? false ? $sessionTimer - 1 : $sessionTimer)];
        }

        if ($variant['missingSave'] ?? false) {
            $fields = array_values(array_filter($fields, static fn (array $field): bool => 253 !== $field[0]));
        }

        if ($variant['invalidSave'] ?? false) {
            $fields[0][2] = pack($word, 0xFFFFFFFF);
        }

        return FitFixture::file($data.$message(34, $fields));
    }
}
