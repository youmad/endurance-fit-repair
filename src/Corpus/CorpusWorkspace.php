<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Corpus;

use Youmad\Endurance\FitRepair\RepairFailed;

/** @internal Persistent files are published atomically; source files are never written. */
final readonly class CorpusWorkspace
{
    public string $root;

    public function __construct(string $root)
    {
        self::directory($root);
        $this->root = realpath($root) ?: throw new RepairFailed('Cannot resolve workspace.');
    }

    public static function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true)) {
            throw new RepairFailed('Cannot create directory: '.$path);
        }
    }

    /** @return array<string, mixed> */
    public static function readJson(string $path): array
    {
        $bytes = file_get_contents($path);

        if (false === $bytes) {
            throw new RepairFailed('Cannot read JSON: '.$path);
        }

        if (str_ends_with(strtolower($path), '.gz')) {
            $bytes = gzdecode($bytes);

            if (false === $bytes) {
                throw new RepairFailed('Cannot decompress report.');
            }
        }

        $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($value)) {
            throw new RepairFailed('Expected a JSON object: '.$path);
        }

        return $value;
    }

    /** @param array<string, mixed> $value */
    public function writeJson(string $name, array $value): void
    {
        $this->write($this->root.'/'.$name, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
    }

    /**
     * @param array<string, mixed>           $metadata
     * @param iterable<array<string, mixed>> $results
     */
    public function writeResultRows(string $name, array $metadata, iterable $results): void
    {
        if (array_key_exists('results', $metadata)) {
            throw new RepairFailed('Result rows must be supplied separately from report metadata.');
        }

        $chunks = (static function () use ($metadata, $results): \Generator {
            $header = [] === $metadata ? '{' : substr(json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0, -1).',';
            yield $header.'"results":[';
            $first = true;

            foreach ($results as $result) {
                yield ($first ? '' : ',')."\n".json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $first = false;
            }

            yield "\n]}\n";
        })();
        $this->writeChunks($this->root.'/'.$name, $chunks);
    }

    public function blob(string $bytes): string
    {
        $hash = hash('sha256', $bytes);
        $path = $this->root.'/versions/'.$hash.'.fit';
        self::directory(dirname($path));

        if (is_file($path)) {
            if (hash_file('sha256', $path) !== $hash) {
                throw new RepairFailed('Stored repaired version was modified.');
            }
        } else {
            $this->write($path, $bytes);
        }

        return $hash;
    }

    public function link(string $tree, string $path, string $file): void
    {
        $destination = $this->root.'/'.$tree.'/'.$path;
        self::directory(dirname($destination));

        if (is_link($destination)) {
            if (readlink($destination) === $file) {
                return;
            }

            if (!unlink($destination)) {
                throw new RepairFailed('Cannot replace working link.');
            }
        } elseif (file_exists($destination)) {
            throw new RepairFailed('Working export contains an unexpected file.');
        }

        if (!symlink($file, $destination)) {
            throw new RepairFailed('Cannot publish working link.');
        }
    }

    public function removeLink(string $tree, string $path): void
    {
        $destination = $this->root.'/'.$tree.'/'.$path;

        if (is_link($destination) && !unlink($destination)) {
            throw new RepairFailed('Cannot withdraw working link.');
        }

        if (file_exists($destination)) {
            throw new RepairFailed('Working export contains an unexpected file.');
        }
    }

    private function write(string $path, string $bytes): void
    {
        $this->writeChunks($path, [$bytes]);
    }

    /** @param iterable<string> $chunks */
    private function writeChunks(string $path, iterable $chunks): void
    {
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'xb');

        if (false === $stream) {
            throw new RepairFailed('Cannot create checkpoint.');
        }

        try {
            foreach ($chunks as $bytes) {
                $position = 0;

                while ($position < strlen($bytes)) {
                    $written = fwrite($stream, substr($bytes, $position));

                    if (false === $written || 0 === $written) {
                        throw new RepairFailed('Cannot write checkpoint.');
                    }

                    $position += $written;
                }
            }

            if (!fflush($stream) || !fsync($stream)) {
                throw new RepairFailed('Cannot flush checkpoint.');
            }
        } catch (\Throwable $failure) {
            fclose($stream);
            unlink($temporary);

            throw $failure;
        }

        fclose($stream);

        if (!rename($temporary, $path)) {
            unlink($temporary);

            throw new RepairFailed('Cannot publish checkpoint.');
        }
    }
}
