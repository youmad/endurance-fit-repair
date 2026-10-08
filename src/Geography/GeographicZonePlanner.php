<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Geography;

use Youmad\Endurance\FitRepair\RepairFailed;

/** @internal Creates the existing offline plan format without external processes. */
final readonly class GeographicZonePlanner
{
    public function __construct(private CertifiedTimeZoneGrid $grid)
    {
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    public function plan(array $report, string $reportSha256): array
    {
        if (1 !== ($report['schemaVersion'] ?? null) || 'activity-repair-corpus' !== ($report['mode'] ?? null)
            || !isset($report['geographicSummary']) || !is_string($report['corpusRoot'] ?? null)
            || !is_string($report['validatorFingerprint'] ?? null) || !is_array($report['results'] ?? null)
            || !array_is_list($report['results']) || !preg_match('/^[a-f0-9]{64}$/D', $reportSha256)) {
            throw new RepairFailed('Expected a corpus repair report made with --collect-geography.');
        }

        $results = $skipped = $paths = $reasons = [];

        foreach ($report['results'] as $row) {
            if (!is_array($row) || !is_string($row['path'] ?? null) || isset($paths[$row['path']])) {
                throw new RepairFailed('Invalid or duplicate geographic report path.');
            }

            $paths[$row['path']] = true;

            if ('FAILED' !== ($row['status'] ?? null) || !isset($row['geographicEvidence'])) {
                continue;
            }

            $evidence = $row['geographicEvidence'];

            if (!is_array($evidence) || !is_string($row['currentSha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $row['currentSha256'])
                || ($evidence['sha256'] ?? null) !== $row['currentSha256']) {
                throw new RepairFailed('Geographic evidence hash differs from current FIT hash.');
            }

            try {
                $resolved = $this->resolve($evidence);
                $results[] = ['path' => $row['path'], 'sha256' => $row['currentSha256']] + $resolved;
            } catch (GeographicZoneUnresolved $failure) {
                $reason = $failure->getMessage();
                $skipped[] = ['path' => $row['path'], 'reason' => $reason];
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }

        $resolver = $this->grid->provenance();
        $resolver['implementation_sha256'] = hash('sha256', hash_file('sha256', __FILE__).hash_file('sha256', __DIR__.'/CertifiedTimeZoneGrid.php'));

        return [
            'schemaVersion' => 1,
            'mode' => 'fit-local-time-zones',
            'corpusRoot' => $report['corpusRoot'],
            'validatorFingerprint' => $report['validatorFingerprint'],
            'reportSha256' => $reportSha256,
            'resolver' => $resolver,
            'summary' => ['resolved' => count($results), 'skipped' => count($skipped), 'skipReasons' => $reasons],
            'results' => $results,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     *
     * @return array{zone: string, basis: array<string, mixed>}
     */
    public function resolve(array $evidence): array
    {
        if ('complete' !== ($evidence['status'] ?? null) || 1 !== ($evidence['schemaVersion'] ?? null)) {
            throw new GeographicZoneUnresolved('unavailable_evidence');
        }

        $columns = [
            'summaryPositions' => ['sequence', 'byteOffset', 'globalMessageNumber', 'position', 'timestamp', 'latitude', 'longitude'],
            'sessions' => ['sequence', 'byteOffset', 'start_time', 'timestamp', 'sport', 'sub_sport'],
            'activities' => ['sequence', 'byteOffset', 'timestamp', 'local_timestamp', 'absoluteUtc'],
        ];

        if (!is_array($evidence['columns'] ?? null) || !is_array($evidence['rows'] ?? null)
            || !is_array($evidence['records'] ?? null) || !is_array($evidence['rowCounts'] ?? null)
            || !is_array($evidence['omittedRows'] ?? null)
            || !is_array($evidence['cells'] ?? null) || !array_is_list($evidence['cells'])
            || ($evidence['cellColumns'] ?? null) !== ['latitudeCell', 'longitudeCell', 'positionCount']
            || 0.01 !== ($evidence['cellSizeDegrees'] ?? null)) {
            throw new RepairFailed('Unsupported geographic evidence shape or grid size.');
        }

        foreach ($columns as $name => $expected) {
            if (($evidence['columns'][$name] ?? null) !== $expected || !is_array($evidence['rows'][$name] ?? null)
                || !array_is_list($evidence['rows'][$name])) {
                throw new RepairFailed('Unsupported geographic evidence columns or rows.');
            }

            foreach ($evidence['rows'][$name] as $row) {
                if (!is_array($row) || !array_is_list($row) || count($row) !== count($expected)) {
                    throw new RepairFailed('Malformed geographic evidence row.');
                }
            }
        }

        foreach ([$evidence['records']['unrepresentedPositions'] ?? null, $evidence['records']['usablePositions'] ?? null, $evidence['unrepresentedClockPairs'] ?? null] as $count) {
            if (!is_int($count) || $count < 0) {
                throw new RepairFailed('Malformed geographic evidence count.');
            }
        }

        if (!empty($evidence['omittedRows']) || 0 !== $evidence['records']['unrepresentedPositions'] || 0 !== $evidence['unrepresentedClockPairs']) {
            throw new GeographicZoneUnresolved('incomplete_evidence');
        }

        foreach ($evidence['rows']['sessions'] as $session) {
            if (58 === $session[5]) {
                throw new GeographicZoneUnresolved('virtual_activity');
            }
        }

        $activities = $evidence['rows']['activities'];

        if (1 !== count($activities) || true !== $activities[0][4]) {
            throw new GeographicZoneUnresolved('relative_or_multiple_activity_times');
        }

        $positions = $evidence['records']['usablePositions'];
        $counted = 0;

        foreach ($evidence['cells'] as $cell) {
            if (!is_array($cell) || !array_is_list($cell) || 3 !== count($cell)
                || !is_int($cell[0]) || !is_int($cell[1]) || !is_int($cell[2]) || $cell[2] < 1) {
                throw new RepairFailed('Malformed geographic coordinate cell.');
            }

            $counted += $cell[2];
        }

        if ($counted !== $positions || ($evidence['rowCounts']['summaryPositions'] ?? null) !== count($evidence['rows']['summaryPositions'])) {
            throw new RepairFailed('Geographic position counts do not cover the reported positions.');
        }

        $zone = null;

        foreach ($evidence['cells'] as $cell) {
            $zone = $this->agree($zone, $this->grid->zoneForCell($cell[0], $cell[1]));
        }

        foreach ($evidence['rows']['summaryPositions'] as $row) {
            if (!is_int($row[5]) || !is_int($row[6]) || $row[5] < -1073741824 || $row[5] >= 1073741824
                || $row[6] < -2147483648 || $row[6] >= 2147483648) {
                throw new GeographicZoneUnresolved('unusable_summary_coordinate');
            }

            $zone = $this->agree($zone, $this->grid->zoneForPosition($row[5], $row[6]));
        }

        if (null === $zone) {
            throw new GeographicZoneUnresolved('no_positions');
        }

        return [
            'zone' => $zone,
            'basis' => [
                'recordPositions' => $positions,
                'recordCells' => count($evidence['cells']),
                'summaryPositions' => count($evidence['rows']['summaryPositions']),
                'policy' => 'all retained cells certified strictly inside one non-Etc zone; all summary cells agree; no truncation',
            ],
        ];
    }

    private function agree(?string $zone, string $next): string
    {
        if (null !== $zone && $zone !== $next) {
            throw new GeographicZoneUnresolved('route_crosses_zones');
        }

        return $next;
    }
}
