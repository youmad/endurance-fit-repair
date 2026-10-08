<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Geography;

use Youmad\Endurance\FitRepair\Corpus\CorpusWorkspace;
use Youmad\Endurance\FitRepair\RepairFailed;

/**
 * @internal Offline certificates for complete 0.01-degree coordinate cells.
 *
 * A positive leaf certifies the entire closed rectangle, not its center. The
 * compilation keeps polygon holes, overlapping zones and invalid boundaries.
 * Zero means no certificate; even a summary point in that cell is declined.
 */
final class CertifiedTimeZoneGrid
{
    private const int HEADER_BYTES = 2604;
    private const int MAX_BYTES = 67108864;

    /** @var array<string, string> */
    private array $cache = [];

    /**
     * @param list<string>         $zones
     * @param array<string, mixed> $provenance
     */
    private function __construct(private readonly string $data, private readonly array $zones, private readonly array $provenance)
    {
    }

    public static function open(?string $manifestPath = null): self
    {
        $manifestPath ??= dirname(__DIR__, 2).'/resources/geography/zones-2026d.json';
        $manifestHash = @hash_file('sha256', $manifestPath);
        $manifest = CorpusWorkspace::readJson($manifestPath);

        if (1 !== ($manifest['schemaVersion'] ?? null) || 'certified-zone-grid-v1' !== ($manifest['format'] ?? null)
            || 0.01 !== ($manifest['gridDegrees'] ?? null) || !is_string($manifest['release'] ?? null)
            || !is_string($manifest['file'] ?? null) || basename($manifest['file']) !== $manifest['file']
            || !is_string($manifest['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256'])
            || !is_string($manifest['uncompressedSha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $manifest['uncompressedSha256'])
            || !is_int($manifest['uncompressedBytes'] ?? null) || $manifest['uncompressedBytes'] < self::HEADER_BYTES + 1296
            || $manifest['uncompressedBytes'] > self::MAX_BYTES || !is_array($manifest['zones'] ?? null)
            || !array_is_list($manifest['zones']) || [] === $manifest['zones'] || count($manifest['zones']) >= 65535
            || !is_array($manifest['source'] ?? null) || 'ODbL-1.0' !== ($manifest['license'] ?? null)
            || !is_string($manifest['attribution'] ?? null) || !is_string($manifest['certification'] ?? null)) {
            throw new RepairFailed('Unsupported or malformed geographic database manifest.');
        }

        foreach ($manifest['zones'] as $zone) {
            if (!is_string($zone) || !preg_match('~^[A-Za-z0-9_+/-]+$~D', $zone)
                || 'UTC' === $zone || str_starts_with($zone, 'Etc/')) {
                throw new RepairFailed('Invalid geographic database zone name.');
            }
        }

        if (count(array_unique($manifest['zones'])) !== count($manifest['zones'])) {
            throw new RepairFailed('Duplicate geographic database zone name.');
        }

        if (!function_exists('gzopen')) {
            throw new RepairFailed('The optional geography planner requires PHP ext-zlib.');
        }

        $path = dirname($manifestPath).'/'.$manifest['file'];

        if (@hash_file('sha256', $path) !== $manifest['sha256']) {
            throw new RepairFailed('Geographic database checksum mismatch.');
        }

        $stream = @gzopen($path, 'rb');

        if (false === $stream) {
            throw new RepairFailed('Cannot open the geographic database.');
        }

        $data = '';

        try {
            while (true) {
                $chunk = gzread($stream, 65536);

                if (false === $chunk) {
                    throw new RepairFailed('Cannot read the geographic database.');
                }

                if ('' === $chunk) {
                    if (!gzeof($stream)) {
                        throw new RepairFailed('Cannot read the geographic database.');
                    }

                    break;
                }

                $data .= $chunk;

                if (strlen($data) > $manifest['uncompressedBytes']) {
                    throw new RepairFailed('Geographic database exceeds its declared size.');
                }
            }
        } finally {
            gzclose($stream);
        }

        if (strlen($data) !== $manifest['uncompressedBytes'] || hash('sha256', $data) !== $manifest['uncompressedSha256']
            || @hash_file('sha256', $path) !== $manifest['sha256'] || "EFTZG01\n" !== substr($data, 0, 8)
            || false === $manifestHash || @hash_file('sha256', $manifestPath) !== $manifestHash) {
            throw new RepairFailed('Geographic database integrity check failed.');
        }

        $grid = new self($data, $manifest['zones'], [
            'implementation' => 'php-certified-zone-grid-v1',
            'release' => $manifest['release'],
            'grid_degrees' => 0.01,
            'database_sha256' => $manifest['sha256'],
            'manifest_sha256' => $manifestHash,
            'source' => $manifest['source'],
            'license' => $manifest['license'],
            'attribution' => $manifest['attribution'],
            'certification' => $manifest['certification'],
            'inference' => 'geographic; not original device timezone',
        ]);
        $grid->validate();

        return $grid;
    }

    /** @return array<string, mixed> */
    public function provenance(): array
    {
        return $this->provenance;
    }

    public function zoneForCell(int $latitude, int $longitude): string
    {
        if ($latitude < -9000 || $latitude >= 9000 || $longitude < -18000 || $longitude >= 18000) {
            throw new GeographicZoneUnresolved('unusable_coordinate_cell');
        }

        $key = $latitude.':'.$longitude;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $column = intdiv($longitude + 18000, 1000);
        $row = intdiv($latitude + 9000, 1000);
        $offset = self::HEADER_BYTES + $this->uint32(12 + 4 * (36 * $row + $column));
        $x0 = -18000 + 1000 * $column;
        $x1 = $x0 + 1000;
        $y0 = -9000 + 1000 * $row;
        $y1 = $y0 + 1000;

        while (65535 === ($value = $this->uint16($offset))) {
            $leftBytes = $this->uint32($offset + 2);
            $offset += 6;

            if ($x1 - $x0 >= $y1 - $y0) {
                $middle = (int) floor(($x0 + $x1) / 2);
                $right = $longitude >= $middle;

                if ($right) {
                    $x0 = $middle;
                } else {
                    $x1 = $middle;
                }
            } else {
                $middle = (int) floor(($y0 + $y1) / 2);
                $right = $latitude >= $middle;

                if ($right) {
                    $y0 = $middle;
                } else {
                    $y1 = $middle;
                }
            }

            if ($right) {
                $offset += $leftBytes;
            }
        }

        if (0 === $value) {
            throw new GeographicZoneUnresolved('uncertified_coordinate_cell');
        }

        $zone = $this->zones[$value - 1];

        try {
            new \DateTimeZone($zone);
        } catch (\Throwable $failure) {
            throw new RepairFailed('PHP timezone database does not recognize '.$zone.'.', previous: $failure);
        }

        // Keep the lookup cache bounded for arbitrarily large corpora.
        if (count($this->cache) >= 32768) {
            $this->cache = [];
        }

        return $this->cache[$key] = $zone;
    }

    public function zoneForPosition(int $latitudeSemicircles, int $longitudeSemicircles): string
    {
        return $this->zoneForCell(
            (int) floor($latitudeSemicircles * 18000 / 2147483648),
            (int) floor($longitudeSemicircles * 18000 / 2147483648),
        );
    }

    private function validate(): void
    {
        if (648 !== $this->uint32(8)) {
            throw new RepairFailed('Unsupported geographic database tile directory.');
        }

        $offset = self::HEADER_BYTES;

        for ($tile = 0; $tile < 648; ++$tile) {
            if ($offset !== self::HEADER_BYTES + $this->uint32(12 + 4 * $tile)) {
                throw new RepairFailed('Invalid geographic database tile offset.');
            }

            $offset = $this->validateNode($offset, 1000, 1000);
        }

        if ($offset !== strlen($this->data)) {
            throw new RepairFailed('Trailing bytes in geographic database.');
        }
    }

    private function validateNode(int $offset, int $width, int $height): int
    {
        $value = $this->uint16($offset);

        if (65535 !== $value) {
            if ($value > count($this->zones)) {
                throw new RepairFailed('Invalid geographic database zone index.');
            }

            return $offset + 2;
        }

        if (1 === $width && 1 === $height) {
            throw new RepairFailed('Geographic database subdivides a minimum cell.');
        }

        $leftBytes = $this->uint32($offset + 2);
        $left = $offset + 6;

        if ($width >= $height) {
            $leftWidth = intdiv($width, 2);
            $leftEnd = $this->validateNode($left, $leftWidth, $height);

            if ($leftEnd !== $left + $leftBytes) {
                throw new RepairFailed('Invalid geographic database subtree length.');
            }

            return $this->validateNode($leftEnd, $width - $leftWidth, $height);
        }

        $leftHeight = intdiv($height, 2);
        $leftEnd = $this->validateNode($left, $width, $leftHeight);

        if ($leftEnd !== $left + $leftBytes) {
            throw new RepairFailed('Invalid geographic database subtree length.');
        }

        return $this->validateNode($leftEnd, $width, $height - $leftHeight);
    }

    private function uint16(int $offset): int
    {
        if ($offset < 0 || $offset + 2 > strlen($this->data)) {
            throw new RepairFailed('Truncated geographic database.');
        }

        return ord($this->data[$offset]) | (ord($this->data[$offset + 1]) << 8);
    }

    private function uint32(int $offset): int
    {
        return $this->uint16($offset) | ($this->uint16($offset + 2) << 16);
    }
}
