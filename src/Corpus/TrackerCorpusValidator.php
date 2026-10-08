<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Corpus;

use Youmad\Endurance\Fit\Profile\Generated\GeneratedFitProfileSet;
use Youmad\Endurance\FitRepair\RepairFailed;

/** Runs the standalone Tracker validator once for a batch, without its kernel. */
final readonly class TrackerCorpusValidator implements CorpusValidator
{
    public function __construct(private string $script)
    {
        if (!is_file($script) || !is_readable($script)) {
            throw new RepairFailed('Validator script is not readable.');
        }
    }

    public function fingerprint(): string
    {
        $root = dirname($this->script, 2);
        $profile = getenv('TRACKER_FIT_PROFILE_DIR') ?: $root.'/var/fit-profile';
        $paths = [
            $this->script,
            $root.'/ingest/src/Import/Infrastructure/Validation',
            $root.'/ingest/src/Import/UI/Console/ValidateFitCorpusCommand.php',
            $profile.'/types.php',
            $profile.'/messages.php',
        ];

        // Only runtime dependencies of the validator belong in this key.
        // Updating the repair package must not invalidate the full corpus.
        $pending = ['youmad/endurance-foundation', 'youmad/endurance-activity', 'youmad/endurance-activity-fit', 'youmad/endurance-fit'];
        $visited = [];

        while ([] !== $pending) {
            $name = array_pop($pending);

            if (isset($visited[$name])) {
                continue;
            }

            $visited[$name] = true;
            $package = $root.'/ingest/vendor/'.$name;
            $manifest = CorpusWorkspace::readJson($package.'/composer.json');

            foreach ($manifest['autoload']['psr-4'] ?? [] as $directories) {
                foreach ((array) $directories as $directory) {
                    $paths[] = $package.'/'.rtrim($directory, '/');
                }
            }

            foreach ($manifest['autoload']['files'] ?? [] as $file) {
                $paths[] = $package.'/'.$file;
            }

            foreach (array_keys($manifest['require'] ?? []) as $dependency) {
                if (str_contains($dependency, '/')) {
                    $pending[] = $dependency;
                }
            }
        }

        $files = [];

        foreach ($paths as $path) {
            $resolved = realpath($path);

            if (false === $resolved) {
                throw new RepairFailed('Cannot fingerprint validator input: '.$path);
            }

            if (is_dir($resolved)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile() && 'php' === $file->getExtension()) {
                        $files[$file->getPathname()] = $file->getPathname();
                    }
                }
            } else {
                $files[$resolved] = $resolved;
            }
        }

        ksort($files, SORT_STRING);
        $hash = hash_init('sha256');
        hash_update($hash, PHP_VERSION."\0");

        foreach ($files as $file) {
            $digest = hash_file('sha256', $file);

            if (false === $digest) {
                throw new RepairFailed('Cannot fingerprint validator file.');
            }

            hash_update($hash, $file."\0".$digest."\0");
        }

        return hash_final($hash);
    }

    public function profileSha256(): string
    {
        $root = dirname($this->script, 2);
        $profile = getenv('TRACKER_FIT_PROFILE_DIR') ?: $root.'/var/fit-profile';

        return GeneratedFitProfileSet::load(messagesFile: $profile.'/messages.php', typesFile: $profile.'/types.php')->sourceSha256();
    }

    public function validate(array $files, string $destination): array
    {
        CorpusWorkspace::directory($destination.'/input');
        $paths = [];
        $index = 0;

        foreach ($files as $path => $file) {
            $name = sprintf('%06d.fit', ++$index);
            $paths[$name] = $path;

            if (!symlink($file, $destination.'/input/'.$name)) {
                throw new RepairFailed('Cannot stage validator input.');
            }
        }

        $log = fopen($destination.'/validator.log', 'xb');

        if (false === $log) {
            throw new RepairFailed('Cannot create validator log.');
        }

        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'memory_limit=512M', $this->script, $destination.'/input', $destination.'/validation'],
                [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log],
                $pipes,
            );

            if (!is_resource($process)) {
                throw new RepairFailed('Cannot start corpus validator.');
            }

            $exit = proc_close($process);
        } finally {
            fclose($log);
        }

        if (!in_array($exit, [0, 1, 2], true)) {
            throw new RepairFailed('Validator process failed; inspect '.$destination.'/validator.log.');
        }

        $report = CorpusWorkspace::readJson($destination.'/validation/corpus.validation.json');

        if (1 !== ($report['schemaVersion'] ?? null) || 'activity-validation' !== ($report['mode'] ?? null)
            || 'decode-crc-map-domain' !== ($report['scope'] ?? null) || !is_array($report['results'] ?? null)
            || PHP_VERSION !== ($report['phpVersion'] ?? null) || $this->profileSha256() !== ($report['profileSha256'] ?? null)) {
            throw new RepairFailed('Unexpected validator report format.');
        }

        $results = [];

        foreach ($report['results'] as $result) {
            if (!is_array($result) || !is_string($result['path'] ?? null) || !isset($paths[$result['path']])) {
                throw new RepairFailed('Validator returned an unexpected file.');
            }

            $path = $paths[$result['path']];

            if (isset($results[$path]) || !in_array($result['status'] ?? null, ['PASS', 'FAILED', 'TOOL_ERROR'], true)
                || ($result['sha256'] ?? null) !== hash_file('sha256', $files[$path])) {
                throw new RepairFailed('Validator result is duplicated, invalid or belongs to different bytes.');
            }

            $result['path'] = $path;
            $results[$path] = $result;
        }

        if (count($files) !== count($results)) {
            throw new RepairFailed('Validator did not report every staged file.');
        }

        return $results;
    }
}
