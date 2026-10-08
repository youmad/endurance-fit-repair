<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

/** One explicit Session and matching existing Lap, including summary-first order. */
final readonly class ReservedSessionIndexFixture
{
    /** @param array<string, int> $overrides */
    public static function file(array $overrides = [], int $architecture = 0, bool $summaryFirst = false): string
    {
        $word = 0 === $architecture ? 'v' : 'n';
        $long = 0 === $architecture ? 'V' : 'N';
        $start = 0x10000020;
        $sessionFields = [
            [254, $overrides['indexType'] ?? 0x84, pack($word, $overrides['index'] ?? 0x7000)],
            [25, 0x84, pack($word, $overrides['first'] ?? 0)],
            [8, 0x86, pack($long, 9_000)],
            [5, 0x00, "\x01"],
            [9, 0x86, pack($long, 12_345)],
            [100, 0x0D, "\xAB\xCD"],
        ];

        if (isset($overrides['count'])) {
            $sessionFields[] = [26, 0x84, pack($word, $overrides['count'])];
        }

        if (isset($overrides['best'])) {
            $sessionFields[] = [70, 0x84, pack($word, $overrides['best'])];
        }

        if (isset($overrides['duplicateIndex'])) {
            $sessionFields[] = [254, 0x84, pack($word, 0x7000)];
        }

        if (isset($overrides['indexArray'])) {
            $sessionFields[0][2] .= pack($word, 0);
        }

        $session = FitFixture::summary(18, $start, 10_000, $sessionFields, [[7, 0, "\x21\x22"]], $architecture);
        $lap = FitFixture::summary(19, $overrides['lapStart'] ?? $start, $overrides['lapElapsed'] ?? 10_000, [
            [254, 0x84, pack($word, $overrides['lapIndex'] ?? 0)],
            [8, 0x86, pack($long, $overrides['lapTimer'] ?? 9_000)],
            [5, 0x00, "\x01"],
            [9, 0x86, pack($long, 12_345)],
        ], [[6, 0, "\x31\x32"]], $architecture);
        $activity = FitFixture::message(34, [
            [1, 0x84, pack($word, $overrides['sessions'] ?? 1)],
            [253, 0x86, pack($long, $start + 10)],
            [5, 0x86, pack($long, $start + 10)],
            [0, 0x86, pack($long, 9_000)],
        ], [], $architecture);
        $record = FitFixture::record($start + 2);
        $event = FitFixture::message(21, [[253, 0x86, pack('V', $start)], [0, 0x00, "\x00"], [1, 0x00, "\x00"]]);
        $fileId = FitFixture::message(0, [[0, 0x00, chr($overrides['fileType'] ?? 4)]], [], $architecture);

        return FitFixture::file($fileId.($summaryFirst ? $session.$activity.$event.$record.$lap : $event.$record.$lap.$session.$activity)
            .(isset($overrides['extraSession']) ? $session : '').(isset($overrides['extraLap']) ? $lap : '')
            .(isset($overrides['extraActivity']) ? $activity : '').(isset($overrides['extraFileId']) ? $fileId : ''));
    }
}
