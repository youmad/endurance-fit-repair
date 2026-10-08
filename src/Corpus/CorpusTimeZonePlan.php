<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Corpus;

use Youmad\Endurance\FitRepair\Internal\Rule\GeographicActivityTimeRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;
use Youmad\Endurance\FitRepair\RepairResult;

/** @internal An explicit offline geographic inference plan bound to current FIT hashes. */
final readonly class CorpusTimeZonePlan
{
    /**
     * @param array<string, array{sha256: string, zone: ?string, offsetSeconds: ?int, basis: array<string, mixed>}> $entries
     * @param array<string, mixed>                                                                                  $resolver
     */
    private function __construct(private string $corpusRoot, private string $validatorFingerprint, private array $entries, private array $resolver, public string $fingerprint)
    {
    }

    public static function read(string $path): self
    {
        $plan = CorpusWorkspace::readJson($path);

        if (1 !== ($plan['schemaVersion'] ?? null) || 'fit-local-time-zones' !== ($plan['mode'] ?? null)
            || !is_string($plan['corpusRoot'] ?? null) || !is_string($plan['validatorFingerprint'] ?? null)
            || !is_array($plan['resolver'] ?? null) || !is_array($plan['results'] ?? null)) {
            throw new RepairFailed('Expected an offline local time zone plan.');
        }

        $entries = [];
        $identifiers = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);

        foreach ($plan['results'] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_string($entry['sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'])
                || (isset($entry['zone']) === isset($entry['offsetSeconds']))
                || (isset($entry['zone']) && (!is_string($entry['zone']) || !in_array($entry['zone'], $identifiers, true) || str_starts_with($entry['zone'], 'Etc/')))
                || (isset($entry['offsetSeconds']) && (!is_int($entry['offsetSeconds']) || -43_200 > $entry['offsetSeconds'] || 50_400 < $entry['offsetSeconds']
                    || 'recorded_device_clock_context' !== ($entry['basis']['method'] ?? null)))
                || !is_array($entry['basis'] ?? null) || isset($entries[$entry['path']])) {
                throw new RepairFailed('Invalid or duplicate geographic zone plan entry.');
            }

            $entries[$entry['path']] = ['sha256' => $entry['sha256'], 'zone' => $entry['zone'] ?? null, 'offsetSeconds' => $entry['offsetSeconds'] ?? null, 'basis' => $entry['basis']];
        }

        $fingerprint = hash_file('sha256', $path);

        if (false === $fingerprint) {
            throw new RepairFailed('Cannot hash the geographic zone plan.');
        }

        return new self($plan['corpusRoot'], $plan['validatorFingerprint'], $entries, $plan['resolver'], $fingerprint);
    }

    public function assertContext(string $corpusRoot, string $validatorFingerprint): void
    {
        if ($this->corpusRoot !== $corpusRoot || $this->validatorFingerprint !== $validatorFingerprint) {
            throw new RepairFailed('Geographic zone plan does not match this corpus and validator.');
        }
    }

    /** @param array<string, mixed> $validation */
    public function applies(string $path, string $sha256, array $validation): bool
    {
        if (!isset($this->entries[$path]) || $this->entries[$path]['sha256'] !== $sha256 || 'FAILED' !== ($validation['status'] ?? null)) {
            return false;
        }

        $errors = $validation['errors'] ?? [];
        $last = is_array($errors) ? end($errors) : false;
        $message = is_array($last) && is_string($last['message'] ?? null) ? $last['message'] : '';

        return str_contains($message, 'failed Garmin required check "Activity Message Local Timestamp is Valid"');
    }

    public function repair(string $path, string $bytes): RepairResult
    {
        $entry = $this->entries[$path] ?? null;

        if (null === $entry || hash('sha256', $bytes) !== $entry['sha256']) {
            throw new RepairFailed('Geographic zone plan does not match the current FIT bytes.');
        }

        $repairer = new GeographicActivityTimeRepairer();
        $repair = null === $entry['zone']
            ? $repairer->repairOffset($bytes, $entry['offsetSeconds'] ?? throw new RepairFailed('Missing recorded device clock offset.'))
            : $repairer->repair($bytes, $entry['zone']);
        $report = $repair->report;
        $report['inference']['plan_sha256'] = $this->fingerprint;
        $report['inference']['resolver'] = $this->resolver;
        $report['inference']['basis'] = $entry['basis'];

        return new RepairResult($repair->bytes, $report);
    }
}
