# Laravel IP Analyzer — development skeleton

Provisional Composer name: `trianity/laravel-ip-analyzer`.
Provisional namespace: `Trianity\IpAnalyzer`.
Target: PHP 8.3+ / Laravel 13. MIT for this project's own code.

**This is a skeleton, not a functioning IP analyzer or a publishable release.**
The lookup and analysis implementations intentionally throw `LogicException`.
Provider registration, configuration, interfaces, enums, readonly DTOs, an example
custom rule and provider smoke tests are supplied. Read `docs/CODEX-TDD-PROMPT.md`.

## Fejlesztés indítása

1. Csomagold ki, nyisd meg VS Code-ban. Igény szerint módosítsd a csomagnevet és
   névteret minden kapcsolódó helyen. Nem ellenőriztük a Packagist névfoglaltságot.
2. Futtasd: `composer validate --strict`, majd `composer install`.
3. Add át a Codexnek a `docs/CODEX-TDD-PROMPT.md` teljes tartalmát.
4. A kész implementáció után futtasd a teszteket: `composer test`.
5. Csak a teljes elfogadási lista után készíts kiadást.

Ebben az összeállítási környezetben PHP és Composer nem volt elérhető; a Composer
telepítés, PHP lint és tesztfuttatás nem történt meg. A JSON/XML és ZIP szerkezetét
ellenőriztük. A függőségi megkötéseket az első TDD-feladatban validálni kell.

## Tervezett használat — implementáció után

```php
use Trianity\IpAnalyzer\Contracts\IpAnalyzer;

$result = app(IpAnalyzer::class)->analyze(request()->ip());
// $result->facts->countryCode; $result->facts->asn;
// $result->matches: list<RuleMatch>
```

Publish config: `php artisan vendor:publish --tag=ip-analyzer-config`.
Place manually downloaded MMDB files at the configured paths, outside the public
web root. Runtime lookups use local files only. Installation requires Composer
repositories; database acquisition requires downloads. Neither is a runtime API call.

The consuming application decides OTP, blocking and logging. No automatic middleware,
firewall changes, telemetry, external lookups, reverse DNS or bundled blacklists.
An empty match list does not mean an IP is safe. Country and ASN are facts, not bot proof.
The application is responsible for trusted proxy configuration and source IP selection.

## Licensing and data

The MIT license covers this project's code. MaxMind SDK and database licenses remain
separate. Obtain and maintain GeoLite databases under the current MaxMind terms;
keep required third-party notices. A 30-day operational age warning does not replace
MaxMind's freshness requirements. Production databases and access keys are not bundled.

References:
- https://github.com/maxmind/GeoIP2-php
- https://dev.maxmind.com/geoip/geolite2-free-geolocation-data/
- https://github.com/orchestral/testbench

## Roadmap

V1: offline Country/ASN lookup, configurable/custom rules, CLI diagnostics.
V2: optional automated downloads and validated atomic replacement, separately invoked.
