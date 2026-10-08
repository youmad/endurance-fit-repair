<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Fit;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\FitRepair\RepairFailed;

/** @internal Emits explicit timestamps and a fresh definition for each message. */
final readonly class PhysicalEncoder
{
    /** @param iterable<PhysicalMessage> $messages */
    public function encode(iterable $messages, int $profileVersion): string
    {
        $data = '';

        foreach ($messages as $message) {
            if (255 < count($message->fields) || 255 < count($message->developerFields)) {
                throw new RepairFailed('Too many fields in a repaired FIT definition.');
            }

            $developer = [] !== $message->developerFields;
            $definition = chr($developer ? 0x60 : 0x40)."\x00".chr($message->architecture->value)
                .pack(FitArchitecture::LittleEndian === $message->architecture ? 'v' : 'n', $message->number)
                .chr(count($message->fields));
            $payload = "\x00";

            foreach ($message->fields as $field) {
                $definition .= chr($field->definition->fieldNumber).chr($field->definition->size).chr($field->definition->baseType->definitionByte());
                $payload .= $field->value->bytes();
            }

            if ($developer) {
                $definition .= chr(count($message->developerFields));

                foreach ($message->developerFields as $field) {
                    $definition .= chr($field->definition->fieldNumber).chr($field->definition->size).chr($field->definition->developerDataIndex);
                    $payload .= $field->value->bytes();
                }
            }

            $data .= $definition.$payload;
        }

        if ('' === $data || 0xFFFFFFFF < strlen($data) || 0 > $profileVersion || 65_535 < $profileVersion) {
            throw new RepairFailed('Invalid repaired FIT size or profile version.');
        }

        $header = "\x0E\x20".pack('vV', $profileVersion, strlen($data)).'.FIT';
        $header .= pack('v', FitCrc16::calculate($header));

        return $header.$data.pack('v', FitCrc16::calculate($header.$data));
    }
}
