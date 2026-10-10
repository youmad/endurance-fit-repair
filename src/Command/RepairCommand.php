<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Command;

use Youmad\Endurance\FitRepair\ActivityRepairer;
use Youmad\Endurance\FitRepair\RepairFailed;

final readonly class RepairCommand
{
    /**
     * @param list<string> $arguments
     * @param resource     $stdout
     * @param resource     $stderr
     */
    public function run(array $arguments, mixed $stdout, mixed $stderr): int
    {
        if (['--help'] === $arguments || ['-h'] === $arguments) {
            fwrite($stdout, <<<'HELP'
Usage: fit-repair --input=PATH --output=PATH [OPTIONS]

Options:
  --input=PATH                 Source FIT file (maximum 64 MiB).
  --output=PATH                New repaired FIT file; must not exist.
  --report=PATH                Optional JSON audit report; must not exist.
  --mode=preserve|reconstruct  Preserve messages (default), or explicitly rebuild.
  --local-time-offset=SECONDS  Explicit offset from UTC (-43200..50400).
                              Preserve mode fills missing Activity local time only.
  --help                      Show this help.

Preserve mode restores supported missing metadata without removing messages.
Reconstruct mode loses original laps, sessions, events and pauses.
Check the resulting FIT with an independent validator.
HELP."\n");

            return 0;
        }

        try {
            $options = [];

            foreach ($arguments as $argument) {
                if (!preg_match('/^--(input|output|report|local-time-offset|mode)=(.+)$/D', $argument, $match)) {
                    throw new RepairFailed('Unknown or malformed option: '.$argument);
                }

                if (isset($options[$match[1]])) {
                    throw new RepairFailed('Duplicate option: '.$match[1]);
                }

                $options[$match[1]] = $match[2];
            }

            foreach (['input', 'output'] as $required) {
                if (!isset($options[$required])) {
                    throw new RepairFailed('Missing --'.$required.'=PATH; use --help.');
                }
            }

            $mode = $options['mode'] ?? 'preserve';

            if (!in_array($mode, ['preserve', 'reconstruct'], true)) {
                throw new RepairFailed('Mode must be preserve or reconstruct.');
            }

            foreach (['input', 'output', 'report'] as $name) {
                if (!isset($options[$name])) {
                    continue;
                }

                if (str_contains($options[$name], "\0") || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $options[$name])) {
                    throw new RepairFailed('Only local file paths are supported: '.$name);
                }
            }

            $offset = null;

            if (isset($options['local-time-offset'])) {
                $value = $options['local-time-offset'];

                if (!preg_match('/^-?[0-9]{1,5}$/D', $value)) {
                    throw new RepairFailed('Local time offset must be an integer in seconds.');
                }

                $offset = (int) $value;
            }

            $input = @fopen($options['input'], 'rb');

            if (false === $input) {
                throw new RepairFailed('Cannot open input file.');
            }

            try {
                $bytes = stream_get_contents($input, 67_108_865);

                if (false === $bytes || 67_108_864 < strlen($bytes)) {
                    throw new RepairFailed('Cannot read input, or input exceeds 64 MiB.');
                }
            } finally {
                fclose($input);
            }

            $result = (new ActivityRepairer())->repair($bytes, $offset, 'reconstruct' === $mode);
            $outputs = [[$options['output'], $result->bytes]];

            if (isset($options['report'])) {
                $outputs[] = [$options['report'], $result->reportJson()];
            }

            $this->publish($outputs);
            fwrite($stdout, (isset($options['report']) ? 'FIT and JSON report written' : 'FIT written').'; activity validation is still required.'."\n");

            return 0;
        } catch (\Throwable $exception) {
            fwrite($stderr, 'FIT repair failed: '.$exception->getMessage()."\n");

            return 1;
        }
    }

    /** @param list<array{string, string}> $outputs */
    private function publish(array $outputs): void
    {
        $created = [];

        try {
            foreach ($outputs as [$path, $content]) {
                $stream = @fopen($path, 'x+b');

                if (false === $stream) {
                    throw new RepairFailed('Cannot exclusively create output: '.$path);
                }

                $created[] = $path;

                try {
                    $position = 0;
                    $length = strlen($content);

                    while ($position < $length) {
                        $written = fwrite($stream, substr($content, $position));

                        if (false === $written || 0 === $written) {
                            throw new RepairFailed('Cannot write output: '.$path);
                        }

                        $position += $written;
                    }

                    if (!fflush($stream)) {
                        throw new RepairFailed('Cannot flush output: '.$path);
                    }
                } finally {
                    fclose($stream);
                }
            }
        } catch (\Throwable $exception) {
            $remaining = [];

            foreach ($created as $path) {
                if (!@unlink($path)) {
                    $remaining[] = $path;
                }
            }

            if ([] !== $remaining) {
                throw new RepairFailed($exception->getMessage().'; failed to remove incomplete outputs: '.implode(', ', $remaining), 0, $exception);
            }

            throw $exception;
        }
    }
}
