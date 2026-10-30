# PR 1 — Basis: design, schil, thema, ⌘K en taal

Branch: `claude/serverscope-design-overhaul-a4kfxq` (op basis van `main` met PR #8).
Plan en besluiten: [`docs/REVAMP_PLAN.md`](../REVAMP_PLAN.md) (§8, §11, §12).

## Wat er veranderd is

### Design en schil
- **Design tokens van de info-site**, exact overgenomen: kleuren met contrastwaarden, Inter en
  JetBrains Mono (zelf gehost, met OFL-licenties in `resources/fonts/`), radius, schaduwen en
  overgangen. Er zijn geen nieuwe pakketten bijgekomen.
- **Licht en donker**: standaard volgens de systeeminstelling, met een schakelaar
  (systeem → licht → donker) die in de browser onthouden wordt (`ss-theme`, dezelfde sleutel als op
  de info-site). `public/js/theme-init.js` zet het thema vóór de eerste weergave, dus zonder flits
  en zonder inline script.
- **Nieuwe schil**:
  - een zijbalk met de goedgekeurde groepen (Monitoring, Incidenten, Rapportage, Beheer, Account),
    met tellers in de bestaande markup `<span class="nav-count …">`;
  - een plakkende topbalk;
  - op mobiel een lade die ook zonder JavaScript werkt (`#sidebar`);
  - een gebruikersmenu met "Ingelogd als …", Profiel, de taalwissel en Uitloggen;
  - een skiplink en flash-meldingen in de stijl van de info-site.
- **⌘K / Ctrl+K**: een zoekdialoog op het nieuwe JSON-antwoord van `/search`. Zonder JavaScript zijn
  de knoppen gewone links naar `/search`.
- **Bevestigingsdialogen**: elk bestaand `data-confirm` (19 plekken) opent een dialoog in de
  huisstijl, met **dezelfde tekst**. De knop draagt de tekst van de oorspronkelijke knop (zoals
  "Verwijderen") en is rood bij een DELETE. Annuleren houdt het verzenden tegen. Zonder `<dialog>`
  valt hij terug op `window.confirm`.
- **Geen inline JavaScript meer** in de layout, het secret-token-component en de teamwissel (eerst
  een `onchange`). Alles staat nu in `resources/js/app.js`.
- **Chart.js** heeft een eigen bundel (`charts.js`) en laadt alleen op het dashboard en de
  serverpagina, zoals voorheen. De kleuren komen uit de tokens (hoofdreeks in het accent, de rest
  grijs), en bij een themawissel worden de grafieken opnieuw getekend. Labels, datums en getallen
  volgen de taal.
- **Overgangslaag**: `public/css/serverscope.css` zet de klassen van de nog niet omgebouwde schermen
  om naar de tokens. 173 vaste kleuren in page-styles en inline styles zijn vervangen door tokens.
  Daardoor kloppen **alle** schermen nu al in licht en donker. Statuskleuren worden alleen nog voor
  een status gebruikt; de rest is grijs.
- **Tailwind-preflight staat tijdelijk uit**: de oude schermen leunen op browserstandaarden. Hij
  gaat aan in PR 7, samen met het verwijderen van `serverscope.css`.

### Taal (Engels standaard, Nederlands als keuze)
- Een migratie voegt `users.locale` toe (standaard `en`, niet nullable).
- Middleware `SetLocale` werkt alleen in de `web`-groep. De volgorde is: de voorkeur van de
  gebruiker, dan de keuze in de sessie, op openbare statuspagina's dan de browsertaal, en anders
  Engels.
- `POST /locale` (`locale.update`):
  - alleen `en` of `nl`;
  - CSRF blijft aan;
  - stuurt terug naar de vorige pagina, maar alleen op dezelfde site (zelfde scheme, host en poort,
    of een pad op de site; al het andere gaat naar het dashboard of de inlogpagina);
  - voor gasten in de sessie, voor ingelogde gebruikers ook in `users.locale`;
  - staat in `RouteTenancyTest::PUBLIC_ROUTES`.
- Een taalveld in het profiel. De flash-meldingen van profiel, inloggen, wachtwoord,
  tweestapsverificatie en teamwissel lopen via `__()`.
- Laravels validatie-, inlog-, wachtwoord- en pagineringsteksten bestaan in `en` en `nl`, met
  Nederlandse veldnamen.
- Al vertaald in PR 1:
  - de schil;
  - de gastpagina's (opnieuw opgebouwd);
  - de openbare statuspagina (alleen de teksten; de restyling volgt in PR 6);
  - de gedeelde componenten (status- en ernstlabels, paginering, secret-token, de SVG-grafiek).
- Datum en getal: `Time::date()`, `Time::dateTime()`, `Format::number()` en `Format::percent()`
  volgen de taal. `Time::relative()` gebruikt de actieve taal in plaats van vast `nl`.
- **Cachesleutels met taal**: de statuspagina (`status-page:{id}:{updated_at}:{taal}`),
  `dashboard:trends` en de samenvatting van de systeemstatus. Bewust ongewijzigd, omdat ze geen
  getoonde tekst bevatten: de `Cache::add`-sloten tegen dubbel versturen, de replay-beveiliging van
  2FA, de RDAP-gegevens en de idempotentie van de API.
- `docs/AUDIT.md` §8 noemt het **opslaan van Nederlandse teksten** (incidenttitels, tijdlijn,
  controleberichten) als blokkade vóór externe klanten. Dat is bewust niet gebouwd.

### Zoeken
`SearchController` gebruikt één `search()`-methode voor zowel HTML als JSON
(`Accept: application/json`). Het JSON-antwoord bevat alleen type, titel, subtitel, status,
statuslabel en URL. De HTML-pagina `/search` is ongewijzigd.

### Overig
`ReleaseScriptsTest` faalt nu met "AGENT_VERSION niet gevonden in collector.py" als de versie
ontbreekt.

## Wat bewust nog niet is gedaan
- De schermen zelf (dashboard, servers enzovoort) zijn nog niet omgebouwd en hun teksten nog niet
  vertaald. Dat gebeurt in PR 2 t/m 7. **Tot die PR's gemerged zijn, ziet een Engelse gebruiker een
  Engelse schil met Nederlandse scherminhoud.**
- E-mails, in-app-meldingen, Discord en webhooks blijven Nederlands tot PR 8 (`Severity::label()`
  en de teksten van de berichten zijn ongewijzigd).

## Schermafbeeldingen vóór en ná

Per scherm staan in één beeld: vóór (alleen donker), ná licht NL, ná donker NL en ná licht EN. Boven
staat desktop (1440 px), onder mobiel (390 px).

| Scherm | Beeld |
|---|---|
| Inloggen | [login.jpg](pr1/login.jpg) |
| Dashboard | [dashboard.jpg](pr1/dashboard.jpg) |
| Servers | [servers.jpg](pr1/servers.jpg) |
| Server | [server-show.jpg](pr1/server-show.jpg) |
| Domein | [domain-show.jpg](pr1/domain-show.jpg) |
| Incident | [incident-show.jpg](pr1/incident-show.jpg) |
| Alerts | [alerts.jpg](pr1/alerts.jpg) |
| Profiel (met taalveld) | [profile.jpg](pr1/profile.jpg) |
| Openbare statuspagina | [status-public.jpg](pr1/status-public.jpg) |
| Zoeken | [search.jpg](pr1/search.jpg) |
| Mobiele lade, gebruikersmenu, ⌘K, bevestiging | [interacties.jpg](pr1/interacties.jpg) |

Alle 47 schermen zijn vastgelegd in licht en donker × desktop en mobiel (NL), plus licht EN. De
vóór-beelden staan in [`docs/revamp/voor/`](voor/).

## Tests

| | Vóór (main na PR #8) | Ná |
|---|---|---|
| PHPUnit | 535 tests: 532 geslaagd, 2 overgeslagen, 1 fout | **565 tests: 562 geslaagd, 2 overgeslagen, 1 fout** |
| pytest | 43 geslaagd | 43 geslaagd |

De enige fout is de bekende `TlsInspectorTest::test_expired_certificate`.

30 nieuwe tests:
- **`LocaleTest` (17)**:
  - standaard Engels, met een migratiestandaard van `en`;
  - een gebruiker kiest Nederlands in het profiel en houdt dat na opnieuw inloggen;
  - een ongeldige taal wordt geweigerd;
  - de snelle wissel voor een gebruiker en voor een gast;
  - alleen `en`/`nl`;
  - geen open redirect (vijf varianten, plus een pad dat op een host lijkt);
  - CSRF staat aan;
  - een ongeldige sessiewaarde wordt Engels;
  - validatiemeldingen in beide talen;
  - de statuspagina volgt de browsertaal en **dezelfde pagina eerst in nl en daarna in en geeft
    twee talen**;
  - de cachesleutels bevatten de taal (statuspagina, dashboard, systeemstatus);
  - de sessiekeuze gaat vóór de browsertaal;
  - **API-antwoorden zijn byte-voor-byte gelijk** in `en` en `nl`;
  - datum- en getalnotatie.
- **`SearchJsonTest` (6)**:
  - alleen de toegestane velden;
  - dezelfde resultaten als de HTML;
  - **nooit resultaten van een ander team** (TwoTeams, in beide richtingen);
  - login en throttle;
  - de HTML is ongewijzigd;
  - validatie.
- **`TranslationKeysTest` (4)**:
  - `en` en `nl` hebben dezelfde bestanden en sleutels;
  - de waarden zijn gevuld en de placeholders gelijk;
  - **elke sleutel die in views, `app/` of JS wordt gebruikt, bestaat in beide talen**;
  - de scanner vindt echt sleutels (gecontroleerd: een ontbrekende sleutel laat de test falen).
- **`LabelsTest` (3)**: de Nederlandse labels zijn exact gelijk aan `Severity::LABELS`, aan
  `StatusPagePresenter::STATE_LABELS` en aan de oude statuslabels.

De bestaande tests draaien in het Nederlands via één instelling in `Tests\TestCase`: de taal van de
app, de taal van nieuwe gebruikers en de browsertaal. **Geen enkel bestaand testbestand en geen
enkele verwachte tekst is aangepast.** De uitzonderingen zijn de goedgekeurde regel in
`RouteTenancyTest` en de melding in `ReleaseScriptsTest`.

## Controle in de browser (Chromium via Playwright)
- **47 schermen** × desktop en mobiel × licht en donker (NL), plus licht EN: overal HTTP 200 en
  **0 px horizontale overflow**. De servicetabel op de serverpagina liep voorheen buiten het
  paneel; die scrollt nu binnen het paneel.
- **Inventaris van knoppen, links en formulieren**: `main` en deze branch draaiden naast elkaar op
  dezelfde database, en per pagina zijn alle formulieren (method, action, veldnamen) en links
  vergeleken (46 pagina's). **Er ontbreekt niets.** Erbij gekomen zijn alleen het taalformulier
  (`POST /locale`), het taalveld in het profiel en de link naar `/search` voor de zoekknop.
- **Klikscenario (21 controles, allemaal OK)**:
  - taalwissel als gast en vanuit het gebruikersmenu, terug naar dezelfde pagina;
  - inloggen en uitloggen;
  - het thema wordt onthouden na herladen;
  - Ctrl+K, resultaten, Enter en Escape;
  - API-token aanmaken, tonen, en intrekken met de bevestiging (annuleren en bevestigen);
  - de skiplink staat als eerste in de tabvolgorde;
  - geen JavaScript-fouten.
- **CLS**: 0,0000–0,0006 op dashboard, servers, server, domein, incident, profiel en inloggen,
  desktop en mobiel.

## Toegankelijkheid
- axe-core 4.10 (WCAG 2.1 A/AA, alleen tijdelijk gebruikt, niet in het project) op inloggen,
  wachtwoord vergeten, registreren, de statuspagina, het dashboard, servers, het profiel, zoeken, het
  open gebruikersmenu en de open ⌘K, in licht en donker, desktop en mobiel: 36 controles. In de schil
  en op de gastpagina's zijn **geen fouten**.
- Gevonden en opgelost:
  - een link binnen een zoekresultaat (`nested-interactive`);
  - het contrast van de legenda en de voettekst van de openbare statuspagina;
  - de focus en Escape van de mobiele lade bij `prefers-reduced-motion`.
- Bestond al, staat gepland:
  - het contrast van de accentkleur die de klant op de statuspagina kiest, en de automatische
    verversing (`meta refresh`): PR 6;
  - de tabel op het mobiele dashboard is niet met het toetsenbord scrollbaar: PR 2.
- `prefers-reduced-motion` zet alle animaties en overgangen uit. Statussen hebben altijd tekst naast
  de kleur, en de focusring is 2 px in het accent.

## Op staging zetten (staging.serverscope.nl, SQLite)

1. **Plesk → Websites & Domains → staging.serverscope.nl → Git**: zet de branch op
   `claude/serverscope-design-overhaul-a4kfxq` en kies **Pull updates**. Documentroot
   `/httpdocs/public`, PHP 8.4.
2. In `httpdocs` (SSH of Plesk → PHP Composer):
   ```bash
   composer install --no-dev --optimize-autoloader --no-interaction
   ```
3. De migratie (voegt `users.locale` toe):
   ```bash
   php artisan migrate --force
   ```
4. Caches legen en opbouwen:
   ```bash
   php artisan optimize:clear
   ```
   ```bash
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
5. Eigenaar goed zetten (de systeemgebruiker van het abonnement staat in Plesk → Websites & Domains
   → Hosting & DNS → Hosting Settings):
   ```bash
   chown -R <systeemgebruiker>:psaserv storage bootstrap/cache database/database.sqlite
   ```
6. Controleren:
   - inloggen;
   - schakelen tussen systeem, licht en donker (de knop naast je avatar);
   - EN/NL wisselen in het gebruikersmenu en op de inlogpagina;
   - Ctrl+K;
   - op een telefoon het menu openen;
   - een verwijderactie starten en annuleren;
   - de openbare statuspagina bekijken met de browser in het Nederlands en in het Engels.
7. **Terug naar main**:
   - Wil je de kolom `users.locale` weghalen, draai dan **eerst, nog op deze branch**:
     ```bash
     php artisan migrate:rollback --step=1
     ```
     Dat kan alleen zolang deze migratie de laatste is. De kolom mag ook blijven staan; main gebruikt
     hem niet.
   - Zet daarna in Plesk de branch op `main`, kies Pull updates en herhaal stap 2 en 4.

`public/build` zit in git; op de server is geen `npm` nodig.
