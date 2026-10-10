<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Corpus;

use Youmad\Endurance\FitRepair\RepairFailed;

/** Runs a PHP batch validator implementing the external validation protocol. */
final readonly class ExternalCorpusValidator implements CorpusValidator
{
    private string $identity;
    private ?string $profile;

    public function __construct(private string $script)
    {
        if (!is_file($script) || !is_readable($script)) {
            throw new RepairFailed('Validator script is not readable.');
        }
        $metadata = $this->describe();
        if (1 !== ($metadata['schemaVersion'] ?? null)
            || !is_string($metadata['fingerprint'] ?? null)
            || 1 !== preg_match('/^[a-f0-9]{64}$/D', $metadata['fingerprint'])
            || !array_key_exists('profileSha256', $metadata)
            || (null !== $metadata['profileSha256'] && (!is_string($metadata['profileSha256'])
                || 1 !== preg_match('/^[a-f0-9]{64}$/D', $metadata['profileSha256'])))) {
            throw new RepairFailed('Unexpected external validator description.');
        }
        $this->identity = $metadata['fingerprint'];
        $this->profile = $metadata['profileSha256'];
    }

    public function fingerprint(): string
    {
        return $this->identity;
    }

    public function profileSha256(): ?string
    {
        return $this->profile;
    }

    /** @return array<string, mixed> */
    private function describe(): array
    {
        $output = tmpfile();
        if (false === $output) {
            throw new RepairFailed('Cannot allocate validator description output.');
        }
        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'memory_limit=512M', $this->script, '--describe'],
                [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
                $pipes,
            );
            if (!is_resource($process)) {
                throw new RepairFailed('Cannot start external validator description.');
            }
            $exit = proc_close($process);
            if (0 !== $exit || !rewind($output)) {
                throw new RepairFailed('External validator --describe failed.');
            }
            $bytes = stream_get_contents($output, 65537);
            if (false === $bytes || strlen($bytes) > 65536) {
                throw new RepairFailed('Cannot read external validator description.');
            }
            $metadata = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($metadata)) {
                throw new RepairFailed('External validator description must be a JSON object.');
            }

            return $metadata;
        } catch (\JsonException $failure) {
            throw new RepairFailed('External validator --describe must emit JSON only.', previous: $failure);
        } finally {
            fclose($output);
        }
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

        if (1 !== ($report['schemaVersion'] ?? null) || !is_array($report['results'] ?? null)
            || !array_key_exists('profileSha256', $report)
            || $this->profileSha256() !== ($report['profileSha256'] ?? null)) {
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
