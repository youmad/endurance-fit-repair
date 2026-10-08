<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

use Youmad\Endurance\Fit\Checksum\FitCrc16;

final class SessionElapsedFromLapsFixture
{
    public const int START = 0x40000020;

    public static function file(int $architecture = 0, int $headerSize = 14, bool $pause = false, bool $summaryFirst = false, int $eventEncoding = 0, int $timerShift = 0, bool $recordedEnd = false): string
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $start = self::START;
        $timer = $pause ? 90500 : 100500;
        $stamp = $summaryFirst ? $start : $start + 120;
        $event = static fn (int $at, int $type): string => FitFixture::message(21, [
            [253, 0x86, pack($word, $start + $at)], [0, $eventEncoding, "\x00"],
            [1, $eventEncoding, chr($type)], [4, 0x02, "\x00"], [3, 0x86, pack($word, 321)],
        ], [], $architecture);
        $lap = static fn (int $index, int $at, int $elapsed, int $active): string => FitFixture::message(19, [
            [2, 0x86, pack($word, $start + $at)], [253, 0x86, pack($word, $summaryFirst ? $start : ($recordedEnd && 1 === $index ? $start + 101 : $start + $at + intdiv($elapsed, 1000)))],
            [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $active)], [254, 0x84, pack($short, $index)],
            [200, 0x0D, "\xAA\xBB"],
        ], [[7, 0, "\xDE\xAD"]], $architecture);
        $laps = $lap(0, 0, 40250, $pause ? 30250 : 40250).$lap(1, $recordedEnd ? 39 : 40, 60250, 60250);
        $session = FitFixture::message(18, [
            [253, 0x86, pack($word, $stamp)], [2, 0x86, pack($word, $start)],
            [7, 0x86, pack($word, 120500)], [8, 0x86, pack($word, $timer)],
            [254, 0x84, pack($short, 0)], [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, 2)], [5, 0, "\x02"],
            [200, 0x0D, "\xCC\xDD"],
        ], [[7, 0, "\xBE\xEF"]], $architecture);
        $activity = FitFixture::message(34, [
            [253, 0x86, pack($word, $stamp)], [5, 0x86, pack($word, $stamp + 3600)],
            [0, 0x86, pack($word, $timer)], [1, 0x84, pack($short, 1)],
        ], [], $architecture);
        $records = FitFixture::message(20, [[253, 0x86, pack($word, $start + $timerShift)], [3, 0x02, "\x65"], [200, 0x0D, "\x11\x22"]], [[7, 0, "\xFA\xCE"]], $architecture)
            .($pause ? $event(20, 4).$event(30, 0) : '')
            .FitFixture::record($start + 100 + $timerShift);
        $fileId = FitFixture::message(0, [[0, $eventEncoding, "\x04"]]);
        $bytes = FitFixture::file($fileId.($summaryFirst ? $activity.$session.$laps : '').$event($timerShift, 0).$records.$event(100 + $timerShift, 4)
            .($summaryFirst ? '' : $laps.$session.$activity));

        if (12 === $headerSize) {
            $body = "\x0C".substr($bytes, 1, 11).substr($bytes, 14, -2);
            $bytes = $body.pack('v', FitCrc16::calculate($body));
        }

        return $bytes;
    }
}
