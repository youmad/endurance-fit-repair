<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

final class SingleLapDurationFixture
{
    public const int START = 0x40000020;

    /** @param array<string, int> $overrides */
    public static function file(array $overrides = [], int $architecture = 0, bool $decorate = true): string
    {
        $base = self::START;
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $elapsed = $overrides['elapsed'] ?? 100500;
        $timer = $overrides['timer'] ?? $elapsed;
        $summary = static function (int $number) use ($base, $word, $short, $elapsed, $architecture, $overrides, $decorate): string {
            $fields = [
                ...((19 === $number && isset($overrides['missingLapStart'])) ? [] : [[2, 0x86, pack($word, $base + (19 === $number ? ($overrides['lapStart'] ?? 0) : 0))]]),
                [253, 0x86, pack($word, $base + ($overrides[19 === $number ? 'lapTimestamp' : 'finish'] ?? 101))],
                [254, 0x84, pack($short, $overrides[19 === $number ? 'lapIndex' : 'sessionIndex'] ?? 0)],
                [18 === $number ? 5 : 25, 0, "\x02"],
            ];

            if (18 === $number || isset($overrides['lapElapsed'])) {
                $fields[] = [7, 0x86, pack($word, 18 === $number ? (isset($overrides['missingSessionElapsed']) ? 0xFFFFFFFF : $elapsed) : $overrides['lapElapsed'])];
            } elseif (isset($overrides['invalidSentinels'])) {
                $fields[] = [7, 0x86, "\xFF\xFF\xFF\xFF"];
            }

            if ((18 === $number && isset($overrides['sessionTimer'])) || (19 === $number && isset($overrides['lapTimer']))) {
                $fields[] = [8, $overrides['timerType'] ?? 0x86, pack($word, $overrides[18 === $number ? 'sessionTimer' : 'lapTimer'])];
            } elseif (isset($overrides['invalidSentinels'])) {
                $fields[] = [8, 0x86, "\xFF\xFF\xFF\xFF"];
            }

            if (isset($overrides['duplicateElapsed']) && 18 === $number) {
                $fields[] = [7, 0x86, pack($word, $elapsed)];
            }

            if (18 === $number && !isset($overrides['missingReferences'])) {
                $fields[] = [25, 0x84, pack($short, $overrides['firstLap'] ?? 0)];
                $fields[] = [26, 0x84, pack($short, $overrides['numLaps'] ?? 1)];
            }

            if (isset($overrides['distance'])) {
                $fields[] = [9, 0x86, pack($word, 19 === $number ? ($overrides['lapDistance'] ?? $overrides['distance']) : $overrides['distance'])];
            }

            if ($decorate) {
                $fields[] = [200, 0x0D, "\xAA\xBB\xCC"];
            }

            if (isset($overrides['zeroDescriptor'])) {
                $fields[] = [201, 0x0D, ''];
            }

            return FitFixture::message($number, $fields, $decorate ? [[7, 0, "\xDE\xAD"]] : [], $architecture);
        };
        $event = static fn (int $at, int $type): string => FitFixture::message(21, [
            [253, 0x86, pack($word, $base + $at)],
            [0, $overrides['eventBaseType'] ?? 0, chr($overrides['eventKind'] ?? 0).str_repeat("\x00", ($overrides['eventSize'] ?? 1) - 1)],
            [1, $overrides['eventTypeBaseType'] ?? 0, chr($overrides['eventTypeValue'] ?? $type).str_repeat("\x00", ($overrides['eventTypeSize'] ?? 1) - 1)],
            ...(!isset($overrides['duplicateEvent']) ? [] : [[0, 0x02, "\x00"]]),
            ...(!isset($overrides['duplicateEventType']) ? [] : [[1, 0x02, chr($type)]]),
            [4, 0x02, chr($overrides['group'] ?? 0)],
        ], [], $architecture);
        $lap = $summary(19);
        $session = $summary(18);
        $activity = FitFixture::message(34, [
            [253, 0x86, pack($word, $base + ($overrides['activityTimestamp'] ?? 102))],
            [5, 0x86, pack($word, $base + 3702)],
            [1, 0x84, pack($short, $overrides['numSessions'] ?? 1)],
            ...(!isset($overrides['missingActivityTimer']) ? [[0, 0x86, pack($word, $timer)]] : []),
        ], [], $architecture);
        $stop = isset($overrides['completeTimer']) ? $event($overrides['stop'] ?? 101, 4) : '';
        $data = FitFixture::message(0, [[0, 0, chr($overrides['fileType'] ?? 4)]])
            .(!isset($overrides['noEvents']) && !isset($overrides['deferredEvents']) ? $event($overrides['timerStart'] ?? 0, 0) : '')
            .FitFixture::record($base + ($overrides['firstRecord'] ?? 0))
            .(isset($overrides['pause']) ? $event(50, 4).$event(60, 0) : '')
            .(isset($overrides['sparseRecords']) ? '' : FitFixture::record($base + ($overrides['lastRecord'] ?? 100)))
            .(isset($overrides['missingRecordTimestamp']) ? FitFixture::message(20, [[3, 0x02, "\x75"]]) : '')
            .(isset($overrides['backwardRecord']) ? FitFixture::record($base + 99) : '')
            .(isset($overrides['deferredEvents']) ? $event($overrides['timerStart'] ?? 0, 0) : '')
            .(isset($overrides['stopAfterLap']) ? $lap.$stop : $stop.$lap)
            .(isset($overrides['extraLap']) ? $lap : '')
            .($decorate ? "\x41\x00\x00\x50\xC3\x01\x07\x01\x02".chr(0xA0 | (($base + 106) & 0x1F))."\x2A" : '')
            .$session.(isset($overrides['extraSession']) ? $session : '').$activity;

        return FitFixture::file($data);
    }
}
