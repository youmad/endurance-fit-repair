<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitFileHeader;
use Youmad\Endurance\Fit\Raw\RawCompressedTimestampDataMessage;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Reinstates missing whole-activity summaries using native counters or a complete timer trace. */
final readonly class RecordedActivitySummaryRepairer
{
    public const string RULE = 'missing_summaries_from_recorded_activity_bounds';
    public const string PREFIX_RULE = 'missing_summaries_from_open_recorded_timer_prefix';
    public const string SESSION_TIMERS_RULE = 'missing_session_timers_from_native_activity_total';
    public const string SUMMARY_ONLY_RULE = 'missing_activity_and_lap_from_native_summary_only_session';
    public const string OBSERVED_SESSION_RULE = 'missing_single_session_timer_from_native_activity_and_observed_extent';

    public function repairObservedSessionTimer(string $bytes): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Observed Session timer propagation requires a bounded FIT member.');
        }
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new RepairFailed('Cannot allocate observed Session input.');
        }
        $summaries = $sports = [];
        $created = $first = $last = $prefixEnd = null;
        $ordinal = $fileIds = $recordCount = 0;
        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill observed Session input.');
            }
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;
            if (0 !== $header->byteOffset || strlen($bytes) !== $header->headerSize + $header->dataSize + 2) {
                throw new RepairFailed('Observed Session recovery requires one complete FIT member.');
            }
            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }
                $m = PhysicalMessage::fromRaw($raw, 0);
                if ($raw instanceof RawCompressedTimestampDataMessage || !in_array($m->number, [0, 12, 18, 20, 34], true)) {
                    throw new RepairFailed('Observed Session recovery cannot replace segmentation, Events or implicit timestamp dependencies.');
                }
                $types = match ($m->number) {
                    0 => [0 => 0x00, 4 => 0x86],
                    12 => [0 => 0x00],
                    18 => [2 => 0x86, 7 => 0x86, 8 => 0x86, 253 => 0x86, 254 => 0x84, 25 => 0x84, 26 => 0x84, 70 => 0x84, 5 => 0x00],
                    20 => [253 => 0x86],
                    34 => [0 => 0x86, 1 => 0x84, 5 => 0x86, 253 => 0x86],
                };
                $seen = [];
                foreach ($m->fields as $f) {
                    $n = $f->definition->fieldNumber;
                    if (isset($types[$n])) {
                        $type = $types[$n];
                        if (isset($seen[$n]) || !in_array($f->definition->baseType->definitionByte(), 0x00 === $type ? [0x00, 0x02] : [$type], true)
                            || (0x86 === $type ? 4 : (0x84 === $type ? 2 : 1)) !== $f->definition->size) {
                            throw new RepairFailed('Observed Session evidence requires unique scalar native clocks, counters and references.');
                        }
                        $seen[$n] = true;
                    }
                }
                if (0 === $m->number) {
                    if (++$fileIds > 1 || 0 !== $ordinal || 4 !== $m->integer(0)) {
                        throw new RepairFailed('Observed Session recovery requires one native Activity FileId first.');
                    }
                    $created = $m->integer(4);
                } elseif (12 === $m->number) {
                    if (null !== $first || count($sports) > 128) {
                        throw new RepairFailed('Observed Session sport metadata must precede Records and remain bounded.');
                    }
                    $sports[] = $m->integer(0);
                } elseif (20 === $m->number) {
                    $t = $m->integer(253);
                    if (null === $t || $t < 0x10000000 || (null !== $last && $t < $last)) {
                        throw new RepairFailed('Observed Session recovery requires ordered absolute Records.');
                    }
                    $prefixEnd ??= $raw->byteOffset();
                    $first ??= $t;
                    $last = $t;
                    ++$recordCount;
                } else {
                    if (null !== $first || isset($summaries[$m->number])) {
                        throw new RepairFailed('Observed Session recovery requires sole native front Session and Activity summaries.');
                    }
                    $summaries[$m->number] = ['index' => $ordinal, 'm' => $m];
                }
                ++$ordinal;
            }
            $file->trailer();
        } finally {
            fclose($stream);
        }
        if (1 !== $fileIds || 2 !== count($summaries) || $recordCount < 2 || null === $first || null === $last || null === $prefixEnd || $prefixEnd > 65_536) {
            throw new RepairFailed('Observed Session recovery requires a bounded native summary prefix and multiple Records.');
        }
        $s = $summaries[18]['m'];
        $a = $summaries[34]['m'];
        $start = $s->integer(2);
        $elapsed = $s->integer(7);
        $timer = $a->integer(0);
        $local = $a->integer(5);
        if (null === $start || $start < 0x10000000 || $created !== $start || $s->integer(253) !== $start || $a->integer(253) !== $start
            || null === $elapsed || null === $timer || $timer <= 0 || $timer !== $elapsed || null !== $s->integer(8)
            || 1 !== $a->integer(1) || !in_array($s->integer(254), [null, 0], true) || !in_array($s->integer(25), [null, 0], true)
            || !in_array($s->integer(26), [null, 0, 1], true) || !in_array($s->integer(70), [null, 0], true)
            || null === $s->integer(5) || [] !== array_filter($sports, static fn (?int $sport): bool => null !== $sport && $sport !== $s->integer(5))
            || $first < $start || $first > $start + 1 || ($last - $start) * 1000 <= $elapsed || ($last - $start) * 1000 > 0xFFFFFFFE
            || null === $local || $local < 0x10000000 || $local - $start < -43200 || $local - $start > 50400 || 0 !== ($local - $start) % 900
            || $local + $last - $start > 0xFFFFFFFE) {
            throw new RepairFailed('One native Activity timer must corroborate the frozen sole Session elapsed without lost references or conflicting clocks.');
        }
        $changes = [];
        foreach ([18 => [8 => [0x86, $timer], 7 => [0x86, ($last - $start) * 1000], 253 => [0x86, $last], 254 => [0x84, 0], 25 => [0x84, 0], 26 => [0x84, 1]],
            34 => [253 => [0x86, $last], 5 => [0x86, $local + $last - $start]]] as $number => $fields) {
            foreach ($fields as $n => [$type, $value]) {
                if ($summaries[$number]['m']->integer($n) !== $value) {
                    $changes[] = ['message' => $summaries[$number]['index'], 'field' => $n, 'type' => $type, 'value' => $value];
                }
            }
        }
        // Edit only the small prefix; the potentially large Record tail remains byte-for-byte intact.
        $prefix = $this->withData($header, substr($bytes, $header->headerSize, $prefixEnd - $header->headerSize));
        $prefix = EditableFitFile::read($prefix)->apply($changes);
        $lap = (new PhysicalMessage(19, $s->architecture))->withInteger(254, 0x84, 0)->withInteger(2, 0x86, $start)
            ->withInteger(253, 0x86, $last)->withInteger(7, 0x86, ($last - $start) * 1000)->withInteger(8, 0x86, $timer);
        $prefix = EditableFitFile::read($prefix)->insertBefore($summaries[18]['index'], $lap);
        $output = $this->withData($header, substr($prefix, $header->headerSize, -2)
            .substr($bytes, $prefixEnd, $header->headerSize + $header->dataSize - $prefixEnd));

        return new RepairResult($output, ['mode' => 'normalize_summary_clock', 'rule' => self::OBSERVED_SESSION_RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inserted_messages' => [['globalMessageNumber' => 19, 'beforeOriginalMessage' => $summaries[18]['index'], 'synthetic' => true,
                'basis' => 'native_single_session_aggregate_original_lap_segmentation_unknown']],
            'evidence' => ['unchanged_activity_timer_ms' => $timer, 'original_session_elapsed_ms' => $elapsed,
                'minimum_observed_elapsed_ms' => ($last - $start) * 1000, 'record_count' => $recordCount],
            'inference' => ['historical_accuracy' => 'not_proven', 'source' => 'sole_native_activity_timer_and_frozen_front_session',
                'policy' => 'copy the sole Activity timer into the missing Session timer; extend elapsed and frozen SAVEs only to the minimum observed extent; original segmentation, actual finish and pause locations remain unknown; no STOP invented; retain the original local/UTC difference without establishing a physical timezone'],
            'preservation' => 'native Activity timer, all measurements, metadata and original summary measurements retained; original Record tail bytes unchanged',
            'activity_validation' => 'not_performed']);
    }

    private function withData(FitFileHeader $header, string $data): string
    {
        $bytes = substr_replace($header->rawBytes(), pack('V', strlen($data)), 4, 4);
        if (14 === $header->headerSize) {
            $bytes = substr_replace($bytes, pack('v', FitCrc16::calculate(substr($bytes, 0, 12))), 12, 2);
        }
        $bytes .= $data;

        return $bytes.pack('v', FitCrc16::calculate($bytes));
    }

    public function repairSummaryOnly(string $bytes): RepairResult
    {
        if (strlen($bytes) > 65_536) {
            throw new RepairFailed('Summary-only recovery is bounded to a small native summary member.');
        }
        $file = EditableFitFile::read($bytes);
        $session = null;
        $position = null;
        $created = null;
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (!in_array($m->number, [0, 18], true) || $m->wasCompressed) {
                throw new RepairFailed('Summary-only recovery must never replace observations, segmentation, Events or an Activity.');
            }
            $types = 0 === $m->number ? [0 => 0x00, 4 => 0x86] : [2 => 0x86, 7 => 0x86, 8 => 0x86, 253 => 0x86, 254 => 0x84, 25 => 0x84, 26 => 0x84, 70 => 0x84, 5 => 0x00];
            $seen = [];
            foreach ($m->fields as $field) {
                $n = $field->definition->fieldNumber;
                if (!isset($types[$n])) {
                    continue;
                }
                $type = $types[$n];
                if (isset($seen[$n]) || !in_array($field->definition->baseType->definitionByte(), 0x00 === $type ? [0x00, 0x02] : [$type], true)
                    || (0x86 === $type ? 4 : (0x84 === $type ? 2 : 1)) !== $field->definition->size) {
                    throw new RepairFailed('Summary-only native clocks and counters require unique scalar fields.');
                }
                $seen[$n] = true;
            }
            if (0 === $m->number) {
                if (0 !== $index || 4 !== $m->integer(0)) {
                    throw new RepairFailed('Summary-only recovery requires one Activity FileId first.');
                }
                $created = $m->integer(4);
            } else {
                if (null !== $session) {
                    throw new RepairFailed('Summary-only recovery requires exactly one original Session.');
                }
                $session = $m;
                $position = $index;
            }
        }
        if (null === $session || null === $position || 2 !== count($file->messages)) {
            throw new RepairFailed('Summary-only recovery requires only a native Activity FileId and Session.');
        }
        $start = $session->integer(2);
        $elapsed = $session->integer(7);
        $timer = $session->integer(8);
        $save = $session->integer(253);
        if (null === $start || $start < 0x10000000 || $created !== $start || $save !== $start
            || null === $elapsed || $elapsed <= 0 || null === $timer || $timer < 0 || $timer > $elapsed
            || !in_array($session->integer(254), [null, 0], true) || !in_array($session->integer(25), [null, 0], true)
            || !in_array($session->integer(26), [null, 0, 1], true) || !in_array($session->integer(70), [null, 0], true)
            || null === $session->integer(5) || $start + intdiv($elapsed, 1000) > 0xFFFFFFFE) {
            throw new RepairFailed('Summary-only recovery requires corroborated native creation/start clocks and usable original durations without lost Lap references.');
        }
        $changes = [];
        foreach ([254 => 0, 25 => 0, 26 => 1] as $field => $value) {
            if ($session->integer($field) !== $value) {
                $changes[] = ['message' => $position, 'field' => $field, 'type' => 0x84, 'value' => $value];
            }
        }
        $lap = (new PhysicalMessage(19, $session->architecture))->withInteger(254, 0x84, 0)->withInteger(2, 0x86, $start)
            ->withInteger(253, 0x86, $save)->withInteger(7, 0x86, $elapsed)->withInteger(8, 0x86, $timer);
        $activity = (new PhysicalMessage(34, $session->architecture))->withInteger(253, 0x86, $save)
            ->withInteger(0, 0x86, $timer)->withInteger(1, 0x84, 1)->withInteger(2, 0x00, 0);
        $output = EditableFitFile::read($file->apply($changes))->insertManyBefore($position, [$lap]);
        $output = EditableFitFile::read($output)->append($activity);

        return new RepairResult($output, ['mode' => 'restore_native_summary_only_activity', 'rule' => self::SUMMARY_ONLY_RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes,
            'inserted_messages' => [['globalMessageNumber' => 19, 'beforeOriginalMessage' => $position, 'synthetic' => true,
                'basis' => 'native_session_aggregate_original_segmentation_unknown']], 'generated_messages' => [34],
            'evidence' => ['native_start' => $start, 'native_elapsed_ms' => $elapsed, 'native_timer_ms' => $timer, 'observations' => 0],
            'inference' => ['historical_accuracy' => 'not_proven',
                'policy' => 'copy the original Session clocks and counters into explicitly synthetic aggregate Lap/Activity summaries; preserve the absence of measurements and Events; original finish, pauses, missing Lap segmentation and local time are unknown'],
            'activity_validation' => 'not_performed']);
    }

    public function repairSessionTimers(string $bytes): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Missing Session timers require a bounded FIT member.');
        }
        $file = EditableFitFile::read($bytes);
        $sessions = $records = [];
        $activity = null;
        $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (0 === $m->number && (++$fileIds > 1 || 0 !== $index || 4 !== $m->integer(0))) {
                throw new RepairFailed('Missing Session timers require one original Activity FileId first.');
            }
            if (in_array($m->number, [19, 101, 142, 225], true) || (21 === $m->number && in_array($m->integer(0), [null, 0], true))) {
                throw new RepairFailed('Native segmentation or a timer trace takes precedence over aggregate timer propagation.');
            }
            $types = match ($m->number) {
                18 => [2 => 0x86, 7 => 0x86, 8 => 0x86, 253 => 0x86, 254 => 0x84, 25 => 0x84, 26 => 0x84],
                20 => [253 => 0x86],
                34 => [0 => 0x86, 1 => 0x84, 253 => 0x86],
                default => [],
            };
            $seen = [];
            foreach ($m->fields as $f) {
                $n = $f->definition->fieldNumber;
                if (isset($types[$n])) {
                    if (isset($seen[$n]) || $types[$n] !== $f->definition->baseType->definitionByte() || (0x86 === $types[$n] ? 4 : 2) !== $f->definition->size) {
                        throw new RepairFailed('Aggregate timer evidence requires unique scalar native clocks, counters and references.');
                    }
                    $seen[$n] = true;
                }
            }
            if (18 === $m->number) {
                if ($m->wasCompressed) {
                    throw new RepairFailed('Session timer propagation requires explicit summary clocks.');
                }
                $sessions[] = ['index' => $index, 'm' => $m];
            } elseif (34 === $m->number) {
                if (null !== $activity) {
                    throw new RepairFailed('Session timer propagation requires one native Activity.');
                }
                $activity = $m;
            } elseif (20 === $m->number) {
                $t = $m->integer(253);
                if (null === $t || $t < 0x10000000 || ([] !== $records && $t < $records[array_key_last($records)])) {
                    throw new RepairFailed('Session timer propagation requires ordered absolute Records.');
                }
                $records[] = $t;
            }
        }
        if (1 !== $fileIds || null === $activity || count($sessions) < 2 || count($sessions) > 128 || count($records) < 2
            || count($sessions) !== $activity->integer(1) || null === $activity->integer(0)) {
            throw new RepairFailed('Missing Session timers require a complete native multisession Activity and Records.');
        }
        $sum = 0;
        $previousEnd = null;
        $changes = $insertions = $generated = [];
        foreach ($sessions as $i => $row) {
            $s = $row['m'];
            $start = $s->integer(2);
            $elapsed = $s->integer(7);
            $save = $s->integer(253);
            if (null === $start || $start < 0x10000000 || null === $elapsed || $elapsed <= 0 || null === $save
                || abs(($save - $start) * 1000 - $elapsed) > 999 || null !== $s->integer(8)
                || (null !== $previousEnd && abs($start * 1000 - $previousEnd) > 999)
                || !in_array($s->integer(254), [null, $i], true) || !in_array($s->integer(25), [null, $i], true)
                || !in_array($s->integer(26), [null, 0, 1], true)) {
                throw new RepairFailed('Every native Session must have corroborating elapsed endpoints without references to lost Laps.');
            }
            $end = $start * 1000 + $elapsed;
            if ([] === array_filter($records, static fn (int $t): bool => $t >= $start && $t * 1000 <= $end + 999)) {
                throw new RepairFailed('Every native Session requires original measurements in its interval.');
            }
            $previousEnd = $end;
            $sum += $elapsed;
            foreach ([8 => [0x86, $elapsed], 254 => [0x84, $i], 25 => [0x84, $i], 26 => [0x84, 1]] as $n => [$type, $value]) {
                if (null === $s->integer($n) || (26 === $n && 0 === $s->integer($n))) {
                    $changes[] = ['message' => $row['index'], 'field' => $n, 'type' => $type, 'value' => $value];
                }
            }
            $lap = (new PhysicalMessage(19, $s->architecture))->withInteger(254, 0x84, $i)->withInteger(2, 0x86, $start)
                ->withInteger(253, 0x86, $save)->withInteger(7, 0x86, $elapsed)->withInteger(8, 0x86, $elapsed);
            $generated[$row['index']] = $lap;
            $insertions[] = ['globalMessageNumber' => 19, 'beforeOriginalMessage' => $row['index'], 'synthetic' => true,
                'basis' => 'native_session_aggregate_original_lap_segmentation_unknown'];
        }
        if (abs($sum - $activity->integer(0)) > 999 || $records[0] < ($sessions[0]['m']->integer(2) ?? PHP_INT_MAX)
            || $records[array_key_last($records)] * 1000 > $previousEnd + 999
            || null === $activity->integer(253) || $activity->integer(253) < ($sessions[array_key_last($sessions)]['m']->integer(253) ?? PHP_INT_MAX)) {
            throw new RepairFailed('Original Activity timer and complete Record bounds must corroborate the elapsed Session total within native resolution.');
        }
        $output = $file->apply($changes);
        foreach (array_reverse($generated, true) as $index => $lap) {
            $output = EditableFitFile::read($output)->insertBefore($index, $lap);
        }

        return new RepairResult($output, ['mode' => 'preserve', 'rule' => self::SESSION_TIMERS_RULE,
            'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output), 'changes' => $changes, 'inserted_messages' => $insertions,
            'inference' => ['historical_accuracy' => 'not_proven', 'source' => 'native_activity_timer_matches_complete_session_elapsed_total_within_one_second',
                'policy' => 'only missing timers filled from native Session elapsed; subsecond active allocation unknown; generated Laps are Session aggregates with unknown original segmentation'],
            'preservation' => 'every original Session and all existing counters, Events and measurements retained; no STOP or pause invented', 'activity_validation' => 'not_performed']);
    }

    public function repair(string $bytes, bool $openPrefix = false): RepairResult
    {
        if (strlen($bytes) > 4_194_304) {
            throw new RepairFailed('Missing summary reconstruction requires a bounded FIT member.');
        }
        $file = EditableFitFile::read($bytes);
        $summaries = $events = $sports = [];
        $first = $last = null;
        $fileIds = 0;
        foreach ($file->messages as $index => $entry) {
            $m = PhysicalMessage::fromRaw($entry['raw'], 0);
            if (0 === $m->number) {
                ++$fileIds;
            }
            $seen = [];
            foreach ($m->fields as $field) {
                $n = $field->definition->fieldNumber;
                $type = match ($m->number) {
                    0, 12 => 0 === $n ? 0x00 : null,
                    19 => match ($n) {
                        2, 7, 8, 253 => 0x86, 254 => 0x84, default => null,
                    },
                    20 => 253 === $n ? 0x86 : null,
                    21 => match ($n) {
                        0, 1 => 0x00, 4 => 0x02, 253 => 0x86, default => null,
                    },
                    34 => match ($n) {
                        0, 253 => 0x86, 1 => 0x84, default => null,
                    },
                    default => null,
                };
                if (null !== $type && (isset($seen[$n]) || ($type !== $field->definition->baseType->definitionByte()
                        && !(0x00 === $type && 0x02 === $field->definition->baseType->definitionByte()))
                    || match ($type) {
                        0x86 => 4, 0x84 => 2, default => 1,
                    } !== $field->definition->size)) {
                    throw new RepairFailed('Missing summary evidence requires unique native scalar fields.');
                }
                $seen[$n] = true;
            }
            if (in_array($m->number, [18, 19, 34], true)) {
                if (isset($summaries[$m->number])) {
                    throw new RepairFailed('Missing summary recovery does not replace native segmentation.');
                }
                $summaries[$m->number] = ['index' => $index, 'message' => $m];
            } elseif (101 === $m->number || 142 === $m->number || 225 === $m->number) {
                throw new RepairFailed('Missing summaries cannot replace native pool, segment or set segmentation.');
            } elseif (20 === $m->number) {
                $timestamp = $m->integer(253);
                if (null === $timestamp || $timestamp < 0x10000000 || (null !== $last && $timestamp < $last)) {
                    throw new RepairFailed('Missing summary recovery requires ordered absolute Records.');
                }
                $first ??= $timestamp;
                $last = $timestamp;
            } elseif (21 === $m->number && (0 === $m->integer(0) || (null === $m->integer(0) && 0 === $m->integer(1)))) {
                $events[] = $m;
            } elseif (12 === $m->number && null !== $m->integer(0)) {
                $sports[] = $m->integer(0);
            }
        }
        if (isset($summaries[18]) || null === $first || null === $last || $last <= $first
            || 1 !== $fileIds || 0 !== $file->messages[0]['raw']->globalMessageNumber() || 4 !== PhysicalMessage::fromRaw($file->messages[0]['raw'], 0)->integer(0)) {
            throw new RepairFailed('Recovery requires a missing Session, an Activity file_id and a nonempty native measurement interval.');
        }
        $lap = $summaries[19]['message'] ?? null;
        $activity = $summaries[34]['message'] ?? null;
        $changes = [];
        $start = $end = $elapsed = $timer = null;
        $source = '';
        if ($openPrefix && ([] !== $summaries || [] === $events || 0 === count($events) % 2)) {
            throw new RepairFailed('Open-prefix recovery requires absent summaries and a native trace ending in START.');
        }
        if (null !== $lap) {
            $start = $lap->integer(2);
            $end = $lap->integer(253);
            $timer = $lap->integer(8);
            $elapsed = $lap->integer(7);
            if (null !== $start && null !== $end && null !== $timer && $timer > 0
                && null === $elapsed && abs(($end - $start) * 1000 - $timer) <= 999 && abs($last - $end) <= 1) {
                $elapsed = max(($end - $start) * 1000, $timer);
                $changes[] = ['message' => $summaries[19]['index'], 'field' => 7, 'type' => 0x86, 'value' => $elapsed];
            }
            if (!in_array($lap->integer(254), [null, 0], true)) {
                throw new RepairFailed('Native Lap indexes cannot imply missing segmentation.');
            }
            $source = 'sole_native_lap_clocks_and_timer';
        } elseif (null !== $activity) {
            $timer = $activity->integer(0);
            $end = $activity->integer(253);
            if (1 !== $activity->integer(1) || !in_array(count($events), [1, 2], true) || 0 !== $events[0]->integer(1)
                || (2 === count($events) && (!in_array($events[1]->integer(1), [1, 4], true) || $events[1]->integer(253) !== $end))) {
                throw new RepairFailed('Native whole-activity timer requires one recorded START and exactly one declared Session.');
            }
            $start = $events[0]->integer(253);
            if (null === $start || null === $end || null === $timer || abs(($end - $start) * 1000 - $timer) > 999) {
                throw new RepairFailed('Native Activity timer must independently corroborate the recorded START and finish.');
            }
            $elapsed = max(($end - $start) * 1000, $timer);
            $source = 'native_activity_timer_and_recorded_start';
        } else {
            if (count($events) < ($openPrefix ? 1 : 2) || count($events) > 4096 || (!$openPrefix && 0 !== count($events) % 2)) {
                throw new RepairFailed('Absent summaries require a complete original timer trace.');
            }
            $timer = 0;
            foreach ($events as $i => $event) {
                $t = $event->integer(253);
                if (0 !== $event->integer(0) || null === $t || $t < 0x10000000 || !in_array($event->integer(4), [null, 0], true)
                    || (0 === $i % 2 ? 0 !== $event->integer(1) : !in_array($event->integer(1), [1, 4], true))
                    || ($i > 0 && $t < $events[$i - 1]->integer(253))) {
                    throw new RepairFailed('Every native timer transition must alternate, retain its clock and belong to one group.');
                }
                if (1 === $i % 2) {
                    $timer += ($t - ($events[$i - 1]->integer(253) ?? throw new \LogicException('Checked timer clock.'))) * 1000;
                }
            }
            $start = $events[0]->integer(253);
            $end = $openPrefix ? $last : $events[array_key_last($events)]->integer(253);
            if ($openPrefix) {
                $activeStart = $events[array_key_last($events)]->integer(253) ?? throw new RepairFailed('Missing final START clock.');
                if ($activeStart > $last) {
                    throw new RepairFailed('The final native START must precede the last observation.');
                }
                $timer += ($last - $activeStart) * 1000;
            }
            $elapsed = (($end ?? 0) - ($start ?? 0)) * 1000;
            $source = $openPrefix ? 'open_native_timer_trace_observed_prefix' : 'complete_native_timer_trace';
        }
        if (null === $start || null === $end || null === $elapsed || null === $timer || $start < 0x10000000 || $end <= $start
            || $timer <= 0 || $elapsed < $timer || $elapsed > 0xFFFFFFFE || $first < $start || abs($last - $end) > 10
            || abs(($end - $start) * 1000 - $elapsed) > 1000
            || (null !== $activity && ($activity->integer(0) !== $timer || 1 !== $activity->integer(1) || $activity->integer(253) !== $end))) {
            throw new RepairFailed('Native clocks, measurement endpoints and timers do not establish one whole-activity interval.');
        }
        if (count(array_unique($sports)) > 1) {
            throw new RepairFailed('Conflicting native sports cannot establish a single Session.');
        }
        $sport = $sports[0] ?? 0;
        $generated = [];
        if (null === $lap) {
            $generated[] = (new PhysicalMessage(19))->withInteger(254, 0x84, 0)->withInteger(2, 0x86, $start)
                ->withInteger(253, 0x86, $end)->withInteger(7, 0x86, $elapsed)->withInteger(8, 0x86, $timer);
        } elseif (null === $lap->integer(254)) {
            $changes[] = ['message' => $summaries[19]['index'], 'field' => 254, 'type' => 0x84, 'value' => 0];
        }
        $generated[] = (new PhysicalMessage(18))->withInteger(254, 0x84, 0)->withInteger(2, 0x86, $start)
            ->withInteger(253, 0x86, $end)->withInteger(7, 0x86, $elapsed)->withInteger(8, 0x86, $timer)
            ->withInteger(5, 0x00, $sport)->withInteger(25, 0x84, 0)->withInteger(26, 0x84, 1);
        if (null === $activity) {
            $generated[] = (new PhysicalMessage(34))->withInteger(253, 0x86, $end)->withInteger(0, 0x86, $timer)
                ->withInteger(1, 0x84, 1)->withInteger(3, 0x00, 26)->withInteger(4, 0x00, 1);
        }
        $output = $file->apply($changes);
        foreach ($generated as $m) {
            $output = EditableFitFile::read($output)->append($m);
        }

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => $openPrefix ? self::PREFIX_RULE : self::RULE, 'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes, 'generated_messages' => array_map(static fn (PhysicalMessage $m): int => $m->number, $generated),
            'inference' => ['historical_accuracy' => 'not_proven', 'source' => $source, 'sport_source' => [] === $sports ? 'generic_placeholder_original_sport_unknown' : 'native_sport',
                'lap_segmentation' => null === $lap ? 'generated_whole_session_aggregate_original_lap_segmentation_unknown' : 'existing_single_lap_retained',
                'finish_policy' => $openPrefix ? 'minimum observed prefix; original finish and unrecorded continuation unknown; no STOP invented' : 'native counter or complete timer endpoint'],
            'preservation' => 'all original measurements and timer Events retained; original Laps never removed or repartitioned; no lost referenced Lap set replaced',
            'activity_validation' => 'not_performed',
        ]);
    }
}
