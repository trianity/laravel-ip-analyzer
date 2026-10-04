# 2.2 parallel validation verification — 2026-10-04

Scope: `.idea/Refactor_04.md`. Country and ASN local MMDB validation can run
as independent PHP subprocesses during a multi-database check. Download, HEAD,
archive extraction, installation and state writes remain in the main process and
are not parallelized. No publication or release tag was performed.

## RED / GREEN

- Nine initial tests failed on the missing validation options, CLI override,
  execution contract, protocol decoder and worker entry point.
- Configuration and CLI regressions cover default 1, cached/configured values,
  explicit CLI precedence, positive-integer validation, one-database capping and
  the direct no-subprocess path.
- A real offline subprocess test validates both synthetic MMDB files through the
  packaged worker and NDJSON protocol. A separate comparison proves identical
  build epoch and SHA-256 results for sequential and parallel execution.
- Controlled process handles prove that both workers start before either finishes
  without timing assertions. They also cover partial/combined chunks, malformed
  and oversized lines, unknown IDs, duplicate/missing results, non-zero exits,
  timeout mapping, pending-task suppression, peer termination and interruption.
- Command regressions preserve requested JSON result order, localized per-database
  progress, clean JSON stdout, progress-only stderr, startup fallback, quiet/no
  progress behavior and read-only check semantics.
- A file replacement between worker validation and result use is rejected. Tests
  reacquire both main-process locks after failure to prove cleanup.

Focused parallel validation suite: **31 tests / 108 assertions**.

## Architecture and safety checks

- `ValidationTask`/`ValidationOutcome` are immutable typed boundaries.
  Sequential and parallel executors share one contract and both invoke the existing
  `CandidateValidator`; the validation algorithm is not duplicated.
- Effective concurrency is the smaller of requested workers and available tasks.
  One worker or one task executes directly. There is no CPU-count detection.
- Symfony Process receives an argument array, an absolute PHP executable, the
  package-owned worker entry point, a Composer autoloader path and a bounded
  base64url JSON payload. No shell command is constructed.
- Worker payloads contain task/database IDs, local path, validation mode, record
  limit, age setting and a fixed timestamp. Credentials and container objects are
  absent. The subprocess environment is cleared except for a small OS execution
  allowlist, so application secrets are not inherited.
- The worker can only validate and hash a local file. It emits structured progress,
  one result or a sanitized error. The main process performs all translation and
  human rendering.
- NDJSON is parsed incrementally with a 64 KiB line bound. Stderr retention is
  capped at 4 KiB and never copied to user output. A successful result requires one
  valid final message and a successful process exit.
- Main-process target locks span validation and result use when lock files exist.
  A stat identity check rejects atomic replacement in the remaining read-only race
  where check mode intentionally does not create lock/state files.
- Workers have an explicit configurable timeout. The supervision loop drains
  output and sleeps between polls. Startup capability failure falls back before
  validation; started-worker failures are not retried sequentially.
- Main-process cancellation terminates active children and retains exit 130 where
  optional PCNTL handling is available. Portable signals and SIGKILL cleanup are
  not claimed.

## Actual verification

The current checkout used PHP 8.5.11, Laravel 13.34.0, Pest 5.3.0 and Symfony
Process 8.1.7. Composer constraints remain PHP `^8.4`, Laravel
`^12.0|^13.0` and Symfony Process `^7.2|^8.0`. The Laravel 12 /
PHP 8.4 compatibility combinations were not rerun for this patch.

- Full offline suite: **313 tests / 1032 assertions**.
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: no errors;
  the final run required local TCP worker permission outside the restrictive sandbox.
- `composer validate --strict --no-check-publish`: valid.
- PHP lint across `src`, `config`, `tests`, `examples`
  and `lang`: no syntax errors.
- `git diff --check`: clean.

Tests used synthetic local MMDB fixtures, fake process handles and mocked HTTP
transports. No real MaxMind request, production database, credential, queue,
Redis, Horizon, Octane, release publication or tag was used.
