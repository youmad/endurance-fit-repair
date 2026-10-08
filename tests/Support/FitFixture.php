<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

use Youmad\Endurance\Fit\Checksum\FitCrc16;

final readonly class FitFixture
{
    public static function file(string $data): string
    {
        $header = "\x0E\x20".pack('vV', 21_208, strlen($data)).'.FIT';
        $header .= pack('v', FitCrc16::calculate($header));

        return $header.$data.pack('v', FitCrc16::calculate($header.$data));
    }

    /**
     * @param list<array{int, int, string}> $fields
     * @param list<array{int, int, string}> $developerFields
     */
    public static function message(int $number, array $fields, array $developerFields = [], int $architecture = 0): string
    {
        $definition = chr([] === $developerFields ? 0x40 : 0x60)."\x00".chr($architecture)
            .pack(0 === $architecture ? 'v' : 'n', $number).chr(count($fields));
        $payload = "\x00";

        foreach ($fields as [$field, $type, $bytes]) {
            $definition .= chr($field).chr(strlen($bytes)).chr($type);
            $payload .= $bytes;
        }

        if ([] !== $developerFields) {
            $definition .= chr(count($developerFields));

            foreach ($developerFields as [$field, $index, $bytes]) {
                $definition .= chr($field).chr(strlen($bytes)).chr($index);
                $payload .= $bytes;
            }
        }

        return $definition.$payload;
    }

    /**
     * @param list<array{int, int, string}> $fields
     * @param list<array{int, int, string}> $developerFields
     */
    public static function summary(int $number, int $start, int $elapsed, array $fields = [], array $developerFields = [], int $architecture = 0): string
    {
        $word = 0 === $architecture ? 'V' : 'N';

        return self::message($number, [
            [2, 0x86, pack($word, $start)],
            [7, 0x86, pack($word, $elapsed)],
            [253, 0x86, pack($word, $start + intdiv($elapsed, 1000))],
            ...$fields,
        ], $developerFields, $architecture);
    }

    public static function record(int $timestamp): string
    {
        return self::message(20, [[253, 0x86, pack('V', $timestamp)]]);
    }
}
