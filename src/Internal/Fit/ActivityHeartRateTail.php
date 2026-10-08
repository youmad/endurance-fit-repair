<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Fit;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitFileHeader;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** @internal Keeps chained HR packets outside edits to existing scalar activity fields. */
final readonly class ActivityHeartRateTail
{
    /** @return array{string, string} first member and unchanged HR-only suffix */
    public static function split(string $bytes): array
    {
        if (strlen($bytes) < 14 || !in_array(ord($bytes[0]), [12, 14], true)) {
            return [$bytes, ''];
        }

        $first = FitFileHeader::fromBytes(0, substr($bytes, 0, ord($bytes[0])));
        $length = $first->headerSize + $first->dataSize + 2;

        if ($length >= strlen($bytes)) {
            return [$bytes, ''];
        }

        $offset = $members = 0;
        while ($offset < strlen($bytes)) {
            if (++$members > 4096 || strlen($bytes) - $offset < 14 || !in_array(ord($bytes[$offset]), [12, 14], true)) {
                throw new RepairFailed('Scalar repair requires complete FIT members without padding or trailing bytes.');
            }
            $header = FitFileHeader::fromBytes($offset, substr($bytes, $offset, ord($bytes[$offset])));
            $size = $header->headerSize + $header->dataSize;
            if (2 < $header->protocolMajorVersion() || $offset + $size + 2 > strlen($bytes)
                || (null !== $header->headerCrc && 0 !== $header->headerCrc && $header->headerCrc !== FitCrc16::calculate(substr($bytes, $offset, 12)))
                || pack('v', FitCrc16::calculate(substr($bytes, $offset, $size))) !== substr($bytes, $offset + $size, 2)) {
                throw new RepairFailed('Every chained FIT member must retain a supported header and valid source CRCs.');
            }
            $offset += $size + 2;
        }

        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new RepairFailed('Cannot allocate chained HR evidence input.');
        }
        try {
            if (strlen($bytes) !== fwrite($stream, $bytes) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill chained HR evidence input.');
            }
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            $heartRates = 0;
            foreach ($file->messages() as $raw) {
                if ($raw instanceof RawDataRecord && $raw->byteOffset() >= $length) {
                    if (132 !== $raw->globalMessageNumber()) {
                        throw new RepairFailed('Only a heart-rate-only FIT suffix can be retained outside scalar activity repair.');
                    }
                    ++$heartRates;
                }
            }
            $file->trailer();
            if (0 === $heartRates) {
                throw new RepairFailed('Chained scalar repair requires recorded heart-rate packets after the activity member.');
            }
        } finally {
            fclose($stream);
        }

        return [substr($bytes, 0, $length), substr($bytes, $length)];
    }

    public static function append(string $source, string $tail, RepairResult $result): RepairResult
    {
        if (strlen($result->bytes) + strlen($tail) !== strlen($source)) {
            throw new RepairFailed('Chained scalar repair must retain every original member boundary.');
        }
        $output = $result->bytes.$tail;

        return new RepairResult($output, array_replace($result->report, [
            'input_sha256' => hash('sha256', $source), 'output_sha256' => hash('sha256', $output),
            'retained_heart_rate_tail_sha256' => hash('sha256', $tail),
        ]));
    }
}
