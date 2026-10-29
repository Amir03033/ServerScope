# Fase 3: stappenplan voor de eigenaar

Van sleutel maken tot live op productie. Eerst het hele traject op
**staging**, pas daarna productie. Achtergrond: `docs/RELEASE_KEYS.md`
(sleutelbeheer en noodplan) en `agent/README.md` (voor klanten).

Afspraken in dit stappenplan:
- `PHP` = `/opt/plesk/php/8.4/bin/php`
- `COMPOSER` = `COMPOSER_ALLOW_SUPERUSER=1 /opt/plesk/php/8.4/bin/php /usr/lib/plesk-9.0/composer.phar`
- `<eigenaar>` = de Plesk-systeemgebruiker van het domein.

## 1. Eenmalig op je MacBook: OpenSSL 3 en de sleutel

1. Installeer OpenSSL 3 en controleer dat het geen LibreSSL is:

   ```bash
   brew install openssl@3
   ```

   ```bash
   /opt/homebrew/opt/openssl@3/bin/openssl version
   ```

   Je moet `OpenSSL 3.…` zien. Op een Intel-Mac is het pad
   `/usr/local/opt/openssl@3/bin/openssl`. Ter vergelijking:
   `/usr/bin/openssl version` toont `LibreSSL …`. Daarmee weigeren de
   scripts te werken; ze stoppen dan met de melding om openssl@3 te
   installeren. Bij elke run tonen ze welke OpenSSL ze gebruiken
   (`==> OpenSSL: /opt/homebrew/opt/openssl@3/bin/openssl (OpenSSL 3…)`).
2. Maak in Schijfhulpprogramma een versleutelde USB-stick
   (APFS (versleuteld)), bijvoorbeeld met de naam `RELEASEKEY`.
3. Haal de code van deze fase binnen en maak de sleutel:

   ```bash
   cd ~/pad/naar/ServerScope && git fetch origin && git checkout claude/admiring-darwin-oiqti5
   ```

   ```bash
   scripts/release/keygen.sh /Volumes/RELEASEKEY
   ```

   Kies een sterke wachtwoordzin. OpenSSL vraagt die twee keer, en daarna
   nog één keer. Het script toont de **sleutel-ID** en de **vingerafdruk**.
4. Zet de wachtwoordzin in je wachtwoordmanager. Kopieer
   `/Volumes/RELEASEKEY/release-key.pem` naar een **tweede** versleutelde
   stick en bewaar die op een andere plek.

## 2. De publieke sleutel aan de repository geven

`release.pub` is openbaar; je mag hem gewoon delen. Twee manieren:

- **Zelf:** het script heeft het commando getoond, bijvoorbeeld:

  ```bash
  cp /Volumes/RELEASEKEY/release.pub resources/agent/release-keys/<sleutel-id>.pub
  ```

  Vul daarna in `agent/README.md`, onder "Release key fingerprint", de
  vingerafdruk in, en commit en push beide op de branch.
- **Via Claude:** plak de inhoud van `release.pub` (de drie regels met
  `BEGIN/END PUBLIC KEY`) in een sessie en vraag om hem als
  `resources/agent/release-keys/<sleutel-id>.pub` toe te voegen, met de
  vingerafdruk in `agent/README.md`.

Controleer zelf dat het klopt. Dit moet de vingerafdruk uit stap 1.3
geven:

```bash
openssl pkey -pubin -in resources/agent/release-keys/<sleutel-id>.pub -outform DER | shasum -a 256
```

## 3. De eerste release bouwen en ondertekenen (op je Mac)

De eerste ondertekende release is **0.3.0** (zit al in de code). Releases
worden alleen gebouwd vanaf een getagde commit die gelijk is aan
`origin/main`. Dat kan dus pas **na het mergen** van deze branch naar
main. Voor staging gebruik je hetzelfde: merge eerst (staging en productie
draaien allebei van main), of bouw de staging-test vanaf main nadat de
PR is gemerged. Zet in dat geval **productie** nog niet live (stap 5)
voordat staging (stap 4) klaar is.

```bash
git checkout main && git pull
```

```bash
git tag agent-v0.3.0 && git push origin agent-v0.3.0
```

```bash
scripts/release/build.sh --key /Volumes/RELEASEKEY/release-key.pem --pub /Volumes/RELEASEKEY/release.pub --out ~/Desktop
```

Je typt de wachtwoordzin. Het script toont de commit, de sha256, de
sleutel-ID en het importcommando. De bundel is dan
`~/Desktop/serverscope-agent-0.3.0.bundle.tar`. Optionele extra controle:

```bash
scripts/release/verify.sh ~/Desktop/serverscope-agent-0.3.0.bundle.tar /Volumes/RELEASEKEY/release.pub
```

Werp de stick daarna uit.

## 4. Staging

1. Code binnenhalen via **Plesk → Git** (staging.serverscope.nl).
2. Als root, in `/var/www/vhosts/serverscope.nl/staging.serverscope.nl`:

   ```bash
   PHP artisan down
   ```

   ```bash
   COMPOSER install --no-dev --optimize-autoloader
   ```

   ```bash
   PHP artisan migrate --force
   ```

   (drie nieuwe tabellen/kolommen, alleen toevoegingen)
3. Upload de bundel (bijvoorbeeld met scp naar `/root/`) en importeer hem
   **vóór** `artisan up`:

   ```bash
   PHP artisan serverscope:release:import /root/serverscope-agent-0.3.0.bundle.tar --admin=<jouw e-mailadres>
   ```

   Je e-mailadres moet van een platformbeheerder zijn. Verwacht: "Release
   0.3.0 geïmporteerd" met dezelfde sha256 en commit als op je Mac.
4. Caches vernieuwen, rechten terugzetten en weer aan:

   ```bash
   PHP artisan config:cache && PHP artisan route:cache && PHP artisan view:cache
   ```

   ```bash
   chown -R <eigenaar>:psacln storage bootstrap/cache vendor
   ```

   ```bash
   PHP artisan up
   ```

5. Open `https://staging.serverscope.nl/install.sh` in je browser. Daarin
   moet `AGENT_VERSION='0.3.0'` staan, plus de vingerafdruk uit stap 1.3
   (`RELEASE_KEY_FINGERPRINT='…'`). Onder **Agentreleases** (menu, als
   platformbeheerder) staat 0.3.0.
6. **Multipass-test** op je Mac:
   - Bestaande testserver met 0.2.0 (uit fase 2): draai op die VM het
     commando van **Servers → Server toevoegen** opnieuw (zonder token;
     het script herkent de koppeling). Beantwoord "Updateprogramma
     installeren? [J/n]" met Enter. Controleer:

     ```bash
     readlink /opt/serverscope-agent/serverscope_agent
     ```

     (moet `releases/0.3.0/serverscope_agent` zijn)

     ```bash
     systemctl list-timers serverscope-updater.timer
     ```

     ```bash
     openssl pkey -pubin -in /etc/serverscope/release.pub -outform DER | sha256sum
     ```

     (moet jouw vingerafdruk zijn)

     In het dashboard staat bij de server versie 0.3.0 en "Updateprogramma
     actief".
   - Een nieuwe VM (`multipass launch 24.04 --name ss-test2`): installeer
     met een nieuw koppeltoken. Het script toont de vingerafdruk;
     vergelijk die.
   - **Een update testen:** verhoog in een aparte commit
     `AGENT_VERSION` naar `0.3.1`, merge naar main, tag `agent-v0.3.1`,
     bouw (stap 3) en importeer op staging. Klik in het dashboard bij de
     VM op **Bijwerken naar 0.3.1**. Binnen ongeveer 5 minuten gaat de
     status van Wachtend via Bezig naar Gelukt. Op de VM:

     ```bash
     journalctl -u serverscope-updater -n 30
     ```

   - **De noodknop testen:**

     ```bash
     sudo bash serverscope-install.sh --disable-updates
     ```

     Daarna staat in het dashboard "lokaal uitgeschakeld". Een nieuwe
     update blijft dan op "Wachtend" staan. `--enable-updates` zet het
     weer aan.
   - Het auditlog toont wie welke update vroeg en het resultaat.

## 5. Productie (pas als staging helemaal goed is)

Dezelfde stappen als 4.1 t/m 4.4, in `/var/www/vhosts/serverscope.nl/httpdocs`:

```bash
PHP artisan down
```

```bash
COMPOSER install --no-dev --optimize-autoloader
```

```bash
PHP artisan migrate --force
```

```bash
PHP artisan serverscope:release:import /root/serverscope-agent-0.3.0.bundle.tar --admin=<jouw e-mailadres>
```

```bash
PHP artisan config:cache && PHP artisan route:cache && PHP artisan view:cache
```

```bash
chown -R <eigenaar>:psacln storage bootstrap/cache vendor
```

```bash
PHP artisan up
```

**Importeer de release vóór `artisan up`.** Zonder geïmporteerde release
weigert install.sh nieuwe installaties, zodat koppelen dan even niet
werkt. Gebruik dezelfde bundel als op staging (zelfde sha256). Als
staging met 0.3.1 is getest: importeer op productie alleen wat je daar
wilt aanbieden.

Bestaande servers (0.2.0) blijven gewoon metingen sturen. Klanten krijgen
het updateprogramma door install.sh één keer opnieuw te draaien; het
dashboard legt dat bij elke server zonder updateprogramma uit.
