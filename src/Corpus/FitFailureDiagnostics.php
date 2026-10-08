<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Corpus;

use Youmad\Endurance\Fit\Decoded\DecodedFieldElements;
use Youmad\Endurance\Fit\Decoded\ValidFieldElement;
use Youmad\Endurance\Fit\Decoder\FitBaseTypeDecoder;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\Exception\FitDecodeException;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;

/** @internal Read-only, bounded evidence for planning repair rules across a corpus. */
final readonly class FitFailureDiagnostics
{
    private const int SUMMARY_LIMIT = 128;
    private const int TIMER_LIMIT = 256;
    private const int DETAIL_LIMIT = 512;

    /** @var array<string, array{number: int, fields: array<string, int>}> */
    private const array TABLES = [
        'fileIds' => ['number' => 0, 'fields' => ['type' => 0, 'manufacturer' => 1, 'product' => 2, 'time_created' => 4]],
        'deviceSettings' => ['number' => 2, 'fields' => ['active_time_zone' => 0, 'utc_offset' => 1, 'time_offset' => 2, 'time_zone_offset_quarter_hours' => 5]],
        'sessions' => ['number' => 18, 'fields' => ['message_index' => 254, 'timestamp' => 253, 'start_time' => 2, 'total_elapsed_time_ms' => 7, 'total_timer_time_ms' => 8, 'total_distance_cm' => 9, 'first_lap_index' => 25, 'num_laps' => 26, 'sport' => 5, 'sub_sport' => 6]],
        'laps' => ['number' => 19, 'fields' => ['message_index' => 254, 'timestamp' => 253, 'start_time' => 2, 'total_elapsed_time_ms' => 7, 'total_timer_time_ms' => 8, 'total_distance_cm' => 9, 'sport' => 25, 'sub_sport' => 39]],
        'activities' => ['number' => 34, 'fields' => ['timestamp' => 253, 'local_timestamp' => 5, 'total_timer_time_ms' => 0, 'num_sessions' => 1]],
        'timerEvents' => ['number' => 21, 'fields' => ['timestamp' => 253, 'event_type' => 1, 'event_group' => 4, 'data' => 3]],
        'sets' => ['number' => 225, 'fields' => ['message_index' => 10, 'timestamp' => 254, 'start_time' => 6, 'duration_ms' => 0, 'set_type' => 5, 'wkt_step_index' => 11]],
        'lengths' => ['number' => 101, 'fields' => ['message_index' => 254, 'timestamp' => 253, 'start_time' => 2, 'total_elapsed_time_ms' => 3, 'total_timer_time_ms' => 4, 'event' => 0, 'event_type' => 1, 'event_group' => 10, 'length_type' => 12]],
    ];

    public function fingerprint(): string
    {
        return hash('sha256', PHP_VERSION.':'.hash_file('sha256', __FILE__).':'.hash_file('sha256', dirname(__DIR__).'/Internal/Fit/PhysicalMessage.php'));
    }

    /**
     * @param callable(string): void|null $progress
     *
     * @return array<string, mixed>
     */
    public function collect(string $path, string $sha256, ?callable $progress = null, ?int $focusSequence = null): array
    {
        if (hash_file('sha256', $path) !== $sha256) {
            throw new RepairFailed('Current FIT bytes changed before diagnostic collection.');
        }

        $stream = fopen($path, 'rb');

        if (false === $stream) {
            throw new RepairFailed('Cannot open FIT diagnostics input.');
        }

        $tables = [];
        $counts = [];
        $columns = [];

        foreach (self::TABLES as $name => $table) {
            $tables[$name] = [];
            $counts[$name] = 0;
            $columns[$name] = ['sequence', 'byteOffset', ...array_keys($table['fields'])];
        }

        $messages = [];
        $records = ['count' => 0, 'missingTimestamp' => 0, 'backwardTimestamp' => 0, 'first' => null, 'last' => null, 'minimum' => null, 'maximum' => null];
        $totals = [];
        $eventTypes = [];
        $previous = null;
        $dataCount = 0;
        $missingEvent = 0;
        $reason = null;
        $focus = null;
        $previousRows = [];
        $physicalRows = array_fill_keys(array_keys(self::TABLES), []);
        $previousPhysicalRows = [];
        $physicalFocus = null;

        try {
            $stat = fstat($stream);
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));

            if (false === $stat || 67_108_864 < $stat['size'] || 0 !== $file->header->byteOffset
                || $stat['size'] !== $file->header->headerSize + $file->header->dataSize + 2) {
                throw new RepairFailed('Detailed diagnostics require one complete FIT member at byte zero, up to 64 MiB.');
            }

            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }

                ++$dataCount;
                $number = $raw->globalMessageNumber();
                $messages[(string) $number] = ($messages[(string) $number] ?? 0) + 1;
                $message = PhysicalMessage::fromRaw($raw, $file->header->headerSize);

                if (20 === $number) {
                    $timestamp = $message->integer(253);
                    $point = [$message->sourceSequence, $message->sourceOffset, $timestamp];
                    $records['first'] ??= $point;
                    $records['last'] = $point;
                    ++$records['count'];

                    if (null === $timestamp) {
                        ++$records['missingTimestamp'];
                    } else {
                        if (null !== $previous && $timestamp < $previous) {
                            ++$records['backwardTimestamp'];
                        }

                        if (null === $records['minimum'] || $timestamp < $records['minimum'][2]) {
                            $records['minimum'] = $point;
                        }

                        if (null === $records['maximum'] || $timestamp > $records['maximum'][2]) {
                            $records['maximum'] = $point;
                        }

                        $previous = $timestamp;
                    }
                }

                if (21 === $number && null === $message->integer(0)) {
                    ++$missingEvent;
                }

                foreach (self::TABLES as $name => $table) {
                    if ($number !== $table['number'] || ('timerEvents' === $name && 0 !== $message->integer(0))) {
                        continue;
                    }

                    ++$counts[$name];
                    $limit = match ($name) {
                        'timerEvents' => self::TIMER_LIMIT,
                        'sets', 'lengths' => self::DETAIL_LIMIT,
                        default => self::SUMMARY_LIMIT,
                    };

                    $row = [$message->sourceSequence, $message->sourceOffset];

                    foreach ($table['fields'] as $field) {
                        $row[] = 'deviceSettings' === $name ? $this->integers($message, $field) : $message->integer($field);
                    }

                    if ($message->sourceSequence === $focusSequence) {
                        $focus = ['table' => $name, 'current' => $row, 'previous' => $previousRows[$name] ?? null];
                    }

                    $physical = null;

                    if ($counts[$name] <= $limit || null !== $focusSequence) {
                        $physical = $this->physicalRow($raw, $file->header->headerSize, [
                            ...array_values($table['fields']), ...('timerEvents' === $name ? [0] : []), ...('sessions' === $name ? [70] : []),
                        ]);
                    }

                    if ($message->sourceSequence === $focusSequence) {
                        $physicalFocus = ['table' => $name, 'current' => $physical, 'previous' => $previousPhysicalRows[$name] ?? null];
                    }

                    if ($counts[$name] <= $limit) {
                        $tables[$name][] = $row;
                        $physicalRows[$name][] = $physical;
                    }

                    // One rolling row per table retains the predecessor even
                    // when the failure lies beyond the diagnostic row limit.
                    $previousRows[$name] = $row;
                    $previousPhysicalRows[$name] = $physical;

                    if (in_array($name, ['sessions', 'laps'], true)) {
                        foreach (['total_elapsed_time_ms' => 7, 'total_timer_time_ms' => 8] as $key => $field) {
                            $value = $message->integer($field);
                            $totals[$name][$key]['sum'] = ($totals[$name][$key]['sum'] ?? 0) + ($value ?? 0);
                            $totals[$name][$key]['missing'] = ($totals[$name][$key]['missing'] ?? 0) + (null === $value ? 1 : 0);
                        }
                    }

                    if ('timerEvents' === $name) {
                        $type = (string) ($message->integer(1) ?? 'missing');
                        $eventTypes[$type] = ($eventTypes[$type] ?? 0) + 1;
                    }
                }

                if (null !== $progress && 0 === $dataCount % 100_000) {
                    $progress($dataCount.' messages scanned.');
                }
            }

            $file->trailer();
        } catch (FitDecodeException|RepairFailed|\InvalidArgumentException $failure) {
            $reason = $failure->getMessage();
        } finally {
            fclose($stream);
        }

        if (hash_file('sha256', $path) !== $sha256) {
            throw new RepairFailed('Current FIT bytes changed during diagnostic collection.');
        }

        if (null !== $reason) {
            return ['schemaVersion' => 1, 'sha256' => $sha256, 'status' => 'unavailable', 'reason' => $reason];
        }

        $truncated = [];

        foreach ($counts as $name => $count) {
            if ($count > count($tables[$name])) {
                $truncated[$name] = $count - count($tables[$name]);
            }
        }

        ksort($messages, SORT_NUMERIC);

        return [
            'schemaVersion' => 1,
            'sha256' => $sha256,
            'status' => 'complete',
            'units' => 'FIT epoch seconds; elapsed/timer/set duration milliseconds; distance centimetres; device settings are unscaled integer arrays. Null means missing or unusable.',
            'dataMessages' => $dataCount,
            'messageCountColumns' => ['globalMessageNumber', 'count'],
            'messageCounts' => array_map(static fn (int $number, int $count): array => [$number, $count], array_keys($messages), array_values($messages)),
            'columns' => $columns,
            'rows' => $tables,
            'rowCounts' => $counts,
            'omittedRows' => $truncated,
            'summaryTotals' => $totals,
            'recordColumns' => ['sequence', 'byteOffset', 'timestamp'],
            'records' => $records,
            'timerEventTypeCountColumns' => ['event_type', 'count'],
            'timerEventTypeCounts' => array_map(static fn (int|string $type, int $count): array => [is_int($type) ? $type : null, $count], array_keys($eventTypes), array_values($eventTypes)),
            'eventsWithMissingEvent' => $missingEvent,
            'focusSequence' => $focusSequence,
            'focus' => $focus,
            'physical' => [
                'columns' => ['sequence', 'byteOffset', 'architecture', 'compressedTimestamp', 'fields'],
                'fieldColumns' => ['fieldNumber', 'size', 'baseType', 'bytesHex'],
                'units' => 'Architecture: 0 little endian, 1 big endian. Base type: FIT definition byte. Hex bytes are physical payload in source order; empty means a zero-size descriptor. Missing fields are absent; duplicate descriptors are retained. Compressed header timestamps are not added as physical fields. Session field 70 is best_lap_index.',
                'rows' => $physicalRows,
                'omittedRows' => $truncated,
                'focus' => $physicalFocus,
            ],
        ];
    }

    /**
     * @param list<int> $numbers
     *
     * @return array{int, int, int, bool, list<array{int, int, int, string}>}
     */
    private function physicalRow(RawDataRecord $raw, int $headerSize, array $numbers): array
    {
        $values = $raw->standardFields();
        $position = 0;
        $fields = [];
        $compressed = $raw instanceof RawCompressedTimestampDataMessage;

        foreach ($raw->definition()->standardFields() as $definition) {
            $bytes = '';

            if (0 !== $definition->size) {
                $bytes = bin2hex($values[$position++]->value->bytes());
            }

            if (in_array($definition->fieldNumber, $numbers, true)) {
                $fields[] = [$definition->fieldNumber, $definition->size, $definition->baseType->definitionByte(), $bytes];
            }
        }

        return [$raw->sequence(), $headerSize + $raw->byteOffset(), $raw->definition()->architecture->value, $compressed, $fields];
    }

    /** @return list<int|null>|null */
    private function integers(PhysicalMessage $message, int $number): ?array
    {
        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }

            $decoded = (new FitBaseTypeDecoder())->decode($field->definition->baseType, $message->architecture, $field->value);

            if (!$decoded instanceof DecodedFieldElements) {
                return null;
            }

            return array_map(static fn ($element): ?int => $element instanceof ValidFieldElement && is_int($element->value) ? $element->value : null, $decoded->elements());
        }

        return null;
    }
}
