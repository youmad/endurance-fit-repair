<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

/** @internal Unchanged elapsed and active spans exclude the one-second-later Lap start. */
final class RecordedLapEndFixture
{
    /** @param array<string, int> $overrides */
    public static function file(array $overrides = [], int $architecture = 0, bool $decorate = true): string
    {
        return RecordedLapBoundaryFixture::file($overrides + [
            'firstElapsed' => 58900, 'previousTimestamp' => 60,
            'lastStart' => 61, 'lastTimestamp' => 101, 'lastElapsed' => 41400,
        ], $architecture, $decorate);
    }
}
