<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Corpus;

interface CorpusValidator
{
    public function fingerprint(): string;

    public function profileSha256(): ?string;

    /**
     * @param array<string, string> $files relative corpus path => current physical file
     *
     * @return array<string, array<string, mixed>>
     */
    public function validate(array $files, string $destination): array;
}
