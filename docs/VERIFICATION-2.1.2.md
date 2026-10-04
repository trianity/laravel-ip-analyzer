# 2.1.2 progress estimate verification — 2026-10-04

> Correction in 2.2.1: real MMDB traversal can yield more CIDR iterations than
> `nodeCount + 1`. The synthetic fixture used here did not expose that behavior.
> Validation percentage and ETA based on this denominator have been removed.

Scope: `.idea/Refactor_03.md`. This patch changes human progress presentation and
supplies the existing ETA calculation with the exact MMDB traversal total. It does
not change validation depth, update decisions, JSON contracts, networking, storage
or database licensing. No release was published or tagged.

## RED / GREEN

- The validation regression initially failed because the final CIDR-range snapshot
  had no total. It became green when validation supplied `nodeCount + 1`, the leaf
  count consumed by the existing complete binary-tree traversal.
- Human-output regressions initially failed because elapsed time, phase ETA,
  wait/timeout and total duration remained seconds-only. They became green with
  localized minute-plus-second messages above 60 seconds.
- The exact boundary remains seconds-only at 60.0 seconds; 60.1 seconds switches to
  the localized minute form. English and Hungarian output are both covered.

Targeted progress and localization suite: **48 tests / 381 assertions**.

## Implementation checks

- The [MaxMind DB format specification](https://maxmind.github.io/MaxMind-DB/)
  defines a binary search tree, records `node_count`, and specifies two branch
  records per node. A complete binary tree with `n` internal nodes has `n + 1`
  leaves; each existing `getWithPrefixLen()` iteration consumes one disjoint leaf
  range. The synthetic fixture independently confirms 406 nodes and 407 ranges.
- The actual processed value remains the range counter. No fabricated progress,
  second traversal, network request or per-record console output was added.
- ETA retains the 2.1 monotonic clock, minimum elapsed time, two-sample warmup,
  smoothed speed, stall handling and non-finite guard. It remains phase-specific.
- Durations up to and including 60 seconds preserve the existing translation keys
  and placeholders. Longer values use explicit `_minutes` keys, so existing partial
  application overrides for short durations remain compatible.
- JSON output and typed numeric snapshots are unchanged. Only Console rendering
  uses the new localized duration strings.

## Actual verification

The current checkout used PHP 8.5.11, Laravel 13.34.0, Pest 5.3.0 and
`maxmind-db/reader` 1.14.0. The package declares PHP `^8.4` and Laravel
`^12.0|^13.0`; the full four-combination compatibility matrix was not rerun for
this patch.

- Full offline suite: **282 tests / 895 assertions**.
- `vendor/bin/pint --test`: passed after formatting the new test code.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: no errors.
- `composer validate --strict --no-check-publish`: valid.
- PHP lint across `src`, `config`, `tests`, `examples` and `lang`: no syntax errors.
- `git diff --check`: clean.

Tests used only synthetic local MMDB fixtures and mocked transports. No real MaxMind
request, production database, long sleep, consuming application, dependency update,
publication or release tag was involved.
