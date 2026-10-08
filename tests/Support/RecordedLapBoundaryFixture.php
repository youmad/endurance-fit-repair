<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

/** @internal Fractional durations corroborate a recorded one-second Lap-start error. */
final class RecordedLapBoundaryFixture
{
    public const int START = 0x40000020;

    /** @param array<string, int> $overrides */
    public static function file(array $overrides = [], int $architecture = 0, bool $decorate = true): string
    {
        $base = self::START;
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $transition = isset($overrides['transition']);
        $firstElapsed = $overrides['firstElapsed'] ?? 60250;
        $firstTimer = $overrides['firstTimer'] ?? $firstElapsed;
        $lastElapsed = $overrides['lastElapsed'] ?? 40650;
        $lastTimer = $overrides['lastTimer'] ?? $lastElapsed;
        $summary = static function (int $number, int $index, int $start, int $timestamp, int $elapsed, int $timer) use ($word, $short, $architecture, $overrides, $decorate): string {
            $fields = [
                [254, 0x84, pack($short, $index)],
                [2, $overrides['startType'] ?? 0x86, pack($word, $start)], [253, 0x86, pack($word, $timestamp)],
                [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $timer)],
                [18 === $number ? 5 : 25, 0, "\x02"],
            ];

            if (18 === $number) {
                $fields[] = [25, 0x84, pack($short, $overrides['firstLapIndex'] ?? (0 === $index ? 0 : 2))];
                $fields[] = [26, 0x84, pack($short, $overrides['numLaps'] ?? (0 === $index ? 2 : 1))];
            }

            if (19 === $number && 1 === $index && isset($overrides['duplicateStart'])) {
                $fields[] = [2, 0x86, pack($word, $start)];
            }

            if ($decorate) {
                $fields[] = [200, 0x0D, "\xAA\xBB\xCC"];
            }

            return FitFixture::message($number, $fields, $decorate ? [[7, 0, "\xDE\xAD"]] : [], $architecture);
        };
        $event = static fn (int $timestamp, int $type): string => FitFixture::message(21, [
            [253, 0x86, pack($word, $base + $timestamp)], [0, 0, chr($overrides['eventKind'] ?? 0)],
            [1, 0, chr($type)], [4, 0x02, chr($overrides['eventGroup'] ?? 0)],
        ], [], $architecture);
        $firstLap = $summary(19, 0, $base, $base + ($overrides['previousTimestamp'] ?? 61), $firstElapsed, $firstTimer);
        $lastLap = $summary(19, $overrides['lastIndex'] ?? 1, $base + ($overrides['lastStart'] ?? 62), $base + ($overrides['lastTimestamp'] ?? ($transition ? 101 : 111)), $lastElapsed, $lastTimer);
        $session = $summary(18, 0, $base + ($overrides['sessionStart'] ?? 0), $base + ($transition ? 101 : 111),
            $firstElapsed + $lastElapsed + ($overrides['elapsedMismatch'] ?? 0), $firstTimer + $lastTimer + ($overrides['timerMismatch'] ?? 0));
        $activity = FitFixture::message(34, [
            [253, 0x86, pack($word, $base + ($overrides['activityTimestamp'] ?? 112))], [5, 0x86, pack($word, $base + 3712)],
            [0, 0x86, pack($word, $firstTimer + $lastTimer + ($transition ? 10000 : 0) + ($overrides['activityTimerMismatch'] ?? 0))],
            [1, 0x84, pack($short, $overrides['numSessions'] ?? ($transition ? 2 : 1))],
        ], [], $architecture);
        $end = FitFixture::record($base + ($overrides['lastRecord'] ?? 101)).($transition ? '' : $event($overrides['stop'] ?? 101, $overrides['stopType'] ?? 4));
        $data = FitFixture::message(0, [[0, 0, "\x04"]]).$event($overrides['timerStart'] ?? 0, 0)
            .FitFixture::record($base + ($overrides['firstRecord'] ?? 0))
            .(isset($overrides['earlierPause']) ? $event(20, 4).$event(30, 0) : '')
            .FitFixture::record($base + 60).$firstLap
            .(isset($overrides['backwardRecord']) ? FitFixture::record($base + 59) : '')
            .(isset($overrides['middleRecord']) ? FitFixture::record($base + $overrides['middleRecord'])
                : "\x41\x00\x00\x14\x00\x01\x03\x01\x02".chr(0xA0 | (($base + 70) & 0x1F))."\x75")
            .(isset($overrides['interiorTimer']) ? $event($overrides['pauseStop'] ?? 70, 4).$event($overrides['pauseResume'] ?? 80, 0) : '')
            .(isset($overrides['secondPause']) ? $event(90, 4).$event(95, 0) : '')
            .(isset($overrides['missingRecordTimestamp']) ? FitFixture::message(20, [[3, 0x02, "\x75"]]) : '')
            .(isset($overrides['lateStop']) ? $lastLap.$end : $end.$lastLap)
            .($decorate ? FitFixture::message(50000, [[100, 0x0D, "\x12\x34"]], [[5, 0, "\xFA\xCE"]]) : '')
            .$session.(isset($overrides['extraSession']) ? $session : '')
            .($transition ? FitFixture::record($base + 101).FitFixture::record($base + 111).$event(111, 4)
                .$summary(19, 2, $base + ($overrides['nextStart'] ?? 101), $base + 111, 10000, 10000)
                .$summary(18, 1, $base + ($overrides['nextStart'] ?? 101), $base + 111, 10000, 10000) : '')
            .$activity;

        return FitFixture::file($data);
    }
}
