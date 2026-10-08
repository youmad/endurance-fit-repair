<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

final class OneBasedLapIndexFixture
{
    public const int START = 0x40000020;

    /** @param array<string, int> $overrides */
    public static function file(array $overrides = [], int $architecture = 0, bool $decorate = true): string
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $count = $overrides['laps'] ?? 2;
        $data = FitFixture::message(0, [[0, 0, chr($overrides['fileType'] ?? 4)]])
            .FitFixture::record(self::START + ($overrides['recordStart'] ?? 0));

        for ($index = 0; $index < $count; ++$index) {
            $data .= FitFixture::record(self::START + 100 * ($index + 1));
            $fields = [[253, 0x86, pack($word, self::START + 100 * ($index + 1))],
                [2, 0x86, pack($word, self::START + 100 * $index)], [7, 0x86, pack($word, 100000)], [8, 0x86, pack($word, 100000)]];

            if (!isset($overrides['missingLapIndex'])) {
                $fields[] = [254, $overrides['lapIndexType'] ?? 0x84,
                    pack($short, $overrides['lapIndex'] ?? $index + 1).(isset($overrides['lapIndexArray']) ? pack($short, 2) : '')];
            }

            if (isset($overrides['duplicateLapIndex'])) {
                $fields[] = [254, 0x84, pack($short, $index + 1)];
            }

            if (isset($overrides['zeroLapIndex'])) {
                $fields[] = [254, 0x84, ''];
            }

            if ($decorate) {
                $fields[] = [200, 0x0D, "\xAA\xBB\xCC"];
            }

            $lap = FitFixture::message(19, $fields, $decorate ? [[7, 0, "\xDE\xAD"]] : [], $architecture);

            if (isset($overrides['compressedLap'])) {
                $lap = substr_replace($lap, chr(0x80 | ((self::START + 100 * ($index + 1)) & 0x1F)), 6 + 3 * count($fields) + ($decorate ? 4 : 0), 1);
            }

            $data .= $lap;
        }

        $fields = [[253, 0x86, pack($word, self::START + 100 * $count)], [2, 0x86, pack($word, self::START)],
            [7, 0x86, pack($word, $count * 100000)], [8, 0x86, pack($word, $count * 100000)], [5, 0, "\x02"]];

        if (!isset($overrides['absentSessionIndex'])) {
            $fields[] = [254, 0x84, pack($short, $overrides['sessionIndex'] ?? 0xFFFF)];
        }

        if (!isset($overrides['missingFirst'])) {
            $fields[] = [25, $overrides['firstType'] ?? 0x84, pack($short, $overrides['first'] ?? 1)];
        }

        if (!isset($overrides['missingCount'])) {
            $fields[] = [26, 0x84, pack($short, $overrides['numLaps'] ?? $count)];
        }

        if (!isset($overrides['absentBest'])) {
            $fields[] = [70, $overrides['bestType'] ?? 0x84, pack($short, $overrides['best'] ?? $count)];
        }

        if (isset($overrides['duplicateFirst'])) {
            $fields[] = [25, 0x84, pack($short, 1)];
        }

        $session = FitFixture::message(18, $fields, $decorate ? [[8, 0, "\xCA\xFE"]] : [], $architecture);
        $data .= $session.(isset($overrides['multipleSessions']) ? $session : '');

        if ($decorate) {
            $data .= "\x41\x00\x00\x50\xC3\x01\x07\x01\x02".chr(0xA0 | ((self::START + 100 * $count + 1) & 0x1F))."\x2A";
        }

        $activity = FitFixture::message(34, [[253, 0x86, pack($word, self::START + 100 * $count)],
            [5, 0x86, pack($word, self::START + 100 * $count + 3600)], [0, 0x86, pack($word, $count * 100000)],
            [1, 0x84, pack($short, $overrides['numSessions'] ?? 1)]], architecture: $architecture);

        return FitFixture::file($data.$activity.(isset($overrides['multipleActivities']) ? $activity : ''));
    }
}
