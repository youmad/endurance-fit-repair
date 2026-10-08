<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

/** @internal A two-Lap activity with a recorded twenty-second pause. */
final class PausedLapFixture
{
    public const int START = 0x40000020;

    /** @param array<string, int> $overrides */
    public static function file(array $overrides = [], int $architecture = 0, bool $decorate = true): string
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $start = self::START;
        $prefixElapsed = $overrides['prefixElapsed'] ?? 0;
        $firstTimer = 0 < $prefixElapsed ? ($overrides['firstElapsed'] ?? 19700) : 60000;
        $firstElapsed = $overrides['firstElapsed'] ?? 60000;
        $secondElapsed = $overrides['secondElapsed'] ?? 40000;
        $secondTimer = $overrides['secondTimer'] ?? 20000;
        $firstTimestamp = $start + ($overrides['firstTimestamp'] ?? 60);
        $secondTimestamp = $start + ($overrides['secondTimestamp'] ?? 100);
        $summary = static function (int $number, int $index, int $from, int $to, int $elapsed, int $timer) use ($word, $short, $architecture, $overrides, $prefixElapsed): string {
            $extra = 18 === $number ? [
                [25, 0x84, pack($short, $overrides['firstLapIndex'] ?? 0)],
                [26, 0x84, pack($short, 0 < $prefixElapsed ? 3 : 2)],
            ] : [];

            return FitFixture::message($number, [
                [254, 0x84, pack($short, $index)],
                [2, 0x86, pack($word, $from)], [253, 0x86, pack($word, $to)],
                [7, 0x86, pack($word, $elapsed)], [8, 0x86, pack($word, $timer)],
                [5, 0, "\x02"], ...$extra,
            ], [], $architecture);
        };
        $event = static fn (int $at, int $type): string => FitFixture::message(21, [
            [253, 0x86, pack($word, $start + $at)], [0, 0, "\x00"], [1, 0, chr($type)],
            [4, 0x02, chr($overrides['eventGroup'] ?? 0)],
        ], [], $architecture);
        $firstLap = $summary(19, 0 < $prefixElapsed ? 1 : 0, $start + (0 < $prefixElapsed ? 40 : 0), $firstTimestamp, $firstElapsed, $firstTimer);
        $secondFields = [
            [254, 0x84, pack(0 === $architecture ? 'v' : 'n', 0 < $prefixElapsed ? 2 : 1)],
            [2, $overrides['startType'] ?? 0x86, pack($word, $start + ($overrides['secondStart'] ?? 80))],
            [253, 0x86, pack($word, $secondTimestamp)],
            [7, 0x86, pack($word, $secondElapsed)], [8, 0x86, pack($word, $secondTimer)], [5, 0, "\x02"],
        ];

        if (isset($overrides['duplicateStart'])) {
            $secondFields[] = [2, 0x86, pack($word, $start + 80)];
        }

        if ($decorate) {
            $secondFields[] = [200, 0x0D, "\xAA\xBB\xCC"];
        }

        $secondLap = FitFixture::message(19, $secondFields, $decorate ? [[7, 0, "\xDE\xAD"]] : [], $architecture);
        $session = $summary(18, 0, $start, $start + 100, $overrides['sessionElapsed'] ?? $prefixElapsed + $firstElapsed + $secondElapsed, $prefixElapsed + $firstTimer + $secondTimer);
        $activity = FitFixture::message(34, [
            [253, 0x86, pack($word, $start + 100)], [5, 0x86, pack($word, $start + 3700)],
            [0, 0x86, pack($word, $prefixElapsed + $firstTimer + $secondTimer)], [1, 0x84, pack(0 === $architecture ? 'v' : 'n', $overrides['numSessions'] ?? 1)],
        ], [], $architecture);
        $data = FitFixture::message(0, [[0, 0, "\x04"]])
            .$event(0, 0).(isset($overrides['extraStart']) ? $event(0, 0) : '')
            .(isset($overrides['missingEventKind']) ? FitFixture::message(21, [[253, 0x86, pack($word, $start)]], [], $architecture) : '')
            .FitFixture::record($start)
            .(0 < $prefixElapsed ? FitFixture::record($start + 40).$summary(19, 0, $start, $start + 40, $prefixElapsed, $prefixElapsed) : '')
            .FitFixture::record($start + 59)
            .$event($overrides['stopTime'] ?? 60, $overrides['stopType'] ?? 4).$firstLap
            .$event($overrides['resumeTime'] ?? 80, 0).FitFixture::record($start + 80)
            ."\x41\x00\x00\x14\x00\x01\x03\x01\x02".chr(0xA0 | (($start + 90) & 0x1F))."\x75"
            .($decorate ? FitFixture::message(50000, [[100, 0x0D, "\x12\x34"]], [[5, 0, "\xFA\xCE"]]) : '')
            .FitFixture::record($start + 100).$event(100, 4).$secondLap.$session
            .(isset($overrides['multipleSessions']) ? $session : '').$activity;

        return FitFixture::file($data);
    }
}
