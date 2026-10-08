<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Fit;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\RawRecordStreamReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\FitFileHeader;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\Fit\Raw\RawDefinitionMessage;
use Youmad\Endurance\FitRepair\RepairFailed;

/** @internal Edits selected fields while retaining all other physical records. */
final readonly class EditableFitFile
{
    /** @param list<array{raw: RawDataRecord, offset: int, length: int, definition: string}> $messages */
    private function __construct(public string $bytes, public FitFileHeader $header, public array $messages)
    {
    }

    public static function read(string $bytes): self
    {
        if ('' === $bytes || 12 > ord($bytes[0]) || strlen($bytes) < ord($bytes[0])) {
            throw new RepairFailed('A complete FIT header at byte zero is required.');
        }

        $header = FitFileHeader::fromBytes(0, substr($bytes, 0, ord($bytes[0])));
        $end = $header->headerSize + $header->dataSize;

        if (2 < $header->protocolMajorVersion() || strlen($bytes) !== $end + 2) {
            throw new RepairFailed('Preserving repair requires one complete FIT member without padding or trailing bytes.');
        }

        if ((null !== $header->headerCrc && 0 !== $header->headerCrc && $header->headerCrc !== FitCrc16::calculate(substr($bytes, 0, 12)))
            || pack('v', FitCrc16::calculate(substr($bytes, 0, $end))) !== substr($bytes, $end, 2)) {
            throw new RepairFailed('Preserving repair requires valid source CRCs.');
        }

        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate FIT input.');
        }

        try {
            if ($header->dataSize !== fwrite($stream, substr($bytes, $header->headerSize, $header->dataSize)) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill FIT input.');
            }

            $messages = [];
            $definitions = [];
            $input = new ResourceFitInput($stream);

            foreach ((new RawRecordStreamReader())->read($input, $header->dataSize) as $raw) {
                $offset = $header->headerSize + $raw->byteOffset();
                $length = $input->position() - $raw->byteOffset();

                if ($raw instanceof RawDefinitionMessage) {
                    $definitions[$raw->definition->localMessageNumber] = substr($bytes, $offset, $length);
                } elseif ($raw instanceof RawDataRecord) {
                    $messages[] = ['raw' => $raw, 'offset' => $offset, 'length' => $length, 'definition' => $definitions[$raw->definition()->localMessageNumber]];
                }
            }

            return new self($bytes, $header, $messages);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param list<array{message: int, field: int, type: int, value: int}> $changes
     */
    public function apply(array $changes): string
    {
        if ([] === $changes) {
            return $this->bytes;
        }

        $byMessage = [];

        foreach ($changes as $change) {
            if (!isset($this->messages[$change['message']]) || 0 > $change['field'] || 255 < $change['field']
                || isset($byMessage[$change['message']][$change['field']])) {
                throw new RepairFailed('Invalid or duplicate FIT field edit.');
            }

            $byMessage[$change['message']][$change['field']] = $change;
        }

        $edits = [];

        foreach ($byMessage as $index => $fields) {
            $message = $this->messages[$index];
            $raw = $message['raw'];

            if ($raw instanceof RawCompressedTimestampDataMessage) {
                throw new RepairFailed('Editing compressed summary messages is unsupported.');
            }

            $little = FitArchitecture::LittleEndian === $raw->definition()->architecture;
            $payload = substr($this->bytes, $message['offset'], $message['length']);
            $position = 1;
            $existing = [];

            foreach ($raw->standardFields() as $field) {
                $number = $field->definition->fieldNumber;

                if (isset($fields[$number])) {
                    if (isset($existing[$number])) {
                        throw new RepairFailed('Cannot edit a duplicated physical field.');
                    }

                    $value = $this->encodeValue($fields[$number], $little);

                    if ($field->definition->size !== strlen($value) || $field->definition->baseType->definitionByte() !== $fields[$number]['type']) {
                        throw new RepairFailed('Cannot replace a field with a different physical type or size.');
                    }

                    $payload = substr_replace($payload, $value, $position, strlen($value));
                    $existing[$number] = true;
                }

                $position += $field->definition->size;
            }

            $descriptors = '';
            $values = '';
            $count = count($raw->standardFields());
            $added = 0;

            foreach ($fields as $number => $change) {
                if (isset($existing[$number])) {
                    continue;
                }

                $value = $this->encodeValue($change, $little);
                $descriptors .= chr($number).chr(strlen($value)).chr($change['type']);
                $values .= $value;
                ++$added;
            }

            if (255 < $count + $added) {
                throw new RepairFailed('Cannot append fields to a full definition.');
            }

            if (0 < $added) {
                $definition = $message['definition'];
                $newDefinition = substr($definition, 0, 5).chr($count + $added)
                    .substr($definition, 6, 3 * $count).$descriptors
                    .substr($definition, 6 + 3 * $count);
                $payload = $newDefinition.substr($payload, 0, $position).$values.substr($payload, $position).$definition;
            }

            $edits[] = ['offset' => $message['offset'], 'length' => $message['length'], 'bytes' => $payload];
        }

        usort($edits, static fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);
        $data = substr($this->bytes, $this->header->headerSize, $this->header->dataSize);
        $previous = strlen($this->bytes);

        foreach ($edits as $edit) {
            if ($edit['offset'] + $edit['length'] > $previous) {
                throw new RepairFailed('Overlapping FIT edits.');
            }

            $data = substr_replace($data, $edit['bytes'], $edit['offset'] - $this->header->headerSize, $edit['length']);
            $previous = $edit['offset'];
        }

        $bytes = $this->withData($data);
        $this->assertPreserved(self::read($bytes), $changes);

        return $bytes;
    }

    /** Appends a synthetic message without disturbing compressed timestamp reconstruction. */
    public function append(PhysicalMessage $addition): string
    {
        $encoded = self::read((new PhysicalEncoder())->encode([$addition], $this->header->profileVersion));
        $data = substr($this->bytes, $this->header->headerSize, $this->header->dataSize)
            .substr($encoded->bytes, $encoded->header->headerSize, $encoded->header->dataSize);
        $bytes = $this->withData($data);
        $output = self::read($bytes);
        $kept = $output->messages;
        $inserted = array_pop($kept);
        $expected = $encoded->messages[0]['raw'];
        if (null === $inserted || count($kept) !== count($this->messages)
            || $inserted['raw']->globalMessageNumber() !== $expected->globalMessageNumber()
            || $inserted['raw']->definition()->architecture !== $expected->definition()->architecture
            || $this->standardValues($inserted['raw']) !== $this->standardValues($expected)
            || $this->developerValues($inserted['raw']) !== $this->developerValues($expected)) {
            throw new RepairFailed('The appended message differs from the planned synthetic message.');
        }
        $this->assertPreserved(new self($output->bytes, $output->header, $kept), []);

        return $bytes;
    }

    /** Inserts a synthetic message and verifies every existing physical message. */
    public function insertBefore(int $index, PhysicalMessage $addition): string
    {
        return $this->insertManyBefore($index, [$addition]);
    }

    /** @param non-empty-list<PhysicalMessage> $additions */
    public function insertManyBefore(int $index, array $additions): string
    {
        if (!isset($this->messages[$index]) || $this->messages[$index]['raw'] instanceof RawCompressedTimestampDataMessage) {
            throw new RepairFailed('Insertion requires an existing uncompressed target message.');
        }

        $target = $this->messages[$index];
        $local = $target['raw']->definition()->localMessageNumber;
        // Encode only the new messages; existing records are never re-encoded.
        $encoded = self::read((new PhysicalEncoder())->encode($additions, $this->header->profileVersion));
        $packet = '';

        foreach ($encoded->messages as $entry) {
            $definitionSize = strlen($entry['definition']);
            $part = substr($encoded->bytes, $entry['offset'] - $definitionSize, $definitionSize + $entry['length']);
            $part[0] = chr(ord($part[0]) | $local);
            $part[$definitionSize] = chr($local);
            $packet .= $part;
        }
        // Restore the active definition before the original target data.
        $packet .= $target['definition'];
        $data = substr($this->bytes, $this->header->headerSize, $this->header->dataSize);
        $bytes = $this->withData(substr_replace($data, $packet, $target['offset'] - $this->header->headerSize, 0));
        $output = self::read($bytes);

        if (count($output->messages) !== count($this->messages) + count($additions)) {
            throw new RepairFailed('Insertion changed the original message count.');
        }

        $kept = $output->messages;
        $inserted = array_splice($kept, $index, count($additions));

        foreach ($inserted as $ordinal => $entry) {
            $actual = $entry['raw'];
            $expected = $encoded->messages[$ordinal]['raw'];

            if ($actual->recordHeaderByte() !== $local || $actual->globalMessageNumber() !== $expected->globalMessageNumber()
                || $actual->definition()->architecture !== $expected->definition()->architecture
                || $this->standardValues($actual) !== $this->standardValues($expected)
                || $this->developerValues($actual) !== $this->developerValues($expected)) {
                throw new RepairFailed('The inserted message differs from the planned synthetic message.');
            }
        }

        $this->assertPreserved(new self($output->bytes, $output->header, $kept), []);

        return $bytes;
    }

    /** Moves one data message to the front and verifies all original physical values. */
    public function moveToFront(int $index): string
    {
        if (!isset($this->messages[$index]) || $this->messages[$index]['raw'] instanceof RawCompressedTimestampDataMessage) {
            throw new RepairFailed('Moving requires an existing uncompressed message.');
        }

        if (0 === $index) {
            return $this->bytes;
        }

        $entry = $this->messages[$index];
        $payload = substr($this->bytes, $entry['offset'], $entry['length']);
        $data = substr($this->bytes, $this->header->headerSize, $this->header->dataSize);
        // Leave original definitions in place; prepend the moved message's definition.
        $data = $entry['definition'].$payload.substr_replace($data, '', $entry['offset'] - $this->header->headerSize, $entry['length']);
        $bytes = $this->withData($data);
        $expected = $this->messages;
        $moved = array_splice($expected, $index, 1)[0];
        array_unshift($expected, $moved);
        (new self($this->bytes, $this->header, $expected))->assertPreserved(self::read($bytes), []);

        return $bytes;
    }

    /** @param array<int, int> $order target data-message index => original data-message index */
    public function reorderRecords(array $order): string
    {
        if ([] === $order) {
            return $this->bytes;
        }

        $targets = array_keys($order);
        $sources = array_values($order);
        sort($targets);
        sort($sources);

        if ($targets !== $sources) {
            throw new RepairFailed('Record reordering must be a permutation of the same existing slots.');
        }

        foreach ($this->messages as $entry) {
            if ($entry['raw'] instanceof RawCompressedTimestampDataMessage) {
                throw new RepairFailed('Record reordering cannot change compressed timestamp context.');
            }
        }

        $data = substr($this->bytes, $this->header->headerSize, $this->header->dataSize);
        $expected = $this->messages;

        foreach ($order as $target => $source) {
            if (!isset($this->messages[$target], $this->messages[$source])) {
                throw new RepairFailed('Record reordering references a nonexistent message.');
            }

            $before = $this->messages[$target];
            $incoming = $this->messages[$source];

            if (20 !== $before['raw']->globalMessageNumber() || 20 !== $incoming['raw']->globalMessageNumber()
                || $before['definition'] !== $incoming['definition'] || $before['length'] !== $incoming['length']) {
                throw new RepairFailed('Reordered Records must share the exact active physical definition.');
            }

            $data = substr_replace($data, substr($this->bytes, $incoming['offset'], $incoming['length']),
                $before['offset'] - $this->header->headerSize, $before['length']);
            $expected[$target] = $incoming;
        }

        $bytes = $this->withData($data);
        (new self($this->bytes, $this->header, $expected))->assertPreserved(self::read($bytes), []);

        return $bytes;
    }

    private function withData(string $data): string
    {
        $header = substr_replace($this->header->rawBytes(), pack('V', strlen($data)), 4, 4);

        if (14 === $this->header->headerSize) {
            $header = substr_replace($header, pack('v', FitCrc16::calculate(substr($header, 0, 12))), 12, 2);
        }

        return $header.$data.pack('v', FitCrc16::calculate($header.$data));
    }

    /** @return list<array{int, int, int, string}> */
    private function standardValues(RawDataRecord $message): array
    {
        $values = [];

        foreach ($message->standardFields() as $field) {
            $values[] = [$field->definition->fieldNumber, $field->definition->size, $field->definition->baseType->definitionByte(), $field->value->bytes()];
        }

        return $values;
    }

    /** @param array{message: int, field: int, type: int, value: int} $change */
    private function encodeValue(array $change, bool $little): string
    {
        return match ($change['type']) {
            0x00 => 0 <= $change['value'] && 254 >= $change['value'] ? chr($change['value']) : throw new RepairFailed('Enum out of range.'),
            0x84 => 0 <= $change['value'] && 65_534 >= $change['value'] ? pack($little ? 'v' : 'n', $change['value']) : throw new RepairFailed('Index out of range.'),
            0x86 => 0 <= $change['value'] && 0xFFFFFFFE >= $change['value'] ? pack($little ? 'V' : 'N', $change['value']) : throw new RepairFailed('Timestamp out of range.'),
            default => throw new RepairFailed('Unsupported preserving field type.'),
        };
    }

    /** @param list<array{message: int, field: int, type: int, value: int}> $changes */
    private function assertPreserved(self $output, array $changes): void
    {
        if (count($this->messages) !== count($output->messages)) {
            throw new RepairFailed('Preservation check: message count changed.');
        }

        $allowed = [];

        foreach ($changes as $change) {
            $allowed[$change['message']][$change['field']] = $change;
        }

        foreach ($this->messages as $index => $input) {
            $before = $input['raw'];
            $after = $output->messages[$index]['raw'];
            $fields = [];

            foreach ($before->standardFields() as $field) {
                if (!isset($allowed[$index][$field->definition->fieldNumber])) {
                    $fields[] = [$field->definition->fieldNumber, $field->definition->size, $field->definition->baseType->definitionByte(), $field->value->bytes()];
                }
            }

            $outputFields = [];

            foreach ($after->standardFields() as $field) {
                if (!isset($allowed[$index][$field->definition->fieldNumber])) {
                    $outputFields[] = [$field->definition->fieldNumber, $field->definition->size, $field->definition->baseType->definitionByte(), $field->value->bytes()];
                }
            }

            if ($before->globalMessageNumber() !== $after->globalMessageNumber()
                || $before->recordHeaderByte() !== $after->recordHeaderByte()
                || $before->definition()->architecture !== $after->definition()->architecture
                || $fields !== $outputFields
                || $this->developerValues($before) !== $this->developerValues($after)
                || ($before instanceof RawCompressedTimestampDataMessage && (!$after instanceof RawCompressedTimestampDataMessage || $before->reconstructedTimestamp !== $after->reconstructedTimestamp))) {
                throw new RepairFailed('Preservation check failed at data message '.$index.'.');
            }

            foreach ($allowed[$index] ?? [] as $field => $change) {
                if ($change['value'] !== PhysicalMessage::fromRaw($after, $output->header->headerSize)->integer($field)) {
                    throw new RepairFailed('Preservation check: planned value was not written.');
                }
            }
        }
    }

    /** @return list<array{int, int, int, string}> */
    private function developerValues(RawDataRecord $message): array
    {
        $values = [];

        foreach ($message->developerFields() as $field) {
            $values[] = [$field->definition->fieldNumber, $field->definition->size, $field->definition->developerDataIndex, $field->value->bytes()];
        }

        return $values;
    }
}
