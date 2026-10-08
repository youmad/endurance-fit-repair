<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

use Youmad\Endurance\Fit\Checksum\FitCrc16;

final class RecordedSummaryTimestampFixture
{
    public const int START = 0x40000020;

    /** @param array<string, int> $options */
    public static function file(array $options = [], int $architecture = 0, int $headerSize = 14): string
    {
        $start = self::START;
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $pause = isset($options['pause']) ? 10000 : 0;
        $front = isset($options['front']);
        $stamp = static function (int $value, string $name) use ($word, $options): array {
            if (isset($options['existing']) || isset($options[$name])) {
                return [[253, 0x86, pack($word, $value)]];
            }

            return isset($options['sentinels']) ? [
                [253, $options['timestampType'] ?? 0x86, str_repeat("\xFF", $options['timestampSize'] ?? 4)],
                ...(isset($options['duplicateTimestamp']) ? [[253, 0x86, "\xFF\xFF\xFF\xFF"]] : []),
            ] : [];
        };
        $lap = static function (int $index, int $at, int $elapsed, int $timer) use ($word, $short, $architecture, $start, $stamp, $options): string {
            return FitFixture::message(19, [
                [2, 0x86, pack($word, $start + $at)], [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $timer)],
                ...$stamp($start + $at + intdiv($elapsed, 1000), 'lapTimestamp'),
                ...(isset($options['indexes']) ? [[254, 0x84, pack($short, $index)]] : []),
                [200, 0x0D, "\xAA\xBB"],
            ], isset($options['plain']) ? [] : [[7, 0, "\xDE\xAD"]], $architecture);
        };
        $event = static fn (int $at, int $type): string => FitFixture::message(21, [
            [253, 0x86, pack($word, $start + $at)], [0, $options['eventType'] ?? 0, chr($options['eventKind'] ?? 0)],
            [1, $options['eventType'] ?? 0, chr($options['timerType'] ?? $type)], [4, 0x02, chr($options['eventGroup'] ?? 0)], [3, 0x86, pack($word, 123)],
        ], [], $architecture);
        $records = FitFixture::record($start + ($options['firstRecord'] ?? 0)).$lap(0, 0, 40000, 40000 - $pause)
            .(isset($options['pause']) ? $event(20, 1).$event(30, 0) : '')
            .FitFixture::record($start + 40).$lap(1, 40, 60000, 60000).FitFixture::record($start + 100);
        if (isset($options['savedEndpoint'])) {
            $records = FitFixture::record($start).FitFixture::record($start + 40)
                .FitFixture::record($start + ($options['lastRecord'] ?? 90))
                .$lap(0, 0, 40000, 40000 - $pause).$lap(1, 40, 60000, 60000);
        }
        if ($front) {
            $records = FitFixture::record($start + ($options['firstRecord'] ?? 0))
                .(isset($options['pause']) ? $event(20, 1).$event(30, 0) : '')
                .FitFixture::record($start + 40).FitFixture::record($start + ($options['lastRecord'] ?? 100));
        }
        $session = FitFixture::message(18, [
            [2, 0x86, pack($word, $start)], [7, 0x86, pack($word, 100000)], [8, 0x86, pack($word, 100000 - $pause)],
            [5, 0, "\x02"], ...$stamp($start + ($options['sessionSave'] ?? 100), 'sessionTimestamp'),
            ...(isset($options['indexes']) ? [[254, 0x84, pack($short, 0)], [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, $front ? 1 : 2)]] : []),
            [200, 0x0D, "\xCC\xDD"],
        ], isset($options['plain']) ? [] : [[7, 0, "\xBE\xEF"]], $architecture);
        $activity = FitFixture::message(34, [
            [0, 0x86, pack($word, 100000 - $pause)], [1, 0x84, pack($short, 1)], ...$stamp($start + ($options['activitySave'] ?? 100), 'activityTimestamp'),
            ...(isset($options['localTime']) ? [[5, 0x86, pack($word, $start + 3700)]] : []), [200, 0x0D, "\xCC\xEE"],
        ], [], $architecture);
        $data = FitFixture::message(0, [[0, 0, "\x04"], [1, 0x84, pack('v', 255)], [4, 0x86, pack('V', $start)]])
            .($front ? $activity.$session.$lap(0, 0, 100000, 100000 - $pause) : '')
            .$event(0, 0).$records.(!isset($options['noStop']) ? $event(100, 4) : '')
            .($front ? '' : $session.$activity);
        $bytes = FitFixture::file($data);

        if (12 === $headerSize) {
            $body = "\x0C".substr($bytes, 1, 11).substr($bytes, 14, -2);
            $bytes = $body.pack('v', FitCrc16::calculate($body));
        }

        return $bytes;
    }
}
