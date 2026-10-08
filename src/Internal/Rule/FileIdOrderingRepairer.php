<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** @internal Relocates the sole Activity file_id without changing physical fields. */
final readonly class FileIdOrderingRepairer
{
    public function repair(EditableFitFile $file): RepairResult
    {
        $first = null;
        $fileIds = [];

        foreach ($file->messages as $index => $entry) {
            $number = $entry['raw']->globalMessageNumber();

            if (105 !== $number && null === $first) {
                $first = $number;
            }

            if (0 === $number) {
                $fileIds[] = $index;
            }
        }

        if (0 === $first) {
            return $this->result($file, $file->bytes, []);
        }

        if (1 !== count($fileIds)) {
            throw new RepairFailed('File ID ordering repair requires exactly one existing Activity file_id.');
        }

        $index = $fileIds[0];
        $raw = $file->messages[$index]['raw'];
        if ([] !== $raw->developerFields()) {
            throw new RepairFailed('A file_id with developer fields cannot be moved ahead of its metadata dependencies.');
        }

        $message = PhysicalMessage::fromRaw($raw, $file->header->headerSize);
        $types = [];

        foreach ($message->fields as $field) {
            if (0 === $field->definition->fieldNumber) {
                $types[] = $field->definition;
            }
        }

        // Enum and uint8 use the same one-byte value and 0xFF invalid sentinel.
        if (1 !== count($types) || !in_array($types[0]->baseType->definitionByte(), [0x00, 0x02], true) || 1 !== $types[0]->size || 4 !== $message->integer(0)) {
            throw new RepairFailed('The sole file_id must contain one valid one-byte Activity type enum or uint8.');
        }

        $bytes = $file->moveToFront($index);

        return $this->result($file, $bytes, [[
            'globalMessageNumber' => 0,
            'fromOriginalMessage' => $index,
            'toMessage' => 0,
            'sourceSequence' => $message->sourceSequence,
            'sourceByteOffset' => $message->sourceOffset,
            'basis' => 'sole_activity_file_id',
        ]]);
    }

    /** @param list<array<string, mixed>> $moves */
    private function result(EditableFitFile $source, string $bytes, array $moves): RepairResult
    {
        return new RepairResult($bytes, [
            'mode' => 'preserve',
            'rule' => PreservingActivityRepairer::FILE_ID_FIRST,
            'input_sha256' => hash('sha256', $source->bytes),
            'output_sha256' => hash('sha256', $bytes),
            'changes' => [],
            'moved_messages' => $moves,
            'preservation' => 'verified',
            'activity_validation' => 'not_performed',
        ]);
    }
}
