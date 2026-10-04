# Changelog

Notable changes to Laravel IP Analyzer are recorded here.
Versions follow Semantic Versioning. Publication is identified by the corresponding
Git tag; these notes do not by themselves indicate that a release was published.

## [2.2.1] - 2026-10-04

### Changed

- Interactive update progress now keeps one stable Country/ASN row and redraws
  the compact block at most once per second, while phase changes, completion,
  failures and cancellation remain immediate.
- Redirected, CI, unsupported and `--no-ansi` output now emits plain periodic
  progress at most once every 15 seconds per database, without cursor controls.
- Human elapsed times use whole seconds and ETA uses compact approximate seconds
  or minutes after the existing smoothing warm-up. Unknown totals omit percentage
  and ETA rather than implying measurable completion.
- MMDB validation work is explicitly labelled as processed CIDR ranges. The
  incorrect trie-node-derived denominator introduced in 2.1.2 is removed: real
  databases can yield more CIDR iterations than `nodeCount + 1`. Validation is
  therefore indeterminate and shows no percentage or ETA, avoiding a second scan.

## [2.2.0] - 2026-10-04

### Added

- Opt-in Country/ASN local validation in separate Symfony Process workers for
  the update check, with a CLI worker override, cached-config-safe environment
  settings and an explicit per-worker timeout.
- Bounded NDJSON worker IPC with incremental progress, deterministic results,
  protocol validation, timeout/crash handling, startup fallback and cooperative
  child-process cleanup on interruption.
- Offline real-subprocess and controlled process-runner regressions covering
  concurrency, ordering, IPC chunking, failures, locks and output routing.

### Changed

- Local and candidate validation share a task/scheduler abstraction. One worker,
  one available task and normal single-candidate update validation remain direct
  in-process execution.
- The main process retains database locks and all HEAD/download/extract/install/state
  responsibilities. Parallel workers receive only explicit local validation data;
  credentials and application container state are not serialized.
- Declare Symfony Process and Illuminate Config as direct MIT dependencies. No new
  PHP extension is required.

## [2.1.2] - 2026-10-04

### Changed

- Local and candidate MMDB validation now reports its exact CIDR-range total from
  the binary search-tree leaf count, enabling percentage and smoothed phase ETA
  without a second validation pass. This assumption was corrected in 2.2.1 after
  real databases demonstrated that SDK traversal iterations can exceed that value.
- Human elapsed, phase-ETA, wait/timeout and total-duration values over 60 seconds
  are rendered as minutes plus seconds in English and Hungarian. Machine-readable
  JSON and numeric progress snapshots remain unchanged.

## [2.1.1] - 2026-10-04

### Added

- Complete English/Hungarian package translations for human progress, summaries,
  status labels, sanitized errors/warnings and command descriptions.
- Per-key current-locale resolution with explicit English fallback, independent
  of application fallback settings, including runtime locale changes and plurals.
- Optional `ip-analyzer-translations` publication and standard Laravel partial
  application overrides, also respected for English fallback.
- Offline localization, publication, config-cache and output-compatibility tests.

### Changed

- Human presentation reads language catalogs; internal progress phase identifiers
  contain machine keys rather than translated labels.
- Explicitly declare the existing Laravel translation component as a direct
  dependency. No installed dependency version or required PHP extension changed.
- JSON keys, codes, types, numeric values and existing message contents remain
  locale-independent. Custom rule messages, validation, updates and ETA unchanged.

## [2.1.0] - 2026-10-04

### Added

- Default human update progress, including early configuration/validation notices,
  separate Country/ASN phases, real CIDR-range counts and streamed SHA-256 bytes.
- Byte-based download progress with percentages only for known totals; monotonic,
  smoothed phase ETA after a warmup, and explicit unknown ETA for record traversal.
- Typed progress observers independent of Console, with a no-op default for services.
- `--progress` / `--no-progress`, STDERR-only progress for `--json --progress`, quiet
  precedence, channel-specific TTY handling and a final human total duration.
- Optional cooperative SIGINT cancellation with exit 130, resource cleanup, prior
  signal-handler restoration and deferred interruption during atomic installation.
- Offline progress, timing, output-routing and cancellation regression tests.

### Changed

- Human update output includes phase messages; use `--json` for machine parsing.
  The final JSON schema, validation depth, update decisions and security checks
  remain unchanged. No new required PHP extension or Composer dependency.

## [2.0.0] - 2026-10-03

### Added

- Explicit `ip-data:update` for initial GeoLite2-Country/ASN acquisition and updates,
  with database selection, force/check modes, stable JSON and partial-failure reporting.
- HTTPS Basic Auth scoped to the MaxMind download origin, validated manual redirects
  to approved R2 hosts, bounded cURL streaming and sanitized transport failures.
- HEAD-based freshness checks using successful-install validators, file identity and
  content hashes; bounded transient retries and persistent Retry-After cooldown.
- Streaming gzip/TAR validation with entry/size limits, safe PAX/GNU path handling,
  notice preservation and rejection of traversal, links, special files and ambiguous MMDBs.
- SDK-based candidate metadata and reachable-network record validation, build-epoch
  downgrade prevention and no-op handling for identical files.
- Per-target filesystem locks, same-filesystem private staging and atomic database/state
  replacement with explicit recovery after post-install state or notice failures.
- Backward-compatible defaults for published 1.x configs and an application-owned,
  opt-in six-hour scheduler example.
- Offline transport/archive/installation/failure tests and a V2 operating guide.

### Changed

- Direct runtime dependencies now declare Guzzle, PSR-7/HTTP Message and the low-level
  MaxMind reader. PHP cURL and zlib extensions are required for the built-in downloader.
- `ip-data:update` is the only package command that performs outbound network requests.
  Existing lookup/status/about contracts, manual data use and rule behavior remain intact.

### Limits

- No tag or publication is performed by this implementation. Tests use synthetic
  credentials and local fixtures; the account owner performs the first real download.
- Only binary tar.gz downloads are supported; ZIP/CSV and bare mmdb.gz are rejected.
- Atomic replacement was tested on local Linux filesystems; distributed storage
  locking, power-loss recovery and Windows replacement are not certified.

## [1.0.0] - 2026-10-03

Initial release.

### Added

- Offline IPv4/IPv6 Country and ASN lookup using local MaxMind MMDB files.
- Canonical IP normalization, IPv4-mapped IPv6 handling and an explicit
  special-purpose address exclusion policy.
- Independent source states: Found, NotFound, Unavailable, InvalidInput and
  NonPublic, with stable error codes and nullable unknown values.
- Readonly result DTOs independent of MaxMind SDK classes.
- Exact database-type validation and build metadata, including age, stale and
  future-build indicators with a testable clock.
- Readers scoped to each source operation and closed in finally, so subsequent
  lookups observe manual atomic file replacement.
- Configurable IP/CIDR, ASN and country observation rules with validated entries,
  unique identifiers, defined severities and deterministic multi-match evaluation.
- Custom Rule classes resolved by the Laravel container with constructor injection.
- Laravel autodiscovery, publishable/cacheable configuration and an Artisan about
  section using Composer version metadata.
- Read-only `ip-data:lookup` and `ip-data:status` commands with human-readable and
  JSON output and documented exit codes.
- Pest tests with the Laravel plugin, original synthetic MMDB fixtures and a
  PHP 8.4/8.5 × Laravel 12/13 GitHub Actions matrix.
- English documentation, Hungarian quickstart, manual database replacement guidance
  and separate notices for dependency and database licenses.

### Requirements

- PHP `^8.4`; tested on PHP 8.4 and 8.5.
- Laravel 12 or 13.
- `geoip2/geoip2 ^3.4`.
- Manually acquired Country and ASN databases for public-address lookups.
  Production databases and credentials are not included.

### Scope

- Observation results are not bot evidence, an allowlist, a blocking decision or
  a global risk score. An empty match list does not certify safety.
- No runtime network calls, automatic database updates, persistent reader/cache,
  application blacklist or telemetry.
- Database status validates metadata, not every record. Country and ASN file
  replacement is not a transaction across both files.
- MIT covers the package's own code and original synthetic fixtures; downloaded
  databases and Composer dependencies retain their separate licenses.
