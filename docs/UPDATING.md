# Database updates in 2.0

The built-in downloader is an explicit opt-in tool. It is never invoked from
provider boot, install hooks, web middleware, lookup, status or about. Offline
manual MMDB use and all 1.x public lookup/rule DTOs remain available without credentials.

## Account and source setup

Create a license key in your MaxMind account and use it with the Account ID.
Do not use the account login password. The account's Download Databases /
Get Permalink(s) page provides the edition-specific source links.

The checked [MaxMind guide](https://dev.maxmind.com/geoip/updating-databases/)
and [download specification](https://github.com/maxmind/openapi/blob/main/bundled/downloads.yaml)
define HTTPS Basic Auth, binary `suffix=tar.gz` and redirects to signed R2 URLs.
The package defaults to:

- `https://download.maxmind.com/geoip/databases/GeoLite2-Country/download?suffix=tar.gz`
- `https://download.maxmind.com/geoip/databases/GeoLite2-ASN/download?suffix=tar.gz`

Only these binary editions are supported. Sources may include an eight-digit
`date` selector; credentials in a URL are rejected. CSV ZIPs, other source hosts
and arbitrary query parameters are not accepted.

Set placeholders in your deployment secret configuration:

```dotenv
IP_ANALYZER_MAXMIND_ACCOUNT_ID=YOUR_ACCOUNT_ID
IP_ANALYZER_MAXMIND_LICENSE_KEY=YOUR_LICENSE_KEY
IP_ANALYZER_UPDATE_SCHEDULE=false
```

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

The direct dependencies are Guzzle plus PSR-7/HTTP Message, the MaxMind reader and
PHP cURL/zlib. The explicit cURL handler supports separate connect/total timeouts
and writes through a bounded sink. Automatic HTTP content decoding is disabled,
so archive gzip decoding is an independent, validated step.

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

A normal run takes the target lock, validates the local database, reads state and
performs HEAD. A matching source/target identity, unchanged local hash/build epoch
and common HTTP validators allow a GET-free skip. Common ETag hashes take priority;
Last-Modified can be compared when only GET supplies ETag. A missing/unparseable
HEAD validator or HEAD 405/501 causes conservative GET for normal runs, and
`update_available` with `remote_version_unverified` for check mode. HEAD
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
force runs. Identical content avoids rename but can update verification metadata.

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

The existing LocalDatabase inspector checks type and metadata. The low-level
MaxMind SDK then walks every reachable address range, decodes its records and
checks Country/ASN field shape under the traversal limit. This catches synthetic
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
php artisan ip-data:update --force
```

Default selection is Country + ASN. Unknown names are configuration errors.
Check wins over force: it never GETs or mutates update state. Both output modes
use one JSON document (indented for humans), with a `results` array and
`partialFailure`. Per-target fields include database, status, oldBuildEpoch,
newBuildEpoch, errorCode, warning, nextRetryAt (Unix seconds), and installed.

States are updated, up_to_date, update_available, busy or failed.
Only an actual rename yields updated/installed=true. Check's update_available
means a download is needed or conservatively required, not proof of a newer build.

| Exit | Meaning / precedence |
| --- | --- |
| 2 | Invalid config/input/missing credentials, detected before network/install |
| 1 | Any failure, or busy combined with an actual successful installation |
| 3 | Busy with no installation and no other failure |
| 0 | All requested operations succeeded; check may report update_available |

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
