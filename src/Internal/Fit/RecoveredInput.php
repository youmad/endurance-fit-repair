<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Fit;

use Youmad\Endurance\Fit\Raw\FitFileHeader;

/** @internal */
final readonly class RecoveredInput
{
    /**
     * @param list<PhysicalMessage> $messages
     * @param list<string>          $issues
     */
    public function __construct(
        public FitFileHeader $header,
        public array $messages,
        public array $issues,
        public int $availableDataBytes,
        public int $completeDataBytes,
    ) {
    }
}
