<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Clears reserved bits only for an explicitly identified sole Session. */
final readonly class ReservedSessionIndexRepairer
{
    public const string RULE = 'reserved_single_session_index';

    public function repair(string $bytes): RepairResult
    {
        $file = EditableFitFile::read($bytes);
        $sessions = $laps = $activities = [];
        $fileIds = 0;

        foreach ($file->messages as $ordinal => $entry) {
            $raw = $entry['raw'];

            switch ($raw->globalMessageNumber()) {
                case 0:
                    ++$fileIds;

                    if (0 !== $ordinal || 4 !== $this->field($raw, 0, 0x00, 1)) {
                        throw new RepairFailed('Reserved Session index repair requires an Activity file_id as the first data message.');
                    }
                    break;
                case 18:
                    $sessions[$ordinal] = $entry;
                    break;
                case 19:
                    $laps[] = $raw;
                    break;
                case 34:
                    $activities[] = $raw;
                    break;
            }
        }

        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($laps) || 1 !== count($activities)
            || 1 !== $this->field($activities[0], 1, 0x84, 2)) {
            throw new RepairFailed('Reserved Session index repair requires exactly one Session, one existing Lap and Activity.num_sessions=1.');
        }

        $ordinal = array_key_first($sessions);
        $entry = $sessions[$ordinal];
        $session = $entry['raw'];
        $lap = $laps[0];
        $index = $this->field($session, 254, 0x84, 2);

        // Profile message_index: reserved=0x7000 (default zero), mask=0x0FFF.
        // Keep selected and every real index value outside this repair rule.
        if (null === $index || 0 !== ($index & 0x8FFF)
            || 0 !== $this->field($session, 25, 0x84, 2)
            || !in_array($this->field($session, 26, 0x84, 2), [null, 1], true)
            || !in_array($this->field($session, 70, 0x84, 2), [null, 0], true)
            || !in_array($this->field($lap, 254, 0x84, 2), [null, 0], true)) {
            throw new RepairFailed('Recorded indexes and references do not identify the sole Session and Lap unambiguously.');
        }

        $values = [];

        foreach ([2, 7, 8] as $field) {
            $value = $this->field($session, $field, 0x86, 4);

            if (null === $value || $value !== $this->field($lap, $field, 0x86, 4)) {
                throw new RepairFailed('The sole Session and Lap must record identical start, elapsed and timer values.');
            }

            $values[$field] = $value;
        }

        if (0x10000000 > $values[2] || $values[8] > $values[7]) {
            throw new RepairFailed('The recorded Session and Lap interval must contain a valid absolute start and timer duration.');
        }

        foreach ([$session, $lap] as $raw) {
            $timestamp = $this->field($raw, 253, 0x86, 4);

            if (null === $timestamp || $timestamp < $values[2]) {
                throw new RepairFailed('Session and Lap timestamps must exist and cannot precede the recorded start.');
            }
        }

        $output = $bytes;
        $changes = [];

        if (0 !== $index) {
            $offset = $entry['offset'] + 1;

            foreach ($session->standardFields() as $field) {
                if (254 === $field->definition->fieldNumber) {
                    $body = substr_replace(substr($bytes, 0, -2), pack(FitArchitecture::LittleEndian === $session->definition()->architecture ? 'v' : 'n', 0), $offset, 2);
                    $output = $body.pack('v', FitCrc16::calculate($body));
                    $changes[] = ['message' => $ordinal, 'field' => 254, 'type' => 0x84, 'value' => 0];
                    break;
                }

                $offset += $field->definition->size;
            }
        }

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE, 'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes, 'evidence' => [
                'session_sequence' => $session->sequence(), 'original_message_index' => $index, 'reserved_mask' => 0x7000,
                'index_mask' => 0x0FFF, 'recorded_first_lap_index' => 0, 'existing_laps' => 1,
                'shared_start_time' => $values[2], 'shared_elapsed_ms' => $values[7], 'shared_timer_ms' => $values[8],
                'policy' => 'clear only reserved message_index bits whose Profile default is zero; retain index zero and all existing Session/Lap references; infer no clock, duration or measurement',
            ], 'preservation' => 'all source bytes retained outside existing Session.message_index and file CRC',
            'activity_validation' => 'not_performed',
        ]);
    }

    private function field(RawDataRecord $raw, int $number, int $type, int $size): ?int
    {
        $matches = 0;

        foreach ($raw->definition()->standardFields() as $definition) {
            if ($number !== $definition->fieldNumber) {
                continue;
            }

            if (++$matches > 1 || $definition->size !== $size || $definition->baseType->definitionByte() !== $type) {
                throw new RepairFailed('Reserved Session index evidence requires unique fields of the expected physical type and size.');
            }
        }

        return PhysicalMessage::fromRaw($raw, 0)->integer($number);
    }
}
