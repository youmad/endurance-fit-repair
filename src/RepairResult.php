<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair;

final readonly class RepairResult
{
    /** @param array<string, mixed> $report */
    public function __construct(public string $bytes, public array $report)
    {
    }

    public function reportJson(): string
    {
        return json_encode($this->report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }
}
