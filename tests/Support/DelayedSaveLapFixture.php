<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

use Youmad\Endurance\Fit\Checksum\FitCrc16;

final class DelayedSaveLapFixture
{
    public const int START = 0x40000020;

    /** @param array<string, int> $options */
    public static function file(array $options = [], int $architecture = 0, int $headerSize = 14): string
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $start = self::START;
        $timer = $options['timer'] ?? (isset($options['noPause']) ? 10250 : 7250);
        $save = $start + ($options['save'] ?? 100);
        $event = static fn (int $at, int $type): string => FitFixture::message(21, [
            [253, 0x86, pack($word, $start + $at)], [0, $options['eventBaseType'] ?? 0, chr($options['eventKind'] ?? 0)],
            [1, $options['eventBaseType'] ?? 0, chr($type)], [4, 0x02, chr($options['group'] ?? 0)],
            ...(isset($options['duplicateEventKind']) ? [[0, 0, "\x00"]] : []),
        ], [], $architecture);
        $records = FitFixture::record($start + ($options['recordStart'] ?? 0));

        if (!isset($options['plainRecords'])) {
            $records .= "\x41\x00\x00\x14\x00\x01\x03\x01\x02".chr(0xA0 | (($start + 1) & 0x1F))."\x75";
        }

        $session = FitFixture::message(18, [
            [2, 0x86, pack($word, $start)], [253, 0x86, pack($word, $save)],
            [7, 0x86, pack($word, $options['elapsed'] ?? 10250)], [8, 0x86, pack($word, $timer)],
            [5, 0, "\x0A"], [6, 0, "\x14"], [9, 0x86, pack($word, 123456)],
            ...(isset($options['missingIndexes']) ? [] : [[254, 0x84, pack($short, 0)], [25, 0x84, pack($short, 0)]]),
            [26, 0x84, pack($short, $options['lapCount'] ?? 1)], [200, 0x0D, "\xFE\xAB"],
        ], isset($options['plain']) ? [] : [[7, 0, "\xAB\xCD"]], $architecture);
        $activity = FitFixture::message(34, [
            [253, 0x86, pack($word, $start + ($options['activitySave'] ?? ($options['save'] ?? 100)))],
            [5, 0x86, pack($word, $save + 3600)], [0, 0x86, pack($word, $options['activityTimer'] ?? $timer)],
            [1, 0x84, pack($short, $options['activitySessions'] ?? 1)],
        ], [], $architecture);
        $data = FitFixture::message(0, [[0, 0, "\x04"]])
            .(isset($options['noStart']) ? '' : $event($options['timerStart'] ?? 0, 0))
            .$records.(isset($options['noPause']) ? '' : $event(4, 1).$event(7, 0))
            .FitFixture::record($start + ($options['recordEnd'] ?? 10))
            .(isset($options['noStop']) ? '' : $event($options['timerStop'] ?? 10, $options['stopType'] ?? 4))
            .FitFixture::message(50000, [[100, 0x0D, "\xAA\xBB"]], isset($options['plain']) ? [] : [[7, 0, "\x21\x22"]])
            .$session.$activity;
        $bytes = FitFixture::file($data);

        if (12 === $headerSize) {
            $body = "\x0C".substr($bytes, 1, 11).substr($bytes, 14, -2);
            $bytes = $body.pack('v', FitCrc16::calculate($body));
        }

        return $bytes;
    }
}
