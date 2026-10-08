<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Copies a missing Session sport from consistent native sport metadata. */
final readonly class RecordedSportRepairer
{
    public const string RULE = 'missing_session_sport_from_native_metadata';
    public const string UNKNOWN_RULE = 'unknown_session_sport_generic_placeholder';

    public function repair(string $bytes, bool $genericPlaceholder = false): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Native sport propagation requires a bounded FIT member.');
        }
        $file = EditableFitFile::read($bytes);
        $sports = $sessions = [];
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (in_array($m->number, [12, 18], true)) {
                $number = 12 === $m->number ? 0 : 5;
                $seen = false;
                foreach ($m->fields as $field) {
                    if ($number !== $field->definition->fieldNumber) {
                        continue;
                    }
                    if ($seen || 1 !== $field->definition->size || !in_array($field->definition->baseType->definitionByte(), [0x00, 0x02], true)) {
                        throw new RepairFailed('Native sport propagation requires a unique scalar enum.');
                    }
                    $seen = true;
                }
            }
            if (12 === $m->number && null !== $m->integer(0)) {
                $sports[] = $m->integer(0);
            } elseif (18 === $m->number) {
                $sessions[$index] = $m;
            }
        }
        if (1 !== count($sessions) || count(array_unique($sports)) > 1 || null !== reset($sessions)->integer(5)
            || ([] === $sports && !$genericPlaceholder)) {
            throw new RepairFailed('One missing Session sport and consistent original sport metadata are required.');
        }
        if ($genericPlaceholder && [] !== $sports) {
            throw new RepairFailed('Native sport metadata takes precedence over an unknown-sport placeholder.');
        }
        $changes = [['message' => array_key_first($sessions), 'field' => 5, 'type' => 0x00, 'value' => $sports[0] ?? 0]];
        $output = $file->apply($changes);

        return new RepairResult($output, ['mode' => $genericPlaceholder ? 'normalize_unknown_sport' : 'preserve', 'rule' => $genericPlaceholder ? self::UNKNOWN_RULE : self::RULE, 'input_sha256' => hash('sha256', $bytes),
            'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'evidence' => ['source_message' => $genericPlaceholder ? null : 12, 'source_field' => $genericPlaceholder ? null : 0,
                'policy' => $genericPlaceholder ? 'Generic is a neutral compatibility placeholder; original sport remains unknown; valid native codes, including unsupported codes, are retained' : 'copy the original native sport; no classification inferred from measurements or filenames'],
            'inference' => ['historical_accuracy' => $genericPlaceholder ? 'not_proven' : 'native_sport_copied', 'sport_source' => $genericPlaceholder ? 'generic_placeholder_original_sport_unknown' : 'native_sport'],
            'activity_validation' => 'not_performed']);
    }
}
