<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\Fit\Raw\RawDefinitionMessage;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Copies recorded durations or an independently corroborated start of one whole-session Lap. */
final readonly class SingleLapDurationRepairer
{
    public const string RULE = 'single_lap_missing_recorded_durations';
    public const string START_RULE = 'single_lap_missing_recorded_start';
    public const string IMPOSSIBLE_START_RULE = 'single_lap_impossible_start_from_session';

    public function repair(string $bytes, bool $recoverStart = false, bool $recoverImpossibleStart = false): RepairResult
    {
        if ($recoverStart && $recoverImpossibleStart) {
            throw new RepairFailed('Select one Lap start recovery mode.');
        }
        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate single-Lap duration input.');
        }

        $summaries = $definitions = $events = [];
        $firstRecord = $lastRecord = $firstRecordSequence = $lastRecordSequence = null;
        $recordCount = $ordinal = $fileIdCount = 0;

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill single-Lap duration input.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;

            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || !in_array($header->headerSize, [12, 14], true)) {
                throw new RepairFailed('Single-Lap duration repair requires one complete FIT member with a 12 or 14-byte header at byte zero.');
            }

            foreach ($file->messages() as $raw) {
                if ($raw instanceof RawDefinitionMessage) {
                    $length = 6 + 3 * count($raw->definition->standardFields())
                        + (0 === ($raw->recordHeaderByte() & 0x20) ? 0 : 1 + 3 * count($raw->definition->developerFields()));
                    $definitions[$raw->definition->localMessageNumber] = substr($bytes, $raw->byteOffset(), $length);
                    continue;
                }

                if (!$raw instanceof RawDataRecord) {
                    continue;
                }

                $number = $raw->globalMessageNumber();

                if (0 === $number) {
                    ++$fileIdCount;

                    if (0 !== $ordinal || 4 !== $this->scalar(PhysicalMessage::fromRaw($raw, 0), 0, 0x00, 1)) {
                        throw new RepairFailed('Duration recovery requires one Activity file_id as the first data message.');
                    }
                }

                if (in_array($number, [18, 19, 20, 21, 34], true)) {
                    $message = PhysicalMessage::fromRaw($raw, 0);

                    if (20 === $number) {
                        $timestamp = $recoverImpossibleStart ? $this->scalar($message, 253, 0x86, 4) : $message->integer(253);

                        if (null === $timestamp || $timestamp < 0x10000000 || (null !== $lastRecord && $timestamp < $lastRecord)) {
                            throw new RepairFailed('Duration recovery requires ordered absolute Record timestamps.');
                        }

                        $firstRecord ??= $timestamp;
                        $firstRecordSequence ??= $raw->sequence();
                        $lastRecord = $timestamp;
                        $lastRecordSequence = $raw->sequence();
                        ++$recordCount;
                    } elseif (21 === $number) {
                        $event = $this->eventEnum($message, 0);

                        if (null === $event) {
                            throw new RepairFailed('Missing Event kind cannot exclude a timer contradiction.');
                        }

                        if (0 === $event) {
                            $events[] = ['sequence' => $raw->sequence(), 'timestamp' => $this->scalar($message, 253, 0x86, 4),
                                'type' => $this->eventEnum($message, 1), 'group' => $this->scalar($message, 4, 0x02, 1)];

                            if (count($events) > 128) {
                                throw new RepairFailed('Duration recovery requires a bounded timer timeline.');
                            }
                        }
                    } else {
                        if (isset($summaries[$number]) || $raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Duration recovery requires exactly one uncompressed Lap, Session and Activity.');
                        }

                        foreach ([...$raw->definition()->standardFields(), ...$raw->definition()->developerFields()] as $field) {
                            if (0 === $field->size) {
                                throw new RepairFailed('Duration recovery requires summary definitions without zero-size descriptors.');
                            }
                        }

                        $length = 1;

                        foreach ([...$raw->standardFields(), ...$raw->developerFields()] as $field) {
                            $length += $field->definition->size;
                        }

                        $summaries[$number] = ['physical' => $message, 'message' => $ordinal, 'offset' => $raw->byteOffset(), 'length' => $length,
                            'definition' => $definitions[$raw->definition()->localMessageNumber]];
                    }
                }

                ++$ordinal;
            }

            $file->trailer();
        } finally {
            fclose($stream);
        }

        if (1 !== $fileIdCount || 3 !== count($summaries) || 0 === $recordCount) {
            throw new RepairFailed('Recorded duration propagation requires one existing Lap, Session, Activity and at least one Record.');
        }

        $lap = $summaries[19]['physical'];
        $session = $summaries[18]['physical'];
        $activity = $summaries[34]['physical'];
        $start = $this->scalar($session, 2, 0x86, 4);
        $finish = $this->scalar($session, 253, 0x86, 4);
        $lapStart = $this->scalar($lap, 2, 0x86, 4);
        $lapTimestamp = $this->scalar($lap, 253, 0x86, 4);
        $elapsed = $this->scalar($session, 7, 0x86, 4);
        $lapElapsed = $this->scalar($lap, 7, 0x86, 4);
        $lapTimer = $this->scalar($lap, 8, 0x86, 4);
        $sessionTimer = $this->scalar($session, 8, 0x86, 4);
        $activityTimer = $this->scalar($activity, 0, 0x86, 4);
        $activityTimestamp = $this->scalar($activity, 253, 0x86, 4);

        if ($recoverImpossibleStart) {
            $distance = $this->scalar($session, 9, 0x86, 4);
            if (null === $start || $start < 0x10000000 || null === $finish || $finish <= $start
                || null === $lapStart || null === $lapTimestamp || $lapStart <= $lapTimestamp || $lapTimestamp !== $finish
                || null === $elapsed || $elapsed <= 0 || $elapsed !== $lapElapsed || $elapsed !== $sessionTimer || $elapsed !== $lapTimer
                || null === $distance || $distance <= 0 || $distance !== $this->scalar($lap, 9, 0x86, 4)
                || (null !== $activityTimer && $activityTimer !== $elapsed) || $activityTimestamp !== $finish
                || 1 !== $this->scalar($activity, 1, 0x84, 2)
                || !in_array($this->scalar($session, 254, 0x84, 2), [null, 0], true)
                || !in_array($this->scalar($lap, 254, 0x84, 2), [null, 0], true)
                || !in_array($this->scalar($session, 25, 0x84, 2), [null, 0], true)
                || !in_array($this->scalar($session, 26, 0x84, 2), [null, 1], true)
                || !in_array($this->scalar($session, 70, 0x84, 2), [null, 0], true)
                || [] !== $events || $recordCount < 2 || $firstRecord !== $start || abs($lastRecord - $finish) > 1
                || abs(($finish - $start) * 1000 - $elapsed) > 1000 || $lastRecord > $start + intdiv($elapsed, 1000) + 1
                || $lastRecordSequence >= $lap->sourceSequence || $lap->sourceSequence >= $session->sourceSequence || $session->sourceSequence >= $activity->sourceSequence) {
                throw new RepairFailed('An impossible sole Lap start requires identical unpaused Session/Lap durations and distance, shared SAVE, corroborating Records and no timer events or conflicting references.');
            }
            $changes = [['message' => $summaries[19]['message'], 'field' => 2, 'type' => 0x86, 'value' => $start]];
            $output = $this->apply($bytes, $header->headerSize, $summaries, $changes);

            return new RepairResult($output, [
                'mode' => 'preserve', 'rule' => self::IMPOSSIBLE_START_RULE,
                'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
                'original_lap_start' => $lapStart,
                'evidence' => ['start_source' => ['globalMessageNumber' => 18, 'field' => 2, 'value' => $start],
                    'shared_elapsed_ms' => $elapsed, 'shared_timer_ms' => $lapTimer, 'shared_distance_cm' => $distance,
                    'shared_save_timestamp' => $finish, 'record_count' => $recordCount,
                    'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                    'policy' => 'copy recorded Session.start_time only into an existing sole whole-session Lap whose start is after its own SAVE; corroborate identical elapsed, timer and distance using ordered Record endpoints; retain all native counters and clocks except the impossible Lap start'],
                'preservation' => 'original packets retained outside existing Lap.start_time and file CRC', 'activity_validation' => 'not_performed',
            ]);
        }

        if ($recoverStart) {
            if (null !== $lapStart || null === $start || $start < 0x10000000 || $firstRecord !== $start || $recordCount < 2
                || null === $elapsed || null === $lapElapsed || $lapElapsed <= 0 || $elapsed < $lapElapsed
                || null === $lapTimer || $lapTimer <= 0 || $lapTimer > $lapElapsed || $lapTimer !== $sessionTimer || $lapTimer !== $activityTimer
                || null === $finish || null === $activityTimestamp || $activityTimestamp < $finish
                || null === $lapTimestamp || $finish < $lapTimestamp
                || 1 !== $this->scalar($activity, 1, 0x84, 2)
                || 0 !== $this->scalar($session, 254, 0x84, 2) || 0 !== $this->scalar($lap, 254, 0x84, 2)
                || 0 !== $this->scalar($session, 25, 0x84, 2) || 1 !== $this->scalar($session, 26, 0x84, 2)
                || $lastRecordSequence >= $lap->sourceSequence || $lap->sourceSequence >= $session->sourceSequence || $session->sourceSequence >= $activity->sourceSequence
                || count($events) < 2 || 0 !== count($events) % 2) {
                throw new RepairFailed('A missing Lap start requires one explicitly referenced whole-session Lap with identical recorded timers and complete clock evidence.');
            }

            $active = 0;
            foreach ($events as $index => $event) {
                if (null === $event['timestamp'] || $event['timestamp'] < $start || $event['timestamp'] > $finish
                    || !in_array($event['group'], [null, 0], true) || $event['sequence'] >= $session->sourceSequence
                    || (0 === $index % 2 ? 0 !== $event['type'] : !in_array($event['type'], [1, 4], true))
                    || ($index > 0 && $event['timestamp'] < $events[$index - 1]['timestamp'])) {
                    throw new RepairFailed('Missing Lap start evidence requires a complete alternating timer trace.');
                }
                if (1 === $index % 2) {
                    $active += ($event['timestamp'] - $events[$index - 1]['timestamp']) * 1000;
                }
            }
            $stop = $events[array_key_last($events)]['timestamp'];
            $span = ($stop - $start) * 1000;
            $paused = $span - $active;
            if ($events[0]['timestamp'] !== $start || abs($lastRecord - $stop) > 1 || abs($lapTimestamp - $stop) > 1
                || abs($span - $lapElapsed) > 1000 || abs($active - $lapTimer) > 1000 || abs($paused - ($lapElapsed - $lapTimer)) > 1000
                || $lastRecord > $start + intdiv($lapElapsed, 1000) + 1) {
                throw new RepairFailed('Recorded timer endpoints and Records must corroborate the existing Lap elapsed and timer before its missing start is copied.');
            }
            $changes = [['message' => $summaries[19]['message'], 'field' => 2, 'type' => 0x86, 'value' => $start]];
            $output = $this->apply($bytes, $header->headerSize, $summaries, $changes);

            return new RepairResult($output, [
                'mode' => 'preserve', 'rule' => self::START_RULE, 'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
                'changes' => $changes, 'evidence' => [
                    'session_sequence' => $session->sourceSequence, 'lap_sequence' => $lap->sourceSequence,
                    'start_source' => ['globalMessageNumber' => 18, 'field' => 2, 'value' => $start],
                    'lap_elapsed_ms' => $lapElapsed, 'lap_timer_ms' => $lapTimer, 'record_count' => $recordCount,
                    'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                    'timer_events' => $events, 'timer_active_ms' => $active, 'timer_paused_ms' => $paused,
                    'policy' => 'copy only the existing Session start into the missing start of its explicitly referenced sole Lap; require the same recorded START and first Record, complete timer pairs and unchanged durations; retain deferred Event packets and all SAVE clocks',
                ], 'preservation' => 'original packets retained outside missing Lap.start_time; active definitions restored after the edit',
                'activity_validation' => 'not_performed',
            ]);
        }

        if (null === $start || null === $finish || null === $elapsed || $elapsed <= 0 || $lapStart !== $start || $firstRecord !== $start
            || null === $lapTimestamp || abs(($finish - $start) * 1000 - $elapsed) > 1000
            || abs(($lapTimestamp - $start) * 1000 - ($lapElapsed ?? $elapsed)) > 1000
            || (null !== $lapElapsed && abs($lapElapsed - $elapsed) > 1000)
            || $lastRecord > $start + intdiv($elapsed, 1000) + 1 || $lastRecordSequence >= $lap->sourceSequence
            || $lap->sourceSequence >= $session->sourceSequence || $session->sourceSequence >= $activity->sourceSequence
            || null === $activityTimestamp || ($activityTimestamp < $finish && $activityTimestamp !== $start) || 1 !== $this->scalar($activity, 1, 0x84, 2)
            || !in_array($this->scalar($session, 254, 0x84, 2), [null, 0], true) || !in_array($this->scalar($lap, 254, 0x84, 2), [null, 0], true)
            || !in_array($this->scalar($session, 25, 0x84, 2), [null, 0], true) || !in_array($this->scalar($session, 26, 0x84, 2), [null, 1], true)) {
            throw new RepairFailed('Existing clocks, indexes and references do not establish one whole-session Lap.');
        }

        $sources = [];

        foreach ([$sessionTimer, $activityTimer, $lapTimer] as $index => $value) {
            if (null !== $value) {
                $sources[] = ['globalMessageNumber' => [18, 34, 19][$index], 'field' => [8, 0, 8][$index], 'value' => $value];
            }
        }

        $timer = $sources[0]['value'] ?? null;

        if (null === $timer || $timer <= 0 || $timer > $elapsed || $timer > ($lapElapsed ?? $elapsed)) {
            throw new RepairFailed('A positive recorded timer duration is required; it cannot exceed either elapsed duration.');
        }

        foreach ($sources as $source) {
            if ($source['value'] !== $timer) {
                throw new RepairFailed('Existing recorded timer durations disagree; none may be overwritten.');
            }
        }

        foreach ($events as $index => $event) {
            if (null === $event['timestamp'] || $event['timestamp'] < $start || $event['timestamp'] > $finish || !in_array($event['group'], [null, 0], true)
                || (0 === $index % 2 ? 0 !== $event['type'] : !in_array($event['type'], [1, 4], true))
                || $event['sequence'] >= $session->sourceSequence || ($index > 0 && $event['timestamp'] < $events[$index - 1]['timestamp'])) {
                throw new RepairFailed('Timer events contradict the single-session duration evidence.');
            }
        }

        $active = $paused = null;

        if (count($events) >= 2 && 0 === count($events) % 2) {
            $stop = $events[array_key_last($events)];

            if ($events[0]['timestamp'] !== $start || $events[0]['sequence'] >= $firstRecordSequence || $stop['timestamp'] !== $finish
                || abs($lastRecord - $stop['timestamp']) > 1 || $stop['sequence'] <= $lastRecordSequence) {
                throw new RepairFailed('Complete timer pairs must corroborate the recorded Session endpoints.');
            }

            $active = 0;

            for ($index = 0; $index < count($events); $index += 2) {
                $active += ($events[$index + 1]['timestamp'] - $events[$index]['timestamp']) * 1000;
            }

            $paused = ($finish - $start) * 1000 - $active;

            if (abs($active - $timer) > 1000 || abs($paused - ($elapsed - $timer)) > 1000) {
                throw new RepairFailed('Recorded timer active and paused spans disagree with supplied durations.');
            }
        } elseif (count($events) > 1 || $timer !== $elapsed
            || ([] !== $events && ($events[0]['timestamp'] !== $start || $events[0]['sequence'] >= $firstRecordSequence))
            || (null === $lapTimer && abs(($lastRecord - $start) * 1000 - $elapsed) > 1000)) {
            throw new RepairFailed('Without complete timer pairs, recorded equal timer/elapsed durations and independent Lap timer or complete Record span are required.');
        }

        if ($activityTimestamp === $start && (null === $active || null === $sessionTimer || $sessionTimer !== $activityTimer)) {
            throw new RepairFailed('An Activity timestamp at START requires a complete timer trace and identical recorded Session and Activity timers.');
        }

        $changes = [];

        foreach ([19 => [7 => [$lapElapsed, $elapsed], 8 => [$lapTimer, $timer]], 18 => [8 => [$sessionTimer, $timer]], 34 => [0 => [$activityTimer, $timer]]] as $number => $fields) {
            foreach ($fields as $field => [$existing, $value]) {
                if (null === $existing) {
                    $changes[] = ['message' => $summaries[$number]['message'], 'field' => $field, 'type' => 0x86, 'value' => $value];
                }
            }
        }

        if ([] === $changes) {
            throw new RepairFailed('No missing recorded duration requires propagation.');
        }

        $output = $this->apply($bytes, $header->headerSize, $summaries, $changes);

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE, 'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes, 'evidence' => [
                'session_sequence' => $session->sourceSequence, 'lap_sequence' => $lap->sourceSequence,
                'elapsed_source' => ['globalMessageNumber' => 18, 'field' => 7, 'value' => $elapsed], 'timer_sources' => $sources,
                'record_count' => $recordCount, 'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                'timer_events' => $events, 'timer_active_ms' => $active, 'timer_paused_ms' => $paused,
                'activity_timestamp' => $activityTimestamp,
                'event_encoding_policy' => 'Event.event and Event.event_type accept unique one-byte enum or uint8 fields with the same 0xFF invalid sentinel; original Event definitions and payloads are retained',
                'policy' => 'copy exact recorded durations only into missing fields of one whole-session Lap; clocks and timer evidence corroborate the values; no duration is inferred from rounded timestamps',
            ], 'preservation' => 'original data packets retained outside missing summary duration fields; active definitions restored after each edit',
            'activity_validation' => 'not_performed',
        ]);
    }

    /**
     * @param array<int, array{physical: PhysicalMessage, message: int, offset: int, length: int, definition: string}> $summaries
     * @param list<array{message: int, field: int, type: int, value: int}>                                             $changes
     */
    private function apply(string $bytes, int $headerSize, array $summaries, array $changes): string
    {
        $edits = [];

        foreach ($summaries as $summary) {
            $fields = [];

            foreach ($changes as $change) {
                if ($summary['message'] === $change['message']) {
                    $change['message'] = 0;
                    $fields[] = $change;
                }
            }

            if ([] === $fields) {
                continue;
            }

            // Verify the small summary packet with the existing physical editor.
            // All Records remain in their original bytes; no Record array is built.
            $packet = $summary['definition'].substr($bytes, $summary['offset'], $summary['length']);
            $header = "\x0E".substr($bytes, 1, 3).pack('V', strlen($packet)).'.FIT';
            $header .= pack('v', FitCrc16::calculate($header));
            $isolated = EditableFitFile::read($header.$packet.pack('v', FitCrc16::calculate($header.$packet)));
            $edited = substr($isolated->apply($fields), 14, -2);
            $replacement = substr($edited, strlen($summary['definition']));
            $edits[] = ['offset' => $summary['offset'], 'length' => $summary['length'], 'bytes' => $replacement];
        }

        usort($edits, static fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);
        $body = substr($bytes, 0, -2);

        foreach ($edits as $edit) {
            $body = substr_replace($body, $edit['bytes'], $edit['offset'], $edit['length']);
        }

        $body = substr_replace($body, pack('V', strlen($body) - $headerSize), 4, 4);

        if (14 === $headerSize) {
            $body = substr_replace($body, pack('v', FitCrc16::calculate(substr($body, 0, 12))), 12, 2);
        }

        return $body.pack('v', FitCrc16::calculate($body));
    }

    private function eventEnum(PhysicalMessage $message, int $number): ?int
    {
        // Some writers use uint8 for Event enum fields. Both carry the same
        // unsigned byte and invalid sentinel; no source Event is rewritten.
        $type = 0x00;

        foreach ($message->fields as $field) {
            if ($number === $field->definition->fieldNumber) {
                $type = 0x02 === $field->definition->baseType->definitionByte() ? 0x02 : 0x00;
                break;
            }
        }

        return $this->scalar($message, $number, $type, 1);
    }

    private function scalar(PhysicalMessage $message, int $number, int $type, int $size): ?int
    {
        $found = false;

        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }

            if ($found || $type !== $field->definition->baseType->definitionByte() || $size !== $field->definition->size) {
                throw new RepairFailed(sprintf('Recorded duration evidence requires unique fields of the expected physical type: global_message_number=%d, sequence=%s, field=%d, expected_type=0x%02X, expected_size=%d, actual_type=0x%02X, actual_size=%d, duplicate=%s.', $message->number, $message->sourceSequence ?? 'missing', $number, $type, $size, $field->definition->baseType->definitionByte(), $field->definition->size, $found ? 'yes' : 'no'));
            }

            $found = true;
        }

        return $message->integer($number);
    }
}
