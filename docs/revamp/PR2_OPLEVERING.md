# PR 2 — Dashboard

Branch: `claude/serverscope-design-overhaul-a4kfxq`, opnieuw gestart op `main` na
[Amir03033/ServerScope#9](https://github.com/Amir03033/ServerScope/pull/9) (PR 1).
Plan: [`docs/REVAMP_PLAN.md`](../REVAMP_PLAN.md) §4 (Dashboard) en §8.

## Wat er veranderd is

### Indeling
1. **"Aandacht nodig"** staat bovenaan. Alleen wat niet in orde is, verschijnt, en elk item is een
   link:
   - open incidenten (rood bij kritiek, met het aantal kritiek en niet bevestigd) → Incidenten;
   - offline servers → Servers, gefilterd op offline;
   - actieve alerts → Alerts;
   - wat binnen 30 dagen verloopt (certificaten en domeinen; rood onder 8 dagen) → de lijst
     "Verloopt binnenkort" op dezelfde pagina;
   - servers met een oudere agent, met "nieuwste versie X · N klaar om bij te werken" → Servers.

   Is er niets, dan staat er één rustige regel: **"Alles in orde"**.
2. **Aan de slag**: zolang er geen servers zijn, staan er drie stappen (Server toevoegen, Domein
   toevoegen, Meldingen instellen), elk met een link. Dit is de goedgekeurde lege toestand.
3. **Kerncijfers** in grijs. Kleur verschijnt alleen als een getal ergens op wijst: offline,
   problemen, kritieke incidenten of uptime onder 99%. Op mobiel staan ze in twee kolommen.
4. Actieve incidenten, Verloopt binnenkort en Recente DNS-wijzigingen; daaronder de responstijd en
   de uptime per dag.
5. **Serverprestaties**:
   - de periodekeuze staat nu bij deze sectie (daar hoort hij bij) in plaats van in de paginakop;
   - de kerncijfers hebben sparklines in het accent;
   - de trendlabels zijn grijs en hebben een uitleg in de tooltip ("37% hoger dan het gemiddelde");
   - de drie grafieken in de themakleuren;
   - systeeminformatie, schijf (donut en balken als SVG) en netwerk.
6. **Servers**: een compacte tabel (server, status, laatste contact, agent, CPU, RAM, met balken)
   in plaats van grote kaarten. Op mobiel wordt elke rij een kaart.
7. **Recente metingen** zijn ingeklapt (`<details>`). De tabel is met het toetsenbord te scrollen
   (`tabindex`, `role="region"`). Dat was een openstaand axe-punt uit PR 1.

### Techniek
- **Geen** page-`<style>` (ruim 180 regels), **geen** inline styles en **geen** inline scripts
  meer op het dashboard:
  - de grafiekdata staat in `<script type="application/json" id="chart-data">`;
  - de vernieuwknop is een gewone link naar dezelfde URL, met de periode erin;
  - de periodekeuze verzendt zichzelf via `data-auto-submit`, en zonder JS is er een knop;
  - breedtes van balken en de donut zijn SVG-attributen.
- Gedeelde componenten `x-status`, `x-severity` en `x-empty-state` gebruiken nu de nieuwe stijl,
  met dezelfde props. Dit is ook op de andere schermen al te zien.
- Nieuwe componentklassen in `app.css`: `ss-panel`, `ss-stat`, `ss-attention`, `ss-row`,
  `ss-status`, `ss-bar`, `ss-donut`, `ss-spark`, `ss-strip`, `ss-dl`, `ss-empty`,
  `ss-table-cards` en `ss-details`.
- **Gevonden en opgelost via axe**: de oude regel `a { color: inherit }` in `serverscope.css`
  stond buiten de CSS-lagen en won daardoor van `.ss-btn-primary`. Primaire knoppen die een link
  zijn (zoals "Server toevoegen") hadden zo te weinig contrast. De regel staat nu in de `base`-laag.
  Dat verbetert alle schermen.

### Gegevens (goedgekeurde uitzondering)
`DashboardController` geeft één extra telling door als weergavedata: `agentUpdates`
(`version`, `outdated`, `ready`).
- Hij gebruikt dezelfde regel als "Alles bijwerken" op de serverspagina: ouder dan de nieuwste
  ondertekende release, en klaar als `AgentUpdates::blocker()` geen bezwaar heeft.
- Hij telt alleen de servers die de controller al laadt, dus alleen van het eigen team.
- Er komen geen extra queries per server bij.

### Taal
- Alle teksten van het dashboard gaan via `lang/{en,nl}/dashboard.php`. **De Nederlandse teksten
  zijn exact gelijk aan voorheen.** Nieuw zijn alleen de teksten van "Aandacht nodig" en
  "Aan de slag".
- Getallen en datums volgen de taal: in het Nederlands `52,2%` en `08-11-2026`, in het Engels
  `52.2%` en `8 Nov 2026`.
- De goedgekeurde aanpassing in `DashboardTest`: `'42.5%'`/`'50.0%'` → `'42,5%'`/`'50,0%'`.
- In de Engelse versie blijven de opgeslagen incidenttitels Nederlands (bekend, `docs/AUDIT.md` §8).

## Schermafbeeldingen vóór en ná

"Vóór" is `main` na PR 1, dus de nieuwe schil met het oude dashboard.

| | Beeld |
|---|---|
| Desktop, hele pagina: vóór licht en donker · ná licht NL, donker NL, licht EN | [dashboard-desktop.jpg](pr2/dashboard-desktop.jpg) |
| Mobiel, hele pagina: vóór licht en donker · ná licht en donker in NL en EN | [dashboard-mobiel.jpg](pr2/dashboard-mobiel.jpg) |
| Bovenkant ná (licht NL), op ware grootte | [dashboard-boven.jpg](pr2/dashboard-boven.jpg) |
| Leeg dashboard ("Alles in orde" en "Aan de slag"), desktop | [dashboard-leeg-desktop.jpg](pr2/dashboard-leeg-desktop.jpg) |
| Leeg dashboard, mobiel | [dashboard-leeg-mobiel.jpg](pr2/dashboard-leeg-mobiel.jpg) |

Paginalengte met dezelfde demodata:
- desktop: 3.268 → 2.556 px (−22%);
- mobiel: 6.692 → 6.257 px (−7%). Op mobiel staan de grafieken nog onder elkaar.

## Tests

| | Vóór (main na PR 1) | Ná |
|---|---|---|
| PHPUnit | 565 tests: 562 geslaagd, 2 overgeslagen, 1 fout | **570 tests: 567 geslaagd, 2 overgeslagen, 1 fout** |
| pytest | 43 geslaagd | 43 geslaagd |

De enige fout is de bekende `TlsInspectorTest::test_expired_certificate`.

Vijf nieuwe tests in `DashboardAttentionTest`:
- oudere agents worden **alleen voor het eigen team** geteld (team B heeft er meer, en die tellen
  niet mee voor A, ook niet in de HTML); zonder updateprogramma telt een server wel als "ouder" maar
  niet als "klaar";
- zonder release of zonder oudere agents verschijnt het item niet;
- de links in "Aandacht nodig" wijzen naar de gefilterde lijsten;
- "Alles in orde" en "Aan de slag" bij een leeg team;
- het dashboard is standaard Engels, zonder Nederlandse resten in de vaste teksten.

`TranslationKeysTest` controleert dat elke nieuwe sleutel in `en` en `nl` bestaat.

## Controle in de browser (Chromium via Playwright)
- **Dashboard**, in licht en donker × desktop (1440) en mobiel (390) × NL en EN, met data en leeg:
  HTTP 200, 0 px horizontale overflow en geen JavaScript-fouten.
- **Formulieren en links tegenover `main`**: niets is verdwenen. Erbij gekomen zijn
  `/servers?status=offline` en `#expiring`, uit "Aandacht nodig". Op **alle 46 andere schermen**
  zijn de formulieren en links gelijk gebleven, en ook daar overal HTTP 200 en 0 px overflow.
- **Klikscenario (alles OK)**:
  - de grafieken worden getekend en ook na een themawissel opnieuw;
  - de periodekeuze verzendt en blijft gekozen;
  - vernieuwen behoudt de periode;
  - alle links in "Aandacht nodig" geven 200, en het anker `#expiring` werkt;
  - de recente metingen klappen uit;
  - alle serverlinks in de tabel geven 200.
- **CLS**: 0,0000 in alle 8 combinaties.

## Toegankelijkheid
- axe-core 4.10 (WCAG 2.1 A/AA) op het dashboard: licht en donker × desktop en mobiel × met data en
  leeg (8 controles), met de metingen uitgeklapt. **Geen fouten.**
- Opgelost: de tabel met metingen is met het toetsenbord te scrollen, en primaire knoppen die een
  link zijn hebben weer genoeg contrast.
- Status heeft altijd tekst naast de kleur. De grafieken hebben `role="img"` met een label. De
  sparklines en balken zijn decoratief (`aria-hidden`), omdat de waarde er als tekst naast staat.

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
   - het dashboard in licht en donker, en in EN en NL;
   - de periode wisselen bij Serverprestaties;
   - "Recente metingen" uitklappen;
   - een item in "Aandacht nodig" aanklikken;
   - op een telefoon: de kerncijfers in twee kolommen en de servertabel als kaarten;
   - als staging een agent heeft die ouder is dan de nieuwste release: het item "servers met een
     oudere agent".
6. **Terug naar main**: zet in Plesk de branch op `main`, kies Pull updates en herhaal stap 2 en 3.
   Er is geen rollback nodig.

`public/build` zit in git; op de server is geen `npm` nodig.
