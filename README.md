# Endurance FIT Repair

Repair supported FIT Activity inconsistencies while retaining original
measurements, events, pauses and summary data wherever the evidence permits.

## Installation

Requires PHP `^8.5`. Install `ext-zlib` to read compressed reports or use the
bundled timezone database.

```bash
composer require youmad/endurance-fit-repair
```

## Repairing one file

```bash
vendor/bin/fit-repair \
    --input=activity.fit \
    --output=activity.repaired.fit
```

The default `preserve` mode attempts supported metadata and boundary repairs
without rebuilding the activity. Input is limited to 64 MiB; the output path
must not already exist. The source file is not overwritten.

Add `--report=activity.repair.json` to save a JSON audit report with applied
changes and unsupported rules. The report path must not already exist.

Memory usage depends on the decoded message count; large inputs may require a
higher PHP `memory_limit`.

Check the resulting FIT with an independent validator before using it.
Use `--help` for all command options.

## PHP API

```php
use Youmad\Endurance\FitRepair\ActivityRepairer;

$result = (new ActivityRepairer())->repair($bytes);
$repairedBytes = $result->bytes;
```

`repair()` accepts FIT bytes and returns a `RepairResult`. Input it cannot process
raises `RepairFailed`. Inspect `$result->report` for audit data or call
`$result->reportJson()` to serialize it as JSON, including source and output
SHA-256 hashes.

Pass `localTimeOffset: 3600` when the activity's offset from UTC is known; in
preserve mode it fills missing Activity local time.

With an external check, `repairValidated()` selects the broader preserving rule
set from your validator's failures and revalidates every changed version:

```php
$result = (new ActivityRepairer())->repairValidated(
    bytes: $bytes,
    validate: $validate,
);

if ('PASS' === $result->report['activity_validation']) {
    $validatedBytes = $result->bytes;
}
```

The callback receives FIT bytes and returns `['status' => 'PASS']` or
`['status' => 'FAILED', 'errors' => [['class' => $type, 'message' => $message]]]`.
Include the exception chain in outer-to-inner order. Failures must use the
Activity validation messages recognized by the rules; unrelated failures stay
unresolved. Throw operational errors from the callback so they propagate.
Passing input is returned unchanged. FAILED means that unresolved problems remain.

Optional policies: `reconcileSummaryClocks: true` enables corroborated native
clock and summary inference; `inferTimeZone: true` tries recorded clock context,
then the bundled geographic database; `useUtcForUnknownLocalTime: true` allows
a UTC placeholder as a last resort. The timezone policies apply only to an
invalid Activity local timestamp. Geographic inference requires `ext-zlib`.
The default limit is 20 changed versions (`maxRounds`); `onProgress` accepts a
callback for processing leases or cancellation. No corpus workspace or report
file is created by this API.

## Validated corpus repair

The corpus command provides a broader, iterative rule set. It attempts repairs
only after relevant validation failures and validates each changed version.
Supply an external PHP validator implementing the batch protocol below.
The validator owns its rules, dependencies and FIT Profile configuration.

```bash
vendor/bin/fit-repair-corpus \
    --corpus=/path/to/Activity \
    --workspace=/path/to/repair-workspace \
    --validator=/path/to/validate-fit-corpus
```

The workspace must be outside the original corpus. Reuse it to resume; originals
and earlier candidate versions remain available. `current/` contains the latest
versions, `ready/` contains only validated PASS files, and `corpus.repair.json`
records the outcome and remaining groups. Exit status is 0 for all PASS, 1 for
unresolved files or a round limit, and 2 for setup, validation-tool or I/O errors.
The default limit is 20 changed groups per invocation; `--max-rounds` changes it.

Duration reconciliation can use a corroborated native timer partition. These
additional policies require explicit selection:

| Option | Effect |
| --- | --- |
| `--reconcile-summary-clocks` | Enables supported clock and missing-summary inference from native evidence. |
| `--use-utc-for-unknown-local-time` | Uses a UTC placeholder for unusable local time after an applicable failure. It does not recover the original timezone. |
| `--local-time-zones=PATH` | Applies an offline timezone plan. |

Inferred boundaries, synthetic summaries and compatibility placeholders are
identified in the audit. `historical_accuracy: not_proven` means that a consistent
result does not establish the exact original clocks, pauses or segmentation.
PASS establishes acceptance by the configured validator.

### External batch validator

`--validator` names a PHP script with two invocations:

- `php validator.php --describe` prints one JSON object containing
  `schemaVersion: 1`, a SHA-256 `fingerprint` of its rules and configuration, and
  `profileSha256` (a SHA-256 hash or `null` when no Profile is used).
- `php validator.php INPUT_DIRECTORY NEW_OUTPUT_DIRECTORY` checks every FIT
  and writes `corpus.validation.json` under the output directory. The JSON has
  `schemaVersion: 1`, the same `profileSha256`, and a `results` array. Each result
  contains `path` relative to the input directory, `sha256`, and `status`
  (`PASS`, `FAILED` or `TOOL_ERROR`). For FAILED results, include an `errors`
  array of exception `class` and `message` pairs in outer-to-inner order.

Exit codes are 0 for all PASS, 1 for FAILED files, and 2 for tool errors.
Validation messages must match supported repair rules. Validation runs in a
separate process; the script supplies its own configuration.

## Offline timezone planning

Add `--collect-geography` to the corpus command to collect evidence, then build
a plan:

```bash
vendor/bin/fit-plan-local-time-zones \
    --report=/path/to/repair-workspace/corpus.repair.json \
    --output=/path/to/local-time-zones.json
```

Pass the plan back to the corpus command with `--local-time-zones=PATH`. The plan
is bound to the original corpus path, validator and candidate file hashes;
regenerate it when these inputs change. Ambiguous or unavailable geographic
evidence stays unresolved.

`--device-clock-context=PATH` optionally uses a corpus snapshot and corroborating
observations from the same device. Run `--help` for its conditions.

## Lossy reconstruction and limits

`--mode=reconstruct`, or `reconstruct: true` in the API, explicitly rebuilds one
Lap and Session from a usable Record prefix. Original segmentation, summary
details, events and pauses are lost; timer duration becomes elapsed duration.
Corpus repair never selects this mode.

Preserving repair requires a complete member with valid CRCs at byte zero.
Only supported chained heart-rate tails are retained; arbitrary concatenated
activities, trailing padding and compressed-summary edits remain unsupported.

## Development

From a source checkout:

```bash
composer install
composer check
```

## License

Code and documentation: [MPL-2.0](LICENSE).

The bundled geographic database is derived from Timezone Boundary Builder and
OpenStreetMap contributors under [ODbL-1.0](resources/geography/DATA_LICENSE).
Source attribution and dataset details are recorded in
[the database manifest](resources/geography/zones-2026d.json).
