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

/** Repairs elapsed counters from a complete native partition without changing timers or starts. */
final readonly class RecordedLapElapsedRepairer
{
    public const string RULE = 'recorded_lap_elapsed_partition';
    public const string CLOCK_RULE = 'recorded_lap_elapsed_partition_with_native_start';

    public function repair(string $bytes, bool $restoreStart = false): RepairResult
    {
        [$member, $tail] = ActivityHeartRateTail::split($bytes);
        if ('' !== $tail) {
            return ActivityHeartRateTail::append($bytes, $tail, $this->repair($member, $restoreStart));
        }
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new RepairFailed('Cannot allocate elapsed partition input.');
        }
        $laps = $sessions = $activities = $stops = $starts = [];
        $firstRecord = $lastRecord = null;
        $lapFirstRecord = null;
        $ordinal = 0;
        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill elapsed partition input.');
            }
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;
            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2 || 2 < $header->protocolMajorVersion()) {
                throw new RepairFailed('Elapsed partition repair requires one complete supported FIT member.');
            }
            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }
                $number = $raw->globalMessageNumber();
                if (in_array($number, [18, 19, 20, 21, 34], true)) {
                    $m = PhysicalMessage::fromRaw($raw, 0);
                    if (20 === $number) {
                        $timestamp = $m->integer(253);
                        if (null === $timestamp || $timestamp < 0x10000000 || (null !== $lastRecord && $timestamp < $lastRecord)) {
                            throw new RepairFailed('Elapsed partition requires ordered absolute Records.');
                        }
                        $firstRecord ??= $timestamp;
                        $lapFirstRecord ??= $timestamp;
                        $lastRecord = $timestamp;
                    } elseif (21 === $number) {
                        if (0 === $m->integer(0) && 0 === $m->integer(1)) {
                            $starts[] = $m->integer(253);
                        }
                        if (0 === $m->integer(0) && in_array($m->integer(1), [1, 4, 8, 9], true)) {
                            $stops[] = $m->integer(253);
                        }
                    } elseif (34 === $number) {
                        $seen = [];
                        foreach ($m->fields as $field) {
                            $n = $field->definition->fieldNumber;
                            $type = match ($n) {
                                0, 253 => 0x86, 1 => 0x84, default => null,
                            };
                            if (null !== $type && (isset($seen[$n]) || $type !== $field->definition->baseType->definitionByte()
                                || (0x86 === $type ? 4 : 2) !== $field->definition->size)) {
                                throw new RepairFailed('Activity evidence requires unique scalar native clocks and counters.');
                            }
                            $seen[$n] = true;
                        }
                        $activities[] = $m;
                    } else {
                        if ($raw instanceof RawCompressedTimestampDataMessage) {
                            throw new RepairFailed('Elapsed partition requires explicit summary clocks.');
                        }
                        $entry = ['message' => $m, 'ordinal' => $ordinal, 'record' => $lastRecord, 'first_record' => $lapFirstRecord, 'offset' => null, 'start_offset' => null,
                            'word' => FitArchitecture::LittleEndian === $m->architecture ? 'V' : 'N'];
                        $position = $raw->byteOffset() + 1;
                        $seen = [];
                        foreach ($m->fields as $field) {
                            $n = $field->definition->fieldNumber;
                            $type = match ($n) {
                                2, 7, 8, 253 => 0x86, 254 => 0x84, 25, 26 => 18 === $number ? 0x84 : null, default => null,
                            };
                            if (null !== $type && (isset($seen[$n]) || (0x86 === $type ? 4 : 2) !== $field->definition->size || $type !== $field->definition->baseType->definitionByte())) {
                                throw new RepairFailed('Elapsed evidence requires scalar uint32 clocks and counters.');
                            }
                            $seen[$n] = true;
                            if (2 === $n) {
                                $entry['start_offset'] = $position;
                            }
                            if (7 === $field->definition->fieldNumber) {
                                if (null !== $entry['offset']) {
                                    throw new RepairFailed('Duplicate elapsed counter.');
                                }
                                $entry['offset'] = $position;
                            }
                            $position += $field->definition->size;
                        }
                        if (18 === $number) {
                            $sessions[] = $entry;
                        } else {
                            $laps[] = $entry;
                            $lapFirstRecord = null;
                        }
                    }
                }
                if (count($laps) > 4096 || count($sessions) > 128 || count($activities) > 1 || count($stops) > 4096 || count($starts) > 4096) {
                    throw new RepairFailed('Elapsed partition evidence exceeds its bounded summary limit.');
                }
                ++$ordinal;
            }
            $file->trailer();
        } finally {
            fclose($stream);
        }
        if ([] === $sessions || [] === $laps || 1 !== count($activities) || null === $firstRecord || null === $lastRecord) {
            throw new RepairFailed('Elapsed partition requires native Sessions, Laps, one Activity and Records.');
        }
        $activity = $activities[0];
        if ($activity->integer(1) !== count($sessions)) {
            throw new RepairFailed('Native Activity must declare the complete Session count.');
        }
        $cursor = $timerTotal = 0;
        $changes = [];
        $output = $bytes;
        foreach ($sessions as $sessionIndex => $session) {
            $s = $session['message'];
            $count = $s->integer(26);
            $start = $s->integer(2);
            $timer = $s->integer(8);
            if ($s->integer(254) !== $sessionIndex || $s->integer(25) !== $cursor || null === $count || $count < 1
                || null === $start || $start < 0x10000000 || null === $timer || null === $session['offset']) {
                throw new RepairFailed('Native Session indexes, references and durations must define the complete partition.');
            }
            $part = array_slice($laps, $cursor, $count);
            if (count($part) !== $count || $part[0]['message']->integer(2) !== $start) {
                throw new RepairFailed('Native Session must start at its first Lap and contain all declared Laps.');
            }
            $sumTimer = 0;
            $frozen = true;
            if ($restoreStart) {
                foreach ($part as $i => &$lap) {
                    if (0 === $i) {
                        continue;
                    }
                    $m = $lap['message'];
                    $previous = $part[$i - 1];
                    $p = $previous['message'];
                    $a = $m->integer(2);
                    $save = $p->integer(253);
                    if (null !== $a && null !== $save && null !== $p->integer(2) && null !== $p->integer(7)
                        && null !== $lap['start_offset'] && $a < $save - 2 && null !== $previous['record']
                        && $previous['record'] <= $save && $previous['record'] >= $save - 1 && $lap['first_record'] === $save + 1
                        && abs($p->integer(2) * 1000 + $p->integer(7) - $save * 1000) <= 999
                        && null !== $m->integer(7) && null !== $m->integer(253)
                        && abs($a * 1000 + $m->integer(7) - $m->integer(253) * 1000) <= 999) {
                        $lap['message'] = $m->withInteger(2, 0x86, $save + 1);
                        $output = substr_replace($output, pack($lap['word'], $save + 1), $lap['start_offset'], 4);
                        $changes[] = ['message' => $lap['ordinal'], 'field' => 2, 'type' => 0x86, 'value' => $save + 1];
                    }
                }
                unset($lap);
            }
            foreach ($part as $i => $lap) {
                $m = $lap['message'];
                $t = $m->integer(8);
                $a = $m->integer(2);
                if ($m->integer(254) !== $cursor + $i || null === $a || null === $t || null === $m->integer(7)
                    || null === $lap['offset'] || $t < 0 || ($i > 0 && ($a < $part[$i - 1]['message']->integer(2)
                        || ($a === $part[$i - 1]['message']->integer(2) && (!$restoreStart || $part[$i - 1]['message']->integer(7) > 999))))) {
                    throw new RepairFailed('Native Laps must have unique ordered starts, complete indexes and original counters.');
                }
                $sumTimer += $t;
                $frozen = $frozen && $m->integer(253) === $start;
            }
            if (abs($sumTimer - $timer) > 1) {
                throw new RepairFailed('Original Session timer must corroborate the sum of original Lap timers.');
            }
            $timerTotal += $timer;
            $sumElapsed = 0;
            $elapsedRows = [];
            foreach ($part as $i => $lap) {
                $m = $lap['message'];
                $a = $m->integer(2) ?? throw new \LogicException('Checked Lap start.');
                $old = $m->integer(7) ?? throw new \LogicException('Checked Lap elapsed.');
                $t = $m->integer(8) ?? throw new \LogicException('Checked Lap timer.');
                $next = $part[$i + 1] ?? $laps[$cursor + $count] ?? null;
                if (null !== $next) {
                    $end = $next['message']->integer(2) ?? throw new RepairFailed('Next Lap start is missing.');
                    if (!$frozen && (null === $m->integer(253) || abs($m->integer(253) - $end) > 2)
                        && (null === $lap['record'] || abs($lap['record'] - $end) > 2)
                        && !(in_array($m->integer(253), $stops, true) && (in_array($end, $starts, true)
                            || ($restoreStart && [] !== array_filter($starts, static fn (?int $t): bool => null !== $t && abs($t - $end) <= 1))))) {
                        throw new RepairFailed('Next original Lap start requires an independent saved or Record boundary.');
                    }
                    $elapsed = $restoreStart && $end === $a && $old <= 999 ? $old : ($end - $a) * 1000;
                } else {
                    $nativeEnd = $a * 1000 + $old;
                    $sessionEnd = $start * 1000 + ($s->integer(7) ?? 0);
                    $nativeStop = array_filter($stops, static fn (?int $t): bool => null !== $t && $t >= $lastRecord && abs($nativeEnd - $t * 1000) <= 999);
                    if ([] !== $nativeStop && $lastRecord * 1000 <= $nativeEnd + 999) {
                        $elapsed = $old;
                    } elseif (abs($nativeEnd - $lastRecord * 1000) <= 2000) {
                        $elapsed = max($nativeEnd, $lastRecord * 1000) - $a * 1000;
                    } elseif (abs($sessionEnd - $lastRecord * 1000) <= 999 && $sessionEnd > $a * 1000) {
                        $elapsed = max($sessionEnd, $lastRecord * 1000) - $a * 1000;
                    } elseif (in_array($lastRecord, $stops, true)
                        || ($s->integer(253) === $lastRecord && $activity->integer(253) === $lastRecord)) {
                        $elapsed = ($lastRecord - $a) * 1000;
                    } elseif ($restoreStart && $nativeEnd < $lastRecord * 1000 && $sessionEnd < $lastRecord * 1000
                        && null !== $m->integer(253) && $m->integer(253) >= $lastRecord
                        && null !== $s->integer(253) && $s->integer(253) >= $lastRecord
                        && null !== $activity->integer(253) && $activity->integer(253) >= $lastRecord) {
                        // A minimum observed extent, not a recovered STOP or additional active time.
                        $elapsed = ($lastRecord - $a) * 1000;
                    } else {
                        throw new RepairFailed('Final elapsed boundary requires original Record and timer or summary corroboration.');
                    }
                }
                if ($elapsed <= 0 || $t > $elapsed + 1999) {
                    throw new RepairFailed('Original Lap timer contradicts its native elapsed boundary.');
                }
                $elapsed = max($elapsed, $t);
                $sumElapsed += $elapsed;
                $elapsedRows[] = ['lap' => $lap, 'old' => $old, 'elapsed' => $elapsed];
            }
            $nextSessionStart = isset($sessions[$sessionIndex + 1]) ? $sessions[$sessionIndex + 1]['message']->integer(2) : null;
            $span = null === $nextSessionStart ? null : ($nextSessionStart - $start) * 1000;
            if ($restoreStart && null !== $span && $span >= $timer && $sumElapsed > $span + 1999) {
                if (!isset($laps[$cursor + $count]) || $laps[$cursor + $count]['message']->integer(2) !== $nextSessionStart) {
                    throw new RepairFailed('Session rounding cannot replace missing native Lap segmentation.');
                }
                // Whole-second Lap clocks can accumulate rounding across a Session.
                // Admit only a feasible partition within the two-second
                // boundary tolerance, never reduce any original active timer.
                $remaining = $sumElapsed - $span;
                foreach ($elapsedRows as &$row) {
                    $m = $row['lap']['message'];
                    $i = $m->integer(254) - $cursor;
                    $nextStart = ($part[$i + 1] ?? $laps[$cursor + $count])['message']->integer(2);
                    $clockSpan = ($nextStart - $m->integer(2)) * 1000;
                    $lower = max(1, $m->integer(8), $clockSpan - 1999);
                    $subtract = min($remaining, max(0, $row['elapsed'] - $lower));
                    $row['elapsed'] -= $subtract;
                    $remaining -= $subtract;
                }
                unset($row);
                if (0 !== $remaining) {
                    throw new RepairFailed('Native timer counters do not admit a complete Session partition inside clock resolution.');
                }
                $sumElapsed = $span;
            }
            foreach ($elapsedRows as $row) {
                $lap = $row['lap'];
                if ($row['old'] !== $row['elapsed']) {
                    $output = substr_replace($output, pack($lap['word'], $row['elapsed']), $lap['offset'], 4);
                    $changes[] = ['message' => $lap['ordinal'], 'field' => 7, 'type' => 0x86, 'value' => $row['elapsed']];
                }
            }
            if ($sumElapsed < $timer || $sumElapsed > 0xFFFFFFFE) {
                throw new RepairFailed('Native timer cannot exceed corrected Session elapsed.');
            }
            if ($s->integer(7) !== $sumElapsed) {
                $output = substr_replace($output, pack($session['word'], $sumElapsed), $session['offset'], 4);
                $changes[] = ['message' => $session['ordinal'], 'field' => 7, 'type' => 0x86, 'value' => $sumElapsed];
            }
            $cursor += $count;
        }
        if ($cursor !== count($laps) || null === $activity->integer(0) || abs($activity->integer(0) - $timerTotal) > 1
            || $firstRecord < ($sessions[0]['message']->integer(2) ?? PHP_INT_MAX) || [] === $changes) {
            throw new RepairFailed('Original Activity timer and physical Lap count must confirm complete coverage and a necessary correction.');
        }
        $output = substr($output, 0, -2).pack('v', FitCrc16::calculate(substr($output, 0, -2)));

        return new RepairResult($output, [
            'mode' => 'normalize_recorded_durations', 'rule' => $restoreStart ? self::CLOCK_RULE : self::RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inference' => ['historical_accuracy' => 'not_proven', 'source' => 'complete_native_lap_partition_and_unchanged_timer_totals',
                'observed_extent_fallback_enabled' => $restoreStart, 'observed_extent_policy' => 'if both original final elapsed counters end before Records and all native final SAVEs include Records, extend only elapsed to the minimum observed extent; original STOP and additional active time remain unknown',
                'session_rounding_policy' => 'when enabled, cumulative Lap elapsed rounding may be reconciled to the next native Session start only inside two-second Lap boundary resolution and above every unchanged native Lap timer'],
            'preservation' => 'existing Lap/Session elapsed counters and CRC changed; optional isolated Lap start recovery requires the previous SAVE/end/Record and next first Record to agree; all timers, pause Events, physical Laps and measurements retained',
            'activity_validation' => 'not_performed',
        ]);
    }
}
