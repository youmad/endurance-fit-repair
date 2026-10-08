<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Internal\Fit;

use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\RawRecordStreamReader;
use Youmad\Endurance\Fit\Exception\FitDecodeException;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\FitFileHeader;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\RepairFailed;

/** @internal Reads a complete prefix; never scans beyond a broken record. */
final readonly class RecoveringReader
{
    public function read(string $bytes): RecoveredInput
    {
        if ('' === $bytes || 12 > ord($bytes[0]) || strlen($bytes) < ord($bytes[0])) {
            throw new RepairFailed('Recovery requires a complete FIT header at byte zero.');
        }

        $header = FitFileHeader::fromBytes(0, substr($bytes, 0, ord($bytes[0])));

        if (2 < $header->protocolMajorVersion()) {
            throw new RepairFailed('Unsupported FIT protocol version.');
        }

        $end = $header->headerSize + $header->dataSize;

        if (strlen($bytes) > $end + 2) {
            throw new RepairFailed('Chained FIT members and trailing bytes are not supported for recovery.');
        }

        $issues = [];

        if (null !== $header->headerCrc && 0 !== $header->headerCrc && $header->headerCrc !== FitCrc16::calculate(substr($bytes, 0, 12))) {
            $issues[] = 'header_crc_mismatch';
        }

        if (strlen($bytes) < $end + 2) {
            $issues[] = 'truncated_file';
        } elseif (pack('v', FitCrc16::calculate(substr($bytes, 0, $end))) !== substr($bytes, $end, 2)) {
            $issues[] = 'file_crc_mismatch';
        }

        $available = min($header->dataSize, strlen($bytes) - $header->headerSize);
        $stream = fopen('php://temp', 'w+b');

        if (false === $stream) {
            throw new RepairFailed('Cannot allocate the recovery buffer.');
        }

        try {
            if ($available !== fwrite($stream, substr($bytes, $header->headerSize, $available)) || !rewind($stream)) {
                throw new RepairFailed('Cannot fill the recovery buffer.');
            }

            $input = new ResourceFitInput($stream);
            $messages = [];
            $complete = 0;

            try {
                foreach ((new RawRecordStreamReader())->read($input, $available) as $raw) {
                    $complete = $input->position();

                    if ($raw instanceof RawDataRecord) {
                        $messages[] = PhysicalMessage::fromRaw($raw, $header->headerSize);
                    }
                }
            } catch (FitDecodeException|\InvalidArgumentException $exception) {
                $issues[] = 'record_decode_failed: '.$exception->getMessage();
            }

            return new RecoveredInput($header, $messages, $issues, $available, $complete);
        } finally {
            fclose($stream);
        }
    }
}
