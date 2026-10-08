<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Restores a recorded Lap boundary accidentally replaced by a timer resume. */
final readonly class PausedLapStartRepairer
{
    public const string RULE = 'lap_start_includes_recorded_pause';
    private const int LIMIT = 4096;

    public function repair(string $bytes): RepairResult
    {
        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate paused Lap repair input.');
        }

        $laps = $sessions = $activities = $events = [];
        $ordinal = 0;
        $missingEvent = false;

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill paused Lap repair input.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;

            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Paused Lap repair requires one complete supported FIT member at byte zero.');
            }

            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }

                $number = $raw->globalMessageNumber();

                if (in_array($number, [18, 19, 21, 34], true)) {
                    $message = PhysicalMessage::fromRaw($raw, 0);

                    if (18 === $number || 19 === $number) {
                        if ($raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Paused Lap repair requires uncompressed summaries.');
                        }

                        $summary = [
                            'message' => $ordinal, 'sequence' => $raw->sequence(), 'offset' => $raw->byteOffset(),
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
                        $activities[] = $this->scalar($message, 1, 0x84, 2);
                    } else {
                        $event = $this->scalar($message, 0, 0x00, 1);
                        $missingEvent = $missingEvent || null === $event;

                        if (0 !== $event) {
                            ++$ordinal;
                            continue;
                        }

                        $timestamp = $this->scalar($message, 253, 0x86, 4);
                        $type = $this->scalar($message, 1, 0x00, 1);
                        $group = $this->scalar($message, 4, 0x02, 1);

                        if (null === $timestamp || $timestamp < 0x10000000 || !in_array($type, [0, 1, 4], true)
                            || (null !== $group && 0 !== $group)
                            || ([] !== $events && $timestamp < $events[array_key_last($events)]['timestamp'])) {
                            throw new RepairFailed('Timer events do not establish one ordered, supported pause timeline.');
                        }

                        $events[] = ['sequence' => $raw->sequence(), 'timestamp' => $timestamp, 'type' => $type, 'group' => $group];
                    }
                }

                if (count($laps) > self::LIMIT || count($events) > self::LIMIT || count($sessions) > self::LIMIT || count($activities) > 1) {
                    throw new RepairFailed('Paused Lap repair requires bounded evidence for one Activity.');
                }

                ++$ordinal;
            }

            $file->trailer();
        } finally {
            fclose($stream);
        }

        if ([] === $sessions || [count($sessions)] !== $activities || count($laps) < 2) {
            throw new RepairFailed('Paused Lap repair requires complete Sessions, one matching Activity and at least two Laps.');
        }

        $session = $sessions[0];
        $firstLap = 0;

        if (1 < count($sessions)) {
            $candidate = null;
            $cursor = 0;

            foreach ($sessions as $index => $part) {
                if ($part['index'] !== $index || $part['first_lap'] !== $cursor || null === $part['num_laps'] || $part['num_laps'] < 1
                    || $cursor + $part['num_laps'] > count($laps) || null === $part['start'] || null === $part['elapsed'] || null === $part['timer']) {
                    throw new RepairFailed('Multiple Sessions require explicit indexes and a complete consecutive Lap partition.');
                }

                $partElapsed = $partTimer = 0;

                for ($lapIndex = $cursor; $lapIndex < $cursor + $part['num_laps']; ++$lapIndex) {
                    $lap = $laps[$lapIndex];

                    if ($lap['index'] !== $lapIndex || null === $lap['start'] || null === $lap['elapsed'] || null === $lap['timer']
                        || $lap['sequence'] >= $part['sequence'] || ($lapIndex === $cursor && $lap['start'] !== $part['start'])) {
                        throw new RepairFailed('Recorded Lap indexes, order and starts must identify their original Session.');
                    }

                    $partElapsed += $lap['elapsed'];
                    $partTimer += $lap['timer'];

                    if (null === $candidate && $lapIndex > 0
                        && abs($lap['start'] - $laps[$lapIndex - 1]['start'] - intdiv($laps[$lapIndex - 1]['elapsed'], 1000)) > 2) {
                        if ($lapIndex === $cursor) {
                            throw new RepairFailed('Pause recovery cannot move a Lap across its recorded Session boundary.');
                        }

                        $candidate = $part;
                    }
                }

                if ($partElapsed !== $part['elapsed'] || $partTimer !== $part['timer']) {
                    throw new RepairFailed('Each Session must retain its exact original Lap duration totals.');
                }

                $cursor += $part['num_laps'];
            }

            if ($cursor !== count($laps) || null === $candidate) {
                throw new RepairFailed('Multiple Sessions must cover every Lap and identify a failing boundary inside one Session.');
            }

            $session = $candidate;
            $firstLap = $session['first_lap'];
            $laps = array_slice($laps, $firstLap, $session['num_laps']);
        }

        if ($firstLap !== $session['first_lap'] || count($laps) !== $session['num_laps'] || (1 === count($sessions) && !in_array($session['index'], [null, 0], true))
            || null === $session['start'] || $session['start'] < 0x10000000 || null === $session['elapsed'] || null === $session['timer']) {
            throw new RepairFailed('Session references and durations do not establish the complete Lap partition.');
        }

        $elapsed = $timer = 0;
        $candidate = null;

        foreach ($laps as $index => $lap) {
            if (null === $lap['start'] || $lap['start'] < $session['start'] || null === $lap['timestamp'] || $lap['timestamp'] < $lap['start']
                || null === $lap['elapsed'] || null === $lap['timer'] || !in_array($lap['index'], [null, $firstLap + $index], true)) {
                throw new RepairFailed('Lap evidence is missing or contradicts the single-session partition.');
            }

            $elapsed += $lap['elapsed'];
            $timer += $lap['timer'];

            if (0 === $index) {
                if ($lap['start'] !== $session['start']) {
                    throw new RepairFailed('First Lap and Session start times disagree.');
                }

                continue;
            }

            $previous = $laps[$index - 1];
            $previousEnd = $previous['start'] + intdiv($previous['elapsed'], 1000);

            // Target only the first failing Lap boundary; later contradictions
            // must not cause unrelated edits ahead of the current diagnosis.
            if (null === $candidate && abs($lap['start'] - $previousEnd) > 2) {
                $candidate = $index;
            }
        }

        if ($elapsed !== $session['elapsed'] || $timer !== $session['timer']) {
            throw new RepairFailed('Existing Session and Lap duration sums must already agree.');
        }

        if (null === $candidate) {
            throw new RepairFailed('No non-abutting Lap boundary requires pause recovery.');
        }

        $lap = $laps[$candidate];
        $previous = $laps[$candidate - 1];
        $boundary = $previous['timestamp'];
        $previousResidual = ($boundary - $previous['start']) * 1000 - $previous['elapsed'];
        $newResidual = ($lap['timestamp'] - $boundary) * 1000 - $lap['elapsed'];
        $oldResidual = ($lap['timestamp'] - $lap['start']) * 1000 - $lap['elapsed'];

        $supported = $lap['start'] > $boundary + 2 && abs($previousResidual) <= 1000 && abs($newResidual) <= 1000 && abs($oldResidual) > 2000
            && $boundary >= $session['start'] && $boundary + intdiv($lap['elapsed'], 1000) <= $session['start'] + intdiv($session['elapsed'], 1000) + 1;

        $pair = null;

        for ($index = 1; $index < count($events); ++$index) {
            $stop = $events[$index - 1];
            $resume = $events[$index];

            if (in_array($stop['type'], [1, 4], true) && 0 === $resume['type']
                && $stop['timestamp'] === $boundary && $resume['timestamp'] === $lap['start']
                && $stop['sequence'] < $previous['sequence'] && $previous['sequence'] < $resume['sequence'] && $resume['sequence'] < $lap['sequence']) {
                if (null !== $pair) {
                    throw new RepairFailed('More than one timer pair supports the same Lap boundary.');
                }

                $pair = [$stop, $resume];
            }
        }

        $delayedSave = null;

        if (!$supported || null === $pair) {
            $delayedSave = $this->delayedSaveBoundary($previous, $lap, $session, $events, $missingEvent);
            $boundary = $delayedSave['pair'][0]['timestamp'];
            $pair = $delayedSave['pair'];
            $previousResidual = ($boundary - $previous['start']) * 1000 - $previous['elapsed'];
            $newResidual = ($lap['timestamp'] - $boundary) * 1000 - $lap['elapsed'];
        }

        $offset = $lap['start_offset'];
        $word = pack($lap['word'], $boundary);
        $body = substr_replace(substr($bytes, 0, -2), $word, $offset, 4);
        $output = $body.pack('v', FitCrc16::calculate($body));

        if (strlen($output) !== strlen($bytes) || substr($output, 0, $offset) !== substr($bytes, 0, $offset)
            || substr($output, $offset + 4, -2) !== substr($bytes, $offset + 4, -2)) {
            throw new RepairFailed('Paused Lap repair changed bytes outside the start field and file CRC.');
        }

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => [['message' => $lap['message'], 'field' => 2, 'type' => 0x86, 'value' => $boundary]],
            'original_start_time' => $lap['start'],
            'evidence' => [
                'session_index' => $session['index'], 'first_lap_index' => $firstLap,
                'lap_sequence' => $lap['sequence'], 'previous_lap_sequence' => $previous['sequence'],
                'previous_lap_timestamp' => $previous['timestamp'],
                'previous_lap_save_delay_seconds' => $previous['timestamp'] - $boundary,
                'delayed_save_timer_evidence' => $delayedSave,
                'previous_boundary_residual_ms' => $previousResidual,
                'original_boundary_residual_ms' => $oldResidual, 'repaired_boundary_residual_ms' => $newResidual,
                'timer_stop' => $pair[0], 'timer_resume' => $pair[1],
                'session_lap_elapsed_sum_ms' => $elapsed, 'session_lap_timer_sum_ms' => $timer,
                'policy' => null === $delayedSave
                    ? 'recorded previous Lap end and consecutive timer stop agree; old start equals resume; unchanged elapsed independently spans pause'
                    : 'recorded timer stop corroborated by adjacent elapsed and active durations; previous Lap was saved while stopped; old start equals resume; SAVE timestamp and all durations retained',
            ],
            'preservation' => 'all original bytes retained outside one Lap.start_time and file CRC',
            'activity_validation' => 'not_performed',
        ]);
    }

    /**
     * @param array{sequence: int, start: int|null, timestamp: int|null, elapsed: int|null, timer: int|null} $previous
     * @param array{sequence: int, start: int|null, timestamp: int|null, elapsed: int|null, timer: int|null} $lap
     * @param array{start: int|null, timestamp: int|null, elapsed: int|null, timer: int|null}                $session
     * @param list<array{sequence: int, timestamp: int, type: int, group: int|null}>                         $events
     *
     * @return array{pair: array{array{sequence: int, timestamp: int, type: int, group: int|null}, array{sequence: int, timestamp: int, type: int, group: int|null}}, previous_active_ms: int, lap_active_ms: int}
     */
    private function delayedSaveBoundary(array $previous, array $lap, array $session, array $events, bool $missingEvent): array
    {
        if (null === $previous['start'] || null === $previous['timestamp'] || null === $previous['elapsed'] || null === $previous['timer']
            || null === $lap['start'] || null === $lap['timestamp'] || null === $lap['elapsed'] || null === $lap['timer']
            || null === $session['start'] || null === $session['timestamp'] || null === $session['elapsed'] || null === $session['timer']
            || $missingEvent || count($events) < 4 || 0 !== count($events) % 2 || $events[0]['timestamp'] !== $session['start']
            || $events[array_key_last($events)]['timestamp'] > $session['timestamp']
            || $events[array_key_last($events)]['timestamp'] < $lap['timestamp']
            || $previous['elapsed'] <= 0 || $previous['timer'] <= 0 || $previous['timer'] > $previous['elapsed']
            || $lap['timer'] <= 0 || $lap['timer'] >= $lap['elapsed'] || $session['timer'] > $session['elapsed']) {
            throw new RepairFailed('Delayed Lap SAVE requires complete timer pairs and usable recorded durations.');
        }

        foreach ($events as $index => $event) {
            if ($event['timestamp'] < $session['start'] || (0 === $index % 2 ? 0 !== $event['type'] : !in_array($event['type'], [1, 4], true))) {
                throw new RepairFailed('Delayed Lap SAVE requires an alternating timer trace from the Session start.');
            }
        }

        $candidate = null;

        for ($index = 1; $index < count($events) - 1; $index += 2) {
            $stop = $events[$index];
            $resume = $events[$index + 1];
            $boundary = $stop['timestamp'];

            if ($resume['timestamp'] !== $lap['start'] || $previous['timestamp'] <= $boundary || $previous['timestamp'] > $resume['timestamp']
                || $stop['sequence'] >= $previous['sequence'] || $previous['sequence'] >= $resume['sequence'] || $resume['sequence'] >= $lap['sequence']
                || $lap['start'] <= $boundary + 2 || abs(($boundary - $previous['start']) * 1000 - $previous['elapsed']) > 1000
                || abs(($lap['timestamp'] - $boundary) * 1000 - $lap['elapsed']) > 1000
                || abs(($lap['timestamp'] - $lap['start']) * 1000 - $lap['elapsed']) <= 2000 || $boundary < $session['start']
                || $boundary + intdiv($lap['elapsed'], 1000) > $session['start'] + intdiv($session['elapsed'], 1000) + 1) {
                continue;
            }

            $previousActive = $this->activeSpan($events, $previous['start'], $boundary);
            $lapActive = $this->activeSpan($events, $boundary, $lap['timestamp']);

            if (abs($previousActive - $previous['timer']) > 1000 || abs($lapActive - $lap['timer']) > 1000) {
                continue;
            }

            if (null !== $candidate) {
                throw new RepairFailed('More than one recorded pause supports the delayed Lap SAVE boundary.');
            }

            $candidate = ['pair' => [$stop, $resume], 'previous_active_ms' => $previousActive, 'lap_active_ms' => $lapActive];
        }

        return $candidate ?? throw new RepairFailed('Delayed Lap SAVE clocks and active durations do not corroborate one recorded pause boundary.');
    }

    /** @param list<array{sequence: int, timestamp: int, type: int, group: int|null}> $events */
    private function activeSpan(array $events, int $start, int $finish): int
    {
        $active = 0;

        for ($index = 0; $index < count($events); $index += 2) {
            $active += max(0, min($finish, $events[$index + 1]['timestamp']) - max($start, $events[$index]['timestamp'])) * 1000;
        }

        return $active;
    }

    private function scalar(PhysicalMessage $message, int $number, int $type, int $size): ?int
    {
        $found = false;

        foreach ($message->fields as $field) {
            if ($number !== $field->definition->fieldNumber) {
                continue;
            }

            if ($found || $size !== $field->definition->size || $type !== $field->definition->baseType->definitionByte()) {
                throw new RepairFailed('Paused Lap evidence requires unique fields of the expected physical type.');
            }

            $found = true;
        }

        return $message->integer($number);
    }
}
