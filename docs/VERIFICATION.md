# V1 verification record — 2026-10-03

## TDD increments

Tests use Pest functions throughout; the initial PHPUnit provider class tests were
converted before implementation. Extra regression tests were added after the
behavioral RED/GREEN steps.

| Step | RED evidence | GREEN / refactor |
| --- | --- | --- |
| Input guard | `pest --compact`: 35 failures from the scaffold LogicException, 2 provider tests passed | 37 tests, 74 assertions; canonicalization and explicit binary prefix policy |
| Local SDK lookup | `pest tests/Feature/LookupTest.php --compact --stop-on-failure`: expected Found, got Unavailable | 53 tests, 121 assertions; real synthetic MMDB records, independent errors, replacement |
| Rules | `pest tests/Feature/RulesTest.php --compact --stop-on-failure`: scaffold LogicException | 89 tests, 159 assertions; configured rule validation and container-resolved custom rules |
| Commands | `pest tests/Feature/CommandsTest.php --compact --stop-on-failure`: missing commands/about section | Lookup/status JSON and human output, exit semantics, Composer version fallback |
| Strict configuration | LookupTest/RulesTest: negative age on NonPublic and null group failed to throw | 124 tests, 246 assertions after validation was shared and null lists rejected |
| Special-purpose services | InputTest: 4 AS112/AMT keys returned Unavailable instead of NonPublic | 128 tests, 250 assertions; explicit IANA service ranges added |

Additional coverage verifies close/finally on success, not-found, corrupt record
exceptions and programming errors; metadata-only reads/type mismatch; exact stale
boundary/future dates; no reader open on invalid/private input; registered-country
non-fallback; constructor DI, null/custom ordering, duplicate IDs and rule failures;
publish + real config cache reload; sanitized CLI failures and all exit codes.

The about assertion was corrected to Laravel's actual JSON key `i_p_analyzer`.
Composer InstalledVersions tests isolate its vendor discovery when testing fallback
data. Testbench config-cache tests refresh the application after the cache command.

## Final tested matrix

Every combination below passed **137 tests / 284 assertions**, including runs with
URL fopen disabled and HTTP/DNS/client-socket functions disabled.

| PHP | Laravel | Testbench | Pest | PHPUnit |
| --- | --- | --- | --- | --- |
| 8.4.26 | 12.69.3 | 10.12.0 | 4.7.8 | 12.5.33 |
| 8.5.11 | 12.69.3 | 10.12.0 | 4.7.8 | 12.5.33 |
| 8.4.26 | 13.34.0 | 11.3.0 | 5.3.0 | 13.3.6 |
| 8.5.11 | 13.34.0 | 11.3.0 | 5.3.0 | 13.3.6 |

geoip2/geoip2 3.4.0 and maxmind-db/reader 1.14.0 were used. Laravel 12 was resolved
in a separate /tmp working copy with Testbench 10/Pest 4 constraints; the main
working tree retained Laravel 13/Pest 5. No platform-requirement bypass was used.

The existing PHP ^8.4 minimum was retained. PHP 8.3 is not claimed or present in
CI because it is outside that constraint and the chosen Pest 4/5 baseline.
The GitHub Actions workflow is configured but has not been run on GitHub.

## Checks

- `composer validate --strict`: valid.
- `composer install --no-interaction`: successful; dependency resolution for the
  added Pest Laravel plugin and Laravel 12 environment also succeeded.
- PHP lint over every PHP file in src/config/tests/examples: no syntax errors.
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: level 6, 0 errors
  for src. This is an application-code check; tests are not in its configured scope.
- `git diff --check`: clean.
- `composer archive --format=zip --dir=/tmp --file=laravel-ip-analyzer-review`:
  generated and inspected. Runtime files, MIT license, notices and user docs are
  included; MMDB, tests, vendor, caches, .env, IDE and agent directories are excluded.
- Binary fixtures are reproducible from the original offline generator.

Offline verification command (run with php8.4 and php8.5 in both environments):

```sh
php8.5 -d allow_url_fopen=0 \
  -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,gethostbyname,gethostbynamel,gethostbyaddr,dns_get_record \
  vendor/bin/pest --compact --colors=never
```

The actual factory imports GeoIp2\Database\Reader, whose SDK path reads local
files through MaxMind\Db\Reader. Stream-wrapper paths are rejected before file
inspection. Tests do not rely solely on Laravel HTTP fakes. This test command
disables common network entry points, not every possible OS networking mechanism;
arbitrary custom application rules remain the application's responsibility.

The sandbox initially prevented Composer DNS access and PHPStan's local worker
socket. These operations were rerun outside that restriction. The Laravel 12 /
PHP 8.4 runner also emitted sandbox stream-fd warnings; its unrestricted rerun
passed without those warnings, retaining the PHP-level networking restrictions.

## Scope limits

Status validates readability, type and metadata, not every MMDB tree node.
The local PHP SDK path was tested, not the optional native MaxMind extension.
Atomic file replacement was tested with a retained lookup object; a deployed
Octane/queue cluster was not started. There is no cross-file transaction, persistent
reader/cache, production data lookup, automated updater or blanket security verdict.
No release, tag, publication or push was performed.
