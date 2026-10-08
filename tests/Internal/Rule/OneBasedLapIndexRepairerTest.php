<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\OneBasedLapIndexRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\OneBasedLapIndexFixture;

final class OneBasedLapIndexRepairerTest extends TestCase
{
    public function testTranslatesCompleteSetAndBestReferenceWithoutChangingOtherBytes(): void
    {
        $architecture = 1;
        $source = OneBasedLapIndexFixture::file(architecture: $architecture);

        $body = "\x0C".substr($source, 1, 11).substr($source, 14, -2);
        $source = $body.pack('v', FitCrc16::calculate($body));

        $result = (new PreservingActivityRepairer())->repair($source, OneBasedLapIndexRepairer::RULE);
        self::assertSame([254, 254, 25, 70], array_column($result->report['changes'], 'field'));
        self::assertSame([0, 1, 0, 1], array_column($result->report['changes'], 'value'));
        self::assertSame(strlen($source), strlen($result->bytes));
        $input = EditableFitFile::read($source);
        $output = EditableFitFile::read($result->bytes);
        $masked = $source;

        foreach ($result->report['changes'] as $change) {
            $entry = $input->messages[$change['message']];
            $position = $entry['offset'] + 1;

            foreach ($entry['raw']->standardFields() as $field) {
                if ($field->definition->fieldNumber === $change['field']) {
                    $masked = substr_replace($masked, substr($result->bytes, $position, 2), $position, 2);
                }

                $position += $field->definition->size;
            }

            self::assertSame($change['value'], PhysicalMessage::fromRaw($output->messages[$change['message']]['raw'], 0)->integer($change['field']));
        }

        self::assertSame(substr($masked, 0, -2), substr($result->bytes, 0, -2));
        $session = array_values(array_filter($output->messages, static fn (array $entry): bool => 18 === $entry['raw']->globalMessageNumber()))[0];
        self::assertNull(PhysicalMessage::fromRaw($session['raw'], 0)->integer(254));
        self::assertSame(2, PhysicalMessage::fromRaw($session['raw'], 0)->integer(26));
        self::assertSame(2, $result->report['evidence']['original_best_lap_index']);
    }

    public function testKeepsAbsentOrInvalidBestReferenceAndSupportsCompressedLap(): void
    {
        foreach ([['absentBest' => 1], ['best' => 0xFFFF], ['absentSessionIndex' => 1, 'absentBest' => 1], ['sessionIndex' => 0, 'absentBest' => 1], ['compressedLap' => 1, 'absentBest' => 1]] as $overrides) {
            $result = (new OneBasedLapIndexRepairer())->repair(OneBasedLapIndexFixture::file($overrides));
            self::assertSame([254, 254, 25], array_column($result->report['changes'], 'field'));
            self::assertNull($result->report['evidence']['original_best_lap_index']);
            EditableFitFile::read($result->bytes);
        }
    }

    public function testSupportsTheLastUnflaggedOneBasedIndex(): void
    {
        $result = (new OneBasedLapIndexRepairer())->repair(OneBasedLapIndexFixture::file(['laps' => 4095], decorate: false));
        self::assertCount(4097, $result->report['changes']);
        self::assertSame(4094, $result->report['changes'][4094]['value']);
        self::assertSame(['message' => 8192, 'field' => 70, 'type' => 0x84, 'value' => 4094], $result->report['changes'][4096]);
    }

    /** @param array<string, int> $overrides */
    #[DataProvider('contradictions')]
    public function testDeclinesAmbiguousMixedOrFlaggedReferences(array $overrides): void
    {
        $this->expectException(RepairFailed::class);
        (new OneBasedLapIndexRepairer())->repair(OneBasedLapIndexFixture::file($overrides));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function contradictions(): iterable
    {
        yield 'partial assignment' => [['numLaps' => 1]];
        yield 'duplicate Lap values' => [['lapIndex' => 1]];
        yield 'flagged Lap index' => [['lapIndex' => 0x1001]];

        yield 'one based index would enter flag bits' => [['laps' => 4096]];
    }

    public function testSelectorUsesTranslationOnlyForIndexOrReferenceFailures(): void
    {
        $repairer = new PreservingActivityRepairer();
        $failure = static fn (string $message): array => ['status' => 'FAILED', 'errors' => [['message' => $message]]];
        self::assertSame([PreservingActivityRepairer::SESSION_INDEX, OneBasedLapIndexRepairer::RULE, PreservingActivityRepairer::SESSION_LAP_REFERENCES, PreservingActivityRepairer::UNPAUSED_FINAL_LAP, PreservingActivityRepairer::IMPOSSIBLE_SINGLE_LAP_START, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapPartitionStartRepairer::NATIVE_RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSingleLapFieldsRepairer::RULE], $repairer->rulesForFailure($failure('"Session Message Valid Message Index": expected message_index=0; got missing.'), null));
        self::assertSame([PreservingActivityRepairer::SESSION_LAP_REFERENCES, OneBasedLapIndexRepairer::RULE, PreservingActivityRepairer::IMPOSSIBLE_SINGLE_LAP_START, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedLapPartitionStartRepairer::NATIVE_RULE], $repairer->rulesForFailure($failure('"Session Message First Lap Index and Num Laps are Valid"'), null));
        self::assertSame([OneBasedLapIndexRepairer::RULE, PreservingActivityRepairer::REPEATED_LAP_INDEXES, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummaryDurationReconciler::RULE], $repairer->rulesForFailure($failure('"Lap Message Valid Message Index": expected message_index=0; got 1.'), null));
        self::assertNotContains(OneBasedLapIndexRepairer::RULE, $repairer->rulesForFailure($failure('"Record Message Timestamps Fall Within Session Message Times"'), null));
        self::assertSame([], $repairer->rulesForFailure(['status' => 'PASS'], null));
    }
}
