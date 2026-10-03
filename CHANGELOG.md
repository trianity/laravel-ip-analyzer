# Changelog

Notable changes to Laravel IP Analyzer are recorded here.
Versions follow Semantic Versioning. Publication is identified by the corresponding
Git tag; these notes do not by themselves indicate that a release was published.

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
