<?php

declare(strict_types=1);

namespace Endurance\FitRepair\Internal\Fit;

use Endurance\Fit\Decoded\DecodedFieldElements;
use Endurance\Fit\Decoded\ValidFieldElement;
use Endurance\Fit\Decoder\FitBaseTypeDecoder;
use Endurance\Fit\Raw\FitArchitecture;
use Endurance\Fit\Raw\FitBaseType;
use Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Endurance\Fit\Raw\RawDataRecord;
use Endurance\Fit\Raw\RawDeveloperField;
use Endurance\Fit\Raw\RawFieldValue;
use Endurance\Fit\Raw\RawStandardField;
use Endurance\Fit\Raw\StandardFieldDefinition;
use Endurance\FitRepair\RepairFailed;

/** @internal Physical fields only; profile expansion is never serialized. */
final readonly class PhysicalMessage
{
    /**
     * @param list<RawStandardField>  $fields
     * @param list<RawDeveloperField> $developerFields
     */
    public function __construct(
        public int $number,
        public FitArchitecture $architecture = FitArchitecture::LittleEndian,
        public array $fields = [],
        public array $developerFields = [],
        public ?int $sourceSequence = null,
        public ?int $sourceOffset = null,
        public bool $wasCompressed = false,
    ) {
    }

    public static function fromRaw(RawDataRecord $raw, int $headerSize): self
    {
        $message = new self(
            $raw->globalMessageNumber(),
            $raw->definition()->architecture,
            $raw->standardFields(),
            $raw->developerFields(),
            $raw->sequence(),
            $headerSize + $raw->byteOffset(),
            $raw instanceof RawCompressedTimestampDataMessage,
        );

        if ($raw instanceof RawCompressedTimestampDataMessage) {
            $message = $message->withField(new RawStandardField(
                StandardFieldDefinition::create(253, 4, FitBaseType::fromDefinitionByte(0x86)),
                RawFieldValue::fromBytes(pack(FitArchitecture::LittleEndian === $message->architecture ? 'V' : 'N', $raw->reconstructedTimestamp)),
            ));
        }

        return $message;
    }

    public function integer(int $number): ?int
    {
        foreach ($this->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }

            $decoded = (new FitBaseTypeDecoder())->decode(
                $field->definition->baseType,
                $this->architecture,
                $field->value,
            );

            if (!$decoded instanceof DecodedFieldElements) {
                return null;
            }

            $elements = $decoded->elements();
            $element = $elements[0];

            return 1 === count($elements) && $element instanceof ValidFieldElement && is_int($element->value)
                ? $element->value
                : null;
        }

        return null;
    }

    public function withInteger(int $number, int $type, int $value): self
    {
        $little = FitArchitecture::LittleEndian === $this->architecture;
        $bytes = match ($type) {
            0x00, 0x02 => 0 <= $value && 254 >= $value ? chr($value) : throw new RepairFailed('FIT enum/uint8 value is out of range.'),
            0x84 => 0 <= $value && 65_534 >= $value ? pack($little ? 'v' : 'n', $value) : throw new RepairFailed('FIT uint16 value is out of range.'),
            0x86 => 0 <= $value && 0xFFFFFFFE >= $value ? pack($little ? 'V' : 'N', $value) : throw new RepairFailed('FIT uint32 value is out of range.'),
            default => throw new RepairFailed('Unsupported generated FIT field type.'),
        };

        return $this->withField(new RawStandardField(
            StandardFieldDefinition::create($number, strlen($bytes), FitBaseType::fromDefinitionByte($type)),
            RawFieldValue::fromBytes($bytes),
        ));
    }

    private function withField(RawStandardField $replacement): self
    {
        $fields = [];

        foreach ($this->fields as $field) {
            if ($field->definition->fieldNumber !== $replacement->definition->fieldNumber) {
                $fields[] = $field;
            }
        }

        $fields[] = $replacement;

        return new self($this->number, $this->architecture, $fields, $this->developerFields, $this->sourceSequence, $this->sourceOffset, $this->wasCompressed);
    }
}
