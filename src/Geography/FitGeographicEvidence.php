<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Geography;

use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\Exception\FitDecodeException;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;

/** @internal Read-only evidence; coordinates and clock pairs do not establish a timezone. */
final readonly class FitGeographicEvidence
{
    private const int ROW_LIMIT = 128;
    private const int CELL_LIMIT = 2048;
    private const int ABSOLUTE_TIME_MINIMUM = 0x10000000;
    private const float DEGREES_PER_SEMICIRCLE = 180.0 / 2147483648;

    public function fingerprint(): string
    {
        return hash('sha256', PHP_VERSION.':'.hash_file('sha256', __FILE__).':'.hash_file('sha256', dirname(__DIR__).'/Internal/Fit/PhysicalMessage.php'));
    }

    /**
     * @param callable(string): void|null $progress
     *
     * @return array<string, mixed>
     */
    public function collect(string $path, string $sha256, ?callable $progress = null): array
    {
        if (hash_file('sha256', $path) !== $sha256) {
            throw new RepairFailed('Current FIT bytes changed before geographic collection.');
        }

        $stream = fopen($path, 'rb');

        if (false === $stream) {
            throw new RepairFailed('Cannot open geographic evidence input.');
        }

        $records = ['count' => 0, 'usablePositions' => 0, 'unusablePositions' => 0, 'first' => null, 'last' => null, 'bounds' => null, 'unrepresentedPositions' => 0];
        $cells = [];
        $tables = ['summaryPositions' => [], 'sessions' => [], 'activities' => [], 'clockPairs' => []];
        $counts = array_fill_keys(array_keys($tables), 0);
        $absoluteActivities = 0;
        $relativeActivities = 0;
        $usableClockPairs = 0;
        $offsetCounts = [];
        $unrepresentedClockPairs = 0;
        $dataCount = 0;
        $reason = null;

        try {
            $stat = fstat($stream);
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));

            if (false === $stat || 67_108_864 < $stat['size'] || 0 !== $file->header->byteOffset
                || $stat['size'] !== $file->header->headerSize + $file->header->dataSize + 2) {
                throw new RepairFailed('Geographic evidence requires one complete FIT member at byte zero, up to 64 MiB.');
            }

            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }

                ++$dataCount;
                $number = $raw->globalMessageNumber();

                if (in_array($number, [18, 19, 20, 34, 55, 103, 162], true)) {
                    $message = PhysicalMessage::fromRaw($raw, $file->header->headerSize);
                    $timestamp = $this->integer($message, 253);
                    $origin = [$message->sourceSequence, $message->sourceOffset];

                    if (20 === $number) {
                        ++$records['count'];
                        $position = $this->position($message, 0, 1);

                        if (null === $position) {
                            ++$records['unusablePositions'];
                        } else {
                            ++$records['usablePositions'];
                            [$latitude, $longitude] = $position;
                            $point = [...$origin, $timestamp, $latitude, $longitude];
                            $records['first'] ??= $point;
                            $records['last'] = $point;
                            $bounds = $records['bounds'];
                            $records['bounds'] = null === $bounds ? [$latitude, $longitude, $latitude, $longitude]
                                : [min($bounds[0], $latitude), min($bounds[1], $longitude), max($bounds[2], $latitude), max($bounds[3], $longitude)];
                            $latitudeCell = (int) floor($latitude * self::DEGREES_PER_SEMICIRCLE * 100);
                            $longitudeCell = (int) floor($longitude * self::DEGREES_PER_SEMICIRCLE * 100);
                            $key = $latitudeCell.':'.$longitudeCell;

                            if (isset($cells[$key])) {
                                ++$cells[$key][2];
                            } elseif (count($cells) < self::CELL_LIMIT) {
                                $cells[$key] = [$latitudeCell, $longitudeCell, 1];
                            } else {
                                ++$records['unrepresentedPositions'];
                            }
                        }
                    }

                    if (18 === $number || 19 === $number) {
                        foreach (['start' => [3, 4], 'end' => 18 === $number ? [38, 39] : [5, 6]] as $label => [$lat, $lon]) {
                            $position = $this->position($message, $lat, $lon);

                            if (null !== $position) {
                                $this->append($tables['summaryPositions'], $counts['summaryPositions'], [...$origin, $number, $label, $timestamp, ...$position]);
                            }
                        }
                    }

                    if (18 === $number) {
                        $this->append($tables['sessions'], $counts['sessions'], [...$origin, $this->integer($message, 2), $timestamp, $this->integer($message, 5), $this->integer($message, 6)]);
                    }

                    if (34 === $number) {
                        $absolute = null !== $timestamp && self::ABSOLUTE_TIME_MINIMUM <= $timestamp;
                        $absoluteActivities += $absolute ? 1 : 0;
                        $relativeActivities += null !== $timestamp && !$absolute ? 1 : 0;
                        $this->append($tables['activities'], $counts['activities'], [...$origin, $timestamp, $this->integer($message, 5), $absolute]);
                    }

                    $localField = match ($number) {
                        55 => 11, 103 => 0, 162 => 3, default => null,
                    };

                    if (null !== $localField) {
                        $local = $this->integer($message, $localField);
                        $offset = null;

                        if (null !== $timestamp && null !== $local && self::ABSOLUTE_TIME_MINIMUM <= min($timestamp, $local)
                            && -43_200 <= $local - $timestamp && 50_400 >= $local - $timestamp) {
                            $offset = $local - $timestamp;
                            ++$usableClockPairs;
                            if (isset($offsetCounts[$offset]) || count($offsetCounts) < self::ROW_LIMIT) {
                                $offsetCounts[$offset] = ($offsetCounts[$offset] ?? 0) + 1;
                            } else {
                                ++$unrepresentedClockPairs;
                            }
                        }

                        $this->append($tables['clockPairs'], $counts['clockPairs'], [...$origin, $number, $timestamp, $local, $offset]);
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
            throw new RepairFailed('Current FIT bytes changed during geographic collection.');
        }

        if (null !== $reason) {
            return ['schemaVersion' => 1, 'sha256' => $sha256, 'status' => 'unavailable', 'reason' => $reason];
        }

        $omitted = [];

        foreach ($counts as $name => $count) {
            if ($count > count($tables[$name])) {
                $omitted[$name] = $count - count($tables[$name]);
            }
        }

        ksort($offsetCounts, SORT_NUMERIC);

        return [
            'schemaVersion' => 1, 'sha256' => $sha256, 'status' => 'complete', 'dataMessages' => $dataCount,
            'units' => 'Coordinates: signed FIT semicircles (degrees = value * 180 / 2147483648). Times: FIT epoch seconds. Offsets: seconds. Null means missing or unusable.',
            'limits' => ['rowsPerTable' => self::ROW_LIMIT, 'recordCells' => self::CELL_LIMIT, 'clockOffsets' => self::ROW_LIMIT],
            'recordColumns' => ['sequence', 'byteOffset', 'timestamp', 'latitude', 'longitude'],
            'boundsColumns' => ['minimumLatitude', 'minimumLongitude', 'maximumLatitude', 'maximumLongitude'],
            'records' => $records,
            'cellSizeDegrees' => 0.01,
            'cellColumns' => ['latitudeCell', 'longitudeCell', 'positionCount'],
            'cells' => array_values($cells),
            'columns' => [
                'summaryPositions' => ['sequence', 'byteOffset', 'globalMessageNumber', 'position', 'timestamp', 'latitude', 'longitude'],
                'sessions' => ['sequence', 'byteOffset', 'start_time', 'timestamp', 'sport', 'sub_sport'],
                'activities' => ['sequence', 'byteOffset', 'timestamp', 'local_timestamp', 'absoluteUtc'],
                'clockPairs' => ['sequence', 'byteOffset', 'globalMessageNumber', 'timestamp', 'local_timestamp', 'usableOffset'],
            ],
            'rows' => $tables, 'rowCounts' => $counts, 'omittedRows' => $omitted,
            'absoluteActivityTimes' => $absoluteActivities, 'relativeActivityTimes' => $relativeActivities,
            'usableClockPairs' => $usableClockPairs, 'unrepresentedClockPairs' => $unrepresentedClockPairs,
            'clockOffsetColumns' => ['offsetSeconds', 'count'],
            'clockOffsetCounts' => array_map(static fn (int $offset, int $count): array => [$offset, $count], array_keys($offsetCounts), array_values($offsetCounts)),
        ];
    }

    /** @return array{int, int}|null */
    private function position(PhysicalMessage $message, int $latitudeField, int $longitudeField): ?array
    {
        $latitude = $this->integer($message, $latitudeField, 5);
        $longitude = $this->integer($message, $longitudeField, 5);

        return null !== $latitude && null !== $longitude && -0x40000000 <= $latitude && 0x40000000 >= $latitude
            && -0x80000000 <= $longitude && 0x7FFFFFFF > $longitude ? [$latitude, $longitude] : null;
    }

    private function integer(PhysicalMessage $message, int $number, ?int $baseType = null): ?int
    {
        $count = 0;

        foreach ($message->fields as $field) {
            if ($number === $field->definition->fieldNumber) {
                ++$count;

                if (null !== $baseType && $baseType !== $field->definition->baseType->number()) {
                    return null;
                }
            }
        }

        return 1 === $count ? $message->integer($number) : null;
    }

    /**
     * @param list<list<int|string|bool|null>> $rows
     * @param list<int|string|bool|null>       $row
     */
    private function append(array &$rows, int &$count, array $row): void
    {
        ++$count;

        if ($count <= self::ROW_LIMIT) {
            $rows[] = $row;
        }
    }
}
