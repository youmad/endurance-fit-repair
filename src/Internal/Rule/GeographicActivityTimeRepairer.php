<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\Fit\Raw\RawDefinitionMessage;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Optional geographic inference; changes only an unusable Activity.local_timestamp. */
final readonly class GeographicActivityTimeRepairer
{
    public const string RULE = 'geographic_activity_local_timestamp';
    public const string CONTEXT_RULE = 'recorded_device_activity_local_timestamp';
    public const string NATIVE_RULE = 'same_file_device_activity_local_timestamp';
    public const string UTC_COMPATIBILITY_RULE = 'explicit_utc_compatibility_local_timestamp';

    /** Explicit placeholder for unknown local time, never a recovered timezone. */
    public function repairUtcCompatibility(string $bytes): RepairResult
    {
        $repair = $this->repairClock($bytes, null, 0, utcCompatibility: true);
        $report = $repair->report;
        $report['mode'] = 'normalize_local_clock';
        $report['rule'] = self::UTC_COMPATIBILITY_RULE;
        $report['inference'] = [
            'source' => 'explicit_utc_compatibility_fallback',
            'historical_accuracy' => 'not_proven',
            'physical_timezone' => 'unknown',
            'offset_seconds' => 0,
            'recording_epoch' => $repair->report['inference']['recording_epoch'],
            'consistent_clock_pairs' => $repair->report['inference']['consistent_clock_pairs'],
            'policy' => 'copy original Activity UTC into unusable local_timestamp for compatibility; this does not establish the original local time or recording timezone',
        ];

        return new RepairResult($repair->bytes, $report);
    }

    public function repair(string $bytes, string $zone): RepairResult
    {
        if (!in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) || str_starts_with($zone, 'Etc/')) {
            throw new RepairFailed('Geographic repair requires a named IANA land timezone.');
        }

        return $this->repairClock($bytes, $zone, null);
    }

    public function repairOffset(string $bytes, int $offset): RepairResult
    {
        if (-43_200 > $offset || 50_400 < $offset) {
            throw new RepairFailed('Recorded device clock offset must be in -43200..50400 seconds.');
        }

        return $this->repairClock($bytes, null, $offset);
    }

    /** Uses only consistent absolute UTC/local pairs recorded in this Activity file. */
    public function repairNativeContext(string $bytes): RepairResult
    {
        $repair = $this->repairClock($bytes, null, null, true);
        $report = $repair->report;
        $report['rule'] = self::NATIVE_RULE;
        $report['inference']['source'] = 'same_file_recorded_device_clock';
        $report['inference']['historical_accuracy'] = 'not_proven';
        $report['inference']['policy'] = 'all usable absolute clock pairs in this file must agree and fall within 24 hours of the original Activity UTC; retain UTC and repair only unusable Activity.local_timestamp';

        return new RepairResult($repair->bytes, $report);
    }

    private function repairClock(string $bytes, ?string $zone, ?int $recordedOffset, bool $nativeContext = false, bool $utcCompatibility = false): RepairResult
    {
        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate geographic repair input.');
        }

        $activity = null;
        $definitions = [];
        $ordinal = 0;
        $clockPairs = [];
        $clockCount = 0;

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill geographic repair input.');
            }

            $input = new ResourceFitInput($stream);
            $file = (new FitFileReader())->open($input);
            $header = $file->header;

            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Geographic repair requires one complete supported FIT member at byte zero.');
            }

            foreach ($file->messages() as $raw) {
                $offset = $raw->byteOffset();

                if ($raw instanceof RawDefinitionMessage) {
                    $length = 6 + 3 * count($raw->definition->standardFields()) + (0 !== ($raw->recordHeaderByte() & 0x20) ? 1 + 3 * count($raw->definition->developerFields()) : 0);
                    $definitions[$raw->definition->localMessageNumber] = substr($bytes, $offset, $length);
                } elseif ($raw instanceof RawDataRecord) {
                    $number = $raw->globalMessageNumber();

                    if (34 === $number) {
                        if (null !== $activity || $raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Geographic repair requires exactly one uncompressed Activity summary.');
                        }

                        $length = 1;

                        foreach ([...$raw->standardFields(), ...$raw->developerFields()] as $field) {
                            $length += $field->definition->size;
                        }

                        $activity = ['raw' => $raw, 'offset' => $offset, 'length' => $length, 'definition' => $definitions[$raw->definition()->localMessageNumber], 'ordinal' => $ordinal];
                    }

                    if (in_array($number, [55, 103, 162], true)) {
                        if (++$clockCount > 128) {
                            throw new RepairFailed('Too many clock pairs to establish geographic consistency.');
                        }

                        $message = PhysicalMessage::fromRaw($raw, 0);
                        $localField = match ($number) {
                            55 => 11, 103 => 0, 162 => 3,
                        };
                        $utc = $this->scalar($message, 253);
                        $local = $this->scalar($message, $localField);

                        if (null !== $utc && null !== $local && 0x10000000 <= min($utc, $local)
                            && -43_200 <= $local - $utc && 50_400 >= $local - $utc) {
                            $clockPairs[] = [$utc, $local - $utc];
                        }
                    }

                    ++$ordinal;
                }
            }

            $file->trailer();
        } finally {
            fclose($stream);
        }

        if (null === $activity) {
            throw new RepairFailed('Activity summary is required for geographic local time repair.');
        }

        $message = PhysicalMessage::fromRaw($activity['raw'], 0);
        $timestamp = $this->scalar($message, 253);
        $local = $this->scalar($message, 5);

        if (null === $timestamp || (!$utcCompatibility && 0x10000000 > $timestamp)) {
            throw new RepairFailed('An absolute Activity UTC timestamp is required.');
        }

        if (null !== $local && -43_200 <= $local - $timestamp && 50_400 >= $local - $timestamp) {
            throw new RepairFailed('Existing Activity local time is usable; geographic repair must preserve it.');
        }

        $timezone = null === $zone ? null : new \DateTimeZone($zone);
        if ($nativeContext) {
            if ([] === $clockPairs) {
                throw new RepairFailed('Same-file local clock repair requires an original absolute UTC/local pair.');
            }
            $recordedOffset = $clockPairs[0][1];
            foreach ($clockPairs as [$utc, $reportedOffset]) {
                if (abs($utc - $timestamp) > 86_400 || $reportedOffset !== $recordedOffset) {
                    throw new RepairFailed('Same-file clock pairs must be recent and agree on one offset.');
                }
            }
        }

        foreach ($clockPairs as [$utc, $reportedOffset]) {
            if (($timezone?->getOffset(new \DateTimeImmutable('@'.($utc + 631_065_600))) ?? $recordedOffset) !== $reportedOffset) {
                throw new RepairFailed('FIT UTC/local clock pair contradicts the geographic timezone.');
            }
        }

        $offset = $timezone?->getOffset(new \DateTimeImmutable('@'.($timestamp + 631_065_600))) ?? $recordedOffset;
        if (null === $offset) {
            throw new \LogicException('Local clock repair requires a timezone or offset.');
        }
        $value = $timestamp + $offset;

        if (-43_200 > $offset || 50_400 < $offset || (!$utcCompatibility && 0x10000000 > $value) || 0 > $value || 0xFFFFFFFE < $value) {
            throw new RepairFailed('Inferred local time is outside the absolute FIT uint32 range.');
        }

        $payload = substr($bytes, $activity['offset'], $activity['length']);
        $position = 1;
        $existing = false;
        $word = FitArchitecture::LittleEndian === $message->architecture ? 'V' : 'N';

        foreach ($message->fields as $field) {
            if (5 === $field->definition->fieldNumber) {
                $payload = substr_replace($payload, pack($word, $value), $position, 4);
                $existing = true;
            }

            $position += $field->definition->size;
        }

        if (!$existing) {
            $count = count($message->fields);

            if (255 === $count) {
                throw new RepairFailed('Cannot append local time to a full FIT definition.');
            }

            $definition = $activity['definition'];
            $expanded = substr($definition, 0, 5).chr($count + 1).substr($definition, 6, 3 * $count)."\x05\x04\x86".substr($definition, 6 + 3 * $count);
            $payload = $expanded.substr($payload, 0, $position).pack($word, $value).substr($payload, $position).$definition;
        }

        // Preserve every byte before/after the sole Activity packet. Restore
        // its local definition so later messages (including developer data)
        // continue to use the original layout and compressed timestamp state.
        $data = substr($bytes, $header->headerSize, $header->dataSize);
        $data = substr_replace($data, $payload, $activity['offset'] - $header->headerSize, $activity['length']);
        $outputHeader = substr_replace($header->rawBytes(), pack('V', strlen($data)), 4, 4);

        if (14 === $header->headerSize) {
            $outputHeader = substr_replace($outputHeader, pack('v', FitCrc16::calculate(substr($outputHeader, 0, 12))), 12, 2);
        }

        $output = $outputHeader.$data.pack('v', FitCrc16::calculate($outputHeader.$data));

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => null === $zone ? self::CONTEXT_RULE : self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => [['message' => $activity['ordinal'], 'field' => 5, 'type' => 0x86, 'value' => $value]],
            'original_local_timestamp' => $local,
            'inference' => ['source' => null === $zone ? 'recorded_device_clock_context' : 'geographic_coordinates', 'timezone' => $zone, 'offset_seconds' => $offset, 'timezone_database_version' => timezone_version_get(), 'consistent_clock_pairs' => count($clockPairs),
                'recording_epoch' => $timestamp < 0x10000000 ? 'relative_origin_unknown_preserved' : 'original_absolute_utc_preserved'],
            'preservation' => 'original bytes retained outside Activity.local_timestamp, its definitions and CRC/header size',
            'activity_validation' => 'not_performed',
        ]);
    }

    private function scalar(PhysicalMessage $message, int $number): ?int
    {
        $count = 0;

        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }

            if (++$count > 1 || 4 !== $field->definition->size || 0x86 !== $field->definition->baseType->definitionByte()) {
                throw new RepairFailed('Geographic local time repair requires unique uint32 clock fields.');
            }
        }

        return $message->integer($number);
    }
}
