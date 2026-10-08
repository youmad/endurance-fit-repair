<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\ReservedSessionIndexRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\ReservedSessionIndexFixture;

final class ReservedSessionIndexRepairerTest extends TestCase
{
    public function testClearsReservedBitsAndPreservesEveryOtherSourceByte(): void
    {
        $architecture = 1;
        $summaryFirst = true;
        $source = ReservedSessionIndexFixture::file(architecture: $architecture, summaryFirst: $summaryFirst);

        $body = "\x0C".substr($source, 1, 11).substr($source, 14, -2);
        $source = $body.pack('v', FitCrc16::calculate($body));

        $result = (new PreservingActivityRepairer())->repair($source, ReservedSessionIndexRepairer::RULE);
        self::assertCount(1, $result->report['changes']);
        $change = $result->report['changes'][0];
        self::assertSame([254], array_column($result->report['changes'], 'field'));
        self::assertSame(0, $change['value']);
        self::assertSame(strlen($source), strlen($result->bytes));
        $input = EditableFitFile::read($source);
        $output = EditableFitFile::read($result->bytes);
        $entry = $input->messages[$change['message']];
        $position = $entry['offset'] + 1;
        $masked = $source;

        foreach ($entry['raw']->standardFields() as $field) {
            if (254 === $field->definition->fieldNumber) {
                $masked = substr_replace($masked, substr($result->bytes, $position, 2), $position, 2);
            }

            $position += $field->definition->size;
        }

        self::assertSame(substr($masked, 0, -2), substr($result->bytes, 0, -2));
        $session = PhysicalMessage::fromRaw($output->messages[$change['message']]['raw'], 0);
        self::assertSame(0, $session->integer(254));
        self::assertNull($session->integer(26));
        self::assertSame(0x7000, $result->report['evidence']['original_message_index']);
        self::assertSame($result->bytes, (new ReservedSessionIndexRepairer())->repair($result->bytes)->bytes);
    }

    public function testDefaultRepairUsesTheExistingReferenceAndIndexRulesAfterClearingReservedBits(): void
    {
        $source = ReservedSessionIndexFixture::file();
        $result = (new ActivityRepairer())->repair($source);
        self::assertSame([ReservedSessionIndexRepairer::RULE, PreservingActivityRepairer::SESSION_LAP_REFERENCES], array_column($result->report['steps'], 'rule'));
        self::assertSame($result->bytes, (new ActivityRepairer())->repair($result->bytes)->bytes);
    }

    /** @param array<string, int> $overrides */
    #[DataProvider('contradictions')]
    public function testDeclinesAmbiguousOrContradictoryEvidence(array $overrides): void
    {
        $this->expectException(RepairFailed::class);
        (new ReservedSessionIndexRepairer())->repair(ReservedSessionIndexFixture::file($overrides));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function contradictions(): iterable
    {
        yield 'real index one' => [['index' => 0x7001]];
        yield 'selected bit' => [['index' => 0x8000]];
        yield 'invalid sentinel' => [['index' => 0xFFFF]];

        yield 'different timer duration' => [['lapTimer' => 8_999]];
    }

    public function testOnlyRoutesAZeroSessionIndexWithReservedBits(): void
    {
        $repairer = new PreservingActivityRepairer();

        foreach ([0x1000, 0x7000, 0x7001, 0x8000, 0x10000] as $index) {
            $failure = ['status' => 'FAILED', 'errors' => [['message' => sprintf('FIT failed Garmin required check "Session Message Valid Message Index": expected message_index=0; got %d.', $index)]]];
            self::assertSame(in_array($index, [0x1000, 0x7000], true) ? [ReservedSessionIndexRepairer::RULE] : [], $repairer->rulesForFailure($failure, null));
        }
    }
}
