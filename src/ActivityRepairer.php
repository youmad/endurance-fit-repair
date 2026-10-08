<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair;

use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\FitRepair\Internal\Fit\ActivityHeartRateTail;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalEncoder;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Fit\RecoveringReader;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;

/** Preserving repair by default; lossy reconstruction requires explicit selection. */
final readonly class ActivityRepairer
{
    public function repair(string $bytes, ?int $localTimeOffset = null, bool $reconstruct = false): RepairResult
    {
        if ($reconstruct) {
            return $this->reconstruct($bytes, $localTimeOffset);
        }

        if (null !== $localTimeOffset && (-43_200 > $localTimeOffset || 50_400 < $localTimeOffset)) {
            throw new RepairFailed('Local time offset must be in -43200..50400 seconds.');
        }

        // Validate structure and CRC even when no supported field needs repair.
        [$member, $tail] = ActivityHeartRateTail::split($bytes);
        EditableFitFile::read($member);
        $sourceHash = hash('sha256', $bytes);
        $steps = [];
        $unsupported = [];
        $repairer = new PreservingActivityRepairer();
        $rules = [PreservingActivityRepairer::FILE_ID_FIRST, PreservingActivityRepairer::RECORDED_TIMER_START, PreservingActivityRepairer::FRONT_SUMMARY_CLOCKS, PreservingActivityRepairer::MISSING_LAP, PreservingActivityRepairer::SINGLE_LAP_START, PreservingActivityRepairer::RESERVED_SESSION_INDEX, PreservingActivityRepairer::UNPAUSED_FINAL_LAP, PreservingActivityRepairer::SESSION_LAP_REFERENCES, PreservingActivityRepairer::SESSION_INDEX, PreservingActivityRepairer::LAP_INDEX, PreservingActivityRepairer::SESSION_ELAPSED_FROM_LAPS, PreservingActivityRepairer::UNPAUSED_LAP_TIMER, PreservingActivityRepairer::RECORDED_LENGTH_LAPS, PreservingActivityRepairer::RECORDED_LENGTH_BOUNDARIES];

        if ('' !== $tail) {
            // Front clocks are followed by the original absolute START/Records,
            // retaining the timestamp context shared with chained HR packets.
            $rules = [PreservingActivityRepairer::FRONT_SUMMARY_CLOCKS, PreservingActivityRepairer::SESSION_ELAPSED_FROM_LAPS, PreservingActivityRepairer::UNPAUSED_LAP_TIMER];
        }

        if (null !== $localTimeOffset && '' === $tail) {
            $rules[] = PreservingActivityRepairer::LOCAL_TIMESTAMP;
        }

        foreach ($rules as $rule) {
            try {
                $result = $repairer->repair($bytes, $rule, $localTimeOffset);

                if ($result->bytes !== $bytes) {
                    $steps[] = $result->report;
                    $bytes = $result->bytes;
                }
            } catch (RepairFailed $failure) {
                $unsupported[$rule] = $failure->getMessage();
            }
        }

        return new RepairResult($bytes, ['mode' => 'preserve', 'input_sha256' => $sourceHash, 'output_sha256' => hash('sha256', $bytes), 'steps' => $steps, 'unsupported' => $unsupported, 'preservation' => 'verified', 'activity_validation' => 'not_performed']);
    }

    private function reconstruct(string $bytes, ?int $localTimeOffset): RepairResult
    {
        $source = (new RecoveringReader())->read($bytes);
        $records = [];
        $dropped = [];
        $last = null;
        $gapReached = false;
        $firstFileId = null;
        $device = null;
        $developerIds = [];
        $descriptions = [];
        $originalCounts = [];

        foreach ($source->messages as $message) {
            $originalCounts[$message->number] = ($originalCounts[$message->number] ?? 0) + 1;

            if (0 === $message->number) {
                $type = $message->integer(0);

                if (null !== $type && 4 !== $type) {
                    throw new RepairFailed('An explicitly non-activity file_id cannot be repaired as an activity.');
                }

                if (null === $firstFileId) {
                    $firstFileId = $message;

                    continue;
                }
            } elseif (23 === $message->number && 0 === $message->integer(0) && null === $device) {
                $device = $message;

                continue;
            } elseif (207 === $message->number) {
                $developerIds[] = $message;

                continue;
            } elseif (206 === $message->number) {
                $descriptions[] = $message;

                continue;
            } elseif (20 === $message->number) {
                $timestamp = $message->integer(253);
                $reason = null;

                if (null === $timestamp || 0x10000000 > $timestamp || 0xFFFFFFFE < $timestamp) {
                    $reason = 'invalid_record_timestamp';
                } elseif (null !== $last && $timestamp < $last) {
                    $reason = 'backward_record_timestamp';
                } elseif ($gapReached) {
                    $reason = 'after_gap_cutoff';
                } elseif (null !== $last && 172_800 <= $timestamp - $last) {
                    $gapReached = true;
                    $reason = 'gap_at_least_48_hours';
                }

                if (null === $reason) {
                    $records[] = $message;
                    $last = $timestamp;

                    continue;
                }

                $dropped[] = $this->provenance($message) + ['reason' => $reason];

                continue;
            }

            $dropped[] = $this->provenance($message) + ['reason' => 'outside_reconstructed_activity'];
        }

        if ([] === $records) {
            throw new RepairFailed('No usable timestamped record is available.');
        }

        $first = $records[0]->integer(253);
        $end = $records[array_key_last($records)]->integer(253);

        if (null === $first || null === $end) {
            throw new RepairFailed('Selected records have no usable boundaries.');
        }

        [$offset, $offsetSource] = $this->localOffset($source->messages, $localTimeOffset);
        [$sport, $subSport, $sportSource] = $this->sport($source->messages);
        $generated = [];
        $fileId = $firstFileId ?? new PhysicalMessage(0);
        $fileId = $this->fill($fileId, 0, 0x00, 4, 'file_id.type', $generated);
        $fileId = $this->fill($fileId, 1, 0x84, 255, 'file_id.manufacturer', $generated);
        $fileId = $this->fill($fileId, 4, 0x86, $first, 'file_id.time_created', $generated);
        $device ??= (new PhysicalMessage(23))->withInteger(0, 0x02, 0);
        $device = $this->fill($device, 253, 0x86, $first, 'device_info.timestamp', $generated);

        if (null === $device->sourceSequence) {
            $device = $device->withInteger(2, 0x84, 255);
            $generated[] = 'device_info.device_index';
            $generated[] = 'device_info.manufacturer';
        }

        $duration = ($end - $first) * 1000;
        $lap = $this->summary(19, $first, $end, $duration);
        $session = $this->summary(18, $first, $end, $duration)
            ->withInteger(5, 0x00, $sport)
            ->withInteger(6, 0x00, $subSport)
            ->withInteger(25, 0x84, 0)
            ->withInteger(26, 0x84, 1);
        $distance = $records[array_key_last($records)]->integer(5);

        if (null !== $distance && 0 <= $distance && 0xFFFFFFFE >= $distance) {
            $lap = $lap->withInteger(9, 0x86, $distance);
            $session = $session->withInteger(9, 0x86, $distance);
        }

        $activity = (new PhysicalMessage(34))
            ->withInteger(253, 0x86, $end)
            ->withInteger(0, 0x86, $duration)
            ->withInteger(1, 0x84, 1)
            ->withInteger(5, 0x86, $end + $offset);
        $messages = [$fileId, $device, ...$developerIds, ...$descriptions, ...$records, $lap, $session, $activity];
        $output = (new PhysicalEncoder())->encode($messages, $source->header->profileVersion);
        $this->assertProtocolValid($output);
        $retained = [];
        $normalized = [];

        foreach ($messages as $message) {
            if (null !== $message->sourceSequence) {
                $retained[] = $this->provenance($message);
            }

            if ($message->wasCompressed) {
                $normalized[] = $message->sourceSequence;
            }
        }

        ksort($originalCounts);

        return new RepairResult($output, [
            'schema_version' => 1,
            'policy' => 'single_activity_record_prefix_v1',
            'rules' => [
                'read_complete_prefix',
                'reject_non_activity_file_id',
                'timestamp_minimum_0x10000000',
                'discard_backward_records_keep_equal',
                'stop_at_48_hour_gap',
                'normalize_compressed_timestamps',
                'preserve_physical_record_and_developer_fields',
                'reconstruct_single_lap_session_activity',
                'elapsed_equals_timer_time',
                'last_record_distance',
                'explicit_or_source_local_offset',
            ],
            'input_sha256' => hash('sha256', $bytes),
            'output_sha256' => hash('sha256', $output),
            'input_bytes' => strlen($bytes),
            'output_bytes' => strlen($output),
            'source_issues' => $source->issues,
            'declared_data_bytes' => $source->header->dataSize,
            'available_data_bytes' => $source->availableDataBytes,
            'complete_data_bytes' => $source->completeDataBytes,
            'original_message_counts' => (object) $originalCounts,
            'retained_source_messages' => $retained,
            'discarded_source_messages' => $dropped,
            'normalized_compressed_sequences' => $normalized,
            'generated_identity_fields' => $generated,
            'retained_records' => count($records),
            'start_timestamp' => $first,
            'end_timestamp' => $end,
            'local_time_offset_seconds' => $offset,
            'local_time_offset_source' => $offsetSource,
            'sport' => $sport,
            'sub_sport' => $subSport,
            'sport_source' => $sportSource,
            'generated_summaries' => ['lap' => 1, 'session' => 1, 'activity' => 1],
            'output_protocol_validated' => true,
            'activity_validation' => 'not_performed',
            'warnings' => [
                'Original laps, sessions, events and other unselected messages are discarded.',
                'Developer metadata is reordered; semantic consistency is not validated.',
                'One lap and one session are reconstructed; multisport segmentation is lost.',
                'Timer time equals elapsed time; pauses cannot be reconstructed.',
                'Distance uses the last retained record value without subtracting the first.',
                'Valid input is also reconstructed when repair is explicitly requested.',
            ],
        ]);
    }

    /** @return array{sequence: ?int, byte_offset: ?int, global_message_number: int} */
    private function provenance(PhysicalMessage $message): array
    {
        return ['sequence' => $message->sourceSequence, 'byte_offset' => $message->sourceOffset, 'global_message_number' => $message->number];
    }

    /** @param list<string> $generated */
    private function fill(PhysicalMessage $message, int $field, int $type, int $value, string $name, array &$generated): PhysicalMessage
    {
        if (null !== $message->integer($field)) {
            return $message;
        }

        $generated[] = $name;

        return $message->withInteger($field, $type, $value);
    }

    private function summary(int $number, int $first, int $last, int $duration): PhysicalMessage
    {
        return (new PhysicalMessage($number))
            ->withInteger(254, 0x84, 0)
            ->withInteger(253, 0x86, $last)
            ->withInteger(2, 0x86, $first)
            ->withInteger(7, 0x86, $duration)
            ->withInteger(8, 0x86, $duration);
    }

    /**
     * @param list<PhysicalMessage> $messages
     *
     * @return array{int, string}
     */
    private function localOffset(array $messages, ?int $explicit): array
    {
        if (null !== $explicit) {
            if (-43_200 > $explicit || 50_400 < $explicit) {
                throw new RepairFailed('Local time offset must be between -43200 and 50400 seconds.');
            }

            return [$explicit, 'explicit'];
        }

        $offsets = [];

        foreach ($messages as $message) {
            if (34 !== $message->number) {
                continue;
            }

            $timestamp = $message->integer(253);
            $local = $message->integer(5);

            if (null !== $timestamp && null !== $local) {
                $offset = $local - $timestamp;

                if (-43_200 <= $offset && 50_400 >= $offset) {
                    $offsets[$offset] = true;
                }
            }
        }

        if (1 !== count($offsets)) {
            throw new RepairFailed('No unambiguous source local time offset; provide --local-time-offset explicitly.');
        }

        $offset = array_key_first($offsets);

        return [$offset, 'source_activity'];
    }

    /**
     * @param list<PhysicalMessage> $messages
     *
     * @return array{int, int, string}
     */
    private function sport(array $messages): array
    {
        foreach ([12, 19, 18] as $number) {
            foreach ($messages as $message) {
                if ($number !== $message->number) {
                    continue;
                }

                $sport = $message->integer(match ($number) {
                    12 => 0,
                    19 => 25,
                    default => 5,
                });
                $subSport = $message->integer(match ($number) {
                    12 => 1,
                    19 => 39,
                    default => 6,
                });

                if (null !== $sport && null !== $subSport && 0 <= $sport && 254 >= $sport && 0 <= $subSport && 254 >= $subSport) {
                    return [$sport, $subSport, match ($number) {
                        12 => 'source_sport',
                        19 => 'source_lap',
                        default => 'source_session',
                    }];
                }
            }
        }

        return [0, 0, 'generic_fallback'];
    }

    private function assertProtocolValid(string $bytes): void
    {
        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate the output validation buffer.');
        }

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill the output validation buffer.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));

            foreach ($file->messages() as $message) {
                // Full consumption checks structure and the file CRC trailer.
            }

            if (!$file->isCompleted()) {
                throw new RepairFailed('Output protocol validation did not complete.');
            }
        } finally {
            fclose($stream);
        }
    }
}
