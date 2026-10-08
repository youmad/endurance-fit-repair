<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

/** @internal Three-Lap activity with a delayed final SAVE and corroborated final timer stop. */
final class RecordedFinalLapFixture
{
    /** @param array<string, int> $overrides */
    public static function file(array $overrides = [], int $architecture = 0, bool $decorate = true): string
    {
        $base = RecordedLapBoundaryFixture::START;
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $firstElapsed = $overrides['firstElapsed'] ?? 38000;
        $middleElapsed = $overrides['middleElapsed'] ?? 31400;
        $finalElapsed = $overrides['finalElapsed'] ?? 31400;
        $firstTimer = $overrides['firstTimer'] ?? $firstElapsed;
        $middleTimer = $overrides['middleTimer'] ?? $middleElapsed;
        $finalTimer = $overrides['finalTimer'] ?? $finalElapsed;
        $elapsed = $firstElapsed + $middleElapsed + $finalElapsed;
        $timer = $firstTimer + $middleTimer + $finalTimer;
        $summary = static function (int $number, int $index, int $from, int $to, int $elapsed, int $timer) use ($base, $word, $short, $architecture, $overrides, $decorate): string {
            $fields = [
                [254, 0x84, pack($short, $index)],
                [2, 0x86, pack($word, $base + $from)], [253, 0x86, pack($word, $base + $to)],
                [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $timer)],
                [18 === $number ? 5 : 25, 0, "\x01"],
            ];

            if (18 === $number) {
                $fields[] = [25, 0x84, pack($short, 0)];
                $fields[] = [26, 0x84, pack($short, $overrides['numLaps'] ?? 3)];
            }

            if ($decorate) {
                $fields[] = [200, 0x0D, "\xAA\xBB\xCC"];
            }

            return FitFixture::message($number, $fields, $decorate ? [[7, 0, "\xDE\xAD"]] : [], $architecture);
        };
        $event = static fn (int $at, int $type): string => FitFixture::message(21, [
            [253, 0x86, pack($word, $base + $at)], [0, 0, chr($overrides['eventKind'] ?? 0)],
            [1, 0, chr($type)], [4, 0x02, chr($overrides['eventGroup'] ?? 0)],
        ], [], $architecture);
        $final = $summary(19, $overrides['finalIndex'] ?? 2, $overrides['finalStart'] ?? 71, $overrides['save'] ?? 111, $finalElapsed, $finalTimer);
        $session = $summary(18, 0, 0, $overrides['sessionSave'] ?? 111, $elapsed + ($overrides['elapsedMismatch'] ?? 0), $timer + ($overrides['timerMismatch'] ?? 0));
        $end = FitFixture::record($base + ($overrides['lastRecord'] ?? 101)).$event($overrides['stop'] ?? 101, $overrides['stopType'] ?? 4);
        $data = FitFixture::message(0, [[0, 0, "\x04"]]).$event($overrides['timerStart'] ?? 0, 0).FitFixture::record($base)
            .(isset($overrides['earlierPause']) ? $event(15, 4).$event(25, 0) : '')
            .FitFixture::record($base + 39).$summary(19, 0, 0, 40, $firstElapsed, $firstTimer)
            .FitFixture::record($base + 69).$summary(19, 1, $overrides['middleStart'] ?? 40, $overrides['middleSave'] ?? 70, $middleElapsed, $middleTimer)
            .FitFixture::record($base + 79)
            ."\x41\x00\x00\x14\x00\x01\x03\x01\x02".chr(0xA0 | (($base + 80) & 0x1F))."\x75"
            .(isset($overrides['finalPause']) ? $event($overrides['pauseStop'] ?? 80, 4).$event($overrides['pauseResume'] ?? 90, 0) : '')
            .(isset($overrides['extraStart']) ? $event(95, 0) : '')
            .(isset($overrides['missingEventKind']) ? FitFixture::message(21, [[253, 0x86, pack($word, $base + 95)]], [], $architecture) : '')
            .(isset($overrides['lateStop']) ? $final.$end : $end.$final)
            .($decorate ? FitFixture::message(50000, [[100, 0x0D, "\x12\x34"]], [[5, 0, "\xFA\xCE"]]) : '')
            .$session.(isset($overrides['extraSession']) ? $session : '')
            .FitFixture::message(34, [
                [253, 0x86, pack($word, $base + 112)], [5, 0x86, pack($word, $base + 3712)],
                [0, 0x86, pack($word, $timer + ($overrides['activityMismatch'] ?? 0))], [1, 0x84, pack($short, 1)],
            ], [], $architecture);

        return FitFixture::file($data);
    }
}
