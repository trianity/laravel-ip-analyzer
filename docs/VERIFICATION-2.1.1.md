# 2.1.1 localization verification — 2026-10-04

Scope: `.idea/Refactor_02.md`. This is a localization patch for the upcoming 2.1.1
release. Validation, download/update decisions, ETA calculations, throttling and
concurrency algorithms are unchanged. No consuming application, production data,
release tag or publication was modified/performed.

## RED / GREEN

- Nine initial translation tests failed: missing adapter/catalogs/publication tag.
  They became green with per-key locale resolution, explicit English fallback,
  pluralization, matching catalog keys/placeholders and provider registration.
- Four command/reporter tests then failed on hardcoded labels/descriptions and
  non-English fallback output. Locale-aware rendering made them pass, including a
  retained reporter after hu → en and STDERR-only localized JSON progress.
- Two human-result tests failed on untranslated configuration messages, statuses
  and warnings. Presentation-only translations resolved them without changing
  JSON keys, codes, types or existing JSON message contents.
- Additional regressions verify real partial vendor overrides, English override
  fallback, optional publication, config cache, unsupported/regional locales,
  missing-key fallback using isolated catalogs, placeholders/plurals, quiet and
  no-progress, custom rule message preservation and upstream secret sanitization.

Targeted localization suite: **27 tests / 283 assertions**.

## Actual compatibility runs

Each row passed **280 tests / 884 assertions**, with networking entry points
blocked at PHP level and synthetic fixtures only:

| PHP | Laravel | Pest | Guzzle / PSR-7 |
| --- | --- | --- | --- |
| 8.4.26 | 12.69.3 | 4.7.8 | 7.15.5 / 2.13.1 |
| 8.5.11 | 12.69.3 | 4.7.8 | 7.15.5 / 2.13.1 |
| 8.4.26 | 13.34.0 | 5.3.0 | 8.2.0 / 3.1.0 |
| 8.5.11 | 13.34.0 | 5.3.0 | 8.2.0 / 3.1.0 |

Laravel 12 ran in an isolated /tmp dependency environment with the current source,
tests and language files. The source checkout retained Laravel 13 dependencies.
The runner allowed local stream descriptors outside the restrictive sandbox while
retaining these PHP network restrictions (both binaries, both environments):

```sh
php8.5 -d allow_url_fopen=0 \
  -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,gethostbyname,gethostbynamel,gethostbyaddr,dns_get_record \
  vendor/bin/pest --compact --colors=never
```

## Implementation and compatibility

- Laravel loads `lang/en/messages.php` and `lang/hu/messages.php` under the
  `ip-analyzer` namespace; boot performs no copying. The optional publication tag
  targets `lang_path('vendor/ip-analyzer')`.
- The adapter checks the current locale without fallback, then explicitly uses en.
  Neither translator/app locale nor host fallback/config is modified. English
  application overrides remain effective for fallback. No region aliasing occurs.
- All catalog keys and placeholder sets match. Complete labels/messages use Laravel
  placeholders; range/byte counts use Laravel pluralization. No custom file loader.
- Internal Phase enum cases are retained; their values are machine identifiers.
  All human labels are resolved by the Console layer. Public lookup/result JSON
  and numeric progress measurements remain locale-independent.
- Human result labels and sanitized configuration details are localized; machine
  status/error/reason codes and application-authored rule messages are unchanged.
  There are no existing package tables to translate; framework-owned help text
  is not copied. Package descriptions resolve the current locale on each read.
- `--json` remains one final document; `--json --progress` localizes only STDERR.
  Regression tests compare success/error JSON byte-for-byte between locales,
  including pre-existing English configuration-message fields.
- `illuminate/translation` is declared as a direct dependency. Composer lock refresh
  reported no dependency version changes; no installation/vendor editing occurred.

## Quality and distribution

- `composer validate --strict`: valid.
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: no errors in the
  existing level 6 src scope; local worker socket permitted.
- PHP lint across src/config/tests/examples/lang: 76 files, no syntax errors.
- `git diff --check`: clean.
- Composer review ZIP inspected: both language catalogs and new runtime classes
  included; vendor/tests/MMDB/state/credentials/IDE prompts remain excluded.
- CI lint now includes lang. GitHub Actions itself was not run during this work.

The source tests retain optional real SIGINT coverage from 2.1. No real MaxMind
requests, long sleeps, Windows terminal certification or production deployment
was used to verify this patch.
