<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

use Youmad\Endurance\Fit\Checksum\FitCrc16;

final class UnpausedLapTimerFixture
{
    public const int START = 0x40000020;

    public static function file(int $architecture = 0, int $headerSize = 14, int $stopType = 1, int $eventEncoding = 0, bool $summaryFirst = false, bool $wholeSession = false, int $saveDelay = 0): string
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $start = self::START;
        $event = static fn (int $at, int $type): string => FitFixture::message(21, [
            [253, 0x86, pack($word, $start + $at)], [0, $eventEncoding, "\x00"],
            [1, $eventEncoding, chr($type)], [4, 0x02, "\x00"], [3, 0x86, pack($word, 321)],
        ], [], $architecture);
        $lap = static fn (int $index, int $at, int $elapsed, int $timer): string => FitFixture::message(19, [
            [8, 0x86, pack($word, $timer)], [200, 0x0D, "\xAA\xBB"],
            [2, 0x86, pack($word, $start + $at)], [253, 0x86, pack($word, $start + $at + intdiv($elapsed, 1000))],
            [7, 0x86, pack($word, $elapsed)], [254, 0x84, pack($short, $index)],
        ], [[8, 0, "\xDE\xAD"]], $architecture);
        $laps = $wholeSession ? $lap(0, 0, 100000, 101000) : $lap(0, 0, 40250, 43000).$lap(1, 40, 60250, 60300);
        $timer = $wholeSession ? 101000 : 100491;
        $session = FitFixture::message(18, [
            [253, 0x86, pack($word, $start + 100 + $saveDelay)], [2, 0x86, pack($word, $start)],
            [7, 0x86, pack($word, $wholeSession ? 100000 : 100500)], [8, 0x86, pack($word, $timer)],
            [254, 0x84, pack($short, 0)], [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, $wholeSession ? 1 : 2)], [5, 0, "\x02"],
        ], [[8, 0, "\xBE\xEF"]], $architecture);
        $activity = FitFixture::message(34, [
            [253, 0x86, pack($word, $start + 100 + $saveDelay)], [5, 0x86, pack($word, $start + 3700 + $saveDelay)],
            [0, 0x86, pack($word, $timer)], [1, 0x84, pack($short, 1)],
        ], [], $architecture);
        $record = FitFixture::message(20, [[253, 0x86, pack($word, $start)], [3, 0x02, "\x65"], [200, 0x0D, "\x11\x22"]], [[8, 0, "\xFA\xCE"]], $architecture);
        $records = $record.FitFixture::record($start + 100);

        if ($wholeSession) {
            $records = '';

            for ($second = 0; $second <= 100; ++$second) {
                $records .= FitFixture::message(20, [[253, 0x86, pack($word, $start + $second + (int) $summaryFirst)],
                    [3, 0x02, "\x65"], [200, 0x0D, "\x11\x22"]], [[8, 0, "\xFA\xCE"]], $architecture);
            }
        }
        $fileId = FitFixture::message(0, [[0, $eventEncoding, "\x04"]]);
        $bytes = FitFixture::file($fileId.($summaryFirst ? $activity.$session.$laps : '').$event(0, 0).$records.$event(100 + $saveDelay, $stopType)
            .($summaryFirst ? '' : $laps.$session.$activity));

        if (12 === $headerSize) {
            $body = "\x0C".substr($bytes, 1, 11).substr($bytes, 14, -2);
            $bytes = $body.pack('v', FitCrc16::calculate($body));
        }

        return $bytes;
    }
}
