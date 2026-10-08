<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

/** @internal A single-Lap file whose summaries and deferred timer share a late start. */
final class RecordedSpanFixture
{
    public const int START = 0x40000020;

    /** @param array<string, int> $overrides */
    public static function file(array $overrides = [], int $architecture = 0, bool $decorate = true): string
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $base = self::START;
        $elapsed = $overrides['elapsed'] ?? 100000;
        $timer = $overrides['timer'] ?? 99000;
        $event = static fn (int $at, int $type): string => FitFixture::message(21, [
            [253, $overrides['eventTimestampType'] ?? 0x86, pack($word, $base + $at)],
            [0, 0, "\x00"], [1, 0, chr($type)], [4, 0x02, chr($overrides['group'] ?? 0)],
            ...($decorate ? [[200, 0x0D, "\x11\x22"]] : []),
        ], $decorate ? [[8, 0, "\xFE\xED"]] : [], $architecture);
        $summary = static function (int $number) use ($word, $short, $base, $elapsed, $timer, $overrides, $decorate, $architecture): string {
            $extra = 18 === $number ? [
                [25, 0x84, pack($short, $overrides['firstLapIndex'] ?? 0)],
                [26, 0x84, pack($short, $overrides['numLaps'] ?? 1)],
            ] : [];
            $fields = [
                [254, 0x84, pack($short, 0)],
                [253, 0x86, pack($word, $base + ($overrides['lapTimestamp'] ?? 100) + (18 === $number ? 1 : 0))],
                [2, $overrides['startType'] ?? 0x86, pack($word, $base + ($overrides[18 === $number ? 'sessionStart' : 'lapStart'] ?? 80))],
                [7, 0x86, pack($word, $elapsed + (18 === $number ? ($overrides['elapsedMismatch'] ?? 0) : 0))],
                [8, 0x86, pack($word, $timer)], [5, 0, "\x11"], ...$extra,
            ];

            if (isset($overrides['duplicateStart'])) {
                $fields[] = [2, 0x86, pack($word, $base + 80)];
            }

            if ($decorate) {
                $fields[] = [201, 0x0D, "\xCA\xFE\x01"];
            }

            return FitFixture::message($number, $fields, $decorate ? [[9, 0, "\xBA\xBE"]] : [], $architecture);
        };
        $records = FitFixture::record($base + ($overrides['firstRecord'] ?? 0)).FitFixture::record($base + 50)
            .FitFixture::record($base + 80)
            ."\x41\x00\x00\x14\x00\x01\x03\x01\x02".chr(0xA0 | (($base + 90) & 0x1F))."\x75"
            .(isset($overrides['backwardRecord']) ? FitFixture::record($base + 70) : '')
            .(isset($overrides['missingRecordTimestamp']) ? FitFixture::message(20, [[3, 0x02, "\x75"]]) : FitFixture::record($base + ($overrides['lastRecord'] ?? 100)));
        $pair = $event($overrides['eventStart'] ?? 80, 0)
            .(isset($overrides['lateCompressed']) ? "\x41\x00\x00\x50\xC3\x01\x07\x01\x02".chr(0xA0 | (($base + 85) & 0x1F))."\x2A" : '')
            .$event($overrides['eventStop'] ?? 100, $overrides['stopType'] ?? 4)
            .(isset($overrides['extraTimer']) ? $event(100, 4) : '');
        $lap = $summary(19);
        $session = $summary(18);
        $activity = FitFixture::message(34, [
            [253, 0x86, pack($word, $base + 102)], [5, 0x86, pack($word, $base + 3702)],
            [0, 0x86, pack($word, $timer + ($overrides['activityTimerMismatch'] ?? 0))],
            [1, 0x84, pack($short, $overrides['numSessions'] ?? 1)],
        ], [], $architecture);
        $data = FitFixture::message(0, [[0, 0, "\x04"]])
            .(isset($overrides['earlyEvents']) ? $pair.$records : $records.$pair)
            .($decorate ? FitFixture::message(50000, [[100, 0x0D, "\x12\x34"]], [[5, 0, "\xFA\xCE"]]) : '')
            .$lap.(isset($overrides['extraLap']) ? $lap : '').$session
            .(isset($overrides['extraSession']) ? $session : '').$activity;

        return FitFixture::file($data);
    }
}
