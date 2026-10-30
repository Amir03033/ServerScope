# Revamp ServerScope-app — stap A: onderzoek en plan

Status: **wacht op akkoord**. Er is nog geen applicatiecode gewijzigd.
Branch: `claude/serverscope-design-overhaul-a4kfxq`. Niets gaat naar `main`.
Referentie: de vernieuwde info-site (`ServerScope-Info`, `resources/css/app.css`, componenten in `resources/views/components`).

---

## 1. Baseline (gemeten op 30 oktober 2026)

| Controle | Resultaat |
|---|---|
| `composer install` | ok (PHP 8.4.27, Composer 2.8.12) |
| `php artisan test` | **535 tests: 530 geslaagd, 3 mislukt, 2 overgeslagen** |
| `python -m pytest -q` | **43 geslaagd** (pytest in een tijdelijke venv buiten het project) |

De drie mislukte tests:

1. `TlsInspectorTest::test_expired_certificate`: de verwachte fout (`handshake_failed` in plaats van `expired`).
2. `ReleaseScriptsTest::test_a_built_bundle_verifies_and_imports`
3. `ReleaseScriptsTest::test_build_refuses_anything_but_a_clean_tagged_commit_on_origin_main`

   Nummers 2 en 3 zijn **niet verwacht, en ze falen ook al op `main`**. De test zet vast de tag
   `agent-v0.3.0`, maar PR #7 ("Agent 0.3.1: version bump") heeft de agent naar 0.3.1 gezet.
   `build.sh` eist nu `agent-v0.3.1`. Dit heeft niets met de revamp te maken, en volgens regel 3
   en 5 raak ik de agent en deze test niet aan. **Vraag:** wil je dit apart laten oplossen
   (bijvoorbeeld de test de versie uit `agent.py` laten lezen)? Tot die tijd geldt
   "3 bekende fouten" als baseline voor elk pull request.

**Laravel Boost** (`CLAUDE.md`/`AGENTS.md` vragen erom) heb ik niet geïnstalleerd. Dat is een
nieuw Composer-pakket, en dat mag volgens regel 6 pas na je akkoord. De info-site deed hetzelfde.

## 2. Beginsituatie (gecontroleerd)

| Onderdeel | Gemeten |
|---|---|
| Stylesheet | `public/css/serverscope.css`, 409 regels, **77 unieke hex-kleuren**, geladen zonder build |
| Inline styles | **181** `style="…"`-attributen |
| `<style>`-blokken | **11 views**: layouts (app, guest), dashboard, servers/show, alerts/index, alert-rules (index, form, create, edit), status-pages/public, reports/print |
| Inline scripts | layout (bevestiging en laadtoestand bij submit), dashboard (verversen), servers/add (polling van het koppeltoken), websites/pages (bulkselectie), plus één `onchange` in de teamwissel |
| Views | 62 Blade-views, ongeveer 6.900 regels |
| Tailwind en Vite | geïnstalleerd. `resources/css/app.css` is nog het standaardskelet (Instrument Sans). `@vite` staat alleen op het dashboard en de serverpagina, en alleen voor Chart.js. |
| Grafieken | Chart.js 4.5 (3 grafieken op het dashboard, 4 op de serverpagina) en een eigen SVG-component `x-line-chart` (domeinen, overzicht). Kleuren cyaan, paars en groen staan vast in de code. |
| Thema | alleen donker (`color-scheme: dark`), met verlopen in de zijbalk |
| Lettertype | `Inter` staat in de stylesheet, maar wordt niet geladen. Er valt dus terug op een systeemlettertype. |
| Gastschermen | `components/layouts/guest.blade.php` heeft een eigen `<style>` met eigen kleuren (cyaan) |
| CSP (`SecurityHeaders`) | `script-src 'self' 'unsafe-inline'`, `style-src 'self' 'unsafe-inline'`, `font-src 'self' data:`. Zelf gehoste lettertypen en Vite-bestanden mogen dus. Ik voeg **geen** inline code toe en haal de bestaande juist weg. |
| Livewire | wel in `composer.json`, geen componenten. Dat blijft zo. |
| `public/build` | staat in git. Daarover gaat `LayoutTest::test_committed_frontend_build_matches_its_manifest`. |

### Tests die aan HTML hangen

| Test | Wat hij controleert | Gevolg |
|---|---|---|
| `LayoutTest::test_pages_share_the_application_shell` | `css/serverscope.css` in de HTML, plus de teksten "Hoofdnavigatie", "Ingelogd als {e-mail}" en "Uitloggen" | De teksten blijven. **Vraag aan jou (regel 5):** in de laatste PR verdwijnt `serverscope.css`. Mag die ene regel dan controleren op de Vite-build (`build/assets/app-…css`) in plaats van op `css/serverscope.css`? De functie blijft gelijk: elke pagina deelt dezelfde schil. Tot die PR blijft het bestand geladen en verandert de test niet. |
| `IncidentManagementTest:348` | `<span class="nav-count ">2</span>` | Ik houd exact deze markup aan. De test hoeft niet te veranderen. |
| `NotificationsTest:84` | `<span class="nav-count info">1</span>` | idem |
| Overige `assertSee`-tests | Nederlandse teksten, `name="type" value="discord"`, `Website &amp; pagina` | De teksten en veldnamen blijven gelijk. |

## 3. Schermafbeeldingen "vóór"

De beelden staan in [`docs/revamp/voor/`](revamp/voor/), één JPEG per scherm (47 stuks), met
desktop (1440 px) links en mobiel (390 px) rechts. Ze zijn gemaakt met Chromium via Playwright, op
een lokale SQLite met demogegevens: 4 servers, 4 domeinen, 2 incidenten, alerts, een statuspagina
en een webhook. Het seedscript staat buiten de repository. De beelden zijn een paar minuten na het
seeden gemaakt; daardoor staan alle servers als "Offline". Dat komt door de testdata en is geen
fout in de app.

Gemeten: **geen enkele pagina scrolt horizontaal** op 390 px. Tabellen scrollen wel binnen hun
kader, en op mobiel worden ze erg krap (zie `servers.jpg`).

## 4. Overzicht van alle schermen

Wat er nu onhandig is, en wat ik per scherm voorstel. Bij elk scherm geldt verder: dezelfde
velden, knoppen en acties, de nieuwe componenten en geen inline styles meer.

### Schil (alle ingelogde pagina's)

| Nu | Voorstel |
|---|---|
| Op mobiel wordt de zijbalk een horizontale strook waarin je maar 3 van de 16 items ziet | Een topbalk met hamburgerknop die een lade (`<dialog>`) opent met de volledige, gegroepeerde navigatie. Escape sluit de lade, de focus blijft erin en keert daarna terug naar de knop. |
| Het zoekveld krimpt op mobiel tot "Zo" (fout) | Een zoekknop in de topbalk met ⌘K/Ctrl+K (zie §6) |
| Accountregel ("Ingelogd als …", Profiel, Uitloggen) staat los tussen titel en inhoud | Een gebruikersmenu rechtsboven met dezelfde drie onderdelen. De tekst "Ingelogd als …" blijft. |
| Groepskop "BEHEER" #5d7290 op #08111f: **3,85:1**, te laag voor WCAG AA bij 10 px | Tokens van de info-site: muted is 7,4:1, subtle is 5,4:1 of hoger |
| Voettekst in 10 px (4,49:1), veel tekst in 11 px | Minimaal 12 px voor bijschriften, 14 px voor gewone tekst |
| Onderin de zijbalk afgekapt (status, team, docs) | Een eigen voet in de zijbalk: monitoringstatus, teamwissel (zonder inline `onchange`) en Documentatie |
| Paginakop met actieknoppen loopt op mobiel uit over 3 tot 4 regels | Op mobiel komt de hoofdactie vooraan; de overige acties gaan in een "Meer"-menu (`<details>`, werkt ook zonder JS) |

**Navigatiegroepen** (zoals in het Filament-panel). De routes en labels blijven gelijk; alleen de
groepering verandert:

- *(zonder kop)*: Dashboard
- **Monitoring**: Servers · Domeinen · Afhankelijkheden
- **Incidenten**: Incidenten (teller) · Alerts (teller)
- **Rapportage**: Rapporten
- **Beheer** (alleen beheerders): Alertregels · Statuspagina's · Webhooks · Gebruikers · Auditlog · Systeemstatus · Agentreleases (alleen platform)
- **Account**: Meldingen (teller) · Profiel

### Dashboard (`dashboard`)

| Nu | Voorstel |
|---|---|
| Twee overzichten onder elkaar: de infrastructuur-KPI's en daarna nog eens "Serverprestaties" met vier KPI's, drie grafieken, systeeminformatie, schijf, netwerk, alle servers als grote kaarten en een tabel met metingen. Op mobiel is de pagina ruim 6.500 px lang. | **1. "Aandacht nodig"** bovenaan: één rij met open incidenten, offline servers, actieve alerts, wat binnen 30 dagen verloopt (certificaten en domeinen) en beschikbare agentupdates. Elk item is een link. Is alles in orde, dan staat er één rustige regel: "Alles in orde". **2. KPI's** in rustig grijs; kleur alleen als een getal ergens op wijst. **3. Twee kolommen**: actieve incidenten en verloopt binnenkort, daarnaast DNS-wijzigingen en de uptimestrook. **4. Serverprestaties** van de gekozen server, met de serverkeuze bij de sectie. **5. Servers** als compacte tabel in plaats van grote kaarten. **6. Recente metingen** ingeklapt in `<details>`. |
| Pil "Geen server online" naast de titel, kleurrijke KPI's (cyaan, paars, groen, geel) | Status als badge met een stip; alle andere tekst in grijs |
| Ververs- en periodeknoppen naast de titel | Blijven, als secundaire knoppen |

"Beschikbare agentupdates" staat nu niet in de dashboarddata. Zie de vragen in §11.

### Servers

| Scherm | Nu | Voorstel |
|---|---|---|
| `servers.index` | De filters zijn knoppen met tellers en lopen op mobiel over 3 regels. De tabel is op mobiel krap: hostnamen breken af na 4 tekens en de kolommen vallen weg. | Filters als gesegmenteerde balk die horizontaal scrolt. **Op mobiel wordt elke tabelrij een kaart** (alleen CSS, met `data-label`): naam, status, laatste contact, CPU en RAM. Een lege toestand met "Server toevoegen". |
| `servers.show` | Lange pagina. De servicetabel loopt **over de rand van het paneel** (de knoppen Uitschakelen, Meldingen uit en Verwijderen worden afgekapt). KPI's raken het paneel eronder. Veel kleur. | **Kop**: naam, status, omgeving, agentversie, laatst gezien en de acties. Daaronder **4 kerncijfers**. Daarna een **plakkende sectienavigatie**: Overzicht · Prestaties · Schijf & systeem · Services · Alerts & incidenten · Agent & updates · Beheer. Het zijn ankerlinks naar secties; ze werken zonder JS, en de bestaande ankers `#metrics` en `#agent-updates` blijven geldig. Gevaarlijke acties (permanent verwijderen, sleutels intrekken) komen samen in de sectie "Beheer". |
| `servers.add` | Stappen, koppelcommando en polling met een inline script | Een stappenindicator (1 token → 2 commando → 3 wachten op contact), het commando in een terminalblok met kopieerknop (zoals op de info-site) en de polling in een JS-module |
| `servers.create`, `servers.edit` | Formulier in twee kolommen met losse styles | Dezelfde velden, het nieuwe formuliercomponent en een duidelijke gevarenzone onderaan het bewerkscherm |

### Domeinen en pagina's

| Scherm | Voorstel |
|---|---|
| `domains.index` | Tabel die op mobiel kaarten wordt; status, SSL-dagen en registratie als badges; een lege toestand met "Domein toevoegen" |
| `domains.show` | De tabs (Domein / Website & pagina's / Ontdekken) blijven, in de tabstijl van de info-site. Bovenaan 5 kerncijfers in grijs, kleur alleen bij de status. Een sectienavigatie: Beschikbaarheid · Certificaat & registratie · DNS · Incidenten · Controles · Geschiedenis. De uptimestrook en de responsgrafiek in themakleuren. |
| `domains.create`, `domains.edit` (`_form`) | Dezelfde velden, gegroepeerd in kaarten (Adres, Controles, Drempels, Meldingen, Onderhoud) |
| `websites.pages`, `websites.discovery`, `pages.show`, `pages.create`, `pages.edit` | Bulkselectie als JS-module (zonder inline script), een plakkende bulkbalk zodra er iets is geselecteerd, en wijzigingen als diff-kaart. Bij een pagina blijft "goedkeuren" de hoofdactie. |

### Incidenten en alerts

| Scherm | Voorstel |
|---|---|
| `incidents.index` | Tabel/kaarten met ernst en status als badges; filters als gesegmenteerde balk |
| `incidents.show` | Bovenaan een **statusbalk** (Open → Bevestigd → In onderzoek → Opgelost → Gesloten) met de actie die nu kan. Links de tijdlijn als verticale lijn met notitieveld, rechts een vaste kolom met Toewijzing, Ernst, Oplossen/Sluiten en Eerdere incidenten. Op mobiel staan de acties direct onder de statusbalk. |
| `incidents.create` | Nieuw formuliercomponent |
| `alerts.index` | Het eigen `<style>`-blok verdwijnt; alertkaarten in de nieuwe kaartstijl met rood of oranje alleen op de statusbadge |
| `alert-rules.*` | De eigen `<style>`-blokken (4 views) verdwijnen; het formulier krijgt een live voorbeeldzin ("Alert als CPU ≥ 85% gedurende 5 minuten"), met dezelfde velden |

### Rapporten en statuspagina's

| Scherm | Voorstel |
|---|---|
| `reports.index` | Periodekeuze en exportknoppen bovenaan; de tabellen in de nieuwe stijl |
| `reports.print` | **Blijft licht**, voor afdrukken. Gebruikt dezelfde tokens (licht) en Inter. |
| `status-pages.index/create/edit` | Nieuw formuliercomponent; de componentenrijen netjes uitgelijnd |
| `status.show` (openbaar) | Licht en donker volgens het systeem. De `accent_color` van de klant blijft het accent van die pagina, met een contrastcontrole: wordt het contrast te laag, dan valt de tekst terug op het neutrale accent. |

### Beheer, profiel en inloggen

| Scherm | Voorstel |
|---|---|
| `users`, `audit-logs`, `webhooks.*`, `system-health`, `platform.agent-releases`, `dependencies`, `notifications`, `search` | Tabellen, formulieren en badges uit de nieuwe set. Bij `search`: resultaten gegroepeerd per type, met de zoekterm gemarkeerd. |
| `profile.edit`, `api-tokens.index`, 2FA | Kaarten per onderwerp (Account, Wachtwoord, Meldingen, Tweestapsverificatie, API-tokens). Een nieuw token in het bestaande `secret-token`-component met kopieerknop. |
| `login`, `register`, `forgot-password`, `reset-password`, `two-factor-challenge` | De gastlayout wordt opnieuw gebouwd op de tokens, met logo, licht/donker en een themaknop, en zonder het eigen `<style>`-blok |

### Lege toestanden (tekstvoorstel, regel 7)

Bestaande teksten blijven, tenzij je hieronder akkoord geeft:

| Plaats | Nu | Voorstel |
|---|---|---|
| Servers, leeg | "Nog geen servers geregistreerd" | ongewijzigd, met de knop **Server toevoegen** (die is er al) |
| Domeinen, leeg | (huidige tekst) | uitleg in één zin plus de knop **Domein toevoegen** |
| Incidenten, leeg | (huidige tekst) | "Geen incidenten. Zodra een controle faalt, verschijnt het hier." |
| Statuspagina's, Webhooks, Alertregels, leeg | (huidige tekst) | een zin over wat het is plus de knop **… aanmaken** |
| Dashboard zonder servers | huidige tekst | stappenkaart: 1 Server toevoegen · 2 Domein toevoegen · 3 Meldingen instellen |

Bij akkoord zet ik de exacte zinnen per PR in de beschrijving.

## 5. Design tokens en componenten (overgenomen van de info-site)

**Tokens: exact gelijk aan `ServerScope-Info/resources/css/app.css`.**

| Token | Licht | Donker | Contrast |
|---|---|---|---|
| `--ss-bg` | `#fafafa` | `#0a0a0b` | |
| `--ss-surface` / `-2` | `#ffffff` / `#f4f4f5` | `#111113` / `#18181b` | |
| `--ss-line` / `-strong` | `#e4e4e7` / `#d4d4d8` | `#232326` / `#2e2e33` | |
| `--ss-text` | `#18181b` | `#ededef` | |
| `--ss-muted` | `#52525b` | `#a1a1aa` | 7,4:1 / 7,7:1 |
| `--ss-subtle` | `#63636b` | `#8e8e96` | 5,6:1 / 5,4:1 (op surface-2) |
| `--ss-accent` | `#2f5fd0` | `#7aa2f7` | 5,5:1 (5,7:1 met witte tekst) / 7,9:1 |
| `--ss-ok` / `--ss-warn` / `--ss-bad` | `#15803d` / `#b45309` / `#b91c1c` | `#4ade80` / `#fbbf24` / `#f87171` | licht 5,0 / 5,0 / 6,5 op wit |
| Schaduw | `--ss-shadow`, `--ss-shadow-lift` | idem (donker) | |

Verder exact overgenomen: radius (kaart 14 px, knop en invoer 10 px, badge 999 px), knophoogte 2,5 rem,
de overgangen (`.2s ease` en `.25s cubic-bezier(.2,.7,.2,1)`), focusring (2 px accent, offset 2 px),
`::selection` en het blok voor `prefers-reduced-motion`.
Lettertypen: **Inter** en **JetBrains Mono**. Ik kopieer de woff2-bestanden met de OFL-licenties uit
de info-site naar `resources/fonts/`. Er komen geen nieuwe pakketten bij en er gaan geen verzoeken
naar derden.

**Componenten**: CSS-klassen met dezelfde namen als op de info-site, en Blade-componenten in
`resources/views/components/ui/`:

| Component | Bron | Opmerking |
|---|---|---|
| `.ss-btn` (primary/secondary/ghost) + **danger** | info-site + nieuw | danger: tekst en rand in `--ss-bad`, gevuld bij hover |
| `.ss-card`, `.ss-badge`, `.ss-dot`, `.ss-input`, `.ss-kbd`, `.ss-segmented`, `.ss-dialog`, `.ss-terminal`, `.ss-copy`, `.ss-toc` | info-site | ongewijzigd |
| `.ss-table` | info-site (`.ss-prose table`) | plus kaartweergave op mobiel en een rij-hover |
| `.ss-stat` (kerncijfer) | info-site (Filament-statkaart) | grijs; kleur alleen via een statusmodifier |
| `.ss-alert` (succes/fout/waarschuwing/info) | info-site (`.callout`) | vervangt `.flash` |
| `.ss-tabs`, sectienavigatie | info-site (`.ss-toc`, segmented) | |
| `x-status`, `x-severity`, `x-empty-state`, `x-pagination`, `x-secret-token`, `x-line-chart` | bestaand | **zelfde props**, nieuwe stijl |

## 6. Bediening

- **Thema**: systeem (standaard) → licht → donker, onthouden in `localStorage` (`ss-theme`, dezelfde sleutel als de info-site). Er is geen flits bij het laden: een klein **extern** script `public/js/theme-init.js` (blokkerend in `<head>`, zo'n 300 bytes) zet `data-theme` vóór de eerste weergave. Er komt dus geen inline script bij.
- **⌘K / Ctrl+K**: een zoekdialoog zoals op de info-site. Zonder JS blijft het een gewoon formulier naar `GET /search?q=`, dus dezelfde zoekfunctie. Met JS haalt de dialoog terwijl je typt `/search?q=` op en toont hij de resultaten uit die HTML (ik voeg `data-search-result` toe aan `search.blade.php`). De controller verandert niet. Zie de vragen in §11 voor een alternatief.
- **Bevestigingsdialogen**: elk bestaand `data-confirm` (19 plekken) opent een `<dialog>` in de huisstijl, met **dezelfde tekst**. Bij een gevaarlijke actie heet de knop "Verwijderen", in de danger-stijl. Annuleren houdt het verzenden tegen, net als nu. Zonder `<dialog>`-ondersteuning valt het terug op `window.confirm`. De laadtoestand bij het verzenden blijft.
- **Animaties**: overgangen bij hover en focus (0,2 s), inhoud die zacht infadet (alleen `opacity` en `transform`, dus geen layoutsprongen), een statusbadge die vloeiend van kleur wisselt, en een pulserende stip alleen bij "live". Met `prefers-reduced-motion` staat dit allemaal uit.
- **Toegankelijkheid**: een skip-link, landmarks, `aria-current`, zichtbare focus, dialogen met focus-trap en Escape, en bediening met het toetsenbord (ook de lade en de menu's). Elk statuskleurtje heeft ook tekst. Per PR controleer ik met axe-core via Playwright (alleen tijdens het testen: een tijdelijke `npm install --no-save` buiten de repository, zonder wijziging aan `package.json`).

## 7. Tailwind, Vite en `serverscope.css`

1. **`resources/css/app.css`** wordt het echte thema: Tailwind 4 met tokens, `@theme inline` en componenten, net als op de info-site.
2. **JS opgesplitst** in twee Vite-ingangen:
   - `resources/js/app.js`, op elke pagina: thema, mobiele lade, ⌘K, bevestigingen, laadtoestand, teamwissel, kopieerknoppen, bulkselectie en koppel-polling. Alle inline scripts verhuizen hierheen.
   - `resources/js/charts.js`: Chart.js, **alleen** op pagina's met grafieken, zoals nu. De kleuren komen uit de CSS-tokens (`getComputedStyle`). Bij een themawissel of een andere systeeminstelling worden de grafieken opnieuw getekend. Het palet: de hoofdreeks in accent, de tweede en derde reeks in grijstinten, rasterlijnen in `--ss-line` en een tooltip op `--ss-surface`. Statuskleuren worden alleen gebruikt voor storingen.
3. **`serverscope.css` als overgangslaag.** In PR 1 schrijf ik het bestand om, zodat de bestaande klassen (`.panel`, `.button`, `.data-table`, `.kpi`, `.pill-status`, `.flash`, `.form-field` …) de nieuwe tokens gebruiken. Zo zien **ook de schermen die nog niet zijn omgebouwd** er direct goed uit, in licht en in donker. Elke volgende PR bouwt een groep schermen om naar componenten en Tailwind, en haalt de bijbehorende inline styles en `<style>`-blokken weg. De **laatste PR verwijdert `serverscope.css`** (met het akkoord op de LayoutTest-regel uit §2).
4. **Tailwind-preflight** reset knoppen, koppen en lijsten. Daarom staat de overgangslaag ná Tailwind in de `@layer components` en zet hij alles expliciet. In PR 1 maak ik schermafbeeldingen van **alle 47 schermen**, ook van de schermen die nog niet zijn omgebouwd.
5. Na elke wijziging volgen `npm run build` en een commit van `public/build`. De manifest-test bewaakt dat.

## 8. Volgorde van de pull requests

| PR | Inhoud | Schermen volledig omgebouwd |
|---|---|---|
| **1. Basis** | tokens, lettertypen, componenten (CSS en Blade), de nieuwe schil (zijbalk met groepen, topbalk, mobiele lade, gebruikersmenu), thema en themaknop, ⌘K, bevestigingsdialoog, het Chart.js-thema, de overgangslaag `serverscope.css`, `theme-init.js` en de inline scripts van de layout naar `app.js` | geen; alleen de schil |
| **2. Dashboard** | "Aandacht nodig", de nieuwe indeling, de grafieken | `dashboard`, `dashboard/_overview` |
| **3. Servers** | lijst met mobiele kaarten, detail met sectienavigatie, toevoegen met stappen, formulieren | `servers/*` (9 views) |
| **4. Domeinen en pagina's** | | `domains/*`, `websites/*` (10 views) |
| **5. Incidenten en alerts** | statusbalk, tijdlijn, alertregels | `incidents/*`, `alerts`, `alert-rules/*`, `dependencies` |
| **6. Rapporten en statuspagina's** | inclusief de openbare statuspagina en de afdrukversie | `reports/*`, `status-pages/*` |
| **7. Beheer, profiel en inloggen** | gastlayout, profiel, gebruikers, webhooks, audit, systeemstatus, releases, meldingen, zoeken. **Verwijdert `serverscope.css`.** | de rest |

Elk pull request krijgt kleine commits, de volledige testsuite (PHP en pytest), Playwright-beelden
vóór en na (licht en donker × desktop en mobiel), een klikcontrole (elke link geeft geen 404/500,
elk formulier wordt ingediend en geeft de verwachte redirect), axe-core, een controle op
horizontaal scrollen (0 px) en layoutverschuiving (CLS), `npm run build` met de build in git, en een
beschrijving in het Nederlands met de stappen voor staging.

## 9. Verbeteringen die ik zelf voorstel

1. Mobiele tabellen worden kaarten (alleen CSS), in plaats van tabellen waarin je zijwaarts moet scrollen.
2. Een plakkende sectienavigatie op lange detailpagina's, in plaats van verborgen tabs. De inhoud blijft in de HTML, dus tests, ankers en zoeken in de pagina (Ctrl+F) blijven werken.
3. De bestaande fouten in de weergave opgelost: de servicetabel die over de rand loopt, het zoekveld dat op mobiel krimpt tot "Zo", de afgekapte voet van de zijbalk en het te lage contrast.
4. Kopieerknoppen bij commando's, tokens en webhook-secrets.
5. Relatieve tijden ("3 minuten geleden") met de exacte datum en tijd in een `title`/`<time datetime>`.
6. Een `<meta name="theme-color">` per thema en `color-scheme: light dark`.
7. Optioneel, later en apart: zodra er geen inline scripts meer zijn, kan `'unsafe-inline'` uit `script-src` van de CSP. Dat is een wijziging in de middleware, dus alleen na je akkoord.

## 10. Risico's en hoe ik ze afvang

| Risico | Maatregel |
|---|---|
| Een knop, link of formulier verdwijnt ongemerkt | Per PR een geautomatiseerde inventaris: alle `action`-, `href`- en `name`-attributen per scherm vóór en na vergelijken (diff in de PR). Elk verschil verklaar ik. |
| Tailwind-preflight breekt schermen die nog niet zijn omgebouwd | De overgangslaag zet alles expliciet, en in PR 1 maak ik beelden van alle 47 schermen |
| JS-functies (koppel-polling, bulkselectie, verversen, teamwissel) breken bij het verhuizen | Playwright-scenario per functie; zonder JS blijven de formulieren gewoon werken |
| Tests op HTML-structuur | De markup van `nav-count` blijft exact gelijk; de LayoutTest-regel verandert pas na je akkoord, in de laatste PR |
| Grafieken onleesbaar in een van de thema's | Kleuren alleen uit tokens, en per PR beelden in beide thema's |
| Een flits van het verkeerde thema bij het laden | `theme-init.js` blokkerend in `<head>` |
| Layoutsprongen door de lettertypen | `font-display: swap` plus preload van Inter, net als op de info-site; CLS gemeten met Playwright |
| Buildbestanden niet bijgewerkt | De manifest-test, plus een controle in de PR-lijst |
| De openbare statuspagina krijgt een accentkleur van de klant met te weinig contrast | Contrastcontrole met terugval op het neutrale accent |
| Cache op Plesk | Vite-bestandsnamen met hash; `serverscope.css` houdt zijn `?v=filemtime` |

## 11. Vragen aan jou

1. **ReleaseScriptsTest** (2 fouten op `main`): apart laten oplossen, of als bekende fout laten staan?
2. **LayoutTest-regel `css/serverscope.css`**: mag die in de laatste PR controleren op de Vite-build? (De rest van de test blijft gelijk.)
3. **"Beschikbare agentupdates" op het dashboard**: daarvoor moet `DashboardController` één extra telling doorgeven (servers met een verouderde agent, volgens dezelfde logica als `servers/_health`). Dat is puur weergavedata. Akkoord?
4. **⌘K**: akkoord met de aanpak zonder controllerwijziging (de bestaande HTML van `/search` uitlezen)? Het alternatief is een JSON-antwoord in `SearchController` bij `Accept: application/json`; dat is netter, maar het is een controllerwijziging.
5. **Teksten van de lege toestanden** (§4): akkoord met de voorstellen?
6. **Navigatiegroepen** (§4): akkoord met deze indeling?
7. **Staging voor de app**: bestaat er al een staging-subdomein (bijvoorbeeld `staging.serverscope.nl`) dat op deze branch kan draaien? Dan schrijf ik de stappen daarvoor uit, zoals bij de info-site.
8. **Laravel Boost**: overslaan, zoals bij de info-site?
