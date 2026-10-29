# Fase 3: ondertekende agentreleases en updates via het dashboard (plan)

Status: **plan, wacht op akkoord**. Nog geen code. Branch
`claude/admiring-darwin-oiqti5`.

## 0. Baseline (gemeten in deze sessie)

- `composer install`: geslaagd (geen nieuwe pakketten).
- `php artisan test`: **483 tests, 482 geslaagd, 1 gefaald**: de bekende
  `TlsInspectorTest::test_expired_certificate` (`handshake_failed` i.p.v.
  `expired`). Die blijft ongemoeid.
- `python -m pytest -q`: **39 geslaagd**.
- `shellcheck resources/agent/install.sh`: schoon.
- Laravel Boost (`CLAUDE.md`) heb ik **niet** geïnstalleerd: dat is een
  nieuw Composer-pakket en valt onder harde regel 6.

## 1. Ondertekeningsmethode: Ed25519 met OpenSSL (geen minisign)

**Keuze**: een gewone Ed25519-handtekening (64 bytes) over het manifest,
gemaakt en gecontroleerd met de `openssl`-opdracht (OpenSSL 3).

**Waarom niet minisign**: minisign zit **niet** in Ubuntu 22.04 (jammy),
ook niet in universe. Daarmee valt het af. Gemeten in de containers:

| Systeem | `openssl` (pakketbron) | Ed25519 verifiëren | minisign |
|---|---|---|---|
| Ubuntu 22.04 | 3.0.2-0ubuntu1.30 (**main**) | goed geaccepteerd, gewijzigd geweigerd | **niet beschikbaar** |
| Ubuntu 24.04 | 3.0.13-0ubuntu3.16 (main) | goed geaccepteerd, gewijzigd geweigerd | 0.11 (universe) |
| Debian 12 | 3.0.22-1~deb12u2 (main) | goed geaccepteerd, gewijzigd geweigerd | 0.11 |

Commando dat in alle drie werkte:
`openssl pkeyutl -verify -pubin -inkey release.pub -rawin -in manifest -sigfile manifest.sig`

Voordelen: `openssl` staat in **main** (security-ondersteuning van
Canonical/Debian) en staat op vrijwel elke server al; install.sh zet het
in de pakketlijst voor het geval het ontbreekt (de kale docker-images
hebben het niet). Ed25519 is deterministisch en standaard, dus:

- **ServerScope zelf** controleert met PHP `sodium_crypto_sign_verify_detached`
  (ext-sodium, geen Composer-pakket). Getest: PHP-sodium accepteert de
  OpenSSL-handtekening en weigert een gewijzigd manifest.
  → **Vraag 1**: draai op productie en staging
  `/opt/plesk/php/8.4/bin/php -m | grep -i sodium`. Staat er niets, dan
  moet ik een andere route kiezen (PHP's `openssl_verify` kent geen
  Ed25519).
- **Op je Mac**: de ingebouwde `/usr/bin/openssl` is LibreSSL en kan geen
  Ed25519 (voor zover ik weet; dat kan ik hier niet testen). Het script
  vereist daarom **Homebrew `openssl@3`** (`brew install openssl@3`) en
  weigert te draaien met LibreSSL.
- **Privésleutel**: PKCS#8-PEM, versleuteld met een wachtwoordzin
  (`openssl pkcs8 -topk8 -scrypt`, AES-256). Zelfs wie het bestand vindt,
  heeft de wachtwoordzin nodig.
- **Sleutel-ID**: de eerste 16 hex-tekens van de SHA-256 van de publieke
  sleutel (DER). Vingerafdruk voor de README: de volledige SHA-256.

## 2. Manifest, handtekening en bundel

Manifest: platte tekst, één `sleutel=waarde` per regel, vaste volgorde,
eindigt op een newline. Strikt te parsen in Bash én PHP (elke regel moet
exact op een regex passen; onbekende of dubbele sleutels = weigeren).

```
format=serverscope-agent-release-1
product=serverscope-agent
version=0.3.0
filename=serverscope-agent-0.3.0.tar.gz
sha256=<64 hex>
size=<bytes>
released_at=2026-11-02T10:00:00Z
min_updater_version=1
key_id=<16 hex>
```

- `product` en `format` voorkomen dat een handtekening voor iets anders
  hergebruikt kan worden.
- De handtekening (`manifest.sig`, base64 van 64 bytes) dekt het hele
  manifest byte voor byte; het manifest dekt de tarball (sha256 + size).
- `filename` moet exact `serverscope-agent-<version>.tar.gz` zijn.
- **Bundel** om te uploaden: `serverscope-agent-0.3.0.bundle.tar` met
  precies drie bestanden: `manifest`, `manifest.sig`, de tarball.

Tarball (zelfde indeling als nu, plus het updateprogramma):
`serverscope_agent/*.py`, `requirements.txt`, `serverscope-agent.service`,
`updater/serverscope-updater`, `updater/serverscope-updater.service`,
`updater/serverscope-updater.timer`. Gemaakt met
`git archive HEAD:agent … | gzip -n -9` (reproduceerbaar, werkt op macOS).

## 3. Scripts voor jou (`scripts/release/`, draaien op je Mac)

- `keygen.sh <map>`: maakt eenmalig `release-key.pem` (versleuteld, 600)
  en `release.pub`; toont sleutel-ID en vingerafdruk. Weigert een
  bestaande sleutel te overschrijven.
- `build.sh --key <pem> [--out <map>]`: vereist een schone werkmap; leest
  de versie uit `collector.py`; bouwt de tarball, schrijft het manifest,
  ondertekent (vraagt de wachtwoordzin), **controleert de handtekening
  direct opnieuw met `release.pub`**, en maakt de bundel. Print sha256,
  sleutel-ID en het importcommando.
- `verify.sh <bundel> <release.pub>`: losse controle (ook bruikbaar op een
  server).

De echte privésleutel komt nergens in de repository. `.gitignore` krijgt
`*.pem` en `release-key*`; tests maken een sleutelpaar in een tijdelijke
map.

## 4. Server: releases (database, commando's, downloads)

### Migraties (alleen additief, met `down()`, met `Schema::hasTable/hasColumn`-guards)

1. `agent_releases` (platformbreed, géén team_id): `id`, `version`
   (uniek), `version_major/minor/patch` (sorteren), `filename`, `sha256`,
   `size`, `manifest` (exacte bytes), `signature` (base64), `key_id`,
   `released_at`, `min_updater_version`, `imported_by` (user, nullable),
   `withdrawn_at`, `withdrawn_by`, `withdraw_reason`, timestamps.
   De tarball staat in `storage/app/agent-releases/<versie>/` (niet
   publiek), alleen schrijfbaar bij import.
2. `agent_update_requests` (team-gebonden, `BelongsToTeam`): `id`,
   `team_id`, `server_id`, `agent_release_id`, `target_version`, `status`
   (`pending`, `in_progress`, `succeeded`, `rolled_back`, `rejected`,
   `failed`, `expired`), `reason` (vaste codes), `source`
   (`manual`, `bulk`, `auto`), `requested_by` (nullable bij auto),
   `started_at`, `finished_at`, timestamps. Index `(server_id, status)`.
3. `servers` erbij: `auto_update_enabled` (bool, default **false**),
   `updater_version` (nullable), `updater_state` (`enabled`/`disabled`,
   nullable), `updater_last_seen_at` (nullable).

### Artisan-commando's (alleen via de shell, dus alleen de platformbeheerder)

- `serverscope:release:import <bundel> --admin=<e-mail>`: pakt uit in een
  tijdelijke map, controleert: precies drie bestanden, handtekening met
  een van de **vertrouwde publieke sleutels uit de config**, manifest
  strikt, sha256 + size van de tarball, versie nog niet aanwezig, tarball
  bevat alleen toegestane gewone bestanden (geen symlinks, geen `..`,
  geen absolute paden). Pas dan atomisch naar `storage/` en in de tabel.
  `--admin` moet een platformbeheerder zijn (anders weigeren), zodat het
  auditlog weet wie het deed. **Er komt geen webroute voor import.**
- `serverscope:release:withdraw <versie> --admin= --reason=`: wordt niet
  meer aangeboden (niet aan install.sh, niet aan het updateprogramma);
  open verzoeken naar die versie worden `expired`.
- `serverscope:release:list`.

Config (`config/serverscope.php`, sectie `releases`):
`public_keys` = lijst PEM-bestanden, standaard
`resources/agent/release-keys/*.pub` (in de repository, publiek). Meerdere
sleutels mag (rotatie). Tests overschrijven dit met een testsleutel.

### Downloads (publiek, zoals nu; er staat geen geheim in)

- `GET /agent/releases/{versie}/manifest`, `/manifest.sig`,
  `/serverscope-agent-{versie}.tar.gz`: alleen geïmporteerde, niet
  teruggetrokken releases; anders 404.
- **Bestaande URL's** `/agent/serverscope-agent-{v}.tar.gz` en `.sha256`:
  blijven werken voor geïmporteerde versies (zelfde ondertekende tarball,
  sha256 uit het manifest); voor andere versies een duidelijke 404-tekst
  ("Deze versie wordt niet meer aangeboden; download install.sh opnieuw").
- `AgentPackage` (zelf tarballs bouwen) verdwijnt; `/install.sh` krijgt de
  nieuwste release-versie en de publieke sleutel ingevuld. Zonder
  geïmporteerde release of zonder geconfigureerde sleutel geeft
  `/install.sh` een 503 met een duidelijke melding (net als nu bij een
  http-APP_URL). Gevolg: **na het uitrollen werkt koppelen pas weer als je
  de eerste release hebt geïmporteerd** (zie stappenplan).

### Gevonden bestaand probleem (vraag 2)

`config/serverscope.php` heeft de sleutel `'agent'` **twee keer**; de
tweede overschrijft de eerste, dus `latest_version` en `known_checksums`
zijn nu altijd leeg (de melding "verouderd" verschijnt nooit). Ik stel
voor ze samen te voegen en "nieuwste versie" voortaan uit de nieuwste
geïmporteerde release te halen (fallback: de env-waarde). Akkoord?

## 5. Update-API (voor het updateprogramma)

Alle routes: `AuthenticateAgent` + eigen limiter `agent-update`
(30/min per credential, net als `agent-self`). De server komt altijd uit
de credential; een `request_id` van een andere server geeft 404.

- `GET /api/agent/update?installed=0.3.0&updater=1&state=enabled`
  → registreert de check-in (`updater_last_seen_at`, versie, status) en
  antwoordt `{"data":{"update":null}}` of
  `{"data":{"update":{"request_id":12,"version":"0.4.0"}}}`.
  Geen URL's in het antwoord: het updateprogramma bouwt de URL zelf.
  Bij `auto_update_enabled` maakt dit endpoint zelf een `auto`-verzoek
  aan als er een nieuwere release is en die versie voor deze server nog
  niet eerder is mislukt/geweigerd.
- `POST /api/agent/update/report` met `request_id`, `status`
  (`in_progress`, `succeeded`, `rolled_back`, `rejected`, `failed`),
  `reason` (vaste lijst, bv. `bad_signature`, `bad_checksum`,
  `downgrade`, `no_sample`, `updater_too_old`, `bad_manifest`,
  `download_failed`, `disabled_locally`), `installed_version`. Alleen
  geldige overgangen (pending→in_progress→eindstatus). Audit-regel.
- `GET /api/agent/self` krijgt er één veld bij: `agent_version` (alleen
  toevoegen; install.sh 0.2.0 negeert het).
- `POST /api/agent/metrics` blijft **byte voor byte gelijk** (een test
  vergelijkt route, middleware en antwoord met de huidige).

## 6. Het updateprogramma op de server van de klant

Bestanden (geïnstalleerd door install.sh, root:root):
`/usr/local/sbin/serverscope-updater` (Bash, 755),
`/etc/systemd/system/serverscope-updater.service` (Type=oneshot, root) en
`.timer` (`OnBootSec=5min`, `OnUnitActiveSec=5min`,
`RandomizedDelaySec=60`), `/etc/serverscope/release.pub` (root:root 644),
optioneel `/etc/serverscope/release.d/*.pub` (rotatie),
`/var/lib/serverscope-updater/state` (root 600: hoogste ooit
geïnstalleerde versie, lokaal mislukte versies).

### Nieuwe indeling voor atomisch wisselen (de unit van de agent blijft gelijk)

```
/opt/serverscope-agent/releases/0.3.0/serverscope_agent/…
/opt/serverscope-agent/releases/0.4.0/serverscope_agent/…
/opt/serverscope-agent/serverscope_agent -> releases/0.4.0/serverscope_agent   (symlink)
```

Wisselen = nieuwe symlink maken en `mv -T` erover (atomisch). De
agent-unit (`WorkingDirectory`, `ExecStart`, `ReadOnlyPaths`) hoeft niet
te veranderen. Huidige en vorige release blijven staan, oudere worden
opgeruimd.

### Flow per run

1. Lock (`flock`). **Noodknop eerst**: bestaat
   `/etc/serverscope/updates-disabled`, dan logt het "updates lokaal
   uitgeschakeld" en stopt, **zonder enig netwerkverkeer**.
2. Geen marker van install.sh, of geen symlink-indeling → stoppen
   (handmatige installaties worden nooit aangeraakt).
3. URL (`SERVERSCOPE_URL`, alleen https) en credential uit
   `/opt/serverscope-agent/.env` (root-eigendom; de agent kan die niet
   wijzigen). Credential via een header-bestand, nooit op de opdrachtregel.
4. `GET /api/agent/update`. Geen update → klaar. Het antwoord wordt
   strikt gecontroleerd: `request_id` = geheel getal, `version` =
   `^\d+\.\d+\.\d+$`; anders negeren.
5. Meld `in_progress`. Download van **vaste paden** onder de eigen URL:
   manifest, handtekening, tarball (met maximale grootte).
6. Controles in deze volgorde, elke fout = `rejected` met reden en niets
   gewijzigd:
   a. handtekening met de **lokale** sleutel(s) (`openssl pkeyutl`);
   b. manifest strikt (format, product, velden, `version` = aangeboden
      versie, `filename` klopt, `min_updater_version` ≤ eigen versie);
   c. sha256 + size van de tarball;
   d. versie **hoger** dan zowel de geïnstalleerde als de hoogste ooit
      geïnstalleerde versie (geen downgrade, geen herhaling), en niet
      eerder lokaal mislukt.
7. Tarball-inhoud controleren (alleen verwachte gewone bestanden), uitpakken
   in `releases/.staging-<versie>`, `py_compile`-controle, rechten
   root:serverscope, hernoemen naar `releases/<versie>`.
8. Noodknop nogmaals controleren; tijdstip T noteren; symlink wisselen;
   `systemctl restart serverscope-agent`.
9. Wachten (max. `max(180 s, 3 × interval)`) tot `GET /api/agent/self`
   een `last_seen_at` na T én `agent_version` = nieuwe versie geeft, en de
   service actief is.
10. Gelukt → state bijwerken, `succeeded` melden, oude releases opruimen.
    Niet gelukt → symlink terug, herstart, versie als lokaal mislukt
    opslaan, `rolled_back` met reden `no_sample` melden.

Het programma voert nooit iets uit wat de server stuurt: het enige wat
het van de server overneemt is een versienummer en een verzoek-ID, en
het installeert alleen bestanden die door jouw sleutel zijn ondertekend.

Hardening van `serverscope-updater.service` (root, maar ingeperkt):
`ProtectSystem=strict`, `ReadWritePaths=/opt/serverscope-agent
/var/lib/serverscope-updater`, `ProtectHome=true`, `PrivateTmp=true`,
`PrivateDevices=true`, `NoNewPrivileges=true`, `ProtectKernel*`,
`ProtectControlGroups`, `RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6`,
`RestrictSUIDSGID`, `LockPersonality`, `MemoryDenyWriteExecute`,
`CapabilityBoundingSet=CAP_CHOWN CAP_FOWNER CAP_DAC_OVERRIDE`
(`systemctl restart` gaat via D-Bus en blijft werken; dat test ik in een
systemd-container als die hier draait, anders meld ik het als niet getest).
Logs: één regel per stap in journalctl, nooit de credential.

## 7. install.sh

- Haalt de nieuwste ondertekende release op (manifest, sig, tarball),
  controleert de handtekening met de **publieke sleutel die in het script
  staat**, daarna sha256/size, en installeert in de nieuwe indeling.
- Schrijft `/etc/serverscope/release.pub` (root:root 644). Bestaat die al
  en is hij **anders**: stoppen met een waarschuwing (optie
  `--replace-release-key` om bewust te vervangen).
- Bestaande installatie in de oude indeling (0.2.0, echte map
  `serverscope_agent/`): wordt omgezet naar `releases/<versie>` + symlink.
- **De vraag** (bij installatie én bij opnieuw draaien):

  ```
  Automatische updates van de agent
  ServerScope kan deze agent bijwerken wanneer een beheerder van je team
  daarom vraagt (of als je dat per server aanzet). Er worden alleen
  releases geïnstalleerd die zijn ondertekend met de releasesleutel van
  ServerScope; die sleutel wordt nu lokaal vastgelegd. Mislukt een
  update, dan komt de vorige versie terug. Je kunt dit altijd lokaal
  uitzetten met: sudo bash serverscope-install.sh --disable-updates
  Updateprogramma installeren? [J/n]
  ```

  **Voorstel standaard: Ja.** Reden: er gebeurt pas iets na een klik van
  een team-beheerder (of de per-server-instelling, standaard uit), en
  alleen met jouw handtekening. Zonder terminal en zonder vlag: ook Ja,
  met een duidelijke regel in de uitvoer. Vlaggen voor automatisering:
  `--updates=on|off` (of `SERVERSCOPE_UPDATES=on|off`).
  → **Vraag 3**: akkoord met standaard Ja, of liever Nee?
- Nieuwe vlaggen: `--disable-updates` (noodknop: maakt
  `/etc/serverscope/updates-disabled`, zet de timer uit, meldt eenmalig
  "uitgeschakeld" aan ServerScope; het bestand alleen is al genoeg, ook
  als iemand de timer weer aanzet) en `--enable-updates`.
- `--uninstall` verwijdert ook het updateprogramma, de timer, `/etc/serverscope`
  en `/var/lib/serverscope-updater`.
- Handmatige installaties: blijven geweigerd/ongemoeid (zoals nu).
- Bash-agent: niets verandert.

## 8. Dashboard

- **Serverpagina** (iedereen in het team ziet het): geïnstalleerde versie,
  nieuwste beschikbare versie, updateprogramma ("actief", "lokaal
  uitgeschakeld", "niet gevonden — draai install.sh opnieuw", "geen
  contact sinds …"), laatste updateverzoek met status (wachtend, bezig,
  gelukt, mislukt en teruggezet, geweigerd + reden, verlopen), en een
  korte uitleg voor 0.2.0-servers.
- **Alleen team-beheerders** (Gate `admin`): knop **Bijwerken**, schakelaar
  **automatisch bijwerken** (standaard uit). Serverlijst: **Alles
  bijwerken** (alleen servers met actief updateprogramma en een oudere
  versie). Maximaal één open verzoek per server; wachtende verzoeken
  verlopen na 24 uur.
- **Auditlog**: `agent_update.requested` (wie, server, van→naar, bron),
  `agent_update.started/succeeded/rolled_back/rejected/failed`,
  `agent_update.auto_enabled/disabled`, `agent_release.imported/withdrawn`.
- **Platformbeheerder**: pagina **Agentreleases**: releases (versie,
  datum, sleutel-ID, teruggetrokken) en per versie het **aantal** servers
  (alleen aantallen, geen teamgegevens, zoals de bestaande systeemstatus).

## 9. Sleutelbeheer en noodplan (komt in `docs/RELEASE_KEYS.md`)

- Maken op je Mac, privésleutel versleuteld met wachtwoordzin; bewaren
  op **twee** versleutelde USB-sticks (APFS-versleuteld) op twee plekken,
  wachtwoordzin in je wachtwoordmanager. Nooit in iCloud/Dropbox/Git.
- **Lek**: releases stoppen, nieuwe sleutel maken, nieuwe install.sh +
  nieuwe vingerafdruk publiceren; bestaande servers vertrouwen de oude
  sleutel nog, dus eerlijk: dan moeten klanten `install.sh` opnieuw
  draaien met `--replace-release-key` (of het bestand handmatig
  vervangen). Een gelekte sleutel + gehackte server = gevaar; dat staat er
  eerlijk in.
- **Kwijt**: bestaande servers kunnen geen nieuwe releases meer krijgen
  tot ze een nieuwe sleutel hebben (zelfde route).
- **Rotatie (ontwerp, minimaal gebouwd)**: het updateprogramma vertrouwt
  `release.pub` + `release.d/*.pub` en kiest de sleutel op `key_id`; de
  server accepteert een lijst sleutels. Later: een release die met de
  oude sleutel is ondertekend kan een nieuwe sleutel in `release.d/`
  zetten (`min_updater_version=2`). Dat bouw ik nu niet, maar niets in
  het ontwerp blokkeert het.

## 10. Tests

PHPUnit (eerst falend, dan bouwen): import geldig / ongeldige
handtekening / verkeerde sleutel / gewijzigde tarball / gewijzigd manifest
/ dubbele versie / onveilige tarball-inhoud / `--admin` geen
platformbeheerder; geen web-import; withdraw; downloads (alleen
geïmporteerd, oude URL's); team-beheerder eigen server (ok), ander team
(404), lid (403); alles-bijwerken alleen eigen team; auto-update;
update-API alleen eigen credential, ander `request_id` 404, ongeldige
overgangen, rate limit; auditlog; geen credential in logs;
`POST /api/agent/metrics` en `/api/agent/self` (bestaande velden)
ongewijzigd; platformpagina alleen voor `platform`. Plus een test die
`scripts/release/build.sh` met een testsleutel draait en de bundel
importeert.

pytest: bestaande 39 blijven; versie 0.3.0.

shellcheck: install.sh, serverscope-updater, scripts/release/*.sh.

Containers (Ubuntu 22.04, 24.04, Debian 12; uitbreiding van
`scripts/e2e`): verse installatie met handtekeningcontrole; geslaagde
update; geweigerd bij gemanipuleerde tarball, verkeerde sleutel, lagere
versie, gelijke versie; terugzetten als de nieuwe versie geen meting
stuurt; noodknop wint ook als de server een update aanbiedt (en er is
dan geen enkel verzoek naar de server); 0.2.0-installatie krijgt het
updateprogramma door install.sh opnieuw te draaien (en wordt omgezet naar
de nieuwe indeling). Het updateprogramma wordt daar met de hand gestart
(geen systemd in de container); ik probeer daarnaast een
systemd-container voor de timer en de hardening.

## 11. Volgorde van commits (na elke commit de volledige suite)

1. Falende tests voor manifest/verificatie + `ReleaseManifest`,
   `ReleaseVerifier` (sodium) + config `releases`.
2. Migratie + model `agent_releases`, commando's import/withdraw/list.
3. Downloads van ondertekende releases; `AgentPackage` eruit; oude URL's;
   `/install.sh` met sleutel en versie; config-dubbeling opgelost.
4. Migraties `agent_update_requests` + servervelden; update-API + limiter.
5. Dashboard: serverpagina, knoppen, alles bijwerken, auto-update,
   audit; platformpagina releases.
6. `agent/updater/` (script + units) + agent 0.3.0 + shellcheck.
7. install.sh: ondertekende release, indeling, vraag, vlaggen, noodknop.
8. `scripts/release/` + integratietest.
9. Containertest `scripts/e2e/update-test.sh`, gedraaid op de drie images.
10. Docs: `docs/AUDIT.md` §7, `agent/README.md` (incl. vingerafdruk-plek),
    `docs/RELEASE_KEYS.md`; dit planbestand weg of omgezet.

## 12. Vragen voor jou

1. Heeft PHP 8.4 op Plesk `sodium`? (`/opt/plesk/php/8.4/bin/php -m | grep -i sodium`)
2. Mag ik de dubbele `'agent'`-sleutel in de config samenvoegen (zie §4)?
3. Standaard bij de vraag in install.sh: **Ja** (mijn voorstel) of Nee?
4. Akkoord dat de eerste ondertekende release **0.3.0** is (bevat het
   updateprogramma) en dat koppelen na het uitrollen pas werkt zodra je
   die hebt geïmporteerd? De publieke sleutel zet jij na het aanmaken in
   `resources/agent/release-keys/` (ik leg uit hoe); tot dan staat daar
   niets en geeft `/install.sh` een duidelijke 503.
5. Homebrew `openssl@3` op je Mac is akkoord?
