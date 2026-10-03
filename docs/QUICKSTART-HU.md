# Magyar quickstart

A V1 csomag helyi Country/ASN adatokat és megfigyelési szabálytalálatokat ad.
Nincs automatikus tiltás, regisztrációs döntés, letöltés vagy telemetria.

1. Laravel 12 vagy 13 alkalmazásban, PHP 8.4/8.5 mellett vedd fel a csomagot
   Composer path repositoryként; a [README](../README.md) mutatja a helyi telepítést.
2. Publikáld a konfigurációt:
   `php artisan vendor:publish --tag=ip-analyzer-config`.
3. Szerezd be külön, a vonatkozó feltételekkel a MaxMind Country és ASN fájlokat.
   Tedd a publikus webgyökéren kívülre, a PHP-folyamat számára olvasható helyre.
4. Állítsd be az `IP_ANALYZER_COUNTRY_DB` és `IP_ANALYZER_ASN_DB` útvonalakat.
   Ha konfigurációs cache-t használsz, építsd újra.
5. Ellenőrzés: `php artisan ip-data:status --json`.
6. Lekérdezés: `php artisan ip-data:lookup 8.8.8.8 --json`.

```php
$result = app(\Trianity\IpAnalyzer\Contracts\IpAnalyzer::class)
    ->analyze(request()->ip());

$facts = $result->facts;
$matches = $result->matches;
```

A státuszokat mindig ellenőrizd. Az ismeretlen ország/ASN null marad. Az adat nem
botbizonyíték; az üres találati lista nem biztonsági igazolás. A trusted proxy
beállítása és a hiteles kliens-IP kiválasztása az alkalmazás feladata.

A config `rules.ip_cidr`, `rules.asn`, `rules.country` mezői rendezett listák.
Minden elem: `id`, `value`, `reason_code`, `severity`, `message`.
Severity: info, warning vagy high. Saját Rule osztályok neve a `custom_rules`
listába kerül; a container támogatja a konstruktoros függőséginjektálást.

Kilépési kódok: 0 használható vizsgálat; 1 elérhetetlen adatforrás (status esetén
elavult adat is); 2 hibás input/config vagy saját szabályhiba. A találat nem
parancshiba. A lookup elavult adatot is visszaad, `stale=true` jelzéssel.

Frissítéskor az új fájlokat privát staging helyen ellenőrizd: eredet/checksum,
pontos adatbázistípus, build-idő, olvashatóság, szükség esetén ismert rekordok.
Az azonos fájlrendszeren validált fájlt atomikus rename-nel cseréld, ne írd felül
helyben a használatban lévő fájlt. A következő lookup az új fájlt olvassa.
A két külön adatbázis cseréje nem közös tranzakció. Automatizált frissítő nincs a V1-ben.

A saját kód MIT; a letöltött adatbázis és a függőségek licence külön kezelendő.
A stale-küszöb nem licencgarancia. Részletek és pontos IP-kategóriák a README-ben.
