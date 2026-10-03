# V2 verification record — 2026-10-03

Target: 2.0.0, unreleased. This record covers the explicit updater and preservation
of the 1.x offline lookup API. No live MaxMind credentials/downloads, consuming
application changes, release tag, push or publication were performed.

## RED / GREEN evidence

New behavior was introduced in small Pest increments, followed by focused
regression tests. All HTTP responses, credentials and archives were synthetic.

| Increment | Observed RED | GREEN / implementation |
| --- | --- | --- |
| Configuration | 12 tests failed before UpdateOptions existed | Validated defaults, credentials, private local targets and legacy config |
| Transport | 8 tests failed before transport implementation | Explicit per-hop auth, checked redirects, bounded streaming |
| Archive | 16 tests failed before archive implementation | Bounded gzip/TAR parsing and safe extraction |
| Candidate validation | 2 tests failed before validator implementation | Real SDK metadata and reachable-record traversal |
| Updater | 6 tests failed before orchestration existed | Lock, HEAD/state decisions, validated atomic installation |
| Command | 4 tests failed before command registration | Stable output and documented exit codes |
| Handler-level stream limit | Expected size failure became transport_failed | Bounded sink preserves sanitized error classification |
| Parent symlink followed by `..` | Target normalization selected the wrong directory | Resolve existing symlinks before dot segments |
| Invalid shared max_age_days | HTTP was attempted and exit was 1 | Shared config validation before network, exit 2 |
| HEAD Last-Modified / GET additional ETag | Second run failed instead of skipping | Compare common validators without assuming identical header sets |
| Exception arguments enabled | Full callback stack exposed synthetic secret | Rebuild transport exceptions at the public boundary; sensitive stream arguments |
| Existing manual GeoIP2-Country | Older GeoLite candidate installed instead of downgrade_rejected | Validate supported existing types separately from exact downloaded edition |

The final focused downgrade test passed with 2 assertions after the last fix.
The complete suite includes 137 existing tests and 95 V2 tests. It covers 401/403,
429 cooldown/retries, HEAD fallback and unsolicited 304, malformed and missing
lengths, interruption, archive attacks, corrupt SDK records, locks, rename/state/
notice failures and recovery, partial Country/ASN results, no-write check mode,
config cache, retained lookup instances, and absence of implicit HTTP/scheduling.

Exception tests explicitly enable argument capture, including long string
arguments. This tests package-owned error boundaries, not arbitrary custom
application middleware/logging or third-party diagnostic request dumpers.

## Tested compatibility matrix

Each row passed **232 tests / 505 assertions** with PHP networking entry points
disabled. No platform-requirement bypass was used.

| PHP | Laravel | Testbench | Pest | PHPUnit | Guzzle | PSR-7 |
| --- | --- | --- | --- | --- | --- | --- |
| 8.4.26 | 12.69.3 | 10.12.0 | 4.7.8 | 12.5.33 | 7.15.5 | 2.13.1 |
| 8.5.11 | 12.69.3 | 10.12.0 | 4.7.8 | 12.5.33 | 7.15.5 | 2.13.1 |
| 8.4.26 | 13.34.0 | 11.3.0 | 5.3.0 | 13.3.6 | 8.2.0 | 3.1.0 |
| 8.5.11 | 13.34.0 | 11.3.0 | 5.3.0 | 13.3.6 | 8.2.0 | 3.1.0 |

Both environments used geoip2/geoip2 3.4.0 and maxmind-db/reader 1.14.0.
Laravel 12 was resolved in an isolated /tmp copy with matching dependency
constraints; the working tree retained Laravel 13. PHP 8.3 is outside the existing
^8.4 package constraint. The optional native MaxMind extension was not tested.

Command, run with both PHP binaries in both environments:

```sh
php8.5 -d allow_url_fopen=0 \
  -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,gethostbyname,gethostbynamel,gethostbyaddr,dns_get_record \
  vendor/bin/pest --compact --colors=never
```

The runner used an environment allowing local stream descriptors, with the PHP
network restrictions above retained. Guzzle handler-level tests exercised request
construction and sinks without opening an internet connection. These restrictions
do not purport to disable every OS networking mechanism or arbitrary custom rules.

CI now selects the same PHP/Laravel and Guzzle/PSR-7 major combinations, performs
Composer validation and lint, and runs the offline suite. GitHub Actions itself
was not run during this work.

## Final checks

- `composer validate --strict`: valid.
- `composer check-platform-reqs`: all installed requirements satisfied.
- `composer licenses --format=json`: direct Guzzle/PSR dependencies MIT; MaxMind
  SDK/reader Apache-2.0. Dependency licenses remain separate from database terms.
- PHP lint across src/config/tests/examples: 56 files, no syntax errors.
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: level 6, no errors
  in configured src scope. PHPStan ran with its local worker socket permitted.
- `git diff --check`: clean.
- Composer ZIP distribution inspected for runtime code, documentation and licenses,
  and exclusion of tests, MMDB, credentials, state, staging, IDE and vendor files.

## Operational limits

Only binary tar.gz is supported. No account was used to verify a production
archive; transport and archive behavior are tested against documented interfaces
and synthetic fixtures. The account owner performs the first real update.
The updater walks SDK-reachable records, not unreachable padding, and cannot
prove geographic accuracy or authenticate a database with a local hash.

Atomic rename and flock were verified on local Linux, not Windows/NFS or a
multi-node deployment. Country/ASN are independent installs. No permanent backup,
account-wide rate limit, vendor checksum fetch or power-loss durability guarantee
is provided. Post-install metadata failure is explicit and recoverable on retry.
Ordinary lookups remain offline and reopen readers on each operation.

See [the updater guide](UPDATING.md) for configuration, credentials/config-cache
handling, permission requirements, exit codes, scheduling and recovery.
