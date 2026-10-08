<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalEncoder;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class RecordedSetLapRepairerTest extends TestCase
{
    private const int START = 0x10000020;

    public function testCopiesTheCompleteSetPartitionWithoutChangingAnyOriginalPacket(): void
    {
        foreach ([0, 1] as $architecture) {
            foreach ([false, true] as $earlySession) {
                foreach ([false, true] as $paused) {
                    $packets = $this->packets($architecture, $earlySession, $paused);
                    $packets['firstRecord'] .= "\x41\x00\x00\x14\x00\x01\x03\x01\x02\xA1\x75";
                    $source = FitFixture::file(implode('', $packets));
                    $result = (new PreservingActivityRepairer())->repair($source, PreservingActivityRepairer::MISSING_LAP);
                    $before = EditableFitFile::read($source);
                    $after = EditableFitFile::read($result->bytes);
                    $sessionIndex = $earlySession ? 1 : ($paused ? 12 : 10);
                    $kept = $after->messages;
                    $inserted = array_splice($kept, $sessionIndex, 3);

                    foreach ($before->messages as $index => $entry) {
                        self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $kept[$index]['offset'], $kept[$index]['length']));
                        self::assertSame($entry['definition'], $kept[$index]['definition']);
                    }

                    foreach ($inserted as $ordinal => $entry) {
                        $lap = PhysicalMessage::fromRaw($entry['raw'], 14);
                        self::assertSame(19, $lap->number);
                        self::assertSame(5, $entry['raw']->recordHeaderByte());
                        self::assertSame($architecture, $lap->architecture->value);
                        self::assertSame([$ordinal, self::START, self::START + ($paused ? [0, 10, 21] : [0, 7, 18])[$ordinal],
                            ($paused ? [10_375, 10_875, 3000] : [7125, 10_875, 3000])[$ordinal], [7125, 10_875, 3000][$ordinal], 10, 20], array_map($lap->integer(...), [254, 253, 2, 7, 8, 25, 39]));
                        self::assertTrue($result->report['inserted_messages'][$ordinal]['synthetic']);
                        self::assertSame([1, 0, 1][$ordinal], $result->report['inserted_messages'][$ordinal]['set_type']);
                    }

                    self::assertSame([], $result->report['changes']);
                    self::assertSame(21_000, $result->report['evidence']['set_duration_sum_ms']);
                    self::assertSame($paused ? 3250 : 0, $result->report['evidence']['recorded_session_pause_ms']);
                    self::assertSame($paused ? 0 : null, $result->report['evidence']['paused_set_index']);
                    self::assertSame('verified', $result->report['preservation']);
                    self::assertSame($result->bytes, (new ActivityRepairer())->repair($source)->bytes);
                    self::assertSame($result->bytes, (new ActivityRepairer())->repair($result->bytes)->bytes);
                    self::assertSame([PreservingActivityRepairer::MISSING_LAP, \Youmad\Endurance\FitRepair\Internal\Rule\RecordedSummarySaveRepairer::RULE], (new PreservingActivityRepairer())->rulesForFailure([
                        'status' => 'FAILED', 'errors' => [['message' => 'Required check "Lap Message Exists" failed.']],
                    ], null));
                }
            }
        }
    }

    public function testRefusesPausesIncompletePartitionsAndContradictoryPhysicalEvidence(): void
    {
        $packets = $this->packets(0, true);
        $source = FitFixture::file(implode('', $packets));
        $file = EditableFitFile::read($source);
        $cases = [];

        foreach ([
            [1, 254, 0x84, 1], [1, 25, 0x84, 1], [1, 26, 0x84, 4],
            [1, 7, 0x86, 21_001], [1, 8, 0x86, 20_000], [1, 5, 0x00, 2],
            [1, 6, 0x00, 0], [1, 9, 0x86, 1], [1, 253, 0x86, self::START - 1],
            [6, 10, 0x84, 0], [6, 6, 0x86, self::START + 12], [5, 6, 0x86, self::START + 1],
            [7, 0, 0x86, 4000], [5, 0, 0x86, 0], [5, 254, 0x86, 0x0FFFFFFF], [6, 5, 0x00, 2],
            [4, 253, 0x86, self::START - 1], [8, 253, 0x86, self::START + 30],
            [3, 1, 0x00, 4], [9, 1, 0x00, 0], [3, 253, 0x86, self::START + 1], [9, 253, 0x86, self::START + 23],
            [2, 0, 0x86, 20_000], [2, 1, 0x84, 2], [2, 253, 0x86, self::START + 1],
        ] as [$message, $field, $type, $value]) {
            $messages = array_map(static fn ($entry): PhysicalMessage => PhysicalMessage::fromRaw($entry['raw'], 14), $file->messages);
            $messages[$message] = $messages[$message]->withInteger($field, $type, $value);
            $cases[] = (new PhysicalEncoder())->encode($messages, $file->header->profileVersion);
        }

        foreach (['session', 'activity', 'begin', 'fileId'] as $extra) {
            $cases[] = FitFixture::file(implode('', $packets).$packets[$extra]);
        }

        $missing = $packets;
        unset($missing['set1']);
        $cases[] = FitFixture::file(implode('', $missing));

        foreach ([[0, 0x84, pack('v', 10_875)], [0, 0x86, pack('V', 10_875)]] as $badField) {
            $bad = $packets;
            $fields = [[10, 0x84, pack('v', 1)], [254, 0x86, pack('V', self::START)], [6, 0x86, pack('V', self::START + 7)], [5, 0, "\x00"]];

            if (0x86 === $badField[1]) {
                $fields[] = $badField;
            }

            $fields[] = $badField;
            $bad['set1'] = FitFixture::message(225, $fields);
            $cases[] = FitFixture::file(implode('', $bad));
        }

        $paused = FitFixture::file(implode('', $this->packets(0, true, true)));
        $pausedFile = EditableFitFile::read($paused);

        foreach ([[1, 7, 0x86, 25_250], [5, 253, 0x86, self::START + 1], [6, 253, 0x86, self::START + 11],
            [6, 253, 0x86, self::START + 2], [5, 1, 0x00, 0], [6, 1, 0x00, 4], [5, 4, 0x02, 1],
            [8, 6, 0x86, self::START + 4]] as [$message, $field, $type, $value]) {
            $messages = array_map(static fn ($entry): PhysicalMessage => PhysicalMessage::fromRaw($entry['raw'], 14), $pausedFile->messages);
            $messages[$message] = $messages[$message]->withInteger($field, $type, $value);
            $cases[] = (new PhysicalEncoder())->encode($messages, $pausedFile->header->profileVersion);
        }

        $cases[] = FitFixture::file(implode('', $this->packets(0, true, true)).$packets['begin'].$packets['stop']);

        foreach ($cases as $case) {
            try {
                (new PreservingActivityRepairer())->repair($case, PreservingActivityRepairer::MISSING_LAP);
                self::fail('Contradictory Set evidence must be refused.');
            } catch (RepairFailed) {
                self::assertSame($case, (new ActivityRepairer())->repair($case)->bytes);
            }
        }
    }

    /** @return array<string, string> */
    private function packets(int $architecture, bool $earlySession, bool $paused = false): array
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $session = FitFixture::message(18, [[254, 0x84, pack($short, 0)], [253, 0x86, pack($word, self::START)],
            [2, 0x86, pack($word, self::START)], [7, 0x86, pack($word, $paused ? 24_250 : 21_000)], [8, 0x86, pack($word, 21_000)],
            [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, 3)], [5, 0, "\x0A"], [6, 0, "\x14"], [9, 0x86, pack($word, 0)]], architecture: $architecture);
        $session[0] = "\x45";
        $session[6 + 3 * ord($session[5])] = "\x05";
        $packets = ['fileId' => FitFixture::message(0, [[0, 0, "\x04"]])];

        if ($earlySession) {
            $packets['session'] = $session;
        }

        $packets['activity'] = FitFixture::message(34, [[253, 0x86, pack('V', self::START)], [0, 0x86, pack('V', 21_000)], [1, 0x84, pack('v', 1)]]);
        $packets['begin'] = FitFixture::message(21, [[253, 0x86, pack('V', self::START)], [0, 0, "\x00"], [1, 0, "\x00"]]);
        $packets['firstRecord'] = FitFixture::record(self::START);

        if ($paused) {
            $packets['pause'] = FitFixture::message(21, [[253, 0x86, pack('V', self::START + 2)], [0, 0, "\x00"], [1, 0, "\x04"], [4, 0x02, "\x00"]]);
            $packets['resume'] = FitFixture::message(21, [[253, 0x86, pack('V', self::START + 5)], [0, 0, "\x00"], [1, 0, "\x00"]]);
        }

        foreach ([[0, 7125, 1], [7, 10_875, 0], [18, 3000, 1]] as $index => [$offset, $duration, $type]) {
            $offset += $paused && $index > 0 ? 3 : 0;
            $packets['set'.$index] = FitFixture::message(225, [[10, 0x84, pack($short, $index)], [254, 0x86, pack($word, self::START)],
                [6, 0x86, pack($word, self::START + $offset)], [0, 0x86, pack($word, $duration)], [5, 0, chr($type)],
                [100, 0x0D, "\xAB\xCD"]], [[7, 0, "\x12\x34"]], $architecture);
        }

        $packets['lastRecord'] = FitFixture::record(self::START + ($paused ? 24 : 21));
        $packets['stop'] = FitFixture::message(21, [[253, 0x86, pack('V', self::START + ($paused ? 24 : 21))], [0, 0, "\x00"], [1, 0, "\x04"]]);

        if (!$earlySession) {
            $packets['session'] = $session;
        }

        $packets['unknown'] = FitFixture::message(50_000, [[100, 0x0D, "\xFE\xEF"]], [[7, 0, "\x56\x78"]]);

        return $packets;
    }
}
