<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Tests\Support;

/** @internal Small artificial certificates to exercise the binary reader. */
final class GeographyFixture
{
    /**
     * @param array<int, string> $tiles
     * @param list<string>       $zones
     */
    public static function write(string $directory, array $tiles = [], array $zones = ['Europe/Paris', 'Europe/Berlin']): string
    {
        $header = "EFTZG01\n".pack('V', 648);
        $payload = '';

        for ($i = 0; $i < 648; ++$i) {
            $header .= pack('V', strlen($payload));
            $payload .= $tiles[$i] ?? pack('v', 0);
        }

        return self::writeRaw($directory, $header.$payload, $zones);
    }

    /** @param list<string> $zones */
    public static function writeRaw(string $directory, string $data, array $zones = ['Europe/Paris', 'Europe/Berlin']): string
    {
        $compressed = gzencode($data);

        if (false === $compressed) {
            throw new \RuntimeException('Cannot encode test geography data.');
        }

        file_put_contents($directory.'/grid.gz', $compressed);
        $manifest = [
            'schemaVersion' => 1, 'format' => 'certified-zone-grid-v1', 'gridDegrees' => 0.01,
            'release' => 'test', 'file' => 'grid.gz', 'sha256' => hash('sha256', $compressed),
            'uncompressedSha256' => hash('sha256', $data), 'uncompressedBytes' => strlen($data),
            'zones' => $zones, 'source' => ['project' => 'artificial test certificates'],
            'license' => 'ODbL-1.0', 'attribution' => 'Test', 'certification' => 'Artificial test rectangles',
        ];
        $path = $directory.'/manifest.json';
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        return $path;
    }

    public static function tile(int $latitudeDegrees, int $longitudeDegrees): int
    {
        return intdiv($latitudeDegrees + 90, 10) * 36 + intdiv($longitudeDegrees + 180, 10);
    }
}
