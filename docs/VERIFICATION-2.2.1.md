# 2.2.1 compact progress verification — 2026-10-04

Scope: `.idea/Refactor_05.md`. This patch changes only human progress collection,
rendering, localization and documentation. Validation algorithms, worker scheduling,
IPC, download, locking, installation and machine-readable JSON contracts are unchanged.
No publication, release tag or deployment was performed.

## RED / GREEN

- The initial compact-renderer suite failed because `UpdateProgress` had no injected
  render clock, per-database state, interactive block or 15-second plain-output cadence.
- `ProgressViewState` now retains the latest Country/ASN snapshot in deterministic
  order. The parent-owned `UpdateProgress` renderer decides independently when that
  state is written; workers still emit structured snapshots only.
- A fake monotonic clock covers rapid events, one-second interactive refreshes,
  immediate phase/terminal transitions, unchanged output, retained completed rows
  and independent 15-second plain-output throttling.
- Rendered-output coverage includes narrow terminal bounds, ANSI-free redirected
  and non-decorated streams, selected-database initial rows, English/Hungarian and
  explicit English fallback, clean JSON stdout, stderr progress, quiet/no-progress,
  success, failure and cancellation.

Focused progress and localization suites: **58 tests / 484 assertions**.

## Progress-unit audit

`CandidateValidator` walks disjoint CIDR ranges using `getWithPrefixLen()`. Each
loop iteration increments the reported numerator once. Production evidence showed
millions of iterations against a much smaller `nodeCount + 1`, proving that the
metadata-derived value is not a matching denominator for this SDK traversal. The
small synthetic fixture had accidentally hidden that mismatch.

Validation now reports the measured CIDR-range count with `total = null`. It does
not show a percentage or ETA, clamp the display, or perform a second MMDB traversal.
This restores the original 2.1 invariant that trie node metadata is not used as a
CIDR traversal total.

Known byte totals continue to support percentages and ETA. Unknown totals show the
measured processed count but omit percentage and ETA. Existing monotonic EWMA speed,
one-second/two-sample warm-up and stall handling remain separate from display
rounding and rendering cadence.

## Rendering behavior

- Interactive mode requires the actual progress output to be a decorated TTY and
  is disabled in CI. The renderer overwrites only its own fixed-height block; it
  never clears the whole terminal.
- The whole block refreshes at most once per second for ordinary events. Phase
  changes, completion, failure and cancellation render immediately. Identical
  content is not redrawn, and rows are bounded to the detected terminal width.
- Redirected, unsupported, CI and `--no-ansi` streams receive plain lines without
  cursor control, at most once every 15 seconds per database between immediate
  initial, phase-transition and terminal events.
- Elapsed time and summaries use whole seconds. ETA is approximate rounded seconds
  below one minute and approximate rounded minutes thereafter. Per-database ETA is
  retained; parallel ETAs are not summed.
- The command finalizes the progress block before writing its result, preventing a
  later redraw from overwriting result lines. JSON stdout and stable machine fields
  remain unchanged.

## Actual verification

The current checkout used PHP 8.5.11, Laravel 13.34.0, Pest 5.3.0 and Symfony
Console 8.1.8. The PHP 8.4/8.5 and Laravel 12/13 compatibility matrix was not
rerun for this patch.

- Full offline suite: **323 tests / 1110 assertions**.
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: no errors.
- `composer validate --strict --no-check-publish`: valid.
- PHP lint across `src`, `config`, `tests`, `examples` and `lang`: no syntax errors.
- `git diff --check`: clean.
- Manual pseudo-TTY smoke: ANSI in-place rendering detected with Country and ASN
  rows; the same renderer redirected to a file contained no ANSI sequences.

Tests and the smoke check used synthetic snapshots and local MMDB fixtures. No real
MaxMind request, production database, credential, publication or tag was used.
