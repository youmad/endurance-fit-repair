<?php

declare(strict_types=1);

namespace Youmad\Endurance\FitRepair\Command;

use Youmad\Endurance\FitRepair\Corpus\CorpusRepairRunner;
use Youmad\Endurance\FitRepair\Corpus\CorpusTimeZonePlan;
use Youmad\Endurance\FitRepair\Corpus\CorpusWorkspace;
use Youmad\Endurance\FitRepair\Corpus\TrackerCorpusValidator;
use Youmad\Endurance\FitRepair\RepairFailed;

final readonly class CorpusRepairCommand
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
Usage: fit-repair-corpus --corpus=PATH --workspace=PATH --validator=PATH [OPTIONS]

Options:
  --corpus=PATH       Original Activity corpus directory; never written.
  --workspace=PATH    Persistent workspace outside the corpus. Reuse to resume.
  --validator=PATH    Tracker bin/validate-fit-corpus (PHP 8.5, ingest deps/profile).
  --baseline=PATH     Optional current corpus.validation.json or .json.gz.
                      Must come from the current validator; file hashes checked.
  --max-rounds=N      Changed groups per invocation (default 20).
  --collect-geography Collect read-only GPS/clock evidence for local-time failures.
  --reconcile-summary-clocks
                      Opt in to corroborated missing summary clocks or damaged starts.
                      Requires native counters or a complete recorded timer trace.
  --use-utc-for-unknown-local-time
                      Opt in to a UTC placeholder for unusable Activity local time.
                      Original timezone remains unknown; this is normalization.
  --local-time-zones=PATH
                      Opt in to local time inference using an offline zone plan.
  --help              Show this help.

One group per round; only changed files are revalidated. Repaired files still
FAILED are retained for the next round. Source files and earlier versions remain.
Current rules restore missing Session/Lap indexes with consistent lap references.
Local time inference requires --local-time-zones; no default timezone fallback.
UTC compatibility is separate and explicit; usable original local clocks remain.
Contradictory duration counters can be reconciled after relevant FAILED checks
using original Lap starts and a complete recorded timer partition. This default
rule retains original clocks, events, pauses and measurements; audit mode is
reconcile_durations, resolution is one second and historical accuracy is not proven.
No activity reconstruction.
Clock reconciliation trusts original Records and counters; historical accuracy
is not proven. Its audit mode is reconcile_clocks, not original-clock recovery.
Recorded pauses can restore a Lap start with agreeing boundaries and durations.
Corroborated Record spans can restore a shared deferred single-Lap start.
Recorded Lap ends and final timer stops can restore a one-second final Lap start.
Final Lap pauses require corroborating recorded active and paused timer spans.
Missing single-Lap durations can be copied from corroborated recorded summaries.
Explicit 1..N Lap indexes can be translated together with their Session references.

Outputs: current/ (latest corpus), ready/ (PASS only), corpus.validation.json,
corpus.repair.json, state.json and checks/*/validator.log.
Exit: 0 all PASS; 1 unresolved or round limit; 2 setup, validation tool or I/O error.
HELP."\n");

            return 0;
        }

        try {
            $options = [];
            $collectGeography = false;
            $reconcileSummaryClocks = false;
            $useUtcForUnknownLocalTime = false;

            foreach ($arguments as $argument) {
                if ('--collect-geography' === $argument && !$collectGeography) {
                    $collectGeography = true;
                    continue;
                }

                if ('--reconcile-summary-clocks' === $argument && !$reconcileSummaryClocks) {
                    $reconcileSummaryClocks = true;
                    continue;
                }

                if ('--use-utc-for-unknown-local-time' === $argument && !$useUtcForUnknownLocalTime) {
                    $useUtcForUnknownLocalTime = true;
                    continue;
                }

                if (!preg_match('/^--(corpus|workspace|validator|baseline|max-rounds|local-time-zones)=(.+)$/D', $argument, $match) || isset($options[$match[1]])) {
                    throw new RepairFailed('Unknown, malformed or duplicate option: '.$argument);
                }

                if (str_contains($match[2], "\0") || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $match[2])) {
                    throw new RepairFailed('Only local paths are supported.');
                }

                $options[$match[1]] = $match[2];
            }

            foreach (['corpus', 'workspace', 'validator'] as $required) {
                if (!isset($options[$required])) {
                    throw new RepairFailed('Missing --'.$required.'=PATH; use --help.');
                }
            }

            $limit = $options['max-rounds'] ?? '20';

            if (!preg_match('/^[1-9][0-9]{0,3}$/D', $limit)) {
                throw new RepairFailed('Round limit must be in 1..9999.');
            }

            $script = realpath($options['validator']);

            if (false === $script) {
                throw new RepairFailed('Validator script does not exist.');
            }

            $workspace = new CorpusWorkspace($options['workspace']);
            $report = (new CorpusRepairRunner())->run(
                $options['corpus'],
                $workspace,
                new TrackerCorpusValidator($script),
                static function (string $message) use ($stdout): void { fwrite($stdout, $message."\n"); },
                $options['baseline'] ?? null,
                maxRounds: (int) $limit,
                collectGeography: $collectGeography,
                localTimeZones: isset($options['local-time-zones']) ? CorpusTimeZonePlan::read($options['local-time-zones']) : null,
                reconcileSummaryClocks: $reconcileSummaryClocks,
                useUtcForUnknownLocalTime: $useUtcForUnknownLocalTime,
            );
            fwrite($stdout, 'Stopped: '.$report['stopReason'].'. Report: '.$workspace->root.'/corpus.repair.json'."\n");

            return 0 < $report['summary']['toolError'] ? 2 : (0 < $report['summary']['failed'] ? 1 : 0);
        } catch (\Throwable $failure) {
            fwrite($stderr, 'FIT corpus repair failed: '.$failure->getMessage()."\n");

            return 2;
        }
    }
}
