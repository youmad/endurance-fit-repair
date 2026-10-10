<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** @internal Adds corroborated Activity or Lap summaries without guessing lost segmentation. */
final readonly class MissingSessionLapRepairer
{
    public const string ACTIVITY_RULE = 'missing_activity_from_recorded_front_session';
    public const string NATIVE_SUMMARY_RULE = 'missing_lap_from_native_session_and_early_save';

    public function repairActivity(EditableFitFile $file): RepairResult
    {
        $session = null;
        $sessionIndex = $firstRecordIndex = $firstRecord = $lastRecord = null;
        $fileIds = $records = 0;
        foreach ($file->messages as $index => $entry) {
            $message = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);
            if (34 === $message->number) {
                throw new RepairFailed('An existing Activity must never be duplicated.');
            }
            if (0 === $message->number) {
                ++$fileIds;
                if (0 !== $index || 4 !== $this->integer($message, 0, 0x00, 1)) {
                    throw new RepairFailed('Missing Activity recovery requires the original Activity FileId first.');
                }
            } elseif (18 === $message->number) {
                if (null !== $session || $message->wasCompressed) {
                    throw new RepairFailed('Missing Activity recovery requires one uncompressed original Session.');
                }
                $session = $message;
                $sessionIndex = $index;
            } elseif (19 === $message->number || 101 === $message->number) {
                throw new RepairFailed('Front Session recovery does not replace existing Lap or Length segmentation.');
            } elseif (21 === $message->number) {
                $kind = $this->eventByte($message, 0);
                if (null === $kind || 0 === $kind) {
                    throw new RepairFailed('Front Session recovery excludes missing Event kinds and timer traces.');
                }
            } elseif (20 === $message->number) {
                $time = $this->integer($message, 253, 0x86, 4);
                if (null === $time || $time < 0x10000000 || (null !== $lastRecord && $time < $lastRecord)) {
                    throw new RepairFailed('Missing Activity recovery requires ordered absolute Record timestamps.');
                }
                $firstRecord ??= $time;
                $firstRecordIndex ??= $index;
                $lastRecord = $time;
                ++$records;
            }
        }
        if (1 !== $fileIds || null === $session || null === $sessionIndex || null === $firstRecordIndex
            || $sessionIndex >= $firstRecordIndex || $records < 2 || null === $lastRecord) {
            throw new RepairFailed('Missing Activity recovery requires a complete front Session followed by Records.');
        }
        $start = $this->integer($session, 2, 0x86, 4);
        $timestamp = $this->integer($session, 253, 0x86, 4);
        $elapsed = $this->integer($session, 7, 0x86, 4);
        $timer = $this->integer($session, 8, 0x86, 4);
        if (null === $start || $start !== $firstRecord || $timestamp !== $start || null === $elapsed || $elapsed <= 0
            || $timer !== $elapsed || abs(($lastRecord - $start) * 1000 - $elapsed) > 1000
            || !in_array($this->integer($session, 254, 0x84, 2), [null, 0], true)
            || !in_array($this->integer($session, 25, 0x84, 2), [null, 0], true)
            || !in_array($this->integer($session, 26, 0x84, 2), [null, 0, 1], true)
            || null === $this->integer($session, 5, 0x00, 1)) {
            throw new RepairFailed('Native Session counters and Record endpoints must corroborate the frozen front clock without lost segmentation.');
        }
        $changes = [['message' => $sessionIndex, 'field' => 253, 'type' => 0x86, 'value' => $lastRecord]];
        $activity = (new PhysicalMessage(34, $session->architecture))
            ->withInteger(253, 0x86, $lastRecord)
            ->withInteger(0, 0x86, $timer)
            ->withInteger(1, 0x84, 1)
            ->withInteger(2, 0x00, 0)
            ->withInteger(3, 0x00, 26)
            ->withInteger(4, 0x00, 1);
        $bytes = EditableFitFile::read($file->apply($changes))->append($activity);

        return new RepairResult($bytes, [
            'mode' => 'preserve', 'rule' => self::ACTIVITY_RULE,
            'input_sha256' => hash('sha256', $file->bytes), 'output_sha256' => hash('sha256', $bytes),
            'changes' => $changes,
            'inserted_messages' => [[
                'globalMessageNumber' => 34, 'afterOriginalMessage' => count($file->messages) - 1,
                'synthetic' => true, 'basis' => 'one_recorded_front_session_and_corroborating_record_span',
                'sourceSequence' => $session->sourceSequence,
                'fields' => array_map(static fn ($field): array => ['number' => $field->definition->fieldNumber, 'value' => $activity->integer($field->definition->fieldNumber)], $activity->fields),
            ]],
            'evidence' => ['record_count' => $records, 'first_record_timestamp' => $firstRecord, 'last_record_timestamp' => $lastRecord,
                'original_session_timestamp' => $timestamp, 'session_elapsed_ms' => $elapsed, 'session_timer_ms' => $timer,
                'elapsed_residual_ms' => ($lastRecord - $start) * 1000 - $elapsed,
                'local_timestamp' => 'unknown; a separate local-clock policy is required'],
            'preservation' => 'original messages, durations, observations and unknown/developer fields retained; only the frozen Session timestamp, definitions and CRC changed; one explicitly synthetic Activity appended',
            'activity_validation' => 'not_performed',
        ]);
    }

    public function repair(EditableFitFile $file, bool $nativeSummary = false): RepairResult
    {
        if ($nativeSummary && strlen($file->bytes) > 4_194_304) {
            throw new RepairFailed('Native early-save summary evidence is bounded to 4 MiB.');
        }
        $sessions = [];
        $hasLaps = false;

        foreach ($file->messages as $index => $entry) {
            if (18 === $entry['raw']->globalMessageNumber()) {
                $sessions[$index] = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);
            } elseif (19 === $entry['raw']->globalMessageNumber()) {
                $hasLaps = true;
            }
        }

        if ($hasLaps) {
            if ($nativeSummary) {
                throw new RepairFailed('An early-save aggregate must never replace existing Laps.');
            }

            return $this->result($file, $file->bytes, [], []);
        }

        if (1 !== count($sessions)) {
            throw new RepairFailed('A synthetic Lap requires exactly one existing Session and no Laps.');
        }

        $first = $file->messages[0]['raw'];
        $fileId = PhysicalMessage::fromRaw($first, $file->header->headerSize);

        if (0 !== $fileId->number || 4 !== $this->integer($fileId, 0, 0x00, 1)) {
            throw new RepairFailed('A synthetic Lap requires an Activity file_id as the first message.');
        }

        $index = array_key_first($sessions);
        $session = $sessions[$index];
        $messageIndex = $this->integer($session, 254, 0x84, 2);
        $firstLap = $this->integer($session, 25, 0x84, 2);
        $lapCount = $this->integer($session, 26, 0x84, 2);

        if (null !== $lapCount && 1 < $lapCount) {
            if ($nativeSummary) {
                throw new RepairFailed('Native early-save recovery cannot replace lost Lap segmentation.');
            }

            return (new RecordedSetLapRepairer())->repair($file);
        }

        if ((null !== $messageIndex && 0 !== $messageIndex) || (null !== $firstLap && 0 !== $firstLap)
            || (null !== $lapCount && !in_array($lapCount, [0, 1], true))) {
            throw new RepairFailed('Existing Session indexes or lap references indicate lost segmentation.');
        }

        $start = $this->integer($session, 2, 0x86, 4);
        $timestamp = $this->integer($session, 253, 0x86, 4);
        $elapsed = $this->integer($session, 7, 0x86, 4);
        $timer = $this->integer($session, 8, 0x86, 4);
        $sport = $this->integer($session, 5, 0x00, 1);

        if (null === $start || null === $timestamp || null === $elapsed || null === $timer || null === $sport
            || 0x10000000 > $start || $timestamp < $start || $timer - $elapsed >= 1000) {
            throw new RepairFailed('A synthetic Lap requires consistent Session timestamps, elapsed time, timer time and sport.');
        }

        // Preserve both precise counters when their excess is less than the
        // one-second timestamp resolution; do not round either source value.

        $last = null;
        $records = ['count' => 0, 'first' => null, 'first_sequence' => null, 'last' => null, 'last_sequence' => null];

        foreach ($file->messages as $entry) {
            if (20 !== $entry['raw']->globalMessageNumber()) {
                continue;
            }

            $record = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);
            $time = $this->integer($record, 253, 0x86, 4);

            if (null === $time || $time < $start || $time > $start + intdiv($elapsed, 1000) + 1
                || (null !== $last && $time < $last)) {
                throw new RepairFailed('Records do not establish a valid timeline within the sole Session.');
            }

            $last = $time;
            $records['first'] ??= $time;
            $records['first_sequence'] ??= $entry['raw']->sequence();
            $records['last'] = $time;
            $records['last_sequence'] = $entry['raw']->sequence();
            ++$records['count'];
        }

        $evidence = $nativeSummary ? $this->nativeSummaryEvidence($file, $session, $records) : [];

        if (!$nativeSummary && abs($timestamp * 1000 - ($start * 1000 + $elapsed)) > 1000) {
            if (1 !== $lapCount || $timestamp * 1000 <= $start * 1000 + $elapsed) {
                throw new RepairFailed('A delayed Session save requires an explicitly recorded single Lap.');
            }
            $evidence = $this->delayedSaveEvidence($file, $session, $records);
        }

        $lap = (new PhysicalMessage(19, $session->architecture))
            ->withInteger(254, 0x84, 0)
            ->withInteger(253, 0x86, $timestamp)
            ->withInteger(2, 0x86, $start)
            ->withInteger(7, 0x86, $elapsed)
            ->withInteger(8, 0x86, $timer)
            ->withInteger(0, 0x00, 9)
            ->withInteger(1, 0x00, 1)
            ->withInteger(25, 0x00, $nativeSummary ? 7 : $sport);

        foreach ([9 => [9, 0x86, 4], 6 => [39, 0x00, 1]] as $source => [$target, $type, $size]) {
            $value = $this->integer($session, $source, $type, $size);

            if (null !== $value) {
                $lap = $lap->withInteger($target, $type, $value);
            }
        }

        $changes = [];

        foreach ([254 => [$messageIndex, 0], 25 => [$firstLap, 0], 26 => [$lapCount, 1]] as $field => [$existing, $value]) {
            if ($existing !== $value) {
                $changes[] = ['message' => $index, 'field' => $field, 'type' => 0x84, 'value' => $value];
            }
        }

        $updated = EditableFitFile::read($file->apply($changes));
        $bytes = $updated->insertBefore($index, $lap);
        $insertions = [[
            'globalMessageNumber' => 19,
            'beforeOriginalMessage' => $index,
            'synthetic' => true,
            'basis' => 'one_existing_session_without_laps',
            'sourceSequence' => $session->sourceSequence,
            'sourceByteOffset' => $session->sourceOffset,
            'fields' => array_map(static fn ($field): array => ['number' => $field->definition->fieldNumber, 'value' => $lap->integer($field->definition->fieldNumber)], $lap->fields),
        ]];

        $result = $this->result($file, $bytes, $changes, $insertions, $evidence);

        return $nativeSummary ? new RepairResult($bytes, [...$result->report, 'rule' => self::NATIVE_SUMMARY_RULE,
            'inference' => ['historical_accuracy' => 'not_proven', 'policy' => 'retain native Session counters and SAVEs; the new whole-session Lap is an aggregate with unknown original segmentation and pauses; do not invent a STOP or alter an Activity timer copied from elapsed']]) : $result;
    }

    /** @param array{count: int, first: int|null, first_sequence: int|null, last: int|null, last_sequence: int|null} $records
     * @return array<string, mixed>
     */
    private function nativeSummaryEvidence(EditableFitFile $file, PhysicalMessage $s, array $records): array
    {
        $start = $this->integer($s, 2, 0x86, 4);
        $save = $this->integer($s, 253, 0x86, 4);
        $elapsed = $this->integer($s, 7, 0x86, 4);
        $timer = $this->integer($s, 8, 0x86, 4);
        $fileIds = 0;
        $activity = null;
        $starts = [];
        foreach ($file->messages as $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (in_array($m->number, [101, 142, 225], true)) {
                throw new RepairFailed('Native detailed segmentation takes precedence over an early-save aggregate Lap.');
            }
            if (0 === $m->number) {
                if (++$fileIds > 1 || $this->integer($m, 4, 0x86, 4) !== $start) {
                    throw new RepairFailed('Native creation and Session start clocks must corroborate the early SAVE.');
                }
            } elseif (34 === $m->number) {
                if (null !== $activity || $m->wasCompressed) {
                    throw new RepairFailed('Native early-save recovery requires one explicit Activity.');
                }
                $activity = $m;
            } elseif (21 === $m->number) {
                $kind = $this->eventByte($m, 0);
                if (null === $kind) {
                    throw new RepairFailed('An unknown Event cannot corroborate native summary counters.');
                }
                if (0 === $kind) {
                    if ($m->wasCompressed || 0 !== $this->eventByte($m, 1) || !in_array($this->integer($m, 4, 0x02, 1), [null, 0], true)) {
                        throw new RepairFailed('Native early-save recovery requires only an original initial START, without a contradictory timer trace.');
                    }
                    $starts[] = $m;
                }
            }
        }
        if (null === $start || null === $save || null === $elapsed || null === $timer || $timer <= 0 || $timer >= $elapsed
            || 1 !== $fileIds || null === $activity || 1 !== count($starts) || $records['count'] < 2
            || !in_array($records['first'], [$start, $start + 1], true) || $save !== $records['last']
            || $save * 1000 >= $start * 1000 + $elapsed || $start * 1000 + $elapsed - $save * 1000 > 2000
            || !in_array($this->integer($s, 26, 0x84, 2), [null, 0], true)
            || $this->integer($starts[0], 253, 0x86, 4) !== $records['first'] || $starts[0]->sourceSequence >= $records['first_sequence']
            || 1 !== $this->integer($activity, 1, 0x84, 2) || $save !== $this->integer($activity, 253, 0x86, 4)
            || !in_array($this->integer($activity, 0, 0x86, 4), [$timer, $elapsed], true)) {
            throw new RepairFailed('Original creation, initial START, endpoint Records and unchanged Session/Activity counters must corroborate the bounded early SAVE without lost Lap references.');
        }

        return ['basis' => 'native_session_with_corroborated_early_save', 'record_count' => $records['count'],
            'native_elapsed_ms' => $elapsed, 'native_timer_ms' => $timer, 'save_residual_ms' => ($save - $start) * 1000 - $elapsed,
            'original_timer_history' => 'initial_START_only; unrecorded_pause_intervals_and_terminal_STOP_unknown'];
    }

    /**
     * @param array{count: int, first: int|null, first_sequence: int|null, last: int|null, last_sequence: int|null} $records
     *
     * @return array<string, mixed>
     */
    private function delayedSaveEvidence(EditableFitFile $file, PhysicalMessage $session, array $records): array
    {
        $start = $this->integer($session, 2, 0x86, 4);
        $save = $this->integer($session, 253, 0x86, 4);
        $elapsed = $this->integer($session, 7, 0x86, 4);
        $timer = $this->integer($session, 8, 0x86, 4);
        $events = $activities = [];
        $fileIds = 0;

        foreach ($file->messages as $entry) {
            $number = $entry['raw']->globalMessageNumber();

            if (0 === $number) {
                ++$fileIds;
            } elseif (21 === $number) {
                $event = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);
                $kind = $this->eventByte($event, 0);

                if (null === $kind) {
                    throw new RepairFailed('A missing Event kind cannot exclude a timer contradiction.');
                }

                if (0 === $kind) {
                    $events[] = [
                        'sequence' => $event->sourceSequence,
                        'timestamp' => $this->integer($event, 253, 0x86, 4),
                        'type' => $this->eventByte($event, 1),
                        'group' => $this->integer($event, 4, 0x02, 1),
                    ];
                }
            } elseif (34 === $number) {
                $activities[] = PhysicalMessage::fromRaw($entry['raw'], $file->header->headerSize);
            }

            if (4096 < count($events) || 1 < count($activities)) {
                throw new RepairFailed('Delayed save evidence requires bounded timer events and one Activity.');
            }
        }

        if (null === $start || null === $save || null === $elapsed || null === $timer || 1 !== $fileIds
            || $records['count'] < 2 || $records['first'] !== $start || null === $records['last']
            || count($events) < 2 || 0 !== count($events) % 2 || 1 !== count($activities)) {
            throw new RepairFailed('A delayed save requires one Activity and complete recorded boundaries and timer pairs.');
        }

        $previous = null;
        $active = 0;

        foreach ($events as $ordinal => $event) {
            $time = $event['timestamp'];

            if (null === $time || $time < $start || $time > $records['last'] || !in_array($event['group'], [null, 0], true)
                || (0 === $ordinal % 2 ? 0 !== $event['type'] : !in_array($event['type'], [1, 4], true))
                || (null !== $previous && $time < $previous)) {
                throw new RepairFailed('A delayed save requires an ordered, alternating timer timeline within the Records.');
            }

            if (1 === $ordinal % 2) {
                $active += ($time - $previous) * 1000;
            }

            $previous = $time;
        }

        $stop = $events[array_key_last($events)];
        $activity = $activities[0];
        $elapsedResidual = ($records['last'] - $start) * 1000 - $elapsed;
        $timerResidual = $active - $timer;
        $pauseResidual = ($records['last'] - $start) * 1000 - $active - ($elapsed - $timer);

        if ($events[0]['timestamp'] !== $start || $events[0]['sequence'] >= $records['first_sequence']
            || $stop['timestamp'] !== $records['last'] || $records['last_sequence'] >= $stop['sequence']
            || $stop['sequence'] >= $session->sourceSequence || $session->sourceSequence >= $activity->sourceSequence
            || $save <= $records['last'] || abs($elapsedResidual) > 1000 || abs($timerResidual) > 1000 || abs($pauseResidual) > 1000
            || 1 !== $this->integer($activity, 1, 0x84, 2) || $save !== $this->integer($activity, 253, 0x86, 4)
            || $timer !== $this->integer($activity, 0, 0x86, 4)) {
            throw new RepairFailed('Records, timer events and Activity do not corroborate the sole Session and its delayed save.');
        }

        return [
            'basis' => 'recorded_single_lap_and_timer_span_with_delayed_save',
            'record_count' => $records['count'], 'first_record_timestamp' => $records['first'], 'last_record_timestamp' => $records['last'],
            'timer_events' => $events, 'timer_active_ms' => $active,
            'elapsed_residual_ms' => $elapsedResidual, 'timer_residual_ms' => $timerResidual, 'pause_residual_ms' => $pauseResidual,
            'session_save_timestamp' => $save, 'save_delay_seconds' => $save - $records['last'],
            'policy' => 'one explicitly recorded Lap; complete timer pairs and first/last Records corroborate unchanged durations within one-second timestamp resolution; preserve the original Session and Activity save times',
        ];
    }

    private function eventByte(PhysicalMessage $message, int $number): ?int
    {
        $type = 0x00;

        foreach ($message->fields as $field) {
            if ($number === $field->definition->fieldNumber && 0x02 === $field->definition->baseType->definitionByte()) {
                $type = 0x02;
            }
        }

        return $this->integer($message, $number, $type, 1);
    }

    private function integer(PhysicalMessage $message, int $number, int $type, int $size): ?int
    {
        $found = false;

        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }

            if ($found || $type !== $field->definition->baseType->definitionByte() || $size !== $field->definition->size) {
                throw new RepairFailed('Synthetic Lap evidence contains an ambiguous or incompatible physical field.');
            }

            $found = true;
        }

        return $message->integer($number);
    }

    /**
     * @param list<array{message: int, field: int, type: int, value: int}> $changes
     * @param list<array<string, mixed>>                                   $insertions
     * @param array<string, mixed>                                         $evidence
     */
    private function result(EditableFitFile $source, string $bytes, array $changes, array $insertions, array $evidence = []): RepairResult
    {
        return new RepairResult($bytes, [
            'mode' => 'preserve',
            'rule' => PreservingActivityRepairer::MISSING_LAP,
            'input_sha256' => hash('sha256', $source->bytes),
            'output_sha256' => hash('sha256', $bytes),
            'changes' => $changes,
            'inserted_messages' => $insertions,
            'evidence' => $evidence,
            'preservation' => 'verified',
            'activity_validation' => 'not_performed',
        ]);
    }
}
