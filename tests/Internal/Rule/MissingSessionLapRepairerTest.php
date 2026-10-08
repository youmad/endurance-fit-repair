<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\MissingSessionLapRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class MissingSessionLapRepairerTest extends TestCase
{
    private const int START = 0x10000020;

    public function testAppendsMissingActivityAndPreservesFrontSessionCountersAndCompressedRecords(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $session = FitFixture::message(18, [
                [2, 0x86, pack($word, self::START)], [253, 0x86, pack($word, self::START)],
                [7, 0x86, pack($word, 10_000)], [8, 0x86, pack($word, 10_000)], [5, 0x00, "\x02"],
                [100, 0x0D, "\xFE\xAB"],
            ], [[7, 0, "\x21\x22"]], $architecture);
            $records = FitFixture::message(20, [[253, 0x86, pack($word, self::START)], [3, 0x02, "\xA1"]], [], $architecture)
                ."\x40\x00".chr($architecture).pack(0 === $architecture ? 'v' : 'n', 20)."\x01\x03\x01\x02"
                .chr(0x80 | ((self::START + 10) & 0x1F))."\xA2";
            $source = FitFixture::file(self::fileId().$session.$records);
            $before = EditableFitFile::read($source);
            $repair = (new PreservingActivityRepairer())->repair($source, MissingSessionLapRepairer::ACTIVITY_RULE);
            $after = EditableFitFile::read($repair->bytes);
            self::assertSame([0, 18, 20, 20, 34], array_map(static fn (array $entry): int => $entry['raw']->globalMessageNumber(), $after->messages));
            self::assertStringContainsString($records, $repair->bytes);
            $kept = PhysicalMessage::fromRaw($after->messages[1]['raw'], 14);
            self::assertSame([self::START, self::START + 10, 10_000, 10_000], array_map($kept->integer(...), [2, 253, 7, 8]));
            self::assertSame($before->messages[1]['raw']->developerFields()[0]->value->bytes(), $after->messages[1]['raw']->developerFields()[0]->value->bytes());
            $activity = PhysicalMessage::fromRaw($after->messages[4]['raw'], 14);
            self::assertSame([self::START + 10, 10_000, 1, null], array_map($activity->integer(...), [253, 0, 1, 5]));
            self::assertTrue($repair->report['inserted_messages'][0]['synthetic']);
            self::assertCount(1, $repair->report['changes']);
            try {
                (new MissingSessionLapRepairer())->repairActivity($after);
                self::fail('An existing Activity must not be duplicated.');
            } catch (RepairFailed $failure) {
                self::assertStringContainsString('duplicated', $failure->getMessage());
            }
        }
    }

    public function testMissingActivityDeclinesUncorroboratedOrLostSegmentation(): void
    {
        $session = static fn (array $extra = []): string => FitFixture::message(18, [
            [2, 0x86, pack('V', self::START)], [253, 0x86, pack('V', self::START)],
            [7, 0x86, pack('V', 10_000)], [8, 0x86, pack('V', 10_000)], [5, 0x00, "\x02"], ...$extra,
        ]);
        $records = FitFixture::record(self::START).FitFixture::record(self::START + 10);
        foreach ([
            $session().FitFixture::record(self::START).FitFixture::record(self::START + 20),
            $session([[26, 0x84, pack('v', 2)]]).$records,
            $session([[7, 0x86, pack('V', 10_000)]]).$records,
            $records.$session(),
            $session().$records.FitFixture::message(21, [[0, 0x00, "\x00"]]),
            $session().$records.FitFixture::summary(19, self::START, 10_000),
        ] as $data) {
            try {
                (new MissingSessionLapRepairer())->repairActivity(EditableFitFile::read(FitFixture::file(self::fileId().$data)));
                self::fail('Contradictory front Session evidence must be declined.');
            } catch (RepairFailed) {
            }
        }
        $selector = new PreservingActivityRepairer();
        self::assertSame([MissingSessionLapRepairer::ACTIVITY_RULE, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedActivitySummaryRepairer::RULE], $selector->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => '"Activity Message Exists"']]], null));
        self::assertSame([], $selector->rulesForFailure(['status' => 'PASS'], null));
    }

    public function testAddsAnExplicitlySyntheticLapAndKeepsOriginalMeasurementsAndPauses(): void
    {
        $records = FitFixture::record(self::START)."\x41\x00\x00\x14\x00\x01\x03\x01\x02\xA1\x75";
        $events = FitFixture::message(21, [[253, 0x86, pack('V', self::START + 1)], [0, 0, "\x00"], [1, 0, "\x04"]]);
        $unknown = FitFixture::message(50_000, [[100, 0x0D, "\xAA\xBB"]], [[7, 0, "\x21\x22"]]);
        $session = self::session([[100, 0x0D, "\xFE\xAB"]], [[1, 0, "\xAB\xCD"]]);
        $source = FitFixture::file(self::fileId().$records.$events.$unknown.$session);
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::MISSING_LAP);
        $output = EditableFitFile::read($result->bytes);
        $lap = PhysicalMessage::fromRaw($output->messages[5]['raw'], 14);
        $keptSession = PhysicalMessage::fromRaw($output->messages[6]['raw'], 14);

        self::assertSame([0, 20, 20, 21, 50_000, 19, 18], array_map(static fn (array $entry): int => $entry['raw']->globalMessageNumber(), $output->messages));
        self::assertStringContainsString($records.$events.$unknown, $result->bytes);
        self::assertSame([self::START, self::START + 10, 10_000, 4_123, 123_456, 2, 7, 0, 9, 1], array_map($lap->integer(...), [2, 253, 7, 8, 9, 25, 39, 254, 0, 1]));
        self::assertSame([0, 0, 1, 4_123], array_map($keptSession->integer(...), [254, 25, 26, 8]));
        self::assertSame("\xAB\xCD", $output->messages[6]['raw']->developerFields()[0]->value->bytes());
        self::assertStringContainsString("\xFE\xAB", $result->bytes);
        self::assertSame('verified', $result->report['preservation']);
        $insertion = $result->report['inserted_messages'][0];
        self::assertTrue($insertion['synthetic']);
        self::assertSame('one_existing_session_without_laps', $insertion['basis']);
        self::assertSame(5, $insertion['beforeOriginalMessage']);
        $original = EditableFitFile::read($source)->messages[5]['raw'];
        self::assertSame($original->sequence(), $insertion['sourceSequence']);
        self::assertSame(14 + $original->byteOffset(), $insertion['sourceByteOffset']);
        self::assertSame($result->bytes, (new PreservingActivityRepairer())->repair($result->bytes, PreservingActivityRepairer::MISSING_LAP)->bytes);
        self::assertSame($result->bytes, (new ActivityRepairer())->repair($source)->bytes);
    }

    public function testNormalizesZeroCountAndInvalidReferenceSentinels(): void
    {
        $source = FitFixture::file(self::fileId().self::session([[254, 0x84, "\xFF\xFF"], [25, 0x84, "\xFF\xFF"], [26, 0x84, "\x00\x00"]]));
        $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::MISSING_LAP);
        $file = EditableFitFile::read($result->bytes);
        $session = PhysicalMessage::fromRaw($file->messages[2]['raw'], 14);
        self::assertSame([0, 0, 1], array_map($session->integer(...), [254, 25, 26]));
        self::assertCount(3, $result->report['changes']);
    }

    public function testCopiesSubsecondTimerExcessWithoutChangingSourceDurations(): void
    {
        foreach ([0, 1] as $architecture) {
            $word = 0 === $architecture ? 'V' : 'N';
            $short = 0 === $architecture ? 'v' : 'n';

            foreach ([1, 999] as $excess) {
                $session = FitFixture::summary(18, self::START, 10_000, [
                    [8, 0x86, pack($word, 10_000 + $excess)], [5, 0x00, "\x02"],
                    [254, 0x84, pack($short, 0)], [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, 1)],
                    [100, 0x0D, "\xFE\xAB"],
                ], [[1, 0, "\xAB\xCD"]], $architecture);
                $records = FitFixture::record(self::START).FitFixture::record(self::START + 10);
                $source = FitFixture::file(self::fileId().$records.$session);
                $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::MISSING_LAP);
                $before = EditableFitFile::read($source);
                $after = EditableFitFile::read($result->bytes);
                $lap = PhysicalMessage::fromRaw($after->messages[3]['raw'], 14);
                self::assertSame([10_000, 10_000 + $excess], array_map($lap->integer(...), [7, 8]));
                self::assertSame([], $result->report['changes']);

                foreach ($before->messages as $index => $entry) {
                    $kept = $after->messages[$index < 3 ? $index : $index + 1];
                    self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $kept['offset'], $kept['length']));
                }

                self::assertSame($result->bytes, (new ActivityRepairer())->repair($source)->bytes);
            }
        }
    }

    #[DataProvider('unsupportedSources')]
    public function testRefusesAmbiguousOrContradictoryEvidence(string $data): void
    {
        $this->expectException(RepairFailed::class);
        (new PreservingActivityRepairer())->repair(FitFixture::file($data), PreservingActivityRepairer::MISSING_LAP);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedSources(): iterable
    {
        yield 'multiple Sessions' => [self::fileId().self::session().self::session()];

        yield 'missing timer' => [self::fileId().FitFixture::summary(18, self::START, 10_000, [[5, 0, "\x02"]])];

        yield 'end contradicts elapsed' => [self::fileId().FitFixture::message(18, [[2, 0x86, pack('V', self::START)], [253, 0x86, pack('V', self::START + 12)], [7, 0x86, pack('V', 10_000)], [8, 0x86, pack('V', 4_123)], [5, 0, "\x02"]])];
        yield 'duplicate field' => [self::fileId().self::session([[8, 0x86, pack('V', 4_123)]])];
        yield 'wrong reference type' => [self::fileId().self::session([[25, 0x86, pack('V', 0)]])];
    }

    private static function fileId(): string
    {
        return FitFixture::message(0, [[0, 0, "\x04"]]);
    }

    /**
     * @param list<array{int, int, string}> $fields
     * @param list<array{int, int, string}> $developerFields
     */
    private static function session(array $fields = [], array $developerFields = [], int $architecture = 0): string
    {
        $word = 0 === $architecture ? 'V' : 'N';

        return FitFixture::summary(18, self::START, 10_000, [[8, 0x86, pack($word, 4_123)], [5, 0, "\x02"], [6, 0, "\x07"], [9, 0x86, pack($word, 123_456)], ...$fields], $developerFields, $architecture);
    }
}
