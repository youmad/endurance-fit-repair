<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Geography;

use Youmad\Endurance\Fit\Decoder\FitFileReader;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Fit\Raw\RawDataRecord;
use Youmad\Endurance\FitRepair\Internal\Fit\PhysicalMessage;
use Youmad\Endurance\FitRepair\RepairFailed;

/** Optional inference from native clocks or freshly certified coordinates of the same device. */
final readonly class RecordedDeviceClockPlanner
{
    /**
     * @param array<string, mixed>   $report
     * @param callable(string): void $progress
     *
     * @return array<string, mixed>
     */
    public function plan(array $report, string $source, GeographicZonePlanner $geography, callable $progress): array
    {
        $root = realpath($source);
        if (false === $root || !is_dir($root) || !is_array($report['results'] ?? null)) {
            throw new RepairFailed('Device clock context requires a corpus directory and repair report.');
        }

        $targets = $peers = $sources = [];
        $scanned = $missing = $unusable = 0;
        foreach ($report['results'] as $row) {
            if (!is_array($row) || !is_string($row['path'] ?? null) || !is_string($row['sourceSha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $row['sourceSha256']) || !is_string($row['currentSha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $row['currentSha256'])
                || preg_match('~(^[/\\\\]|(^|[/\\\\])\.\.([/\\\\]|$))~', $row['path'])) {
                throw new RepairFailed('Invalid original corpus row for device clock context.');
            }
            $path = $root.'/'.$row['path'];
            if (!is_file($path)) {
                ++$missing;
                continue;
            }
            $sha256 = hash_file('sha256', $path);
            if (!in_array($sha256, [$row['sourceSha256'], $row['currentSha256']], true)) {
                throw new RepairFailed('Device context source differs from report: '.$row['path']);
            }
            try {
                $clock = $this->clock($path);
            } catch (\InvalidArgumentException|\Youmad\Endurance\Fit\Exception\FitDecodeException|RepairFailed) {
                $clock = null;
            }
            if (hash_file('sha256', $path) !== $sha256) {
                throw new RepairFailed('Device context source changed while reading: '.$row['path']);
            }
            if (null === $clock) {
                ++$unusable;
            } else {
                $device = implode(':', $clock['device']);
                $nativeClock = $sha256 === $row['sourceSha256'] && null !== $clock['offset'];
                if ($nativeClock) {
                    $peers[$device][] = ['path' => $row['path'], 'sha256' => $sha256, 'utc' => $clock['utc'], 'offsetSeconds' => $clock['offset'], 'source' => 'original_recorded_clock'];
                } else {
                    $sources[] = ['path' => $row['path'], 'file' => $path, 'sha256' => $sha256, 'device' => $device, 'utc' => $clock['utc']];
                }
                $evidence = $row['geographicEvidence'] ?? null;
                $errors = $row['validation']['errors'] ?? [];
                $lastError = is_array($errors) ? end($errors) : false;
                $localFailure = is_array($lastError) && is_string($lastError['message'] ?? null)
                    && str_contains($lastError['message'], 'failed Garmin required check "Activity Message Local Timestamp is Valid"');
                if ($localFailure && 'FAILED' === ($row['status'] ?? null) && is_array($evidence)
                    && 'complete' === ($evidence['status'] ?? null) && ($evidence['sha256'] ?? null) === $row['currentSha256']
                    && 1 === ($evidence['absoluteActivityTimes'] ?? null) && 0 === ($evidence['relativeActivityTimes'] ?? null)
                    && 1 === count($evidence['rows']['activities'] ?? []) && null === $clock['offset']) {
                    $utc = $evidence['rows']['activities'][0][2];
                    if (is_int($utc) && $utc === $clock['utc']) {
                        $targets[] = ['path' => $row['path'], 'sha256' => $row['currentSha256'], 'device' => $device, 'utc' => $utc];
                    }
                }
            }
            if (0 === ++$scanned % 100) {
                $progress('Device context: '.$scanned.' files read.');
            }
        }

        $targetDevices = array_fill_keys(array_column($targets, 'device'), true);
        foreach ($sources as $sourceFile) {
            if (!isset($targetDevices[$sourceFile['device']])) {
                continue;
            }
            $evidence = (new FitGeographicEvidence())->collect($sourceFile['file'], $sourceFile['sha256']);
            try {
                $resolved = $geography->resolve($evidence);
            } catch (GeographicZoneUnresolved) {
                continue;
            }
            $zone = new \DateTimeZone($resolved['zone']);
            $peers[$sourceFile['device']][] = ['path' => $sourceFile['path'], 'sha256' => $sourceFile['sha256'], 'utc' => $sourceFile['utc'],
                'offsetSeconds' => $zone->getOffset(new \DateTimeImmutable('@'.($sourceFile['utc'] + 631_065_600))),
                'source' => 'freshly_certified_coordinates', 'zone' => $resolved['zone'], 'geographicBasis' => $resolved['basis']];
        }

        $zones = [];
        foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $name) {
            try {
                $zones[] = new \DateTimeZone($name);
            } catch (\DateInvalidTimeZoneException) {
                // Some timezone databases also enumerate non-zone entries such as leapseconds.
                continue;
            }
        }
        $cache = $episodes = $results = $skipped = [];
        foreach ($targets as $target) {
            $observations = $peers[$target['device']] ?? [];
            $before = $after = false;
            foreach ($observations as $observation) {
                $before = $before || $observation['utc'] < $target['utc'];
                $after = $after || $observation['utc'] > $target['utc'];
            }
            if (!$before || !$after) {
                $skipped[$target['path']] = 'device_clock_context_does_not_bracket_activity';
                continue;
            }
            if (!isset($cache[$target['device']])) {
                $cache[$target['device']] = array_values(array_filter($zones, static function (\DateTimeZone $zone) use ($observations): bool {
                    foreach ($observations as $observation) {
                        if ($zone->getOffset(new \DateTimeImmutable('@'.($observation['utc'] + 631_065_600))) !== $observation['offsetSeconds']) {
                            return false;
                        }
                    }

                    return true;
                }));
            }
            $compatible = $cache[$target['device']];
            $offsets = [];
            foreach ($compatible as $zone) {
                $offsets[$zone->getOffset(new \DateTimeImmutable('@'.($target['utc'] + 631_065_600)))] = true;
            }
            if (1 !== count($offsets)) {
                $episodes[$target['device']] ??= $this->geographicEpisodes($observations);
                $episode = null;
                foreach ($episodes[$target['device']] as $candidate) {
                    if ($candidate['fromUtc'] < $target['utc'] && $target['utc'] < $candidate['untilUtc']) {
                        $episode = $candidate;
                        break;
                    }
                }
                if (null === $episode) {
                    $skipped[$target['path']] = [] === $compatible ? 'inconsistent_device_clock_context' : 'ambiguous_device_clock_context';
                    continue;
                }
                $zone = new \DateTimeZone($episode['zone']);
                $results[] = ['path' => $target['path'], 'sha256' => $target['sha256'],
                    'offsetSeconds' => $zone->getOffset(new \DateTimeImmutable('@'.($target['utc'] + 631_065_600))), 'basis' => [
                        'method' => 'recorded_device_clock_context', 'scope' => 'certified_geographic_episode',
                        'device' => $target['device'], 'activityUtc' => $target['utc'],
                        'fromUtc' => $episode['fromUtc'], 'untilUtc' => $episode['untilUtc'],
                        'assumption' => 'The identified device stayed in the same certified geographic timezone between this uninterrupted run of geographic observations; all original recorded clocks inside that interval agree.',
                        'compatibleZones' => [$episode['zone']], 'observations' => $episode['observations'],
                        'observationsOutsideEpisode' => count($observations) - count($episode['observations']),
                    ]];
                continue;
            }
            $results[] = ['path' => $target['path'], 'sha256' => $target['sha256'], 'offsetSeconds' => array_key_first($offsets), 'basis' => [
                'method' => 'recorded_device_clock_context', 'device' => $target['device'], 'activityUtc' => $target['utc'],
                'assumption' => 'The identified device stayed in a timezone compatible with its original clock or certified geographic observations bracketing this activity.',
                'compatibleZones' => array_map(static fn (\DateTimeZone $zone): string => $zone->getName(), $compatible), 'observations' => $observations,
            ]];
        }

        $skippedDevices = [];
        foreach ($targets as $target) {
            if (!isset($skipped[$target['path']])) {
                continue;
            }
            $skippedDevices[$target['device']] ??= ['observations' => $peers[$target['device']] ?? [], 'targets' => []];
            $skippedDevices[$target['device']]['targets'][] = $target + ['reason' => $skipped[$target['path']]];
        }

        return ['results' => $results, 'skipped' => $skipped, 'sourceFiles' => $scanned, 'missingSourceFiles' => $missing, 'unusableSourceFiles' => $unusable,
            'skippedDevices' => $skippedDevices, 'timezoneDatabaseVersion' => timezone_version_get(), 'implementationSha256' => hash_file('sha256', __FILE__)];
    }

    /**
     * @param list<array<string, mixed>> $observations
     *
     * @return list<array{zone: string, fromUtc: int, untilUtc: int, observations: list<array<string, mixed>>}>
     */
    private function geographicEpisodes(array $observations): array
    {
        $geographic = array_values(array_filter($observations, static fn (array $row): bool => 'freshly_certified_coordinates' === $row['source']));
        usort($geographic, static fn (array $left, array $right): int => ($left['utc'] <=> $right['utc']) ?: strcmp($left['path'], $right['path']));
        $runs = [];
        foreach ($geographic as $row) {
            $last = array_key_last($runs);
            if (null === $last || $row['zone'] !== $runs[$last]['zone']) {
                $runs[] = ['zone' => $row['zone'], 'fromUtc' => $row['utc'], 'untilUtc' => $row['utc']];
            } else {
                $runs[$last]['untilUtc'] = $row['utc'];
            }
        }
        $episodes = [];
        foreach ($runs as $run) {
            if ($run['fromUtc'] === $run['untilUtc']) {
                continue;
            }
            // Include every peer in this entire geographic run, including native clocks and tied dates.
            $inside = array_values(array_filter($observations, static fn (array $row): bool => $run['fromUtc'] <= $row['utc'] && $row['utc'] <= $run['untilUtc']));
            $zone = new \DateTimeZone($run['zone']);
            foreach ($inside as $row) {
                if (('freshly_certified_coordinates' === $row['source'] && $run['zone'] !== $row['zone'])
                    || $zone->getOffset(new \DateTimeImmutable('@'.($row['utc'] + 631_065_600))) !== $row['offsetSeconds']) {
                    continue 2;
                }
            }
            $episodes[] = $run + ['observations' => $inside];
        }

        return $episodes;
    }

    /** @return array{device: list<int>, utc: int, offset: ?int}|null */
    private function clock(string $path): ?array
    {
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            throw new RepairFailed('Cannot open original clock context.');
        }
        $identity = $activity = null;
        $fileIds = $activities = 0;
        try {
            $stat = fstat($stream);
            $file = (new FitFileReader())->open(new ResourceFitInput($stream));
            if (false === $stat || 67_108_864 < $stat['size'] || 0 !== $file->header->byteOffset
                || $stat['size'] !== $file->header->headerSize + $file->header->dataSize + 2) {
                return null;
            }
            foreach ($file->messages() as $raw) {
                if (!$raw instanceof RawDataRecord || !in_array($raw->globalMessageNumber(), [0, 34], true)) {
                    continue;
                }
                $message = PhysicalMessage::fromRaw($raw, 0);
                if (0 === $message->number) {
                    ++$fileIds;
                    $identity = [$this->uniqueInteger($message, 1), $this->uniqueInteger($message, 2), $this->uniqueInteger($message, 3)];
                    if (4 !== $this->uniqueInteger($message, 0)) {
                        return null;
                    }
                } else {
                    ++$activities;
                    $activity = [$this->uniqueInteger($message, 253), $this->uniqueInteger($message, 5)];
                }
            }
            $file->trailer();
        } finally {
            fclose($stream);
        }
        if (1 !== $fileIds || 1 !== $activities || null === $identity || in_array(null, $identity, true)
            || 255 === $identity[0] || 0 === $identity[2] || 0xFFFFFFFF === $identity[2]
            || null === $activity || null === $activity[0] || 0x10000000 > $activity[0]) {
            return null;
        }
        $offset = null === $activity[1] ? null : $activity[1] - $activity[0];

        return ['device' => $identity, 'utc' => $activity[0], 'offset' => null !== $offset && -43_200 <= $offset && 50_400 >= $offset ? $offset : null];
    }

    private function uniqueInteger(PhysicalMessage $message, int $number): ?int
    {
        $found = 0;
        foreach ($message->fields as $field) {
            if ($number === $field->definition->fieldNumber && 1 < ++$found) {
                throw new RepairFailed('Device context requires unique scalar identification and clock fields.');
            }
        }

        return $message->integer($number);
    }
}
