<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Internal\Rule;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Fit\EditableFitFile;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalEncoder;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\RecordedLengthLapRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\Tests\Support\FitFixture;

final class RecordedLengthLapRepairerTest extends TestCase
{
    private const int START = 0x10000020;

    public function testRestoresExactDurationsAndRetainsEveryOtherPhysicalField(): void
    {
        foreach ([0, 1] as $architecture) {
            foreach ([false, true] as $multiple) {
                $source = FitFixture::file(implode('', $this->packets($architecture, $multiple)));
                $result = (new PreservingActivityRepairer())->repair($source, RecordedLengthLapRepairer::RULE);
                $before = EditableFitFile::read($source);
                $after = EditableFitFile::read($result->bytes);
                $edits = [];

                foreach ($result->report['changes'] as $change) {
                    $edits[$change['message']][$change['field']] = $change['value'];
                }

                self::assertCount($multiple ? 4 : 2, $result->report['changes']);
                self::assertSame(strlen($source), strlen($result->bytes));
                self::assertSame(substr($source, 0, 14), substr($result->bytes, 0, 14));
                self::assertCount(count($before->messages), $after->messages);

                foreach ($before->messages as $index => $entry) {
                    $original = $entry['raw'];
                    $repaired = $after->messages[$index]['raw'];
                    self::assertSame($entry['definition'], $after->messages[$index]['definition']);
                    self::assertSame($entry['offset'], $after->messages[$index]['offset']);
                    self::assertSame($entry['length'], $after->messages[$index]['length']);

                    if (!isset($edits[$index])) {
                        self::assertSame(substr($source, $entry['offset'], $entry['length']), substr($result->bytes, $entry['offset'], $entry['length']));
                        continue;
                    }

                    $physical = PhysicalMessage::fromRaw($repaired, 14);

                    foreach ($original->standardFields() as $fieldIndex => $field) {
                        $number = $field->definition->fieldNumber;

                        if (isset($edits[$index][$number])) {
                            self::assertContains($number, [7, 8]);
                            self::assertSame($edits[$index][$number], $physical->integer($number));
                        } else {
                            self::assertSame($field->value->bytes(), $repaired->standardFields()[$fieldIndex]->value->bytes());
                        }
                    }

                    self::assertSame(array_map(static fn ($field): string => $field->value->bytes(), $original->developerFields()), array_map(static fn ($field): string => $field->value->bytes(), $repaired->developerFields()));
                }

                self::assertSame(30_250, $result->report['evidence']['length_duration_sum_ms']);
                self::assertSame([18_000, ...($multiple ? [9000] : [])], array_column($result->report['evidence']['repaired_laps'], 'recorded_length_duration_sum_ms'));
                self::assertSame($result->bytes, (new ActivityRepairer())->repair($source)->bytes);
                self::assertSame($result->bytes, (new ActivityRepairer())->repair($result->bytes)->bytes);

                foreach (['FIT lap at sequence 10 is not sequential with the previous lap or does not abut it:',
                    'Required check "Session Message total_elapsed_time is Equal to the Sum of Lap Messages total_elapsed_time Values" failed.',
                    'Required check "Session Message total_timer_time is Equal to the Sum of Lap Messages total_timer_time Values" failed.'] as $error) {
                    self::assertContains(RecordedLengthLapRepairer::RULE, (new PreservingActivityRepairer())->rulesForFailure(['status' => 'FAILED', 'errors' => [['message' => $error]]], null));
                }
            }
        }
    }

    public function testRefusesAmbiguousReferencesPausesAndContradictoryDurations(): void
    {
        $packets = $this->packets(0, false);
        $source = FitFixture::file(implode('', $packets));
        $file = EditableFitFile::read($source);
        $cases = [];

        foreach ([[1, 7, 0x86, 30_251], [1, 8, 0x86, 30_000], [1, 26, 0x84, 4], [1, 25, 0x84, 1], [1, 5, 0x02, 2],
            [2, 0, 0x86, 30_000], [2, 1, 0x84, 2], [3, 1, 0x00, 4], [3, 4, 0x02, 1],
            [4, 253, 0x86, self::START + 2], [6, 2, 0x86, self::START + 1], [7, 254, 0x84, 0],
            [7, 3, 0x86, 10_876], [7, 4, 0x86, 10_000], [7, 3, 0x86, 0], [7, 12, 0x00, 2],
            [10, 35, 0x84, 1], [11, 35, 0x84, 2], [11, 2, 0x86, self::START + 19], [11, 8, 0x86, 3000],
            [10, 7, 0x86, 17_500], [10, 8, 0x86, 17_500], [13, 253, 0x86, self::START + 32], [14, 1, 0x00, 0]] as [$message, $field, $type, $value]) {
            $messages = array_map(static fn ($entry): PhysicalMessage => PhysicalMessage::fromRaw($entry['raw'], 14), $file->messages);
            $messages[$message] = $messages[$message]->withInteger($field, $type, $value);
            $cases[] = (new PhysicalEncoder())->encode($messages, $file->header->profileVersion);
        }

        $small = $file->apply([['message' => 10, 'field' => 7, 'type' => 0x86, 'value' => 17_500], ['message' => 10, 'field' => 8, 'type' => 0x86, 'value' => 17_500]]);
        $cases[] = $small;

        foreach (['session', 'activity', 'begin', 'length1'] as $extra) {
            $cases[] = FitFixture::file(implode('', $packets).$packets[$extra]);
        }

        $missing = $packets;
        unset($missing['length1']);
        $cases[] = FitFixture::file(implode('', $missing));
        $missing = $packets;
        $missing['firstRecord'] = FitFixture::message(20, [[5, 0x86, pack('V', 123)]]);
        $cases[] = FitFixture::file(implode('', $missing));

        $messages = array_map(static fn ($entry): PhysicalMessage => PhysicalMessage::fromRaw($entry['raw'], 14), $file->messages);
        $bad = $messages[7];
        $messages[7] = new PhysicalMessage($bad->number, $bad->architecture, [...$bad->fields, $bad->fields[3]], $bad->developerFields);
        $cases[] = (new PhysicalEncoder())->encode($messages, $file->header->profileVersion);
        $cases[] = $source.$source;
        $cases[] = substr_replace($source, "\xFF", -1, 1);

        foreach ($cases as $case) {
            try {
                (new RecordedLengthLapRepairer())->repair($case);
                self::fail('Contradictory Length evidence must be refused.');
            } catch (RepairFailed $failure) {
                self::assertNotEmpty($failure->getMessage());
            }
        }
    }

    /** @return array<string, string> */
    private function packets(int $architecture, bool $multiple): array
    {
        $word = 0 === $architecture ? 'V' : 'N';
        $short = 0 === $architecture ? 'v' : 'n';
        $packets = [
            'fileId' => FitFixture::message(0, [[0, 0x02, "\x04"]]),
            'session' => FitFixture::message(18, [[254, 0x84, pack($short, 0)], [253, 0x86, pack($word, self::START)],
                [2, 0x86, pack($word, self::START)], [7, 0x86, pack($word, 30_250)], [8, 0x86, pack($word, 30_250)],
                [25, 0x84, pack($short, 0)], [26, 0x84, pack($short, 3)], [5, 0x02, "\x05"], [6, 0x02, "\x11"]], architecture: $architecture),
            'activity' => FitFixture::message(34, [[253, 0x86, pack('V', self::START)], [0, 0x86, pack('V', 30_250)], [1, 0x84, pack('v', 1)]]),
            'begin' => FitFixture::message(21, [[253, 0x86, pack('V', self::START + 1)], [0, 0, "\x00"], [1, 0, "\x00"], [4, 0x02, "\x00"]]),
            'firstRecord' => FitFixture::record(self::START + 1),
            'compressed' => "\x41\x00\x00\x14\x00\x01\x03\x01\x02\xA2\x75",
        ];

        foreach ([[0, 0, 7125, 1], [2, 7, 10_875, 1], [3, 18, 3250, 0], [4, 21, 9000, 1]] as $index => [$reference, $offset, $duration, $type]) {
            $packets['length'.$index] = FitFixture::message(101, [[254, 0x84, pack($short, $reference)], [253, 0x86, pack($word, self::START)],
                [2, 0x86, pack($word, self::START + $offset)], [3, 0x86, pack($word, $duration)], [4, 0x86, pack($word, $duration)],
                [0, 0, "\x1C"], [1, 0, "\x01"], [12, 0, chr($type)], [100, 0x0D, "\xAB\xCD"]], [[7, 0, "\x12\x34"]], $architecture);
        }

        foreach ([[0, 0, 7125], [3, 18, 3250], [4, 21, $multiple ? 0 : 9000]] as $index => [$reference, $offset, $duration]) {
            $packets['lap'.$index] = FitFixture::message(19, [[254, 0x84, pack($short, $index)], [253, 0x86, pack($word, self::START)],
                [2, 0x86, pack($word, self::START + $offset)], [7, 0x86, pack($word, $duration)], [8, 0x86, pack($word, $duration)],
                [35, 0x84, pack($short, $reference)], [9, 0x86, pack($word, 12345)], [25, 0, "\x05"], [39, 0, "\x11"],
                [100, 0x0D, "\xFE\xEF"]], [[7, 0, "\x56\x78"]], $architecture);
        }

        $packets['lastRecord'] = FitFixture::record(self::START + 31);
        $packets['stop'] = FitFixture::message(21, [[253, 0x86, pack('V', self::START + 31)], [0, 0, "\x00"], [1, 0, "\x04"]]);
        $packets['unknown'] = FitFixture::message(50_000, [[100, 0x0D, "\xDE\xAD"]]);

        return $packets;
    }
}
