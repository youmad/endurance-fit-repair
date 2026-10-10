<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Corpus;

use Youmad\Endurance\Fit\Exception\FitDecodeException;
use Youmad\Endurance\FitRepair\Geography\FitGeographicEvidence;
use Youmad\Endurance\FitRepair\Internal\Rule\GeographicActivityTimeRepairer;
use Youmad\Endurance\FitRepair\Internal\Rule\PreservingActivityRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;

/** A fixed-point run: each round changes one group and validates only new bytes. */
final readonly class CorpusRepairRunner
{
    /**
     * @param callable(string): void $progress
     *
     * @return array<string, mixed>
     */
    public function run(string $source, CorpusWorkspace $workspace, CorpusValidator $validator, callable $progress, ?string $baseline = null, ?int $offset = null, int $maxRounds = 20, bool $collectGeography = false, ?CorpusTimeZonePlan $localTimeZones = null, bool $reconcileSummaryClocks = false, bool $useUtcForUnknownLocalTime = false): array
    {
        if (1 > $maxRounds || (null !== $offset && (-43_200 > $offset || 50_400 < $offset)) || (null !== $offset && null !== $localTimeZones)) {
            throw new RepairFailed('Invalid round limit or local time offset.');
        }

        $root = realpath($source);
        $output = realpath($workspace->root);

        if (false === $root || !is_dir($root) || false === $output || $output === $root || str_starts_with($output, $root.DIRECTORY_SEPARATOR)) {
            throw new RepairFailed('Use a corpus directory and a workspace outside that directory.');
        }

        $lock = fopen($workspace->root.'/.lock', 'c+b');

        if (false === $lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RepairFailed('This workspace is already in use.');
        }

        try {
            $files = $this->inventory($root);
            $fingerprint = $validator->fingerprint();
            $state = $this->load($workspace, $root, $files, $fingerprint, $baseline, $validator->profileSha256());
            $localTimeZones?->assertContext($root, $fingerprint);
            $rules = $this->rulesFingerprint($offset, $reconcileSummaryClocks, $useUtcForUnknownLocalTime).':'.$fingerprint.':'.($localTimeZones->fingerprint ?? '');
            $repairer = new PreservingActivityRepairer();
            $workspace->writeJson('state.json', $state);
            $progress('Corpus: '.count($files).' files; checkpoint loaded.');

            // Resume pending edits before choosing any new group.
            $this->validateCurrent($state, $workspace, $validator, $progress);
            $rounds = 0;

            while (true) {
                $summary = $this->summary($state);

                if (0 < $summary['toolError']) {
                    $reason = 'tool_error';
                    break;
                }

                if (0 === $summary['failed']) {
                    $reason = 'all_pass';
                    break;
                }

                if ($rounds >= $maxRounds) {
                    $reason = 'round_limit';
                    break;
                }

                $groups = [];

                foreach ($state['files'] as $path => $file) {
                    $fileRules = $repairer->rulesForFailure($file['result'], $offset, $reconcileSummaryClocks);

                    if (null !== $localTimeZones && $localTimeZones->applies($path, $file['currentSha'], $file['result'])) {
                        $fileRules = [GeographicActivityTimeRepairer::RULE];
                    } elseif ($useUtcForUnknownLocalTime && null === $offset && 'FAILED' === ($file['result']['status'] ?? null)) {
                        foreach ($file['result']['errors'] ?? [] as $error) {
                            if (str_contains((string) ($error['message'] ?? ''), '"Activity Message Local Timestamp is Valid"')) {
                                $fileRules[] = GeographicActivityTimeRepairer::NATIVE_RULE;
                                $fileRules[] = GeographicActivityTimeRepairer::UTC_COMPATIBILITY_RULE;
                                break;
                            }
                        }
                    }

                    foreach ($fileRules as $rule) {
                        if (!isset($file['attempts'][$rules.':'.$file['currentSha'].':'.$rule])) {
                            $groups[$rule][] = $path;
                            break;
                        }
                    }
                }

                if ([] === $groups) {
                    $reason = 'fixed_point';
                    break;
                }

                uksort($groups, static fn (string $a, string $b): int => (count($groups[$b]) <=> count($groups[$a])) ?: strcmp($a, $b));
                $rule = array_key_first($groups);
                $changed = 0;
                $progress(sprintf('Group %s: %d candidates.', $rule, count($groups[$rule])));

                foreach ($groups[$rule] as $index => $path) {
                    $file = &$state['files'][$path];
                    $attempt = $rules.':'.$file['currentSha'].':'.$rule;

                    try {
                        $bytes = $this->readFit($this->currentPath($state, $workspace, $path));

                        if (hash('sha256', $bytes) !== $file['currentSha']) {
                            throw new \RuntimeException('Current file changed during repair: '.$path);
                        }

                        $repair = match (true) {
                            GeographicActivityTimeRepairer::RULE === $rule && null !== $localTimeZones => $localTimeZones->repair($path, $bytes),
                            GeographicActivityTimeRepairer::NATIVE_RULE === $rule => (new GeographicActivityTimeRepairer())->repairNativeContext($bytes),
                            GeographicActivityTimeRepairer::UTC_COMPATIBILITY_RULE === $rule => (new GeographicActivityTimeRepairer())->repairUtcCompatibility($bytes),
                            default => $repairer->repair($bytes, $rule, $offset),
                        };
                        $sha = hash('sha256', $repair->bytes);

                        if ($sha === $file['currentSha']) {
                            $file['attempts'][$attempt] = 'no_change';
                        } elseif (in_array($sha, $file['seen'], true)) {
                            $file['attempts'][$attempt] = 'cycle_detected';
                        } else {
                            $workspace->blob($repair->bytes);
                            $file['pending'] = ['sha256' => $sha, 'report' => $repair->report];
                            $file['attempts'][$attempt] = 'changed';
                            ++$changed;
                        }
                    } catch (RepairFailed|FitDecodeException|\InvalidArgumentException $failure) {
                        $file['attempts'][$attempt] = 'unsupported: '.$failure->getMessage();
                    } finally {
                        unset($file);
                    }

                    if (0 === ($index + 1) % 25) {
                        $progress(sprintf('Prepared %d/%d files; %d changed.', $index + 1, count($groups[$rule]), $changed));
                    }
                }

                if (0 < $changed) {
                    ++$state['round'];
                    ++$rounds;
                }

                // A process interruption here leaves complete candidate versions
                // and a pending validation batch, rather than losing this round.
                $workspace->writeJson('state.json', $state);

                if (0 < $changed) {
                    $progress(sprintf('Round %d: %d changed files.', $state['round'], $changed));
                    $this->validateCurrent($state, $workspace, $validator, $progress);
                }
            }

            return $this->publish($state, $workspace, $reason, $progress, $collectGeography, $reconcileSummaryClocks, $useUtcForUnknownLocalTime);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, string> Relative path => SHA-256. */
    private function inventory(string $root): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && 'fit' === strtolower($file->getExtension())) {
                $hash = hash_file('sha256', $file->getPathname());

                if (false === $hash) {
                    throw new RepairFailed('Cannot hash source file.');
                }

                $files[substr($file->getPathname(), strlen($root) + 1)] = $hash;
            }
        }

        if ([] === $files) {
            throw new RepairFailed('No FIT files found.');
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param array<string, string> $files
     *
     * @return array<string, mixed>
     */
    private function load(CorpusWorkspace $workspace, string $root, array $files, string $fingerprint, ?string $baseline, ?string $profile): array
    {
        if (is_file($workspace->root.'/state.json')) {
            $state = CorpusWorkspace::readJson($workspace->root.'/state.json');

            if (1 !== ($state['schemaVersion'] ?? null) || $root !== ($state['corpusRoot'] ?? null)
                || !is_array($state['files'] ?? null) || array_keys($files) !== array_keys($state['files'])) {
                throw new RepairFailed('Workspace belongs to another corpus; use a new workspace.');
            }

            foreach ($files as $path => $sha) {
                $stored = $state['files'][$path];

                if ($stored['sourceSha'] !== $sha || !preg_match('/^[a-f0-9]{64}$/D', $stored['currentSha'])
                    || (isset($stored['pending']) && !preg_match('/^[a-f0-9]{64}$/D', $stored['pending']['sha256']))) {
                    throw new RepairFailed('Corpus or checkpoint changed; use a new workspace.');
                }

                if (hash_file('sha256', $this->currentPath($state, $workspace, $path)) !== $stored['currentSha']) {
                    throw new RepairFailed('A stored FIT version was modified.');
                }
            }

            if ($state['validatorFingerprint'] !== $fingerprint) {
                $state['validatorFingerprint'] = $fingerprint;
                $state['profileSha256'] = $profile;
                $state['cache'] = [];

                foreach ($state['files'] as $path => &$file) {
                    $file['result'] = null;
                    $workspace->removeLink('ready', $path);
                }

                unset($file);
            }

            return $state;
        }

        if ([] !== array_diff(scandir($workspace->root) ?: [], ['.', '..', '.lock'])) {
            throw new RepairFailed('New workspace must be empty.');
        }

        $state = ['schemaVersion' => 1, 'corpusRoot' => $root, 'validatorFingerprint' => $fingerprint, 'profileSha256' => $profile, 'round' => 0, 'check' => 0, 'files' => [], 'cache' => []];
        $results = [];

        if (null !== $baseline) {
            $report = CorpusWorkspace::readJson($baseline);

            if (1 !== ($report['schemaVersion'] ?? null)
                || (isset($report['phpVersion']) && PHP_VERSION !== $report['phpVersion'])
                || ($report['profileSha256'] ?? null) !== $profile
                || (isset($report['validatorFingerprint']) && $report['validatorFingerprint'] !== $fingerprint)
                || !is_array($report['results'] ?? null)) {
                throw new RepairFailed('Baseline must be a current validation report for this validator and Profile.');
            }

            foreach ($report['results'] as $result) {
                if (!is_array($result) || !is_string($result['path'] ?? null) || !isset($files[$result['path']])
                    || isset($results[$result['path']]) || ($result['sha256'] ?? null) !== $files[$result['path']]
                    || !in_array($result['status'] ?? null, ['PASS', 'FAILED', 'TOOL_ERROR'], true)) {
                    throw new RepairFailed('Baseline contains an unexpected, duplicate or changed file.');
                }

                $results[$result['path']] = $result;
            }

            if (count($results) !== count($files)) {
                throw new RepairFailed('Baseline does not cover the complete corpus.');
            }
        }

        foreach ($files as $path => $sha) {
            $state['files'][$path] = ['sourceSha' => $sha, 'currentSha' => $sha, 'seen' => [$sha], 'result' => $results[$path] ?? null, 'history' => [], 'attempts' => []];

            if (isset($results[$path]) && 'TOOL_ERROR' !== $results[$path]['status']) {
                $state['cache'][$sha] = $results[$path];
            }
        }

        return $state;
    }

    /**
     * @param array<string, mixed>   $state
     * @param callable(string): void $progress
     */
    private function validateCurrent(array &$state, CorpusWorkspace $workspace, CorpusValidator $validator, callable $progress): void
    {
        $needed = [];
        $hashes = [];
        $results = [];

        foreach ($state['files'] as $path => $file) {
            if (!isset($file['pending']) && null !== $file['result'] && 'TOOL_ERROR' !== $file['result']['status']) {
                continue;
            }

            $hash = $file['pending']['sha256'] ?? $file['currentSha'];
            $physical = isset($file['pending']) ? $workspace->root.'/versions/'.$hash.'.fit' : $this->currentPath($state, $workspace, $path);

            if (hash_file('sha256', $physical) !== $hash) {
                throw new RepairFailed('Pending validation bytes changed: '.$path);
            }

            $hashes[$path] = $hash;

            if (isset($state['cache'][$hash])) {
                $results[$path] = $state['cache'][$hash];
            } else {
                $needed[$path] = $physical;
            }
        }

        if ([] !== $needed) {
            $destination = $workspace->root.'/checks/'.sprintf('%06d', ++$state['check']);
            $workspace->writeJson('state.json', $state);
            $progress('Validating '.count($needed).' changed or uncached files; log: '.$destination.'/validator.log');
            $validated = $validator->validate($needed, $destination);

            if (count($validated) !== count($needed) || [] !== array_diff_key($validated, $needed)) {
                throw new RepairFailed('Validator batch is incomplete.');
            }

            foreach ($validated as $path => $result) {
                if (($result['sha256'] ?? null) !== $hashes[$path] || !in_array($result['status'] ?? null, ['PASS', 'FAILED', 'TOOL_ERROR'], true)) {
                    throw new RepairFailed('Validation result does not match the planned bytes.');
                }

                $results[$path] = $result;

                if ('TOOL_ERROR' !== $result['status']) {
                    $state['cache'][$hashes[$path]] = $result;
                }
            }
        }

        foreach ($results as $path => $result) {
            $file = &$state['files'][$path];
            $result['path'] = $path;

            if (isset($file['pending'])) {
                $file['history'][] = ['round' => $state['round'], 'repair' => $file['pending']['report'], 'validationStatus' => $result['status']];
                $file['currentSha'] = $file['pending']['sha256'];
                $file['seen'][] = $file['currentSha'];
                unset($file['pending']);
            }

            $file['result'] = $result;
            unset($file);
        }

        $workspace->writeJson('state.json', $state);
        $summary = $this->summary($state);
        $progress(sprintf('PASS: %d; FAILED: %d; TOOL_ERROR: %d.', $summary['pass'], $summary['failed'], $summary['toolError']));
    }

    /** @param array<string, mixed> $state */
    private function currentPath(array $state, CorpusWorkspace $workspace, string $path): string
    {
        $file = $state['files'][$path];

        return $file['currentSha'] === $file['sourceSha'] ? $state['corpusRoot'].'/'.$path : $workspace->root.'/versions/'.$file['currentSha'].'.fit';
    }

    private function readFit(string $path): string
    {
        $stream = fopen($path, 'rb');

        if (false === $stream) {
            throw new \RuntimeException('Cannot open current FIT file.');
        }

        try {
            $bytes = stream_get_contents($stream, 67_108_865);

            if (false === $bytes) {
                throw new \RuntimeException('Cannot read current FIT file.');
            }

            if (67_108_864 < strlen($bytes)) {
                throw new RepairFailed('Preserving repair supports files up to 64 MiB.');
            }

            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    private function rulesFingerprint(?int $offset, bool $reconcileSummaryClocks, bool $useUtcForUnknownLocalTime): string
    {
        $root = dirname(__DIR__);
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);
        $hash = hash_init('sha256');
        hash_update($hash, (string) $offset."\0");
        hash_update($hash, $reconcileSummaryClocks ? "reconcile_summary_clocks\0" : "strict\0");

        hash_update($hash, $useUtcForUnknownLocalTime ? "utc_compatibility\0" : "native_local_clock\0");

        foreach ($files as $file) {
            hash_update($hash, substr($file, strlen($root) + 1)."\0".(string) hash_file('sha256', $file)."\0");
        }

        return hash_final($hash);
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{pass: int, failed: int, toolError: int}
     */
    private function summary(array $state): array
    {
        $summary = ['pass' => 0, 'failed' => 0, 'toolError' => 0];

        foreach ($state['files'] as $file) {
            ++$summary[match ($file['result']['status'] ?? null) {
                'PASS' => 'pass',
                'FAILED' => 'failed',
                default => 'toolError',
            }];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed>   $state
     * @param callable(string): void $progress
     *
     * @return array<string, mixed>
     */
    private function publish(array $state, CorpusWorkspace $workspace, string $reason, callable $progress, bool $collectGeography, bool $reconcileSummaryClocks, bool $useUtcForUnknownLocalTime): array
    {
        $groups = [];
        $results = [];
        $repairs = [];
        $collector = new FitFailureDiagnostics();
        $revision = hash('sha256', $collector->fingerprint().':'.$state['validatorFingerprint']);
        $diagnostics = ['complete' => 0, 'unavailable' => 0, 'truncated' => 0, 'read' => 0, 'cached' => 0];
        $failed = $this->summary($state)['failed'];
        $done = 0;
        $geographicCollector = new FitGeographicEvidence();
        $geographicRevision = hash('sha256', $geographicCollector->fingerprint().':'.$state['validatorFingerprint']);
        $geography = ['candidates' => 0, 'complete' => 0, 'unavailable' => 0, 'read' => 0, 'cached' => 0, 'withRecordPositions' => 0, 'withSummaryPositions' => 0, 'withAbsoluteActivityTime' => 0, 'withUsableClockPairs' => 0, 'truncated' => 0];

        if (0 < $failed) {
            $progress('Diagnostics: '.$failed.' failed files; cached evidence will be reused.');
        }

        foreach ($state['files'] as $path => $file) {
            $physical = $this->currentPath($state, $workspace, $path);
            $workspace->link('current', $path, $physical);
            $result = $file['result'];
            $results[] = $result;

            if ('PASS' === $result['status']) {
                $workspace->link('ready', $path, $physical);
            } else {
                $workspace->removeLink('ready', $path);
                $errors = $result['errors'] ?? [];
                $last = is_array($errors) ? end($errors) : false;
                $message = is_array($last) && is_string($last['message'] ?? null) ? $last['message'] : 'Unknown failure';

                if (preg_match('/failed Garmin required check "([^"]+)"/', $message, $match)) {
                    $group = $match[1];
                } else {
                    $group = preg_replace('/-?\d+(?:\.\d+)?/', '#', $message) ?? $message;
                }

                $groups[$group] = ($groups[$group] ?? 0) + 1;
            }

            $repair = ['path' => $path, 'sourceSha256' => $file['sourceSha'], 'currentSha256' => $file['currentSha'], 'status' => $result['status'], 'validation' => $result, 'history' => $file['history'], 'attempts' => $file['attempts']];

            if ('FAILED' === $result['status']) {
                $focus = preg_match('/at sequence\s+#?(\d+)/', $message, $sequence) ? (int) $sequence[1] : null;
                $key = hash('sha256', $file['currentSha'].':'.$focus);
                $cache = 'diagnostics/'.$revision.'/'.$key.'.json';

                if (is_file($workspace->root.'/'.$cache)) {
                    $evidence = CorpusWorkspace::readJson($workspace->root.'/'.$cache);
                    ++$diagnostics['cached'];
                } else {
                    $evidence = $collector->collect($physical, $file['currentSha'], static fn (string $message) => $progress('Diagnostics '.$path.': '.$message), focusSequence: $focus);
                    CorpusWorkspace::directory(dirname($workspace->root.'/'.$cache));
                    $workspace->writeJson($cache, $evidence);
                    ++$diagnostics['read'];
                }

                if (1 !== ($evidence['schemaVersion'] ?? null) || $file['currentSha'] !== ($evidence['sha256'] ?? null)
                    || !in_array($evidence['status'] ?? null, ['complete', 'unavailable'], true)) {
                    throw new RepairFailed('Diagnostic cache does not match the current FIT version.');
                }

                ++$diagnostics[$evidence['status']];

                if ([] !== ($evidence['omittedRows'] ?? [])) {
                    ++$diagnostics['truncated'];
                }

                $repair['diagnostics'] = $evidence;

                if ($collectGeography && str_contains($message, 'failed Garmin required check "Activity Message Local Timestamp is Valid"')) {
                    ++$geography['candidates'];
                    $cache = 'geography/'.$geographicRevision.'/'.$file['currentSha'].'.json';

                    if (is_file($workspace->root.'/'.$cache)) {
                        $geographicEvidence = CorpusWorkspace::readJson($workspace->root.'/'.$cache);
                        ++$geography['cached'];
                    } else {
                        $geographicEvidence = $geographicCollector->collect($physical, $file['currentSha'], static fn (string $message) => $progress('Geography '.$path.': '.$message));
                        CorpusWorkspace::directory(dirname($workspace->root.'/'.$cache));
                        $workspace->writeJson($cache, $geographicEvidence);
                        ++$geography['read'];
                    }

                    if (1 !== ($geographicEvidence['schemaVersion'] ?? null) || $file['currentSha'] !== ($geographicEvidence['sha256'] ?? null)
                        || !in_array($geographicEvidence['status'] ?? null, ['complete', 'unavailable'], true)) {
                        throw new RepairFailed('Geographic cache does not match the current FIT version.');
                    }

                    ++$geography[$geographicEvidence['status']];

                    if ('complete' === $geographicEvidence['status']) {
                        $geography['withRecordPositions'] += 0 < $geographicEvidence['records']['usablePositions'] ? 1 : 0;
                        $geography['withSummaryPositions'] += 0 < $geographicEvidence['rowCounts']['summaryPositions'] ? 1 : 0;
                        $geography['withAbsoluteActivityTime'] += 0 < $geographicEvidence['absoluteActivityTimes'] ? 1 : 0;
                        $geography['withUsableClockPairs'] += 0 < $geographicEvidence['usableClockPairs'] ? 1 : 0;
                        $geography['truncated'] += [] !== $geographicEvidence['omittedRows'] || 0 < $geographicEvidence['records']['unrepresentedPositions'] || 0 < $geographicEvidence['unrepresentedClockPairs'] ? 1 : 0;
                    }

                    $repair['geographicEvidence'] = $geographicEvidence;

                    if (0 === $geography['candidates'] % 25) {
                        $progress(sprintf('Geography: %d candidates; read %d, cached %d, unavailable %d.', $geography['candidates'], $geography['read'], $geography['cached'], $geography['unavailable']));
                    }
                }

                ++$done;

                if (0 === $done % 25 || $done === $failed) {
                    $progress(sprintf('Diagnostics: %d/%d; read %d, cached %d, unavailable %d.', $done, $failed, $diagnostics['read'], $diagnostics['cached'], $diagnostics['unavailable']));
                }
            }

            $repairs[] = $repair;
        }

        arsort($groups, SORT_NUMERIC);
        $summary = $this->summary($state);
        $report = ['schemaVersion' => 1, 'mode' => 'activity-repair-corpus', 'corpusRoot' => $state['corpusRoot'], 'stopReason' => $reason, 'rounds' => $state['round'], 'validatorFingerprint' => $state['validatorFingerprint'], 'summary' => $summary, 'remainingGroups' => $groups, 'diagnosticSummary' => $diagnostics, 'summaryClockReconciliationEnabled' => $reconcileSummaryClocks, 'utcCompatibilityEnabled' => $useUtcForUnknownLocalTime];
        if ($collectGeography) {
            $report['geographicSummary'] = $geography;
            $progress(sprintf('Geography: %d candidates; read %d, cached %d, unavailable %d.', $geography['candidates'], $geography['read'], $geography['cached'], $geography['unavailable']));
        }

        $workspace->writeResultRows('corpus.repair.json', $report, $repairs);
        $workspace->writeResultRows('corpus.validation.json', ['schemaVersion' => 1, 'mode' => 'activity-validation', 'scope' => 'decode-crc-map-domain', 'corpusRoot' => $workspace->root.'/current', 'generatedAt' => gmdate(DATE_ATOM), 'phpVersion' => PHP_VERSION, 'profileSha256' => $state['profileSha256'], 'validatorFingerprint' => $state['validatorFingerprint'], 'fileCount' => count($results), 'summary' => $summary], $results);

        $report['results'] = $repairs;

        return $report;
    }
}
