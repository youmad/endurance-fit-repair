<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Rule;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** Translates an explicitly referenced one-based Lap set without changing membership. */
final readonly class OneBasedLapIndexRepairer
{
    public const string RULE = 'one_based_lap_indexes';

    public function repair(string $bytes): RepairResult
    {
        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate one-based Lap index input.');
        }

        $laps = $sessions = $activities = [];
        $ordinal = $fileIds = 0;

        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill one-based Lap index input.');
            }

            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $header = $file->header;

            if (0 !== $header->byteOffset || !in_array($header->headerSize, [12, 14], true)
                || strlen($bytes) !== $header->headerSize + $header->dataSize + 2) {
                throw new RepairFailed('Lap index translation requires one complete FIT member at byte zero.');
            }

            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord) {
                    continue;
                }

                $number = $raw->globalMessageNumber();

                if (0 === $number) {
                    ++$fileIds;

                    if (0 !== $ordinal || 4 !== $this->field($raw, 0, 0x00, 1)['value']) {
                        throw new RepairFailed('Lap index translation requires one Activity file_id as the first data message.');
                    }
                } elseif (19 === $number) {
                    $laps[] = ['message' => $ordinal, 'sequence' => $raw->sequence(), 'index' => $this->field($raw, 254, 0x84, 2),
                        'elapsed' => $this->field($raw, 7, 0x86, 4)['value'], 'timer' => $this->field($raw, 8, 0x86, 4)['value']];
                } elseif (18 === $number) {
                    $sessions[] = ['message' => $ordinal, 'sequence' => $raw->sequence(),
                        'index' => $this->field($raw, 254, 0x84, 2)['value'], 'first' => $this->field($raw, 25, 0x84, 2),
                        'count' => $this->field($raw, 26, 0x84, 2)['value'], 'best' => $this->field($raw, 70, 0x84, 2),
                        'elapsed' => $this->field($raw, 7, 0x86, 4)['value'], 'timer' => $this->field($raw, 8, 0x86, 4)['value']];
                } elseif (34 === $number) {
                    $activities[] = ['sequence' => $raw->sequence(), 'sessions' => $this->field($raw, 1, 0x84, 2)['value']];
                }

                if (count($laps) > 4095 || count($sessions) > 1 || count($activities) > 1) {
                    throw new RepairFailed('Lap index translation requires one Session, one Activity and 1..4095 Laps.');
                }

                ++$ordinal;
            }

            $file->trailer();
        } finally {
            fclose($stream);
        }

        if (1 !== $fileIds || 1 !== count($sessions) || 1 !== count($activities) || [] === $laps) {
            throw new RepairFailed('Lap index translation requires one Activity file_id, Session, Activity and existing Laps.');
        }

        $session = $sessions[0];
        $activity = $activities[0];
        $count = count($laps);
        $base = 1;
        if (1 === $count && null !== $session['first']['value'] && $session['first']['value'] > 1 && $session['first']['value'] <= 4095
            && null !== $session['elapsed'] && $session['elapsed'] > 0 && $session['elapsed'] === $laps[0]['elapsed']
            && null !== $session['timer'] && $session['timer'] <= $session['elapsed'] && $session['timer'] === $laps[0]['timer']) {
            $base = $session['first']['value'];
        }

        if (!in_array($session['index'], [null, 0], true) || $base !== $session['first']['value'] || $count !== $session['count']
            || 1 !== $activity['sessions'] || $laps[$count - 1]['sequence'] >= $session['sequence']
            || (1 === $base && $session['sequence'] >= $activity['sequence'])) {
            throw new RepairFailed('Existing references do not identify one complete one-based Lap set.');
        }

        $best = $session['best']['value'];

        if (null !== $best && ($best < $base || $best >= $base + $count)) {
            throw new RepairFailed('Recorded best_lap_index does not identify a Lap in the one-based set.');
        }

        $edits = $mapping = [];

        foreach ($laps as $index => $lap) {
            if ($index + $base !== $lap['index']['value']) {
                throw new RepairFailed('Every existing Lap index must be exactly 1..N in physical message order.');
            }

            $edits[] = ['message' => $lap['message'], 'field' => 254, 'type' => 0x84, 'value' => $index,
                'offset' => $lap['index']['offset'], 'word' => $lap['index']['word']];
            $mapping[] = ['sequence' => $lap['sequence'], 'from' => $index + $base, 'to' => $index];
        }

        $edits[] = ['message' => $session['message'], 'field' => 25, 'type' => 0x84, 'value' => 0,
            'offset' => $session['first']['offset'], 'word' => $session['first']['word']];

        if (null !== $best) {
            $edits[] = ['message' => $session['message'], 'field' => 70, 'type' => 0x84, 'value' => $best - $base,
                'offset' => $session['best']['offset'], 'word' => $session['best']['word']];
        }

        $body = substr($bytes, 0, -2);
        $changes = [];

        foreach ($edits as $edit) {
            $body = substr_replace($body, pack($edit['word'], $edit['value']), $edit['offset'], 2);
            $changes[] = ['message' => $edit['message'], 'field' => $edit['field'], 'type' => $edit['type'], 'value' => $edit['value']];
        }

        $output = $body.pack('v', FitCrc16::calculate($body));

        return new RepairResult($output, [
            'mode' => 'preserve', 'rule' => self::RULE, 'input_sha256' => hash('sha256', $bytes), 'output_sha256' => hash('sha256', $output),
            'changes' => $changes, 'evidence' => [
                'session_sequence' => $session['sequence'], 'original_first_lap_index' => $base, 'num_laps' => $count,
                'original_best_lap_index' => $best, 'lap_index_mapping' => $mapping,
                'policy' => 'translate the complete native Lap set and Session references by subtracting its first index; a larger origin requires one explicitly referenced Lap with identical original Session/Lap counters; no clock or duration is adjusted',
            ], 'preservation' => 'all source bytes retained outside existing Lap.message_index, Session.first_lap_index, valid Session.best_lap_index and file CRC',
            'activity_validation' => 'not_performed',
        ]);
    }

    /** @return array{value: int|null, offset: int, word: string} */
    private function field(RawDataRecord $raw, int $number, int $type, int $size): array
    {
        $matches = 0;

        foreach ($raw->definition()->standardFields() as $definition) {
            if ($number !== $definition->fieldNumber) {
                continue;
            }

            if (++$matches > 1 || $definition->size !== $size || $definition->baseType->definitionByte() !== $type) {
                throw new RepairFailed('Lap index evidence requires unique fields of the expected physical type and size.');
            }
        }

        $position = $raw->byteOffset() + 1;
        $word = FitArchitecture::LittleEndian === $raw->definition()->architecture ? 'v' : 'n';

        foreach ($raw->standardFields() as $field) {
            if ($number === $field->definition->fieldNumber) {
                return ['value' => PhysicalMessage::fromRaw($raw, 0)->integer($number), 'offset' => $position, 'word' => $word];
            }

            $position += $field->definition->size;
        }

        return ['value' => null, 'offset' => 0, 'word' => $word];
    }
}
