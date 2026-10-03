# Third-party licenses and data

The root MIT LICENSE applies to this project's own code and original synthetic
test fixtures. No production MaxMind database is redistributed in this repository
or package archive.

Composer installs dependencies with their own license files; these are not
relicensed by this project. The verified SDK dependency versions are:

- geoip2/geoip2 3.4.0: Apache-2.0, https://github.com/maxmind/GeoIP2-php
- maxmind-db/reader 1.14.0: Apache-2.0, https://github.com/maxmind/MaxMind-DB-Reader-php
- maxmind/web-service-common 0.11.1: Apache-2.0,
  https://github.com/maxmind/web-service-common-php
- Laravel Illuminate components: MIT, https://github.com/laravel/framework

Dependency source and license files remain in their Composer packages. The SDK
includes web-service dependencies, but this package instantiates only its local
GeoIp2\Database\Reader; no WebService client is constructed.

For the complete installed dependency tree (including development tools), run
`composer licenses`. Dependency versions may change independently of this file;
the installed package license files are authoritative.

Downloaded GeoLite/GeoIP databases are separate data products subject to the
applicable MaxMind terms, attribution and maintenance obligations:
https://dev.maxmind.com/geoip/geolite2-free-geolocation-data/

No license key is needed by this package at runtime. Do not commit credentials
or production MMDB data. An operational stale threshold does not replace the
applicable data terms or guarantee freshness/accuracy.

V2 declares the downloader's direct dependencies explicitly: guzzlehttp/guzzle,
guzzlehttp/psr7 and psr/http-message use MIT; maxmind-db/reader uses Apache-2.0.
Guzzle's cURL handler enforces transport timeouts and streams bytes, PSR-7 supplies
request/URI/stream handling, and the low-level reader traverses candidate records.
PHP cURL and zlib extensions provide transport and bounded gzip decoding;
no external archive package or shell extraction tool is used.
Only the explicit updater uses a network client. Local lookup still uses the
GeoIp2 database reader exclusively. Archive LICENSE/COPYRIGHT/README contents
are preserved separately beside the installed data and retain their own terms.
