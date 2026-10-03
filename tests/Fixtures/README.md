# Synthetic MMDB fixtures

All five binary fixtures are original project test data, licensed under the root
MIT LICENSE, generated offline by `generate.py` revision **1**. No MaxMind
production records, third-party fixture binaries or geographic measurements are
included. Format reference: https://maxmind.github.io/MaxMind-DB/ (format 2.0).
The generator is original code, not copied from the reference implementation.

Reproduce with `python3 tests/Fixtures/generate.py`; output is deterministic.
All files use a 128-bit tree and contain exactly these synthetic host records:
8.8.8.8, 2606:4700:4700::1111, 192.0.2.1, 2001:db8::1.
The first two are merely numeric test keys, not statements about real networks.
Other addresses (e.g. 1.1.1.1) are absent.

| File | Schema type | Record at every listed key | buildEpoch |
| --- | --- | --- | --- |
| country.mmdb | GeoLite2-Country | country HU; registered_country US | 1700000000 |
| country-next.mmdb | GeoLite2-Country | country DE | 1700086400 |
| registered-only.mmdb | GeoLite2-Country | registered_country US, no country | 1700000000 |
| asn.mmdb | GeoLite2-ASN | ASN 64512; organization Synthetic Network | 1700000000 |
| wrong-type.mmdb | GeoIP2-City | country HU | 1700000000 |

Schema identifiers are present solely for SDK compatibility tests; these files
are not MaxMind database products. Special-use keys are tested via LocalDatabase;
the public lookup guard is not weakened. Corruption tests read README.md as MMDB.

Exact fixture paths are exempted from .gitignore. All tests, including fixtures,
are intentionally excluded from distribution archives by .gitattributes; no
production MMDB path is exempted. Source checkouts used by CI retain the fixtures.

## V2 archive fixtures

`update-archives.php` is original MIT test code (revision 1) that generates
deterministic USTAR headers, gzip archives and PAX records in memory from the
same synthetic MMDB files. Tests materialize them only in private temporary
directories. No downloaded MaxMind archive or third-party data is included.
Corruption, traversal, link/special entries and size-limit cases are generated
explicitly by the tests. No binary tar.gz fixture needs to be tracked.
