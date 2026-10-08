<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Command;

use Youmad\Endurance\FitRepair\Corpus\CorpusWorkspace;
use Youmad\Endurance\FitRepair\Geography\CertifiedTimeZoneGrid;
use Youmad\Endurance\FitRepair\Geography\GeographicZonePlanner;
use Youmad\Endurance\FitRepair\Geography\RecordedDeviceClockPlanner;
use Youmad\Endurance\FitRepair\RepairFailed;

/** @internal Offline planning; optional device context reads FIT files without editing them. */
final readonly class GeographicZonePlanCommand
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
Usage: fit-plan-local-time-zones --report=PATH --output=PATH [--database=PATH] [--device-clock-context=PATH]

  --report=PATH    Corpus repair report collected with --collect-geography.
                   Plain JSON and .json.gz are supported.
  --output=PATH    New plan JSON; existing files are never overwritten.
  --database=PATH  Optional certificate database manifest. Default: bundled 2026d.
  --device-clock-context=PATH
                  Opt in to inference from other files of the same device.
                  PATH is a corpus snapshot, checked against original/current report hashes.
                  Uses original clocks or freshly certified coordinates; inferred
                  local clocks are never reused as original observations.
                  Clock observations must bracket the activity; all compatible
                  IANA zones must agree on its offset. Assumes a stable device zone.

Fully offline PHP planner. Complete 0.01-degree cells must be certified inside
one non-Etc zone; summary positions require certified cells too. Ambiguous
cells, missing coordinates, virtual activities and truncated evidence are skipped.
Pass the resulting plan to fit-repair-corpus --local-time-zones=PATH.
Exit: 0 plan created (including skipped candidates); 2 invalid input or I/O error.
HELP."\n");

            return 0;
        }

        $temporary = null;

        try {
            $options = [];

            foreach ($arguments as $argument) {
                if (!preg_match('/^--(report|output|database|device-clock-context)=(.+)$/D', $argument, $match) || isset($options[$match[1]])) {
                    throw new RepairFailed('Unknown, malformed or duplicate option: '.$argument);
                }

                if (str_contains($match[2], "\0") || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $match[2])) {
                    throw new RepairFailed('Only local paths are supported.');
                }

                $options[$match[1]] = $match[2];
            }

            if (!isset($options['report'], $options['output'])) {
                throw new RepairFailed('Missing --report=PATH or --output=PATH; use --help.');
            }

            if (file_exists($options['output']) || is_link($options['output'])) {
                throw new RepairFailed('Output already exists; use a new plan filename.');
            }

            $reportHash = @hash_file('sha256', $options['report']);

            if (false === $reportHash) {
                throw new RepairFailed('Cannot hash the geographic report.');
            }

            $report = CorpusWorkspace::readJson($options['report']);

            if (@hash_file('sha256', $options['report']) !== $reportHash) {
                throw new RepairFailed('Geographic report changed while reading.');
            }

            fwrite($stderr, "Loading offline geographic certificates...\n");
            $grid = CertifiedTimeZoneGrid::open($options['database'] ?? null);
            $geography = new GeographicZonePlanner($grid);
            $plan = $geography->plan($report, $reportHash);
            if (isset($options['device-clock-context'])) {
                $context = (new RecordedDeviceClockPlanner())->plan($report, $options['device-clock-context'], $geography, static function (string $message) use ($stderr): void { fwrite($stderr, $message."\n"); });
                $resolvedPaths = array_fill_keys(array_column($plan['results'], 'path'), true);
                $added = 0;
                foreach ($context['results'] as $entry) {
                    if (!isset($resolvedPaths[$entry['path']])) {
                        $plan['results'][] = $entry;
                        $resolvedPaths[$entry['path']] = true;
                        ++$added;
                    }
                }
                $plan['skipped'] = array_values(array_filter($plan['skipped'], static fn (array $entry): bool => !isset($resolvedPaths[$entry['path']])));
                $reasons = [];
                foreach ($plan['skipped'] as $entry) {
                    $reasons[$entry['reason']] = ($reasons[$entry['reason']] ?? 0) + 1;
                }
                $plan['summary'] = ['resolved' => count($plan['results']), 'skipped' => count($plan['skipped']), 'skipReasons' => $reasons, 'deviceClockContextResolved' => $added];
                $plan['resolver']['deviceClockContext'] = array_diff_key($context, ['results' => true]);
            }
            $bytes = json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
            $directory = realpath(dirname($options['output']));

            if (false === $directory || !is_dir($directory)) {
                throw new RepairFailed('Output directory does not exist.');
            }

            $temporary = tempnam($directory, '.fit-zone-plan-');

            if (false === $temporary || dirname($temporary) !== $directory || file_put_contents($temporary, $bytes) !== strlen($bytes)) {
                throw new RepairFailed('Cannot write the geographic zone plan.');
            }

            // Publish a complete file without replacing an existing concurrent plan.
            if (!@link($temporary, $options['output'])) {
                throw new RepairFailed('Cannot publish plan; output may already exist.');
            }

            fwrite($stdout, json_encode($plan['summary'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");

            return 0;
        } catch (\Throwable $failure) {
            fwrite($stderr, 'Geographic timezone planning failed: '.$failure->getMessage()."\n");

            return 2;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
