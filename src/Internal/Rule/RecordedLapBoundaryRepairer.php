<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\ActivityHeartRateTail;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Restores the final Lap start from corroborated recorded boundaries. */
final readonly class RecordedLapBoundaryRepairer
{
    public const string RULE = 'last_lap_start_from_recorded_boundary';
    private const int LIMIT = 4096;

    public function repair(string $bytes): RepairResult
    {
        [$member, $tail] = ActivityHeartRateTail::split($bytes);
        if ('' !== $tail) {
            return ActivityHeartRateTail::append($bytes, $tail, $this->repair($member));
        }

        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate recorded Lap boundary input.');
        }

        $laps = $sessions = $activities = $events = $recordSpans = [];
        $ordinal = $recordCount = 0;
        $firstRecord = $lastRecord = $lastRecordSequence = null;

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill recorded Lap boundary input.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;

            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Recorded Lap repair requires one complete supported FIT member at byte zero.');
            }

            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }

                $number = $raw->globalMessageNumber();

                if (in_array($number, [18, 19, 20, 21, 34], true)) {
                    $message = PhysicalMessage::fromRaw($raw, 0);

                    if (20 === $number) {
                        $timestamp = $message->integer(253);

                        if (null === $timestamp || $timestamp < 0x10000000 || (null !== $lastRecord && $timestamp < $lastRecord)) {
                            throw new RepairFailed('Recorded Lap repair requires ordered, absolute Record timestamps.');
                        }

                        $firstRecord ??= $timestamp;
                        $lastRecord = $timestamp;
                        $lastRecordSequence = $raw->sequence();
                        ++$recordCount;
                        $slot = count($sessions);
                        $recordSpans[$slot] ??= ['first' => $timestamp, 'last' => $timestamp, 'sequence' => $raw->sequence(), 'count' => 0];
                        $recordSpans[$slot]['last'] = $timestamp;
                        $recordSpans[$slot]['sequence'] = $raw->sequence();
                        ++$recordSpans[$slot]['count'];
                    } elseif (18 === $number || 19 === $number) {
                        if ($raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Recorded Lap repair requires uncompressed summaries.');
                        }

                        $summary = [
                            'message' => $ordinal, 'sequence' => $raw->sequence(),
                            'start' => $this->scalar($message, 2, 0x86, 4),
                            'timestamp' => $this->scalar($message, 253, 0x86, 4),
                            'elapsed' => $this->scalar($message, 7, 0x86, 4),
                            'timer' => $this->scalar($message, 8, 0x86, 4),
                            'index' => $this->scalar($message, 254, 0x84, 2),
                        ];

                        if (18 === $number) {
                            $summary['first_lap'] = $this->scalar($message, 25, 0x84, 2);
                            $summary['num_laps'] = $this->scalar($message, 26, 0x84, 2);
                            $sessions[] = $summary;
                        } else {
                            $position = $raw->byteOffset() + 1;

                            foreach ($raw->standardFields() as $field) {
                                if (2 === $field->definition->fieldNumber) {
                                    $summary['start_offset'] = $position;
                                }

                                $position += $field->definition->size;
                            }

                            $summary['word'] = FitArchitecture::LittleEndian === $raw->definition()->architecture ? 'V' : 'N';
                            $laps[] = $summary;
                        }
                    } elseif (34 === $number) {
                        $activities[] = [
                            'sequence' => $raw->sequence(), 'timestamp' => $this->scalar($message, 253, 0x86, 4),
                            'timer' => $this->scalar($message, 0, 0x86, 4), 'sessions' => $this->scalar($message, 1, 0x84, 2),
                        ];
                    } else {
                        $event = $this->scalar($message, 0, 0x00, 1);

                        if (null === $event) {
                            throw new RepairFailed('An Event with missing kind cannot exclude a timer contradiction.');
                        }

                        if (0 === $event) {
                            $events[] = [
                                'sequence' => $raw->sequence(), 'timestamp' => $this->scalar($message, 253, 0x86, 4),
                                'type' => $this->scalar($message, 1, 0x00, 1), 'group' => $this->scalar($message, 4, 0x02, 1),
                            ];
                        }
                    }
                }

                if (count($laps) > self::LIMIT || count($events) > self::LIMIT || count($sessions) > self::LIMIT || count($activities) > 1) {
                    throw new RepairFailed('Recorded Lap repair requires bounded evidence for Sessions and one Activity.');
                }

                ++$ordinal;
            }

            $file->trailer();
        } finally {
            fclose($stream);
        }

        if ([] === $sessions || 1 !== count($activities) || count($laps) < 2 || $recordCount < 2 || count($events) < 2 || 0 !== count($events) % 2) {
            throw new RepairFailed('Recorded Lap repair requires Sessions and one Activity, multiple Laps and Records, and complete timer pairs.');
        }

        $session = $sessions[0];
        $activity = $activities[0];
        $eventOrigin = $session['start'];
        $firstLapIndex = 0;
        $transition = null;
        $finalRecord = $lastRecord;
        $finalRecordSequence = $lastRecordSequence;

        if (count($sessions) > 1) {
            [$session, $laps, $transition] = $this->transitionPartition($sessions, $laps, $recordSpans, $activity);
            $firstLapIndex = $session['first_lap'];
            $finalRecord = $transition['records']['last'];
            $finalRecordSequence = $transition['records']['sequence'];
        }

        if ((null === $transition && (0 !== $session['index'] || 0 !== $session['first_lap'] || 1 !== $activity['sessions'] || $firstRecord !== $session['start']))
            || count($laps) !== $session['num_laps'] || null === $session['elapsed'] || null === $session['timer']
            || $session['timer'] > $session['elapsed'] || (null === $transition && $activity['timer'] !== $session['timer'])) {
            throw new RepairFailed('Session references, durations and first Record must establish one complete activity partition.');
        }

        $elapsed = $timer = 0;
        $sessionEnd = $session['start'] + intdiv($session['elapsed'], 1000);
        $last = count($laps) - 1;

        foreach ($laps as $index => $lap) {
            if ($index + $firstLapIndex !== $lap['index'] || null === $lap['start'] || null === $lap['timestamp'] || null === $lap['elapsed'] || null === $lap['timer']
                || $lap['start'] < $session['start'] || $lap['timestamp'] < $lap['start'] || $lap['elapsed'] <= 0 || $lap['timer'] > $lap['elapsed']
                || (0 === $index && $lap['start'] !== $session['start'])
                || ($index < $last && $lap['start'] + intdiv($lap['elapsed'], 1000) > $sessionEnd + 1)
                || ($index > 0 && ($lap['sequence'] <= $laps[$index - 1]['sequence'] || abs($lap['start'] - $laps[$index - 1]['start'] - intdiv($laps[$index - 1]['elapsed'], 1000)) > 2))) {
                throw new RepairFailed('Existing Lap partition contradicts the isolated final-boundary diagnosis.');
            }

            $elapsed += $lap['elapsed'];
            $timer += $lap['timer'];
        }

        if ($elapsed !== $session['elapsed'] || $timer !== $session['timer']) {
            throw new RepairFailed('Existing Session and Lap duration sums must already agree exactly.');
        }

        foreach ($events as $index => $event) {
            if (null === $event['timestamp'] || $event['timestamp'] < $eventOrigin || !in_array($event['group'], [null, 0], true)
                || (0 === $index % 2 ? 0 !== $event['type'] : !in_array($event['type'], [1, 4], true))
                || ($index > 0 && $event['timestamp'] < $events[$index - 1]['timestamp'])) {
                throw new RepairFailed('Timer events do not establish one ordered, alternating timeline.');
            }
        }

        $stop = $events[array_key_last($events)];
        $resume = $events[count($events) - 2];
        $lap = $laps[$last];
        $previous = $laps[$last - 1];
        $boundary = $previous['timestamp'];
        $finish = null === $transition ? $stop['timestamp'] : $session['timestamp'];
        $previousResidual = ($boundary - $previous['start']) * 1000 - $previous['elapsed'];
        $originalResidual = ($finish - $lap['start']) * 1000 - $lap['elapsed'];
        $repairedResidual = ($finish - $boundary) * 1000 - $lap['elapsed'];
        $originalExcess = $lap['start'] + intdiv($lap['elapsed'], 1000) - $sessionEnd;

        $paused = $lap['timer'] < $lap['elapsed'];
        $timerEvidence = $paused ? $this->pausedTimerEvidence($events, $previous, $lap, $stop) : null;

        if (null !== $transition) {
            $active = 0;

            for ($index = 0; $index < count($events); $index += 2) {
                $active += max(0, min($finish, $events[$index + 1]['timestamp']) - max($session['start'], $events[$index]['timestamp'])) * 1000;
            }

            if ($paused || $session['elapsed'] !== $session['timer'] || $active !== ($finish - $session['start']) * 1000
                || abs($active - $session['timer']) > 1000) {
                throw new RepairFailed('A recorded Session transition must have independently corroborated uninterrupted timer and unchanged durations.');
            }

            $transition['active_ms'] = $active;
        }

        if ($events[0]['timestamp'] !== $eventOrigin || $stop['timestamp'] !== $lastRecord || $lastRecord <= $firstRecord
            || abs(($finalRecord - $session['start']) * 1000 - $session['elapsed']) > 1000
            || (null === $transition && !$paused && ($resume['sequence'] >= $previous['sequence'] || $resume['timestamp'] > $boundary))
            || !($previous['sequence'] < $finalRecordSequence && $finalRecordSequence < $lap['sequence']
                && $lap['sequence'] < $session['sequence'] && $session['sequence'] < $activity['sequence'])
            || (null === $transition && !($lastRecordSequence < $stop['sequence'] && $stop['sequence'] < $lap['sequence']))
            || null === $session['timestamp'] || $session['timestamp'] < $lap['timestamp'] || null === $activity['timestamp'] || $activity['timestamp'] < $session['timestamp']
            || $lap['timestamp'] < $finish || 2 !== $originalExcess
            || $lap['start'] !== $boundary + 1 || abs($previousResidual) > 1000 || $originalResidual >= -1000 || abs($repairedResidual) > 1000) {
            throw new RepairFailed('Previous Lap end, unchanged durations and final recorded timer stop do not corroborate a one-second start correction.');
        }

        $offset = $lap['start_offset'];
        $body = substr_replace(substr($bytes, 0, -2), pack($lap['word'], $boundary), $offset, 4);
        $output = $body.pack('v', FitCrc16::calculate($body));

        if (strlen($output) !== strlen($bytes) || substr($output, 0, $offset) !== substr($bytes, 0, $offset)
            || substr($output, $offset + 4, -2) !== substr($bytes, $offset + 4, -2)) {
            throw new RepairFailed('Recorded Lap repair changed bytes outside the start field and file CRC.');
        }

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => [['message' => $lap['message'], 'field' => 2, 'type' => 0x86, 'value' => $boundary]],
            'original_start_time' => $lap['start'],
            'evidence' => [
                'lap_sequence' => $lap['sequence'], 'start_byte_offset' => $offset, 'previous_lap_sequence' => $previous['sequence'],
                'previous_lap_timestamp' => $boundary, 'previous_boundary_residual_ms' => $previousResidual,
                'original_stop_residual_ms' => $originalResidual, 'repaired_stop_residual_ms' => $repairedResidual,
                'original_session_end_excess_seconds' => $originalExcess, 'repaired_session_end_excess_seconds' => $originalExcess - 1,
                'record_count' => $recordCount, 'last_record_timestamp' => $finalRecord, 'timer_stop' => null === $transition ? $stop : null,
                'session_transition' => $transition,
                'finish_basis' => null === $transition ? 'timer_stop' : 'session_transition',
                'session_index' => $session['index'],
                'session_lap_elapsed_sum_ms' => $elapsed, 'session_lap_timer_sum_ms' => $timer,
                'final_lap_timer_evidence' => $timerEvidence,
                'policy' => 'previous recorded Lap end agrees with unchanged elapsed; corrected start independently spans the last Record and recorded timer stop or uninterrupted Session transition; native durations remain unchanged',
            ],
            'preservation' => 'all original bytes retained outside one Lap.start_time and file CRC',
            'activity_validation' => 'not_performed',
        ]);
    }

    /**
     * @param list<array<string, mixed>>                                          $sessions
     * @param list<array<string, mixed>>                                          $laps
     * @param array<int, array{first: int, last: int, sequence: int, count: int}> $recordSpans
     * @param array<string, mixed>                                                $activity
     *
     * @return array{array<string, mixed>, list<array<string, mixed>>, array<string, mixed>}
     */
    private function transitionPartition(array $sessions, array $laps, array $recordSpans, array $activity): array
    {
        $cursor = $timer = 0;
        $candidate = null;

        foreach ($sessions as $index => $session) {
            if ($index !== $session['index'] || $cursor !== $session['first_lap'] || null === $session['num_laps'] || $session['num_laps'] < 1
                || $cursor + $session['num_laps'] > count($laps) || null === $session['start'] || null === $session['timestamp']
                || null === $session['elapsed'] || null === $session['timer'] || $session['start'] < 0x10000000) {
                throw new RepairFailed('Session transitions require an explicit complete Lap partition.');
            }

            $elapsed = $lapTimer = 0;
            $last = $cursor + $session['num_laps'] - 1;

            for ($lapIndex = $cursor; $lapIndex <= $last; ++$lapIndex) {
                $lap = $laps[$lapIndex];

                if ($lapIndex !== $lap['index'] || null === $lap['elapsed'] || null === $lap['timer'] || null === $lap['start']
                    || $lap['sequence'] >= $session['sequence'] || ($lapIndex === $cursor && $lap['start'] !== $session['start'])) {
                    throw new RepairFailed('Session transitions require explicit Lap identities, order and native duration sums.');
                }

                $elapsed += $lap['elapsed'];
                $lapTimer += $lap['timer'];
            }

            if ($elapsed !== $session['elapsed'] || $lapTimer !== $session['timer']) {
                throw new RepairFailed('All Session and Lap duration sums must already agree exactly.');
            }

            $timer += $session['timer'];
            $finalLap = $laps[$last];

            if (null === $candidate && $session['num_laps'] > 1 && isset($sessions[$index + 1], $recordSpans[$index])
                && 2 === $finalLap['start'] + intdiv($finalLap['elapsed'], 1000) - $session['start'] - intdiv($session['elapsed'], 1000)) {
                $records = $recordSpans[$index];
                $next = $sessions[$index + 1];

                if (($session['timestamp'] !== $next['start'] || $session['timestamp'] !== $finalLap['timestamp']
                    || $session['timestamp'] !== $records['last'] || $session['sequence'] >= $next['sequence'])
                    || $records['count'] < 2 || $records['first'] < $session['start'] || $records['first'] > $laps[$last - 1]['timestamp']
                    || (0 === $index ? $records['first'] !== $session['start'] : $sessions[$index - 1]['timestamp'] !== $session['start'])) {
                    throw new RepairFailed('The next Session, final Lap and last Record must independently identify the same transition.');
                }

                $candidate = [$session, array_slice($laps, $cursor, $session['num_laps']), [
                    'session_index' => $index, 'next_session_index' => $next['index'], 'timestamp' => $session['timestamp'], 'records' => $records,
                ]];
            }

            $cursor = $last + 1;
        }

        if ($cursor !== count($laps) || $activity['sessions'] !== count($sessions) || $activity['timer'] !== $timer || null === $candidate
            || $sessions[array_key_last($sessions)]['sequence'] >= $activity['sequence'] || null === $activity['timestamp']
            || $activity['timestamp'] < $sessions[array_key_last($sessions)]['timestamp']) {
            throw new RepairFailed('A complete activity partition must identify an isolated recorded Session-transition boundary.');
        }

        return $candidate;
    }

    /**
     * @param list<array{sequence: int, timestamp: int|null, type: int|null, group: int|null}> $events
     * @param array<string, mixed>                                                             $previous
     * @param array<string, mixed>                                                             $lap
     * @param array{sequence: int, timestamp: int|null, type: int|null, group: int|null}       $stop
     *
     * @return array<string, mixed>
     */
    private function pausedTimerEvidence(array $events, array $previous, array $lap, array $stop): array
    {
        $boundary = $previous['timestamp'];
        $finish = $stop['timestamp'];
        $active = $paused = 0;
        $pauses = [];

        foreach ($events as $event) {
            if ($event['timestamp'] > $boundary && $event['sequence'] < $previous['sequence']) {
                throw new RepairFailed('A timer event after the recorded Lap boundary precedes that Lap physically.');
            }

            if ($event['timestamp'] < $boundary && $event['sequence'] > $previous['sequence']) {
                throw new RepairFailed('A timer event before the recorded Lap boundary follows that Lap physically.');
            }
        }

        for ($index = 0; $index < count($events); $index += 2) {
            $start = $events[$index];
            $end = $events[$index + 1];
            $active += max(0, min($finish, $end['timestamp']) - max($boundary, $start['timestamp'])) * 1000;

            if (!isset($events[$index + 2])) {
                continue;
            }

            $resume = $events[$index + 2];
            $from = max($boundary, $end['timestamp']);
            $to = min($finish, $resume['timestamp']);

            if ($to <= $from) {
                continue;
            }

            if ($end['timestamp'] < $boundary || $resume['timestamp'] > $finish) {
                throw new RepairFailed('A pause crossing a final Lap boundary does not establish an unambiguous Lap timer span.');
            }

            $paused += ($to - $from) * 1000;
            $pauses[] = ['stop' => $end, 'resume' => $resume];
        }

        $activeResidual = $active - $lap['timer'];
        $pauseResidual = $paused - ($lap['elapsed'] - $lap['timer']);

        if ([] === $pauses || $lap['timer'] <= 0 || abs($activeResidual) > 1000 || abs($pauseResidual) > 1000) {
            throw new RepairFailed('Recorded final Lap active spans and complete pauses must independently agree with unchanged timer and elapsed durations within one second.');
        }

        return [
            'active_ms' => $active, 'paused_ms' => $paused,
            'active_residual_ms' => $activeResidual, 'pause_residual_ms' => $pauseResidual,
            'pauses' => $pauses,
        ];
    }

    private function scalar(PhysicalMessage $message, int $number, int $type, int $size): ?int
    {
        $found = false;

        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }

            if ($found || $size !== $field->definition->size || $type !== $field->definition->baseType->definitionByte()) {
                throw new RepairFailed('Recorded Lap evidence requires unique fields of the expected physical type.');
            }

            $found = true;
        }

        return $message->integer($number);
    }
}
