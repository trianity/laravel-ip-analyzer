# Laravel IP Analyzer

Documentation for **2.2.0** · [Changelog](CHANGELOG.md) · [Magyar quickstart](docs/QUICKSTART-HU.md)

Local Country and ASN facts with configurable observation rules for Laravel 12–13.
PHP 8.4–8.5 is the tested range. The package reads manually installed MaxMind MMDB
files; lookups and read-only diagnostics perform no HTTP, DNS, downloads or telemetry.
The explicit `ip-data:update` command can acquire and update these files over HTTPS.

The package does not make signup decisions, block requests, assign a global risk
score or ship an application blacklist. Country/ASN data is not bot evidence; an
empty match list does not certify safety.

## Installation and use

Requirements: PHP 8.4 or 8.5, Laravel 12 or 13, and the PHP cURL and zlib extensions.
Install the published 2.1 release:

```sh
composer require trianity/laravel-ip-analyzer:^2.1
php artisan vendor:publish --tag=ip-analyzer-config
```

Releases are available on [GitHub](https://github.com/trianity/laravel-ip-analyzer/releases)
and [Packagist](https://packagist.org/packages/trianity/laravel-ip-analyzer).
For a local source checkout, use the
[local development installation](#local-development-installation).

The provider is autodiscovered. Configure local files outside the public web root:

```dotenv
IP_ANALYZER_COUNTRY_DB=/srv/private/ip-data/GeoLite2-Country.mmdb
IP_ANALYZER_ASN_DB=/srv/private/ip-data/GeoLite2-ASN.mmdb
```

The defaults are under `storage/app/ip-analyzer/`. Only the configuration file
reads environment variables. After changing paths, rebuild your application's
configuration cache and reload long-lived workers to pick up configuration changes.
Replacing the file at an unchanged path does not require restarting a reader.

```php
use Trianity\IpAnalyzer\Contracts\IpAnalyzer;
use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Enums\LookupStatus;

$result = app(IpAnalyzer::class)->analyze(request()->ip());
if ($result->facts->countryStatus === LookupStatus::Found) {
    $country = $result->facts->countryCode;
}
$observations = $result->matches; // list<RuleMatch>; no automatic decision
$factsOnly = app(IpLookup::class)->lookup('8.8.8.8');
```

The application owns trusted-proxy configuration and source-IP selection. Do not
pass a raw forwarded-header list. DTOs are readonly and expose no MaxMind objects.

## Input policy and source states

Input must be an exact IPv4 or IPv6 address: no hostname, URL, port, whitespace,
zone ID or address list. Accepted input is canonicalized with `inet_pton/inet_ntop`.
IPv4-mapped IPv6 is converted to IPv4 before classification and rule matching.

`NonPublic` is a conservative **special-purpose exclusion policy**, not a claim
that every excluded address is unroutable. No database is opened for InvalidInput
or NonPublic. Configuration is still validated. See the exact ranges in
[IP policy](docs/IP-POLICY.md), based on the
[IANA IPv4 registry](https://www.iana.org/assignments/iana-ipv4-special-registry/)
and [IPv6 registry](https://www.iana.org/assignments/iana-ipv6-special-registry/).

Each source is independent:

| Status | Meaning |
| --- | --- |
| `found` | Requested data exists; only then is its value populated |
| `not_found` | Address or requested field is absent |
| `unavailable` | File cannot be read, is corrupt, has an invalid record or wrong type |
| `invalid_input` | Input is not one literal IP |
| `non_public` | Address is excluded by the policy |

Country reads `country.isoCode` only, never registered-country fallback. Missing
data remains null, never HU, ASN 0 or a safe assessment. ASN organization may
be null even when the ASN number exists.

Stable source error codes are `file_unreadable`, `invalid_database`,
`database_type_mismatch`, and `invalid_record`. Public errors do not include paths.
Expected SDK data/IO errors become states; programming errors propagate from PHP
services. Custom-rule errors also propagate.

Accepted metadata types are exactly `GeoLite2-Country` or `GeoIP2-Country` for Country
and `GeoLite2-ASN` for ASN. City/ISP databases are deliberately not substitutes.
Metadata contains `databaseType`, `buildEpoch`, `ageDays`, `stale`, and `futureBuild`.
Age is clamped to zero for a future build; `futureBuild=true` makes that clock
anomaly explicit. Stale means age **strictly greater than** `max_age_days` (default
30); equality is fresh. The threshold must be a non-negative integer whose
conversion to seconds fits a PHP integer. Stale data remains readable.

Readers exist only within a source operation, with close in finally. Status reads
metadata without probing an IP. New operations reopen the files, so an atomic
replacement is visible even to a retained lookup instance. There is no persistent
reader, Redis cache or per-lookup full-file hash. A concurrent replacement of the
two files is not a transaction across both sources.

## Observation rules

In `config/ip-analyzer.php`:

```php
'rules' => [
    'ip_cidr' => [
        [
            'id' => 'observed-test-network',
            'value' => '192.0.2.0/24',
            'reason_code' => 'operator_observation',
            'severity' => 'warning',
            'message' => 'Matches an operator-maintained observation.',
        ],
    ],
    'asn' => [],
    'country' => [],
],
'custom_rules' => [
    App\IpRules\ObservedNetworkRule::class,
],
```

All three groups use the same five required fields. `value` is a literal IP or
CIDR, a positive integer ASN, or a two-letter country code (normalized uppercase).
Severity is `info`, `warning` or `high`. IDs must be non-empty and unique across
all configured and custom rules. Malformed entries, unknown fields/groups,
duplicates and invalid custom classes raise `InvalidArgumentException`.

Order is IP/CIDR, ASN, country, then custom class list order. The lookup runs once;
every rule is evaluated and all matches are retained. ASN/country rules require
the corresponding Found status. IP rules also apply to normalized NonPublic
addresses, but never invalid input. CIDR compares binary prefixes within one
address family, including /0, /32 and /128. Host bits in the configured CIDR are
ignored. Mapped single IP rules normalize to IPv4; mapped IPv6 CIDRs are rejected
as ambiguous—use their IPv4 CIDR equivalent.

Custom classes implement `Contracts\Rule`, with `id(): string` and
`evaluate(IpFacts $facts): ?RuleMatch`. The Laravel container resolves constructor
dependencies. Null means no match. A match must use the rule's ID. See
[the custom-rule example](examples/ObservedNetworkRule.php). Keep custom rules
local and side-effect free; the package cannot enforce arbitrary application code.
Config contains class names, not closures or instantiated rules, so it can be cached.

## Commands

```sh
php artisan ip-data:lookup 8.8.8.8
php artisan ip-data:lookup 2606:4700:4700::1111 --json
php artisan ip-data:status --json
php artisan about
```

Human output includes localized status/error labels and an indented structured
result; `--json` emits one compact parseable document without decoration. Lookup includes facts, source states/metadata/errors and matches.
Status reports each source's readability/structural metadata status, without
opening records or making a geographic probe. Found in status means metadata was
read successfully; it is not an exhaustive integrity scan of every record.
Both configured sources are required for command availability.

| Exit | Lookup | Status |
| --- | --- | --- |
| 0 | Usable check; includes NotFound, NonPublic, stale data and rule matches | Both sources readable, right type, not stale |
| 1 | At least one source Unavailable | At least one source Unavailable or stale |
| 2 | Invalid input/configuration or rule failure | Invalid configuration or unexpected failure |

At the CLI boundary failures are sanitized to `{"error":"invalid_configuration_or_rule"}`
and exit 2; exception details remain available to callers of the PHP services.
A future-build flag alone does not change the exit code. These read-only commands never download
data or create directories/databases. `about` reads the installed Composer version,
with a root-package/development fallback, and does not open MMDB files.

## Optional downloads and updates (2.0)

Manual MMDB installation still works without credentials. For the built-in updater,
obtain the following from your [MaxMind account](https://www.maxmind.com/en/account/sign-in):

| MaxMind setting | Where to obtain it | Host application setting |
| --- | --- | --- |
| `AccountID` | Account Information ([instructions](https://support.maxmind.com/knowledge-base/articles/find-your-maxmind-account-id)) | `.env`: `IP_ANALYZER_MAXMIND_ACCOUNT_ID`; config: `ip-analyzer.update.account_id` |
| `LicenseKey` | [License Keys](https://www.maxmind.com/en/accounts/current/license-key), create a key ([instructions](https://support.maxmind.com/knowledge-base/articles/generate-a-maxmind-license-key)) | `.env`: `IP_ANALYZER_MAXMIND_LICENSE_KEY`; config: `ip-analyzer.update.license_key` |
| `EditionIDs` | [Download Databases](https://www.maxmind.com/en/accounts/current/geoip/downloads), available database editions and Get Permalink(s) | Fixed mapping: `country` → `GeoLite2-Country`, `asn` → `GeoLite2-ASN` |

Use a License Key, not your account login password. Put the values in the
**consuming Laravel application's `.env`**, not in the package/vendor directory:

```dotenv
IP_ANALYZER_MAXMIND_ACCOUNT_ID=YOUR_ACCOUNT_ID
IP_ANALYZER_MAXMIND_LICENSE_KEY=YOUR_LICENSE_KEY
IP_ANALYZER_UPDATE_SCHEDULE=false
IP_ANALYZER_VALIDATION_WORKERS=1
IP_ANALYZER_VALIDATION_WORKER_TIMEOUT=1800
```

The host application's `config/ip-analyzer.php` maps these environment variables
into the `update` section. Publish it with
`php artisan vendor:publish --tag=ip-analyzer-config` if it does not exist; retain
existing custom rules when upgrading. See the [config example](docs/UPDATING.md#host-application-configuration).

MaxMind's `GeoIP.conf` is for the separate `geoipupdate` program. This package
neither reads that file nor requires that program: copy the AccountID/LicenseKey
values into the settings above. There is no `EditionIDs` environment variable;
the updater supports Country and ASN, both selected by default or individually
with `--database=country` / `--database=asn`. `GeoLite2-City` from a MaxMind sample
is **not supported** by this package.

```sh
php artisan ip-data:update --check --json
php artisan ip-data:update --check --workers=2
php artisan ip-data:update
php artisan ip-data:update --database=country
php artisan ip-data:update --force --json
```

The first normal update invocation downloads missing files. Later invocations use HEAD and
the installed file/state to avoid unnecessary GETs. Candidates are bounded,
extracted in private staging, validated using the MMDB reader, and renamed
atomically per file. Older build epochs are rejected even with `--force`.
`--check` performs only local reads and HEAD, without installing or writing state.

Downloads use HTTPS, origin-scoped Basic Auth and a checked redirect allowlist.
Successful lookups, provider boot, status/about and Composer installation never
start downloads. Long Retry-After responses persist a cooldown for normal update
runs; `--force` cannot bypass it.

See [the updater guide](docs/UPDATING.md) for source URLs, configuration limits,
scheduler opt-in, status/exit codes, credential handling, notices, recovery and
filesystem/platform constraints. Upgrading from 1.x does not require overwriting
a published config: new update options receive defaults. Add credentials to the
environment used when building the config cache; republishing with `--force`
would overwrite your custom rules and paths.

## Update progress (2.1)

Human `ip-data:update` output announces work before expensive validation and
shows Country/ASN, the phase, measured work and elapsed time. A complete local
integrity scan can take several minutes, including with `--check`; validation
depth and update decisions are unchanged.

```sh
php artisan ip-data:update --check               # default human progress
php artisan ip-data:update --no-progress        # final result and total duration only
php artisan ip-data:update --json               # one final JSON document on STDOUT
php artisan ip-data:update --json --progress     # progress and duration on STDERR
```

`--quiet` suppresses all output, including explicit `--progress`.
`--no-progress` takes precedence over `--progress`. Non-TTY output uses separate
lines without ANSI cursor control; terminal detection uses the selected output
channel. Human output includes a final total duration; the JSON result schema is
unchanged. Use `--json` for machine parsing.

Since 2.1.2, record traversal reports actual processed CIDR ranges against the
exact leaf count of the MMDB binary search tree. This enables a percentage and
phase ETA without a second record-counting pass. Hashing and downloads use bytes
when total size is known. Missing Content-Length means no download percentage or
ETA. Phase ETA uses a monotonic clock and smoothed speed, starts only after at
least one second and two samples, and becomes unknown during a stall. It is not
an estimate for the whole command. Human durations over 60 seconds use minutes
plus seconds; JSON and numeric snapshots are unchanged. Samples are limited to
four per second; non-TTY output updates at most every five seconds within a phase.
Phase changes and completion bypass throttling. `--check` never shows download,
extraction or installation as performed phases.

Where optional PHP PCNTL signal handling is available, Ctrl-C requests cooperative
cancellation, reports interruption and exits 130. Readers, staging files and owned
locks are released at safe checkpoints. An in-flight blocking call may delay
cancellation until it returns or reaches a callback/timeout. Installation already
in its atomic rename/state section is completed before cancellation is observed;
a previously completed database is not rolled back. Inspect status and rerun to
reconcile an interrupted command; its JSON error does not claim overall success.
No cleanup guarantee is made for SIGKILL. PCNTL is not a package requirement.

See [2.1 verification](docs/VERIFICATION-2.1.md) and the
[2.1.2 ETA verification](docs/VERIFICATION-2.1.2.md) for tests and platform limits.

## Parallel local validation (2.2)

Parallel validation is opt-in and primarily benefits a full Country + ASN check:

    php artisan ip-data:update --check --workers=2
    php artisan ip-data:update --check --workers=1 --json
    php artisan ip-data:update --database=country --check --workers=2

Priority is the CLI workers option over ip-analyzer.validation.workers, whose
default is 1. Effective concurrency is bounded by available tasks: Country + ASN
can use at most two workers, while one selected database always runs directly in
the main process. A value of 1 starts no subprocess. There is no CPU-count
autodetection.

Each worker performs one complete local MMDB traversal and hash using the same
validator as sequential execution. It cannot perform HTTP, extraction, installation
or state writes. The main process owns target locks, checks that the validated file
was not replaced, performs HEAD requests and preserves deterministic requested
result order. Worker IPC is bounded internal NDJSON and is not part of the public
JSON schema; credentials are never passed to workers.

The ip-analyzer.validation.worker_timeout setting defaults to 1800 seconds. If
proc_open, a readable Composer autoloader or a usable PHP CLI executable is
unavailable before startup, the command reports a localized notice in progress
mode and runs sequentially. A started worker crash, invalid protocol or timeout is
a failure and is not silently retried. Supported Ctrl-C handling stops active
children before returning exit 130; portable signal handling and SIGKILL cleanup
are not promised. Subprocess termination and file-identity protection are verified
on local Linux filesystems; Windows and network/distributed filesystems are not
certified by this release.

Two workers may roughly double validation memory use and increase storage I/O.
Speedup depends on CPU, filesystem/cache behavior and MMDB sizes and is not
guaranteed. Download, HEAD, extraction, installation and state writes remain
non-parallel. Rebuild Laravel's config cache after changing either environment
setting. See [2.2 verification](docs/VERIFICATION-2.2.md).

## Language and application overrides (2.1.1)

Package-owned human output supports **English (`en`) and Hungarian (`hu`)**.
Every rendering uses the application's current Laravel translator locale, including
locale changes within the same application instance. The package never changes
`app.locale`, `app.fallback_locale` or the translator's settings.

Resolution is per key: current locale (including application overrides), then
explicit **English** (including English application overrides). The host fallback
locale is not used for package messages. There is no regional-locale mapping:
`hu_HU` and `en_GB` fall back to English unless the application supplies those
exact locale's package translations.

Translations work immediately, without publishing. Optional publication:

```sh
php artisan vendor:publish --tag=ip-analyzer-translations
```

The destination is the host application's `lang_path('vendor/ip-analyzer')`.
For a partial Hungarian override, create
`lang/vendor/ip-analyzer/hu/messages.php` (under your configured language path):

```php
<?php

return [
    'progress' => [
        'start' => 'Az adatbázisok ellenőrzése elindult; ez több percig tarthat.',
    ],
];
```

Omitted keys retain package translations. An equivalent `en/messages.php` override
also applies when English is selected as fallback. Preserve the placeholders of
the overridden key; for example `progress.elapsed` uses `:elapsed` and
`progress.summary` uses `:status` and `:elapsed`. Their over-60-second counterparts
are `progress.elapsed_minutes` (`:minutes`, `:seconds`) and
`progress.summary_minutes` (`:status`, `:minutes`, `:seconds`); ETA and wait keys
follow the same `_minutes` convention. Counted range/byte messages use Laravel
pluralization. Keep overrides in the host application, not in `vendor/`.

Progress, summaries, status labels, sanitized errors/warnings and package command
descriptions are localized. Human results include localized source labels followed
by the structured result; machine codes and custom rule messages remain intact.
Framework-owned help text is left to Laravel/Symfony. There are no package tables
or table headers to translate.

`--json` output is locale-independent, including existing `message` fields.
With `--json --progress`, only STDERR human progress is localized; STDOUT remains
one unchanged JSON document. Quiet, no-progress, timing, validation, installation
and cancellation behavior are unchanged. See the
[2.1.1 verification record](docs/VERIFICATION-2.1.1.md) for test results.

## Manual data maintenance

Obtain Country and ASN databases manually from
[MaxMind](https://dev.maxmind.com/geoip/geolite2-free-geolocation-data/), under the
applicable terms. The package does not supply production databases or credentials.

1. Download and extract in a private staging directory on the same filesystem as
   the destination. Keep credentials out of the application repository.
2. Verify the source/checksum using the vendor's distribution information.
   Check file ownership and read permissions for the PHP worker account.
3. Validate candidate metadata with the local SDK: exact database type, acceptable
   build date and no future-clock anomaly. In an isolated application process,
   point the two config paths at staged files and run `ip-data:status --json`.
   Ensure cached config is not still pointing at the installed files.
4. For deeper integrity checks, read known records offline; status validates metadata,
   not every tree node. Do not overwrite/truncate a live MMDB in place.
5. Rename each validated candidate over its destination atomically on that same
   filesystem. Retain a rollback copy under the applicable data terms, and run
   status with the application's normal config again.

An operator-controlled atomic rename preserves active readers and lets the next
operation see the replacement. The optional V2 updater performs this workflow
without changing offline lookup behavior.

## Development and compatibility

Run development checks from a Git source checkout. Distribution archives exclude
the tests, fixtures and development configuration.

```sh
composer install
composer validate --strict
composer test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

The tests are Pest functions, including the original provider tests, with the Pest
Laravel plugin and Orchestra Testbench. Synthetic MMDB fixtures are committed;
tests never download data. Their original generator, license and records are in
[fixture documentation in the source repository](https://github.com/trianity/laravel-ip-analyzer/blob/master/tests/Fixtures/README.md). See
[V2 verification notes](docs/VERIFICATION-V2.md) for RED/GREEN evidence and actual versions.

The CI workflow covers PHP 8.4/8.5 with Laravel 12 (Testbench 10, Pest 4, PHPUnit 12)
and Laravel 13 (Testbench 11, Pest 5, PHPUnit 13), performs Composer validation and PHP lint,
then runs Pest with networking entry points disabled. PHP 8.3 is deliberately
outside this package's existing `^8.4` requirement and Pest 4/5 test baseline,
even though [Laravel 13 itself supports PHP 8.3](https://laravel.com/docs/13.x/releases).
No Laravel/PHP version outside the tested matrix is claimed here.

### Local development installation

Add the local directory as a path repository in the consuming Laravel application:

```json
{
    "repositories": [
        {"type": "path", "url": "../packages/laravel-ip-analyzer"}
    ]
}
```

Then install the checkout and publish its configuration:

```sh
composer require trianity/laravel-ip-analyzer:@dev
php artisan vendor:publish --tag=ip-analyzer-config
```

Package versions come from Git tags; `composer.json` deliberately has no
`version` field. See [2.0 verification](docs/VERIFICATION-V2.md) for the local
implementation checks and known limits. The 1.0 verification remains in
[the historical release record](docs/RELEASE-1.0.0.md).

## Licenses and limits

MIT applies to this project's code and its original synthetic fixtures. MaxMind
SDK dependencies and downloaded databases have separate licenses; see
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md). The age threshold is an operational
warning, not a guarantee of license compliance or data accuracy. Database
acquisition, update/deletion obligations and attribution remain the operator's
responsibility under the applicable terms.

[Magyar quickstart](docs/QUICKSTART-HU.md).
