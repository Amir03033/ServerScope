# PR 3 — Servers

Branch: `claude/serverscope-design-overhaul-a4kfxq`, opnieuw gestart op `main` na
[Amir03033/ServerScope#10](https://github.com/Amir03033/ServerScope/pull/10) (PR 2).
Plan: [`docs/REVAMP_PLAN.md`](../REVAMP_PLAN.md) §4 (Servers) en §8.

## Wat er veranderd is

### Serverlijst (`servers.index`)
- **Filters**: de statusfilters (Alle, Online, Offline, In afwachting, Onderhoud, Verwijderd, met
  tellers) staan in een gesegmenteerde balk. Op mobiel scrolt die zijwaarts.
- **Zoeken, omgeving en sorteren** staan op één rij.
- **Mobiel**: elke rij wordt een kaart met labels, zonder zijwaarts scrollen.
- **Lege toestand** met **"Eerste server toevoegen"** (alleen voor beheerders); "Geen servers
  gevonden" bij filters zonder resultaat.
- "Alles bijwerken naar X" en "Server toevoegen" staan in de kop, met dezelfde bevestigingstekst.

### Serverpagina (`servers.show`)
- **Kop**: naam, hostname en IP, besturingssysteem, agentversie, **laatst gezien**, omgeving en
  status. Daaronder de **vier kerncijfers** (CPU, RAM, load, uptime).
- **Plakkende sectienavigatie**: Overzicht · Prestaties · Schijf & systeem · Services · Alerts &
  incidenten · Agent & updates · Beheer.
  - Het zijn gewone ankers, dus het werkt ook zonder JavaScript.
  - De bestaande ankers **`#metrics` en `#agent-updates`** werken nog. `#credentials` ook; dat staat
    nu binnen Beheer.
  - Een anker landt precies onder de topbalk en de sectiebalk.
  - Tijdens het scrollen markeert `app.js` de actieve sectie; onderaan de pagina is dat de laatste.
- **Overzicht**: de diagnose met agent, integriteit en metingen, plus "Afwijkingen en prognoses"
  als die er zijn.
- **Prestaties**: de periode als gesegmenteerde balk, vier grafieken en de uitgebreide historie.
- **Schijf & systeem**: schijven met balken (oranje vanaf 75%, rood vanaf 90%) en de
  systeeminformatie.
- **Services**: de tabel staat nu over de volle breedte. **De knoppen lopen niet meer over de
  rand**; dat is gemeten (zie de controles hieronder). Op mobiel worden de rijen kaarten.
- **Alerts & incidenten**: recente alerts en afhankelijkheden.
- **Agent & updates**: identiek in gedrag (zie hieronder).
- **Beheer** (alleen voor beheerders) bundelt alles wat je niet zomaar terugdraait: agentcredentials
  (extra token, intrekken, roteren) en, bij een verwijderde server, herstellen en permanent
  verwijderen (met de servernaam ter bevestiging). Bij een verwijderde server verwijst de melding
  bovenaan naar Beheer.

### Fase 2 en 3: exact hetzelfde gedrag
- **Agentupdates**:
  - de knop **"Bijwerken naar X"** heeft dezelfde voorwaarden (`blocker === null` en geen open
    verzoek), dezelfde route en dezelfde bevestigingstekst;
  - "Automatisch bijwerken aan/uit" werkt zoals voorheen (`PUT`, verborgen veld `enabled`);
  - de status van het updateprogramma (actief, lokaal uitgeschakeld, geen contact, niet gevonden)
    met dezelfde uitleg en commando's;
  - "Laatste update" met de status, `van → naar` en de reden.
- **Server toevoegen (koppelen)**:
  - dezelfde formulieren (`POST /enrollment-tokens` met `label`, en intrekken met `DELETE`);
  - dezelfde ontbrekende-https-melding;
  - het commando staat nu in een terminalblok met kopieerknop;
  - drie genummerde stappen (commando, token plakken, wachten op contact);
  - de **live status** pollt hetzelfde endpoint (`enrollment-tokens.status`, elke 3 s) en toont
    dezelfde toestanden, maar vanuit `app.js` in plaats van een inline script.
- `install.sh` is **ongewijzigd**. `AgentInstall::urlProblem()` geeft de download van install.sh
  nog steeds dezelfde Nederlandse tekst; alleen de pagina gebruikt de nieuwe vertaalde variant.
  Een test bewaakt dit.

### Formulieren
"Server toevoegen" (Linux en handmatig), bewerken, onderhoud starten en beëindigen, credentials en
services: **dezelfde velden, namen, waarden en acties**. De standaardwaarde `Primary agent` voor de
credentialnaam is opgeslagen data en blijft gelijk. Ook de verborgen waarde `draait op` in het
component Afhankelijkheden blijft ongewijzigd.

### Techniek
- Geen page-`<style>` (ruim 120 regels op de serverpagina), geen inline styles en geen inline
  scripts meer:
  - het commando kopiëren gaat via `data-copy-from`;
  - de koppelstatus via `data-enroll-status`;
  - de grafiekdata via `<script type="application/json">`.
- Nieuwe stijlklassen: `ss-section-nav`, `ss-steps`, `ss-diagnosis`, `ss-panel-danger`,
  `ss-tabs-scroll`, `ss-count` en `ss-enroll-status`.
- Het component `x-dependencies` is vertaald en in de nieuwe stijl gezet. Het staat ook op de
  domeinpagina.

### Taal
- **Teksten**: alle teksten van de serverschermen, de flash-meldingen van de controllers, de
  serverdiagnose en de redenen waarom een update niet kan, gaan via `lang/{en,nl}/servers.php`.
  **Nederlands ongewijzigd.**
- **Modellabels** (omgeving, status, service, updatestatus en -reden, voorspellingen,
  alertmetrics) gaan via `lang/*/labels.php`. `LabelsTest` houdt het Nederlands exact gelijk aan de
  constanten in de modellen.
- **Getallen, datums en uptime** volgen de taal (`41d 16u` / `41d 16h`).
- Opgeslagen teksten (incidentgebeurtenissen, de reden van een voorspelling) blijven zoals ze zijn
  opgeslagen (`docs/AUDIT.md` §8).
- De typelabels en de reden van een voorgestelde afhankelijkheid komen uit `DependencyGraph`; die
  volgen in PR 5 (afhankelijkheden).

## Schermafbeeldingen vóór en ná

"Vóór" is `main` na PR 2. De beelden zijn per scherm op desktop (1440 px, de hele pagina) en mobiel
(390 px): vóór licht en donker, en ná licht en donker in NL, plus EN.

| Scherm | Desktop | Mobiel |
|---|---|---|
| Serverlijst | [servers-desktop.jpg](pr3/servers-desktop.jpg) | [servers-mobiel.jpg](pr3/servers-mobiel.jpg) |
| Lege serverlijst | [servers-leeg.jpg](pr3/servers-leeg.jpg) | (zelfde beeld) |
| Serverpagina (online, met update) | [server-desktop.jpg](pr3/server-desktop.jpg) | [server-mobiel.jpg](pr3/server-mobiel.jpg) |
| Serverpagina (offline) | [server-offline-desktop.jpg](pr3/server-offline-desktop.jpg) | [server-offline-mobiel.jpg](pr3/server-offline-mobiel.jpg) |
| Server toevoegen | [toevoegen-desktop.jpg](pr3/toevoegen-desktop.jpg) | [toevoegen-mobiel.jpg](pr3/toevoegen-mobiel.jpg) |
| Server toevoegen, met koppeltoken | [toevoegen-token-desktop.jpg](pr3/toevoegen-token-desktop.jpg) | [toevoegen-token-mobiel.jpg](pr3/toevoegen-token-mobiel.jpg) |
| Handmatig registreren | [handmatig-desktop.jpg](pr3/handmatig-desktop.jpg) | [handmatig-mobiel.jpg](pr3/handmatig-mobiel.jpg) |
| Bewerken | [bewerken-desktop.jpg](pr3/bewerken-desktop.jpg) | [bewerken-mobiel.jpg](pr3/bewerken-mobiel.jpg) |
| Bovenkant serverpagina ná, op ware grootte | [server-boven.jpg](pr3/server-boven.jpg) | |
| Bovenkant Server toevoegen ná, op ware grootte | [toevoegen-boven.jpg](pr3/toevoegen-boven.jpg) | |

De serverpagina is ongeveer even lang gebleven (4.067 → 4.163 px op desktop): de winst zit in de
indeling en de navigatie, niet in minder inhoud.

Lokaal stond `SERVERSCOPE_ENROLLMENT_ALLOW_INSECURE_URL` aan (alleen buiten productie toegestaan),
zodat de koppelpagina zonder https te tonen was. Daarom staat er in de beelden een `http://`-commando
zonder `--proto`. In productie is het commando `https` met `--proto '=https' --tlsv1.2`, zoals de
bestaande test controleert.

## Tests

| | Vóór (main na PR 2) | Ná |
|---|---|---|
| PHPUnit | 570 tests: 567 geslaagd, 2 overgeslagen, 1 fout | **577 tests: 574 geslaagd, 2 overgeslagen, 1 fout** |
| pytest | 43 geslaagd | 43 geslaagd |

De enige fout is de bekende `TlsInspectorTest::test_expired_certificate`. Eén keer faalde
`PredictiveMonitoringTest::test_cpu_spike_against_the_baseline_is_reported_with_explanation`. Die
test hangt af van tijd en willekeur in de testdata en raakt geen code van deze PR: los slaagt hij
drie keer achter elkaar, en in de twee volgende volledige runs ook.

Nieuw in **`ServerPagesTest` (6)**:
- elke link in de sectienavigatie wijst naar precies één bestaand anker, in de juiste volgorde;
- Beheer en de gevaarlijke acties (roteren, extra token) staan alleen bij beheerders, en binnen
  Beheer;
- herstellen en permanent verwijderen van een verwijderde server staan binnen Beheer, met
  `confirm_name`;
- de serverschermen zijn standaard Engels (lijst, pagina, toevoegen, bewerken), zonder Nederlandse
  vaste teksten, en de diagnose is vertaald;
- op mobiel heeft elke tabelcel een label;
- `install.sh` houdt zijn Nederlandse melding, terwijl de pagina de taal volgt.

Verder controleert `LabelsTest` nu ook de negen nieuwe labelgroepen tegen de modelconstanten, en
`TranslationKeysTest` dat elke sleutel in `en` en `nl` bestaat.

## Controle in de browser (Chromium via Playwright)

### Pagina's en inventaris
- **Serverlijst, serverpagina (online en offline), toevoegen (met en zonder token), handmatig en
  bewerken**: licht en donker × desktop en mobiel, NL en EN. Overal HTTP 200, 0 px horizontale
  overflow en geen JavaScript-fouten.
- **Formulieren en links tegenover `main`**: op alle 46 schermen is niets verdwenen en niets
  bijgekomen. Bij de controle draaide `main` met een eigen autoloader, zodat "vóór" echt de oude
  code was.

### Klikscenario (60 controles, allemaal OK)
- **Bijwerken**:
  - de knop "Bijwerken naar 0.3.2" is zichtbaar;
  - annuleren in de dialoog vraagt niets aan;
  - bevestigen geeft "Update naar 0.3.2 aangevraagd";
  - "Laatste update: Wachtend 0.3.1 → 0.3.2" verschijnt, en de knop verdwijnt zolang het verzoek
    open is;
  - de status "Updateprogramma actief" klopt;
  - automatisch bijwerken aan en weer uit.
- **Koppeltoken**:
  - aanmaken toont het commando en het token;
  - de live status wordt opgehaald (HTTP 200) en toont "Wachten op agent…";
  - de kopieerknop werkt zonder inline script;
  - intrekken via de bevestiging: de melding "is ingetrokken" verschijnt en er staat één token
    minder open.
- **Sectienavigatie**: "Agent & updates" springt naar `#agent-updates` en wordt gemarkeerd, en
  `#metrics` landt onder de plakkende balken.
- **Periode** 7d.
- **Onderhoud** starten en beëindigen.
- **Services**: uitschakelen, inschakelen, en verwijderen annuleren. De servicetabel blijft binnen
  het paneel (gemeten: 1373 ≤ 1408 px).
- **Credentials**: een extra token (eenmalig getoond), intrekken en roteren.
- **Bewerken** opslaan.
- Geen JavaScript-fouten.

### Prestaties
**CLS**: 0,0000 op alle zes schermen, in licht en donker, op desktop en mobiel.

## Toegankelijkheid
- axe-core 4.10 (WCAG 2.1 A/AA) op de zes serverschermen: licht en donker × desktop en mobiel
  (24 controles). **Geen fouten.**
- De sectienavigatie bestaat uit gewone links en is met Tab te bedienen. De actieve sectie heeft
  `aria-current`.
- De live koppelstatus staat in een `role="status"` met `aria-live="polite"`.
- Status heeft altijd tekst naast de kleur. Balken zijn decoratief, met de waarde als tekst ernaast.

## Op staging zetten (staging.serverscope.nl, SQLite)

Deze PR heeft **geen migratie**.

1. **Plesk → Websites & Domains → staging.serverscope.nl → Git**: zet de branch op
   `claude/serverscope-design-overhaul-a4kfxq` en kies **Pull updates**.
2. In `httpdocs`:
   ```bash
   composer install --no-dev --optimize-autoloader --no-interaction
   ```
3. Caches legen en opbouwen:
   ```bash
   php artisan optimize:clear
   ```
   ```bash
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
4. Eigenaar goed zetten:
   ```bash
   chown -R <systeemgebruiker>:psaserv storage bootstrap/cache database/database.sqlite
   ```
5. Controleren:
   - de serverlijst: de filterbalk, en op een telefoon de kaarten;
   - een serverpagina: de sectienavigatie (ook `…/servers/{id}#agent-updates` direct openen), en de
     knoppen in de servicetabel blijven binnen de rand;
   - **Bijwerken**: op een server met een actief updateprogramma en een oudere agent, "Bijwerken
     naar …" → bevestigen → "Laatste update: Wachtend";
   - **Server toevoegen**: een koppeltoken aanmaken → de live status "Wachten op agent…" → het
     token intrekken;
   - Beheer: een extra token aanmaken en weer intrekken;
   - EN/NL wisselen in het gebruikersmenu.
6. **Terug naar main**: zet in Plesk de branch op `main`, kies Pull updates en herhaal stap 2 en 3.
   Er is geen rollback nodig.

`public/build` zit in git; op de server is geen `npm` nodig.
