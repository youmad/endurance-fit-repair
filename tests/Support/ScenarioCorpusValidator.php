<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

use Youmad\Endurance\FitRepair\Corpus\CorpusValidator;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;

/** Test oracle with successive missing-index and reference failures in real encoded FIT files. */
final class ScenarioCorpusValidator implements CorpusValidator
{
    /** @var list<array<string, string>> */
    public array $batches = [];
    public ?int $interruptOnCall = null;

    public function __construct(public string $version = 'one')
    {
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->version);
    }

    public function profileSha256(): ?string
    {
        return null;
    }

    public function validate(array $files, string $destination): array
    {
        $this->batches[] = $files;

        if (count($this->batches) === $this->interruptOnCall) {
            throw new \RuntimeException('Simulated interruption.');
        }

        $results = [];

        foreach ($files as $path => $file) {
            $bytes = file_get_contents($file);

            if (false === $bytes) {
                throw new \RuntimeException('Cannot read fixture.');
            }

            $results[$path] = self::result($path, $bytes);
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public static function result(string $path, string $bytes): array
    {
        $file = EditableFitFile::read($bytes);
        $missing = [];
        $missingReferences = false;
        $sessions = 0;
        $laps = 0;
        $firstNonPad = null;
        $hasFileId = false;

        foreach ($file->messages as $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);
            if (105 !== $message->number && null === $firstNonPad) {
                $firstNonPad = $message->number;
            }
            $hasFileId = $hasFileId || 0 === $message->number;
            $sessions += 18 === $message->number ? 1 : 0;
            $laps += 19 === $message->number ? 1 : 0;

            if (in_array($message->number, [18, 19], true) && null === $message->integer(254)) {
                $missing[$message->number] = true;
            }

            if (18 === $message->number && (null === $message->integer(25) || null === $message->integer(26))) {
                $missingReferences = true;
            }
        }

        $wrongFileIdOrder = $hasFileId && 0 !== $firstNonPad;
        $missingLaps = 0 < $sessions && 0 === $laps;
        $result = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'status' => [] === $missing && !$missingReferences && !$missingLaps && !$wrongFileIdOrder ? 'PASS' : 'FAILED'];

        if ($wrongFileIdOrder) {
            $result['errors'] = [['class' => 'TestValidationFailure', 'message' => 'FIT message failed Garmin required check "FileId Message Is First": a helper message precedes file_id.']];
        } elseif ($missingLaps) {
            $result['errors'] = [['class' => 'TestValidationFailure', 'message' => 'FIT message failed Garmin required check "Lap Message Exists": no Laps.']];
        } elseif ([] !== $missing) {
            $check = isset($missing[18]) ? 'Session' : 'Lap';
            $result['errors'] = [['class' => 'TestValidationFailure', 'message' => 'FIT message failed Garmin required check "'.$check.' Message Valid Message Index": expected message_index=0; got missing.']];
        } elseif ($missingReferences) {
            $result['errors'] = [['class' => 'TestValidationFailure', 'message' => 'FIT message failed Garmin required check "Session Message First Lap Index and Num Laps are Valid": missing references.']];
        }

        return $result;
    }
}
