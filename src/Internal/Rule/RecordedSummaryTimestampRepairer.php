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

/** Fills missing summary timestamps from an exact recorded partition and corroborating timer or SAVE clocks. */
final readonly class RecordedSummaryTimestampRepairer
{
    public const string RULE = 'missing_summary_recorded_timestamps';
    private const int LIMIT = 4096;

    public function repair(string $bytes): RepairResult
    {
        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate recorded summary timestamp input.');
        }

        $summaries = $definitions = $laps = $sessions = $activities = $events = [];
        $firstRecord = $lastRecord = $firstRecordSequence = $lastRecordSequence = null;
        $ordinal = $recordCount = $fileIdCount = 0;
        $preserved = hash_init('sha256');

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill recorded summary timestamp input.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;

            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2
                || !in_array($header->headerSize, [12, 14], true) || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Timestamp recovery requires one complete supported FIT member with a 12 or 14-byte header.');
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

                $this->hashPreserved($preserved, $raw);
                $number = $raw->globalMessageNumber();

                if (0 === $number) {
                    ++$fileIdCount;

                    if (0 !== $ordinal || 4 !== $this->scalar(PhysicalMessage::fromRaw($raw, 0), 0, 0x00, 1)) {
                        throw new RepairFailed('Timestamp recovery requires one Activity FileId as the first data message.');
                    }
                } elseif (in_array($number, [18, 19, 20, 21, 34], true)) {
                    $message = PhysicalMessage::fromRaw($raw, 0);

                    if (20 === $number) {
                        $timestamp = $this->scalar($message, 253, 0x86, 4);

                        if (null === $timestamp || $timestamp < 0x10000000 || (null !== $lastRecord && $timestamp < $lastRecord)) {
                            throw new RepairFailed('Timestamp recovery requires ordered absolute Record timestamps.');
                        }

                        $firstRecord ??= $timestamp;
                        $firstRecordSequence ??= $raw->sequence();
                        $lastRecord = $timestamp;
                        $lastRecordSequence = $raw->sequence();
                        ++$recordCount;
                    } elseif (21 === $number) {
                        $kind = $this->eventEnum($message, 0);

                        if (null === $kind) {
                            throw new RepairFailed('An Event with missing kind cannot exclude a timer contradiction.');
                        }

                        if (0 === $kind) {
                            $events[] = ['sequence' => $raw->sequence(), 'timestamp' => $this->scalar($message, 253, 0x86, 4),
                                'type' => $this->eventEnum($message, 1), 'group' => $this->scalar($message, 4, 0x02, 1)];
                        }
                    } else {
                        if ($raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Timestamp recovery requires uncompressed summaries.');
                        }

                        $length = 1;

                        foreach ([...$raw->standardFields(), ...$raw->developerFields()] as $field) {
                            if (0 === $field->definition->size) {
                                throw new RepairFailed('Timestamp recovery requires summary definitions without zero-size descriptors.');
                            }

                            $length += $field->definition->size;
                        }

                        $summaries[$ordinal] = ['physical' => $message, 'message' => $ordinal, 'offset' => $raw->byteOffset(), 'length' => $length,
                            'definition' => $definitions[$raw->definition()->localMessageNumber]];

                        if (19 === $number) {
                            $laps[] = $ordinal;
                        } elseif (18 === $number) {
                            $sessions[] = $ordinal;
                        } else {
                            $activities[] = $ordinal;
                        }
                    }
                }

                if (count($laps) > self::LIMIT || count($events) > self::LIMIT || count($sessions) > 1 || count($activities) > 1) {
                    throw new RepairFailed('Timestamp recovery requires bounded evidence for one Session and Activity.');
                }

                ++$ordinal;
            }

            $file->trailer();
        } finally {
            fclose($stream);
        }

        if (1 !== $fileIdCount || 1 !== count($sessions) || 1 !== count($activities) || [] === $laps || $recordCount < 2
            || [] === $events || (count($events) > 1 && 0 !== count($events) % 2)) {
            throw new RepairFailed('Timestamp recovery requires one Session, Activity, a complete Lap set, Records and complete timer pairs.');
        }

        $session = $summaries[$sessions[0]]['physical'];
        $activity = $summaries[$activities[0]]['physical'];
        $start = $this->scalar($session, 2, 0x86, 4);
        $elapsed = $this->scalar($session, 7, 0x86, 4);
        $timer = $this->scalar($session, 8, 0x86, 4);
        $sessionTimestamp = $this->scalar($session, 253, 0x86, 4);
        $activityTimestamp = $this->scalar($activity, 253, 0x86, 4);
        $savedEndpoint = 1 === count($events) && count($laps) > 1 && null !== $sessionTimestamp
            && $activityTimestamp === $sessionTimestamp && null !== $elapsed && $elapsed === $timer;

        if (1 === count($events) && !$savedEndpoint) {
            throw new RepairFailed('A single START requires matching existing Session and Activity SAVE clocks and unchanged unpaused durations.');
        }

        // Some exporters place all three summaries before the timer and Records.
        $front = 1 === count($laps) && null === $sessionTimestamp && null === $activityTimestamp
            && null === $this->scalar($summaries[$laps[0]]['physical'], 253, 0x86, 4)
            && $activity->sourceSequence < $session->sourceSequence
            && $session->sourceSequence < $summaries[$laps[0]]['physical']->sourceSequence
            && $summaries[$laps[0]]['physical']->sourceSequence < $events[0]['sequence'];

        if (null === $start || $start < 0x10000000 || $firstRecord < $start || $firstRecord > $start + 1 || null === $elapsed || $elapsed <= 0 || 0 !== $elapsed % 1000
            || null === $timer || $timer <= 0 || $timer > $elapsed || $this->scalar($activity, 0, 0x86, 4) !== $timer
            || 1 !== $this->scalar($activity, 1, 0x84, 2)
            || !in_array($this->scalar($session, 254, 0x84, 2), [null, 0], true)
            || !in_array($this->scalar($session, 25, 0x84, 2), [null, 0], true)
            || !in_array($this->scalar($session, 26, 0x84, 2), [null, count($laps)], true)
            || (!$front && ($lastRecordSequence >= $session->sourceSequence || $session->sourceSequence >= $activity->sourceSequence))) {
            throw new RepairFailed('Session, Activity, references and Records do not corroborate one exact recorded span.');
        }

        $finish = $start + intdiv($elapsed, 1000);

        if (($savedEndpoint ? $lastRecord > $finish || $sessionTimestamp > $finish + 1 : ($front ? $lastRecord < $finish - 1 || $lastRecord > $finish : $lastRecord !== $finish)) || (null !== $sessionTimestamp && $sessionTimestamp < $finish)
            || (null !== $activityTimestamp && $activityTimestamp < ($sessionTimestamp ?? $finish))) {
            throw new RepairFailed('Final Record and existing summary timestamps contradict the Session span.');
        }

        $active = 0;

        foreach ($events as $index => $event) {
            if (null === $event['timestamp'] || $event['timestamp'] < $start || $event['timestamp'] > $finish
                || !in_array($event['group'], [null, 0], true)
                || (0 === $index % 2 ? 0 !== $event['type'] : !in_array($event['type'], [1, 4], true))
                || ($index > 0 && $event['timestamp'] < $events[$index - 1]['timestamp'])
                || (!$front && $event['sequence'] >= $session->sourceSequence)) {
                throw new RepairFailed('Timestamp recovery requires one ordered alternating timer trace.');
            }

            if (1 === $index % 2) {
                $active += ($event['timestamp'] - $events[$index - 1]['timestamp']) * 1000;
            }
        }

        $stop = $events[array_key_last($events)];

        if ($events[0]['timestamp'] !== $start || $events[0]['sequence'] >= $firstRecordSequence || (!$savedEndpoint && $stop['timestamp'] !== $finish)
            || (!$savedEndpoint && ($stop['sequence'] <= $lastRecordSequence || $active !== $timer))) {
            throw new RepairFailed('The timer trace must exactly corroborate Session elapsed/active durations and endpoint Records.');
        }

        $changes = $evidence = [];
        $nextStart = $start;
        $lapElapsedSum = $lapTimerSum = 0;

        foreach ($laps as $index => $ordinal) {
            $lap = $summaries[$ordinal]['physical'];
            $lapStart = $this->scalar($lap, 2, 0x86, 4);
            $lapElapsed = $this->scalar($lap, 7, 0x86, 4);
            $lapTimer = $this->scalar($lap, 8, 0x86, 4);
            $timestamp = $this->scalar($lap, 253, 0x86, 4);

            if ($lapStart !== $nextStart || null === $lapElapsed || $lapElapsed <= 0 || 0 !== $lapElapsed % 1000
                || null === $lapTimer || $lapTimer < 0 || $lapTimer > $lapElapsed
                || !in_array($this->scalar($lap, 254, 0x84, 2), [null, $index], true)
                || (!$front && ($lap->sourceSequence <= $events[0]['sequence'] || $lap->sourceSequence >= $session->sourceSequence))) {
                throw new RepairFailed('Laps must form one complete, ordered exact partition with consistent existing indexes.');
            }

            $nextStart += intdiv($lapElapsed, 1000);
            $lapActive = 0;

            if ($savedEndpoint) {
                $lapActive = $lapElapsed;

                if ($lastRecord < $summaries[$laps[array_key_last($laps)]]['physical']->integer(2)
                    || $lastRecordSequence >= $summaries[$laps[0]]['physical']->sourceSequence) {
                    throw new RepairFailed('Missing Lap SAVE clocks require all original Records before the Lap summaries and a Record inside the final Lap.');
                }
            } else {
                for ($pair = 0; $pair < count($events); $pair += 2) {
                    $lapActive += max(0, min($nextStart, $events[$pair + 1]['timestamp']) - max($lapStart, $events[$pair]['timestamp'])) * 1000;
                }
            }

            if ($nextStart > $finish || $lapActive !== $lapTimer || (null !== $timestamp && ($timestamp < $nextStart || $timestamp > ($sessionTimestamp ?? $finish)))) {
                throw new RepairFailed('Lap elapsed/active durations and existing timestamps contradict the unchanged partition.');
            }

            $lapElapsedSum += $lapElapsed;
            $lapTimerSum += $lapTimer;

            if (null === $timestamp) {
                $value = $savedEndpoint && $index === count($laps) - 1 ? $sessionTimestamp : $nextStart;
                $changes[] = ['message' => $ordinal, 'field' => 253, 'type' => 0x86, 'value' => $value];
                $evidence[] = ['globalMessageNumber' => 19, 'sequence' => $lap->sourceSequence, 'value' => $value,
                    'start_time' => $lapStart, 'elapsed_ms' => $lapElapsed, 'timer_ms' => $lapTimer,
                    'next_lap_sequence' => isset($laps[$index + 1]) ? $summaries[$laps[$index + 1]]['physical']->sourceSequence : null,
                    'basis' => isset($laps[$index + 1]) ? 'next_lap_start_and_exact_recorded_duration' : ($savedEndpoint ? 'matching_existing_session_and_activity_save_clocks' : 'final_record_and_timer_stop')];
            }
        }

        if ($nextStart !== $finish || $lapElapsedSum !== $elapsed || $lapTimerSum !== $timer) {
            throw new RepairFailed('Unchanged Session durations must exactly equal the complete Lap sums.');
        }

        foreach ([[$sessions[0], $sessionTimestamp, $session, $finish], [$activities[0], $activityTimestamp, $activity, $sessionTimestamp ?? $finish]] as [$ordinal, $timestamp, $message, $value]) {
            if (null === $timestamp) {
                $changes[] = ['message' => $ordinal, 'field' => 253, 'type' => 0x86, 'value' => $value];
                $evidence[] = ['globalMessageNumber' => $message->number, 'sequence' => $message->sourceSequence, 'value' => $value,
                    'basis' => 34 === $message->number && null !== $sessionTimestamp ? 'existing_session_timestamp' : 'final_record_and_timer_stop'];
            }
        }

        if ([] === $changes) {
            throw new RepairFailed('No missing summary timestamp requires recovery.');
        }

        $output = $this->apply($bytes, $header->headerSize, $summaries, $changes);
        $this->verifyPreserved($output, hash_final($preserved));

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE, 'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes, 'evidence' => [
                'timestamps' => $evidence, 'session_elapsed_ms' => $elapsed, 'session_timer_ms' => $timer,
                'lap_elapsed_sum_ms' => $lapElapsedSum, 'lap_timer_sum_ms' => $lapTimerSum,
                'record_count' => $recordCount, 'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                'timer_events' => $events, 'timer_active_ms' => $savedEndpoint ? null : $active,
                'final_endpoint_source' => $savedEndpoint ? 'matching_existing_session_and_activity_save_clocks' : 'final_record_and_timer_stop',
                'policy' => $savedEndpoint
                    ? 'copy next recorded Lap starts for missing interior timestamps and the identical existing Session/Activity SAVE clock for the final timestamp only when the exact whole-second unpaused Lap partition and unchanged Session/Activity durations corroborate those clocks; no STOP event is invented'
                    : 'fill only missing timestamps from an exact recorded partition, next Lap starts and final Record/timer stop; no fractional duration rounding or original SAVE-time inference',
            ], 'preservation' => 'original packets retained outside missing summary timestamps; all other physical fields and compressed timestamps verified',
            'activity_validation' => 'not_performed',
        ]);
    }

    private function verifyPreserved(string $bytes, string $expected): void
    {
        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate timestamp preservation input.');
        }

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill timestamp preservation input.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $hash = hash_init('sha256');

            foreach ($file->messages() as $raw) {
                if ($raw instanceof RawDataRecord) {
                    $this->hashPreserved($hash, $raw);
                }
            }

            $file->trailer();

            if (hash_final($hash) !== $expected) {
                throw new RepairFailed('Timestamp recovery would change an original field or compressed timestamp.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function hashPreserved(\HashContext $hash, RawDataRecord $raw): void
    {
        $number = $raw->globalMessageNumber();
        hash_update($hash, pack('vCC', $number, $raw->recordHeaderByte(), $raw->definition()->architecture->value));

        $standardFields = array_filter($raw->standardFields(), static fn ($field): bool => !in_array($number, [18, 19, 34], true) || 253 !== $field->definition->fieldNumber);
        hash_update($hash, pack('C', count($standardFields)));

        foreach ($standardFields as $field) {
            hash_update($hash, pack('CCC', $field->definition->fieldNumber, $field->definition->size, $field->definition->baseType->definitionByte()).$field->value->bytes());
        }

        hash_update($hash, pack('C', count($raw->developerFields())));

        foreach ($raw->developerFields() as $field) {
            hash_update($hash, pack('CCC', $field->definition->fieldNumber, $field->definition->size, $field->definition->developerDataIndex).$field->value->bytes());
        }

        if ($raw instanceof RawCompressedTimestampDataMessage) {
            hash_update($hash, pack('V', $raw->reconstructedTimestamp));
        }
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
                throw new RepairFailed(sprintf('Recorded timestamp evidence requires unique fields of the expected physical type: global_message_number=%d, sequence=%s, field=%d, expected_type=0x%02X, expected_size=%d, actual_type=0x%02X, actual_size=%d, duplicate=%s.', $message->number, $message->sourceSequence ?? 'missing', $number, $type, $size, $field->definition->baseType->definitionByte(), $field->definition->size, $found ? 'yes' : 'no'));
            }

            $found = true;
        }

        return $message->integer($number);
    }
}
