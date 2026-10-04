# Database updates in 2.x

The built-in downloader is an explicit opt-in tool. It is never invoked from
provider boot, install hooks, web middleware, lookup, status or about. Offline
manual MMDB use and all 1.x public lookup/rule DTOs remain available without credentials.

## Account and source setup

Sign in to your [MaxMind account](https://www.maxmind.com/en/account/sign-in).
Find your Account ID in **Account Information** using the
[official instructions](https://support.maxmind.com/knowledge-base/articles/find-your-maxmind-account-id).
Create a License Key on the account's
[License Keys page](https://www.maxmind.com/en/accounts/current/license-key)
([creation instructions](https://support.maxmind.com/knowledge-base/articles/generate-a-maxmind-license-key)).
Use that key, not the account login password. Available editions and their
Get Permalink(s) links are on
[Download Databases](https://www.maxmind.com/en/accounts/current/geoip/downloads).

The account also offers a [GeoIP.conf template](https://www.maxmind.com/en/accounts/current/license-key/GeoIP.conf)
for the separate `geoipupdate` program. Its `AccountID` and `LicenseKey` correspond
to this package's `update.account_id` and `update.license_key`. This package does
not read GeoIP.conf or execute geoipupdate; enter those values in the host
application as shown below. `EditionIDs` is not a package configuration key:
`country` always selects GeoLite2-Country and `asn` selects GeoLite2-ASN.
Both are selected by default. GeoLite2-City, even if listed in your account or
GeoIP.conf, is not supported by this package.

Lookup, status and `ip-data:verify` also accept a manually installed
GeoIP2-Country database. The built-in updater does not download that commercial
edition: it treats it as incompatible with GeoLite2 freshness state, reports
unknown freshness in check mode and attempts to obtain a validated GeoLite2
replacement in a normal run. The existing GeoIP2 build epoch still prevents a downgrade.

The checked [MaxMind guide](https://dev.maxmind.com/geoip/updating-databases/)
and [download specification](https://github.com/maxmind/openapi/blob/main/bundled/downloads.yaml)
define HTTPS Basic Auth, binary `suffix=tar.gz` and redirects to signed R2 URLs.
The package defaults to:

- `https://download.maxmind.com/geoip/databases/GeoLite2-Country/download?suffix=tar.gz`
- `https://download.maxmind.com/geoip/databases/GeoLite2-ASN/download?suffix=tar.gz`

Only these binary updater source editions are supported. Sources may include an
eight-digit `date` selector; credentials in a URL are rejected. CSV ZIPs, other
source hosts and arbitrary query parameters are not accepted.

## Host application configuration

Set the following in the **host Laravel application's `.env`** (or its deployment
environment), replacing the placeholders. Do not place credentials in the package's
own directory or edit files under `vendor/`:

```dotenv
IP_ANALYZER_MAXMIND_ACCOUNT_ID=YOUR_ACCOUNT_ID
IP_ANALYZER_MAXMIND_LICENSE_KEY=YOUR_LICENSE_KEY
IP_ANALYZER_UPDATE_SCHEDULE=false
IP_ANALYZER_VALIDATION_WORKERS=1
IP_ANALYZER_VALIDATION_WORKER_TIMEOUT=1800
```

Publish the host configuration if it does not exist:

```sh
php artisan vendor:publish --tag=ip-analyzer-config
```

In the host application's `config/ip-analyzer.php`, the relevant section is:

```php
'validation' => [
    'workers' => env('IP_ANALYZER_VALIDATION_WORKERS', 1),
    'worker_timeout' => env('IP_ANALYZER_VALIDATION_WORKER_TIMEOUT', 1800),
],
'update' => [
    'account_id' => env('IP_ANALYZER_MAXMIND_ACCOUNT_ID'),
    'license_key' => env('IP_ANALYZER_MAXMIND_LICENSE_KEY'),
    'schedule_enabled' => env('IP_ANALYZER_UPDATE_SCHEDULE', false),
],
```

This is a section of the returned config array, not a replacement for the whole
file. Keep existing paths, rules and update overrides. Omitted updater options
receive package defaults; the published V2 config includes those defaults too.
The consuming application reads update settings as `ip-analyzer.update.*` and
validation settings as `ip-analyzer.validation.*`. The CLI `--workers` value
overrides the configured worker count for one invocation.
For a cached deployment, rebuild with `php artisan config:cache` after changes.

No credentials are accepted as command options. Environment variables are read
only by the config file. Laravel's normal configuration cache includes configured
credentials, as with other application secrets: protect that cache and do not
commit/distribute it. The **updater's own state/cache, outputs and exceptions**
contain neither credentials nor signed URLs. Rebuild config cache after changing
secrets and restart workers that retain old application configuration.

A previously published 1.x config works because the update section and missing
update options receive defaults. If editing the published config, add an
`update` array with only the overrides you need. Do not blindly force-publish
over application-maintained rules.

## HTTP and limits

Basic Auth is constructed separately for each request and only sent to
`download.maxmind.com`. Redirects are followed manually for HEAD and GET.
R2 receives no Authorization or Cookie header. TLS verification remains enabled;
HTTP downgrades, userinfo, non-443 ports, unapproved hosts, redirect loops and
excessive hops fail without exposing the rejected URL.

The default allowlist contains the download origin and:

`mm-prod-geoip-databases.a2649acb697e2c09b632799562c076f2.r2.cloudflarestorage.com`

An operator can add individually verified hostnames under MaxMind or R2's domain
through `update.allowed_hosts`; no wildcard or automatic trust expansion occurs.
Authentication does not expand with this allowlist. Permit DNS and outbound
HTTPS/443 to the configured hosts. No live account access is required by CI.

The direct dependencies are Guzzle plus PSR-7/HTTP Message, the MaxMind reader,
Illuminate Config and Symfony Process, plus PHP cURL/zlib. The explicit cURL
handler supports separate connect/total timeouts and writes through a bounded
sink. Automatic HTTP content decoding is disabled, so archive gzip decoding is an
independent, validated step. Symfony Process is used only for opt-in local
validation workers.

| update option | Default | Meaning |
| --- | --- | --- |
| connect_timeout | 10 | Connection timeout seconds |
| timeout | 120 | Total seconds per request chain, including redirects |
| max_redirects | 3 | Maximum redirect hops |
| retries | 2 | Extra attempts for 429, 500, 502, 503, 504 |
| max_retry_wait | 2 | Maximum seconds to sleep before an in-process retry |
| retry_delay | 1 | Base seconds for bounded exponential backoff |
| max_bytes | 134217728 | Actual compressed transfer byte limit (128 MiB) |
| max_expanded_bytes | 536870912 | Actual expanded TAR byte limit (512 MiB) |
| max_entries | 100 | Includes metadata/directory entries |
| max_records | 5000000 | Maximum SDK network-range traversal operations |
| lock_timeout | 0 | Seconds to wait for a target lock; 0 means immediate busy |
| cleanup_age | 86400 | Minimum age for cleanup of abandoned owned staging |
| schedule_enabled | false | Switch for the application scheduler example |

Validation has a separate, cached-config-safe section:

| validation option | Default | Meaning |
| --- | --- | --- |
| workers | 1 | Positive worker limit; CLI `--workers` has precedence |
| worker_timeout | 1800 | Positive timeout in seconds for each subprocess |

Options are validated integers with finite upper bounds; booleans must be booleans.
No option disables TLS or unlimited-download protection. Limits cover received
bytes even without Content-Length; malformed or inconsistent length also fails.
Compressed data goes to disk, never into a whole-archive PHP string. gzip
decompression is chunked and checks actual output bytes, end-of-stream and CRC.

401/403 and invalid archives are not retried. Retry-After accepts seconds or an
HTTP date. Short waits fit the configured attempt/wait budget. Long waits or
exhausted retries return a retry timestamp and save per-target cooldown. Further
normal runs do not make requests before that timestamp, even with force.
`--check` respects existing cooldown but **cannot persist a new one** because it
is read-only; operators must heed its reported retry time.

## Freshness and state

A normal run takes the target lock, performs a lightweight local metadata inspection,
reads state and performs HEAD. Compatible completed state, usable local metadata and
matching HTTP validators allow a GET-free skip without claiming local integrity.
Common ETag hashes take priority;
Last-Modified can be compared when only GET supplies ETag. A missing/unparseable
HEAD validator or HEAD 405/501 causes conservative GET for normal runs, and
`freshness_unknown` with `remote_version_unverified` for check mode. Missing,
invalid or edition-incompatible state also produces `freshness_unknown`. HEAD
401/403/429 never falls back to GET. Unsolicited 304 is an error; no conditional
GET protocol is assumed.

State records edition, source/target identity hash, hashed ETag/validator,
Last-Modified timestamp, MMDB buildEpoch, local SHA-256, install/check time and
completion state. Signed URL queries are never retained. HTTP dates are not
treated as MMDB buildEpoch or filesystem mtime. For a manually installed unchanged
file, the original install time may be unknown/null.

Only the final successful GET's validators are associated with installed bytes,
avoiding a HEAD/GET release race. Missing, corrupt or wrong-type local files force
download regardless of state. Source changes invalidate the previous identity.
Downloaded candidates older than the valid local build are rejected, including
force runs, also when the existing Country file is a manually installed
GeoIP2-Country database. State is evidence of a previous successful installation,
not proof that the current file bytes never changed. Therefore forced or conservative
replacement installs the fully validated candidate even when its hash equals the
recorded historical hash.

The local SHA-256 is a change fingerprint, **not source authentication**.
This version does not fetch vendor checksum files.

## Archive and database validation

Supported format: one gzip stream containing a TAR archive. USTAR headers,
safe PAX metadata and GNU long-name records are handled. Path/size overrides are
checked; unsupported semantic extensions (including sparse/link metadata) fail.
ZIP/CSV and bare mmdb.gz are unsupported. Headers, type, checksum, end markers,
entry count and sizes are checked independently of filename or Content-Type.

All member names are validated, including ignored ordinary files. Absolute,
Windows/UNC, traversal and embedded-NUL paths, symlinks, hardlinks and device/FIFO
entries are rejected. Exactly one expected `GeoLite2-Country.mmdb` or
`GeoLite2-ASN.mmdb` is required. No archive-selected destination path is used.

LICENSE, COPYRIGHT and README (with optional filename extension) are retained in
the private target workspace's `notices/` directory. These database notices keep
their original licenses; they are not relicensed as MIT.

The lightweight LocalDatabase inspector checks availability, type and metadata
without enumerating records or hashing the file. `ip-data:verify` applies the full
validation to installed files. During update, it applies only to newly extracted
candidates. The low-level MaxMind SDK walks every reachable address range, decodes
its records and checks Country/ASN field shape under the traversal limit. This catches synthetic
corrupt record payloads even when metadata alone succeeds. It does not prove
geographic accuracy, authenticate the producer, or inspect unreachable padding.
Future/nonpositive build epochs fail. No expected real-world country for a live
IP is needed. Raising limits is an explicit operator choice.

## Installation, permissions and recovery

Targets use the existing `databases.country/asn` paths. They must be distinct,
absolute local files outside the Laravel public path. Parent symlinks are resolved
before dot segments; target symlink files, stream wrappers, identical paths and
existing hardlink aliases are rejected.

Each normalized target has an adjacent private directory:

`<target-parent>/.ip-analyzer-<sha256-of-normalized-target>/`

It holds a persistent lock file, atomic `state.json`, database notices and random
`stage-*` workspaces. Directories are 0700; installed MMDB/state/notices are 0600.
Run updater and lookup under the same account, or implement an explicit operator
permission policy. Protect the target's parent directory from untrusted writers.
The updater creates missing private parent directories as needed. It does not
use a remote Laravel disk or a configurable public temp directory.

A kernel flock protects each target and has **no TTL that can expire during work**.
The lock is released by its owner in finally (and by the OS on process exit).
Never unlink the persistent lock file while updater processes may be alive.
The downloader lock works without the scheduler or Redis. A global account-wide
lock/rate-limit is not implemented; each target has its own cooldown.

After validation, a pending-state marker is written, then a same-filesystem
rename installs the candidate without first deleting the old file. Notices and
complete state follow. Country and ASN are independent: success is retained when
the other database fails.

If rename succeeds but notice/state saving fails, the result is failed with
`installed=true`, `installed_metadata_failed` and the new build epoch. The pending
marker forces a conservative retry; no false up-to-date result is reported.
If rename fails, the old database remains. The updater does not make a permanent
backup: keep operator-managed rollback copies under the applicable data terms.
Never restore or remove files concurrently with an active updater.

Ordinary failures clean flat staging files/readers in finally. After a killed
process, only owned staging/state-temp names older than cleanup_age are removed,
under the target lock. Symlinks/unknown directories are not recursively followed.
Do not delete another process's workspace manually.

Atomic replacement and locking were tested on local Linux. Windows replacement
failure preserves the old file; support is not certified. Shared/NFS storage
requires operator verification of flock and atomic rename behavior; identical
canonical paths/storage must identify the same lock on every node. No distributed
filesystem or sudden-power-loss durability guarantee is made.

## Commands and scheduling

```sh
php artisan ip-data:update
php artisan ip-data:update --database=country --database=asn --json
php artisan ip-data:update --check --json
php artisan ip-data:verify --workers=2
php artisan ip-data:update --workers=2
php artisan ip-data:update --force
```

Default selection is Country + ASN. Unknown names are configuration errors.
Check wins over force: it never traverses records, hashes the full file, GETs or
mutates update state. `verify` performs no network, download, installation or state
write. The final result
has a `results` array and `partialFailure`. Since 2.1, human mode surrounds its
indented JSON result with progress and a total-duration summary. `--no-progress`
removes phase messages but keeps the final human summary. `--json` alone emits
exactly one JSON document without progress or a timing field; `--json --progress`
sends all phase messages and the timing summary exclusively to STDERR.
`--quiet` overrides explicit progress, and `--no-progress` overrides `--progress`.
Interactive ANSI terminals keep a stable Country/ASN block, refreshed at most
once per second except for immediate phase and terminal transitions. Redirected,
CI, unsupported and `--no-ansi` streams use separate lines without cursor controls,
with periodic output limited to once every 15 seconds per database. Detection is
performed on the actual progress stream (STDERR for `--json --progress`).
Since 2.1.1, human labels use the current Laravel locale (`en`/`hu`) with explicit
English fallback. See [localization and overrides](../README.md#language-and-application-overrides-211).
Full MMDB traversal reports processed CIDR ranges. Real databases proved that
metadata `nodeCount + 1` is not the total number of SDK traversal iterations, so
2.2.1 removes that incorrect denominator. Validation progress is indeterminate:
it has no percentage or ETA, and no second pass is made to manufacture a total.
Known-size byte phases retain percentage and smoothed ETA. Normal elapsed display
uses whole seconds and ETA uses approximate rounded seconds/minutes. JSON fields
and numeric progress snapshots were unchanged by the 2.2.1 rendering work. Version
2.3 adds the result fields and verification result documented below.
JSON, including existing message fields, remains locale-independent.
See [progress, ETA and interruption behavior](../README.md#update-and-verification-progress).
Country and ASN verification, or two prepared update candidates, can run in at most
two separate PHP processes with `--workers=2`. One task or one worker remains
in-process. The main
process retains locks and all network/install/state work. Startup capability
failure falls back before validation; a started worker failure or timeout does not.
Parallel operation can use more memory and storage I/O and may not be faster on
every system. See [freshness and parallel validation](../README.md#freshness-and-parallel-validation-23).
Update-result fields include database, status, oldBuildEpoch, newBuildEpoch,
errorCode, warning, nextRetryAt (Unix seconds), installed, localStatus,
localErrorCode and integrityVerified. Verify results contain database, status,
buildEpoch, sha256, errorCode and integrityVerified.

Update/check states are updated, up_to_date, update_available, freshness_unknown,
busy or failed; verification adds verified. Only an actual rename yields
updated/installed=true. Check's update_available means comparable remote validators
changed. `freshness_unknown` means the available evidence cannot decide freshness.
Neither `up_to_date` nor lightweight local status means integrity was verified.

| Exit | Meaning / precedence |
| --- | --- |
| 2 | Invalid config/input, or missing updater credentials for update/check, detected before network/install |
| 1 | Any failure, or busy combined with an actual successful installation |
| 3 | Busy with no installation and no other failure |
| 0 | All operations completed; check may report update_available or freshness_unknown |
| 130 | Cooperative Ctrl-C interruption; no overall success is claimed |

A per-target failure can still include installed=true after metadata failure.
Read all results; partialFailure marks success/installation mixed with failures/busy.

The package registers **no scheduled event**. In a consuming application's
`routes/console.php`, add this once and set the schedule flag true to opt in:

```php
use Illuminate\Support\Facades\Schedule;

if (config('ip-analyzer.update.schedule_enabled', false)) {
    Schedule::command('ip-data:update')->everySixHours()->withoutOverlapping();
}
```

Run the application's normal Laravel scheduler. onOneServer is appropriate only
with a suitable shared cache and genuinely shared database storage; otherwise
each node needs its own data. Do not add a second automatic event for the same job.

## Licensing

Own code and original synthetic fixtures remain MIT. MaxMind data terms and
notices remain separate. No production database, state, downloaded archive,
credential or staging directory belongs in Git/Composer distributions.
Stale thresholds are operational signals, not license-compliance guarantees.
