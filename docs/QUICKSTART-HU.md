# Magyar quickstart

A V2 csomag helyi Country/ASN adatokat és megfigyelési szabálytalálatokat ad.
Nincs automatikus tiltás, regisztrációs döntés vagy telemetria. Hálózati letöltést
csak a kifejezetten indított frissítés végez.

1. Laravel 12 vagy 13 alkalmazásban, PHP 8.4/8.5 mellett a publikált 2.1-es kiadás
   telepítése: `composer require trianity/laravel-ip-analyzer:^2.1`.
   Helyi forráskódból történő fejlesztéshez használd a
   [README helyi fejlesztői telepítését](../README.md#local-development-installation).
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
A két külön adatbázis cseréje nem közös tranzakció. A V2 frissítőparancsa ugyanezt a fájlonként atomikus cserét végzi.

A saját kód MIT; a letöltött adatbázis és a függőségek licence külön kezelendő.
A stale-küszöb nem licencgarancia. Részletek és pontos IP-kategóriák a README-ben.


## Első letöltés és későbbi frissítés

Lépj be a [MaxMind-fiókodba](https://www.maxmind.com/en/account/sign-in).
Az adatokat innen szerezheted be:

- **AccountID:** az Account Information oldalon található
  ([hivatalos útmutató](https://support.maxmind.com/knowledge-base/articles/find-your-maxmind-account-id)).
- **LicenseKey:** a [License Keys oldalon](https://www.maxmind.com/en/accounts/current/license-key)
  hozz létre külön kulcsot
  ([útmutató](https://support.maxmind.com/knowledge-base/articles/generate-a-maxmind-license-key));
  ez nem a fiók belépési jelszava.
- **EditionIDs:** az elérhető adatbázisokat és a Get Permalink(s) hivatkozásokat a
  [Download Databases oldalon](https://www.maxmind.com/en/accounts/current/geoip/downloads)
  találod. A csomag a `GeoLite2-Country` és `GeoLite2-ASN` kiadásokat támogatja.

Az értékeket a **csomagot használó Laravel host alkalmazás `.env` fájljában**
(vagy a telepítési környezet változóiban) add meg, ne a csomag vagy a `vendor`
könyvtárában. Az alábbiak kizárólag helyőrzők:

```dotenv
IP_ANALYZER_MAXMIND_ACCOUNT_ID=YOUR_ACCOUNT_ID
IP_ANALYZER_MAXMIND_LICENSE_KEY=YOUR_LICENSE_KEY
IP_ANALYZER_UPDATE_SCHEDULE=false
```

A host alkalmazás `config/ip-analyzer.php` fájljának `update` része olvassa ezeket:

```php
'update' => [
    'account_id' => env('IP_ANALYZER_MAXMIND_ACCOUNT_ID'),
    'license_key' => env('IP_ANALYZER_MAXMIND_LICENSE_KEY'),
    'schedule_enabled' => env('IP_ANALYZER_UPDATE_SCHEDULE', false),
],
```

Ez csak a konfiguráció megfelelő részlete; a meglévő útvonalakat, szabályokat és
frissítési beállításokat tartsd meg. Ha a fájl még nem létezik, publikáld:
`php artisan vendor:publish --tag=ip-analyzer-config`.
Cache-elt konfigurációnál a módosítás után futtasd: `php artisan config:cache`.

A MaxMind által kínált `GeoIP.conf` a különálló `geoipupdate` programhoz tartozik.
Ez a csomag nem olvassa azt és nem igényli a program telepítését: az ottani
`AccountID` és `LicenseKey` értékét kell átvezetni a fenti `.env` változókba.
Nincs külön `EditionIDs` env/config beállítás: alapból Country és ASN frissül,
vagy választhatsz a `--database=country` / `--database=asn` kapcsolókkal.
A mintában szereplő **GeoLite2-City nem támogatott**.

PHP cURL és zlib szükséges. A célkönyvtár a webgyökéren kívül legyen, az updater
felhasználója írhassa; a letöltött fájlok 0600, a privát munkakönyvtárak 0700
jogosultságot kapnak. A lookupot lehetőleg ugyanazzal az OS-felhasználóval futtasd.
A config cache-t az új env értékekkel építsd újra, majd a hosszú életű folyamatokat
töltsd újra. A Laravel konfigurációs cache hitelesítő adatokat is tartalmazhat:
ugyanúgy védd, mint az alkalmazás többi titkos konfigurációját.

```sh
php artisan ip-data:update --check --json
php artisan ip-data:update
php artisan ip-data:update --database=asn --json
```

A 2.1-es verzió human módban alapból jelzi a munkafázist, az aktuális Country/ASN
adatbázist és az eltelt időt. A teljes helyi integritásvizsgálat `--check` mellett
is több percig tarthat. A feldolgozott CIDR-tartományok száma valós számláló;
ismert teljes darabszám nélkül százalék és ETA nem jelenik meg. A hash és az ismert
méretű letöltés bájtalapú százalékot és elegendő minta után simított fázis-ETA-t ad.

- `--no-progress`: csak a végső human eredmény és teljes futási idő.
- `--json`: egyetlen végső JSON a STDOUT-on, folyamatjelzés nélkül.
- `--json --progress`: a folyamatjelzés és időösszegzés kizárólag STDERR-re kerül.
- `--quiet`: minden kimenetet elnyom, a kifejezetten kért progress-t is.

A `--no-progress` elsőbbséget élvez a `--progress` kapcsolóval szemben.
Nem TTY kimenetnél ritkított, külön soros jelzés készül, ANSI vezérlés nélkül.
Opcionális PCNTL-támogatással a Ctrl-C ellenőrzött megszakítást kér: 130-as kilépés,
reader/staging/lock takarítás. Blokkoló hívásnál ez a következő ellenőrzési pontig
várhat; a megkezdett atomikus telepítés és state-mentés befejeződik. A korábban már
telepített adatbázist nem vonja vissza. Megszakítás után ellenőrizd a státuszt;
SIGKILL-re nincs takarítási garancia. Új kötelező PHP-extension nem szükséges.

A `--check` csak helyi vizsgálatot és HEAD-et végez. A `--force` új GET-et kérhet,
de nem kapcsolja ki a validációt, a régebbi build tiltását vagy a cooldown-t.
A korábbi 1.x konfiguráció megtartható; az új opciók alapértékeket kapnak.
Ne írd felül ellenőrizetlenül a publisholt fájlt, mert abban saját szabályok lehetnek.

Az ütemezés alkalmazásoldali opt-in. Laravel `routes/console.php` példa:

```php
use Illuminate\Support\Facades\Schedule;

if (config('ip-analyzer.update.schedule_enabled', false)) {
    Schedule::command('ip-data:update')->everySixHours()->withoutOverlapping();
}
```

A csomag önmagában nem regisztrál ütemezett feladatot. A fenti kód és az
`IP_ANALYZER_UPDATE_SCHEDULE=true` együtt engedélyezi az alkalmazás normál
schedulerében; nincs párhuzamos automatikus regisztráció.

Updater kilépési kódok: 0 minden vizsgálat/művelet sikerült; 1 hiba vagy részleges
frissítés; 2 hibás input/config vagy hiányzó credential; 3 foglalt lock, telepítés
nem történt. A sikeres Country-t nem vonja vissza egy ASN-hiba.
A `partialFailure` és az egyes eredmények `installed` mezője mutatja a tényleges helyzetet.

Kifelé HTTPS/443 és DNS kell a MaxMind download hosthoz és a dokumentált R2 hosthoz.
Az updater állapota, lockja, notice-ai és stagingje a cél mellett, egy
`.ip-analyzer-<célhash>` privát könyvtárban található. State/notice-mentési hiba
után a jelölt már lehet telepítve: az eredmény ezt külön jelzi, a következő
futtatás újraellenőriz. Ne töröld a lockfájlt aktív frissítés közben.
A részletes limitek, források, helyreállítás és korlátok: [UPDATING.md](UPDATING.md).

## Angol és magyar lokalizáció (2.1.1)

A csomag saját human szövegei az alkalmazás aktuális Laravel locale-ját követik:
`en` esetén angolul, `hu` esetén magyarul jelennek meg. Futás közbeni locale-váltás
után a következő megjelenítés az új nyelvet használja. A csomag nem módosítja az
alkalmazás locale- vagy fallback-beállításait.

A feloldás kulcsonként az aktuális nyelvben kezdődik, az alkalmazás felülírásait is
figyelembe véve. Hiányzó kulcs vagy nem támogatott locale esetén mindig **angol**
a fallback, akkor is, ha az alkalmazás fallback_locale értéke `hu` vagy `de`.
Nincs automatikus `hu_HU` → `hu` megfeleltetés. Az adott regionális locale-hoz
az alkalmazás saját fordítást adhat; hiányzó kulcsai angolra esnek vissza.

Publikálás nélkül is működik. Opcionális parancs:

```sh
php artisan vendor:publish --tag=ip-analyzer-translations
```

A cél a host alkalmazás `lang_path('vendor/ip-analyzer')` könyvtára.
Részleges felülírás példája a `lang/vendor/ip-analyzer/hu/messages.php` fájlban
(a ténylegesen beállított Laravel nyelvi könyvtár alatt):

```php
<?php

return [
    'progress' => [
        'start' => 'Az adatbázisok ellenőrzése elindult; ez több percig tarthat.',
    ],
];
```

A többi kulcs csomagfordítása megmarad. Az `en/messages.php` alkalmazásfelülírása
az angol fallback esetén is érvényesül. A helyőrzőket tartsd meg: például
`progress.elapsed` esetén `:elapsed`; `progress.summary` esetén `:status` és
`:elapsed`. A számlálók Laravel-pluralizációt használnak.

A human státuszfeliratok, hibák és figyelmeztetések lokalizáltak. A gépi kódok és
a saját szabályaid szabad szöveges üzenetei nem változnak. A `--json` kimenet teljes
egészében locale-független, a meglévő `message` mezőkkel együtt. `--json --progress`
esetén kizárólag a STDERR-re kerülő human folyamatjelzés lokalizált.
