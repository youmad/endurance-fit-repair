<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\Corpus\CorpusRepairRunner;
use Youmad\Endurance\FitRepair\Corpus\CorpusValidator;
use Youmad\Endurance\FitRepair\Corpus\CorpusWorkspace;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\SummaryClockReconciler;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class SummaryClockReconcilerTest extends TestCase
{
    private const int START = 0x10000020;
    private const string FAILURE = 'FIT message failed Garmin required check "Record Message Timestamps Fall Within Session Message Times": first Record timestamp and last Record timestamp are outside the Session.';

    public function testJointMinimumKeepsTheOneSecondBoundAndDeclinesTies(): void
    {
        $source = self::file(['recordEnd' => 100, 'starts' => [0, 43]]);
        $result = (new PreservingActivityRepairer())->repair($source, SummaryClockReconciler::JOINT_RULE);
        self::assertSame(SummaryClockReconciler::JOINT_RULE, $result->report['rule']);
        self::assertCount(3, $result->report['changes']);
        $restored = substr($result->bytes, 0, -2);
        foreach ($result->report['changes'] as $change) {
            self::assertSame(1, abs($change['value'] - $change['original_value']));
            $restored = substr_replace($restored, substr($source, $change['byte_offset'], 4), $change['byte_offset'], 4);
        }
        self::assertSame(substr($source, 0, -2), $restored);
        foreach ([
            self::file(['recordEnd' => 101, 'starts' => [0, 41]]),
            self::file(['starts' => [0, 55]]),
            self::file(['starts' => [0, 11, 24, 34], 'elapsed' => [10900, 10900, 10900, 10900], 'timer' => [10900, 10900, 10900, 10900], 'recordEnd' => 45]),
        ] as $bytes) {
            try {
                (new SummaryClockReconciler())->repair($bytes, true);
                self::fail('Compatible, excessive or ambiguous clocks must not be changed.');
            } catch (RepairFailed) {
            }
        }
        $validation = ['status' => 'FAILED', 'errors' => [['message' => self::FAILURE]]];
        $selector = new PreservingActivityRepairer();
        self::assertNotContains(SummaryClockReconciler::JOINT_RULE, $selector->rulesForFailure($validation, null));
        self::assertContains(SummaryClockReconciler::JOINT_RULE, $selector->rulesForFailure($validation, null, true));
    }

    public function testUniqueMinimumEditsPreserveEveryOtherByteAndCompressedClocks(): void
    {
        foreach ([0, 1] as $architecture) {
            $source = self::file(architecture: $architecture, compressed: true);
            $repair = (new PreservingActivityRepairer())->repair($source, SummaryClockReconciler::RULE);
            self::assertSame('reconcile_clocks', $repair->report['mode']);
            self::assertSame('not_proven', $repair->report['inference']['historical_accuracy']);
            self::assertCount(2, $repair->report['changes']);
            $restore = substr($repair->bytes, 0, -2);
            foreach ($repair->report['changes'] as $change) {
                self::assertSame(1, $change['value'] - $change['original_value']);
                $restore = substr_replace($restore, substr($source, $change['byte_offset'], 4), $change['byte_offset'], 4);
            }
            self::assertSame(substr($source, 0, -2), $restore);
            $before = EditableFitFile::read($source);
            $after = EditableFitFile::read($repair->bytes);
            self::assertCount(count($before->messages), $after->messages);
            foreach ($before->messages as $index => $entry) {
                self::assertSame(PhysicalMessage::fromRaw($entry['raw'], 0)->integer(253), PhysicalMessage::fromRaw($after->messages[$index]['raw'], 0)->integer(253));
            }
            try {
                (new SummaryClockReconciler())->repair($repair->bytes);
                self::fail('Already compatible clocks must remain unchanged.');
            } catch (RepairFailed $failure) {
                self::assertStringContainsString('already compatible', $failure->getMessage());
            }
        }
    }

    public function testDeclinesAmbiguityLargeShiftsAndContradictoryOrIncompleteEvidence(): void
    {
        $cases = [
            [self::file(['recordEnd' => 101]), 'one Session start'],
            [self::file(['recordEnd' => 103]), 'one Session start'],
            [self::file(['sessionStart' => -1]), 'within one second'],
            [self::file(['starts' => [0, 45]]), 'No compatible Lap partition'],
            [self::file(['starts' => [0, 11, 24, 34], 'elapsed' => [10900, 10900, 10900, 10900], 'timer' => [10900, 10900, 10900, 10900], 'recordEnd' => 45]), 'Equally minimal'],
            [self::file(['sumError' => 1500]), 'duration sums'],
            [self::file(['duplicateStart' => true]), 'unique scalar'],
            [self::file(['missingStart' => true]), 'must be complete'],
            [self::file(['lapIndexError' => true]), 'identities'],
            [self::file(['backwardRecord' => true]), 'ordered absolute'],
        ];
        foreach ($cases as [$source, $reason]) {
            try {
                (new SummaryClockReconciler())->repair($source);
                self::fail('Contradictory evidence must be declined: '.$reason);
            } catch (RepairFailed $failure) {
                self::assertStringContainsString($reason, $failure->getMessage());
            }
        }
        $selector = new PreservingActivityRepairer();
        $validation = ['status' => 'FAILED', 'errors' => [['message' => self::FAILURE]]];
        self::assertNotContains(SummaryClockReconciler::RULE, $selector->rulesForFailure($validation, null));
        self::assertContains(SummaryClockReconciler::RULE, $selector->rulesForFailure($validation, null, true));
        self::assertSame([], $selector->rulesForFailure(['status' => 'PASS'], null, true));
    }

    public function testCorpusOptInValidatesChangedBytesAndResumesWithoutReapplying(): void
    {
        $directory = sys_get_temp_dir().'/fit-clock-reconcile-'.bin2hex(random_bytes(8));
        CorpusWorkspace::directory($directory.'/source');
        $source = self::file(['missingTimerStop' => true]);
        file_put_contents($directory.'/source/activity.fit', $source);
        $validator = new class(self::FAILURE) implements CorpusValidator {
            public int $calls = 0;

            public function __construct(private readonly string $failure)
            {
            }

            public function fingerprint(): string
            {
                return 'clock-containment-test';
            }

            public function profileSha256(): ?string
            {
                return null;
            }

            public function validate(array $files, string $directory): array
            {
                ++$this->calls;
                $results = [];
                foreach ($files as $path => $physical) {
                    $bytes = file_get_contents($physical);
                    if (false === $bytes) {
                        throw new \RuntimeException('Cannot read fixture.');
                    }
                    $end = $last = 0;
                    foreach (EditableFitFile::read($bytes)->messages as $entry) {
                        $m = PhysicalMessage::fromRaw($entry['raw'], 0);
                        if (20 === $m->number) {
                            $last = $m->integer(253);
                        } elseif (18 === $m->number) {
                            $end = $m->integer(2) + intdiv($m->integer(7), 1000);
                        }
                    }
                    $pass = $last <= $end + 1;
                    $results[$path] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'status' => $pass ? 'PASS' : 'FAILED',
                        ...($pass ? [] : ['errors' => [['message' => $this->failure]]])];
                }

                return $results;
            }
        };
        try {
            $runner = new CorpusRepairRunner();
            $workspace = new CorpusWorkspace($directory.'/work');
            $progress = static function (string $message): void {};
            $strict = $runner->run($directory.'/source', $workspace, $validator, $progress);
            self::assertSame(1, $strict['summary']['failed']);
            self::assertSame($source, file_get_contents($directory.'/work/current/activity.fit'));
            $enabled = $runner->run($directory.'/source', $workspace, $validator, $progress, reconcileSummaryClocks: true);
            self::assertSame(1, $enabled['summary']['pass']);
            self::assertTrue($enabled['summaryClockReconciliationEnabled']);
            self::assertSame('reconcile_clocks', $enabled['results'][0]['history'][0]['repair']['mode']);
            self::assertSame('PASS', $enabled['results'][0]['history'][0]['validationStatus']);
            self::assertFileExists($directory.'/work/ready/activity.fit');
            self::assertSame($source, file_get_contents($directory.'/source/activity.fit'));
            self::assertSame(2, $validator->calls);
            $runner->run($directory.'/source', $workspace, $validator, $progress, reconcileSummaryClocks: true);
            self::assertSame(2, $validator->calls);
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }

    /** @param array<string, mixed> $options */
    private static function file(array $options = [], int $architecture = 0, bool $compressed = false): string
    {
        $starts = $options['starts'] ?? [0, 42];
        $elapsed = $options['elapsed'] ?? [40500, 60000];
        $timer = $options['timer'] ?? [30000, 60000];
        $last = $options['recordEnd'] ?? 102;
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $message = static function (int $number, array $fields) use ($architecture, $word, $short): string {
            $encoded = [];
            foreach ($fields as [$field, $type, $value]) {
                $encoded[] = [$field, $type, 0 === $type ? chr($value) : pack(0x84 === $type ? $short : $word, $value)];
            }

            return FitFixture::message($number, [...$encoded, [200, 0x0D, "\xAA\xBB"]], [[7, 0, "\xDE\xAD"]], $architecture);
        };
        $event = static fn (int $at, int $type): string => $message(21, [[253, 0x86, self::START + $at], [0, 0, 0], [1, 0, $type]]);
        $data = $message(0, [[0, 0, 4]]).$event(0, 0).FitFixture::record(self::START)
            .$event(20, 4).$event(30, 0)
            .(($options['backwardRecord'] ?? false) ? FitFixture::record(self::START + $last + 1) : '')
            .FitFixture::record(self::START + $last - ($compressed ? 1 : 0));
        if ($compressed) {
            $data .= "\x41\x00\x00\x14\x00\x01\x03\x01\x02".chr(0xA0 | ((self::START + $last) & 0x1F))."\x75";
        }
        foreach ($starts as $index => $start) {
            $data .= $message(19, [[254, 0x84, $index + (($options['lapIndexError'] ?? false) ? 1 : 0)],
                ...(($options['missingStart'] ?? false) ? [] : [[2, 0x86, self::START + $start]]),
                ...(($options['duplicateStart'] ?? false) ? [[2, 0x86, self::START + $start]] : []),
                [253, 0x86, self::START + $last], [7, 0x86, $elapsed[$index]], [8, 0x86, $timer[$index]]]);
        }
        $data .= (($options['missingTimerStop'] ?? false) ? '' : $event($last, 4))
            .$message(18, [[254, 0x84, 0], [2, 0x86, self::START + ($options['sessionStart'] ?? 0)], [253, 0x86, self::START + $last],
                [7, 0x86, array_sum($elapsed) + ($options['sumError'] ?? 0)], [8, 0x86, array_sum($timer)], [25, 0x84, 0], [26, 0x84, count($starts)]])
            .$message(34, [[253, 0x86, self::START + $last], [0, 0x86, array_sum($timer)], [1, 0x84, 1], [5, 0x86, self::START + $last + 3600]]);

        return FitFixture::file($data);
    }
}
