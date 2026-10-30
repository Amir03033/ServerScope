# Eindoplevering — revamp stap 3 t/m 8

Branch: `claude/serverscope-design-overhaul-a4kfxq`, één pull request naar `main` met PR 3
(servers, al goedgekeurd) en stap 4 t/m 8.
Plan en afspraken: [`docs/REVAMP_PLAN.md`](../REVAMP_PLAN.md). Eerdere opleveringen:
[PR 1](PR1_OPLEVERING.md), [PR 2](PR2_OPLEVERING.md), [PR 3](PR3_OPLEVERING.md).

## In het kort

- **Alle schermen** zijn omgebouwd naar de componenten en tokens van de info-site, in licht en
  donker, en zijn tweetalig (Engels standaard, Nederlands als keuze). **Het Nederlands is
  ongewijzigd**, op de goedgekeurde nieuwe teksten na (zie "Tekstwijzigingen").
- **`public/css/serverscope.css` is weg** en de Tailwind-preflight staat weer aan, net als op de
  info-site. Er staan geen `<style>`-blokken, inline styles, inline scripts of inline handlers
  (`onclick`, `onsubmit`, …) meer in de views.
- **Berichten** (e-mail, in-app, Discord, webhooks) gaan in de taal van de ontvanger.
- **Functies en core zijn gelijk gebleven**: dezelfde routes, formulieren, veldnamen, knoppen en
  links. Geen nieuwe pakketten, geen migraties, geen bestaande test versoepeld of overgeslagen.
- Onderweg **drie bestaande fouten** gevonden en in de view-laag opgelost (zie "Gevonden fouten").

## Per stap

### Stap 3 — Servers (al goedgekeurd)
Zie [PR3_OPLEVERING.md](PR3_OPLEVERING.md). Ongewijzigd meegenomen.

### Stap 4 — Domeinen en websitepagina's
- **Domeinlijst**: kerncijfers in grijs (kleur alleen bij een waarde die ergens op wijst),
  statusfilters als gesegmenteerde balk, op mobiel kaarten per rij.
- **Domeinpagina**: kop met vijf kerncijfers en een **plakkende sectienavigatie**
  (Beschikbaarheid · Certificaat & registratie · DNS · Incidenten · Controles · Geschiedenis).
  Het bestaande anker `#checks` (filter op type) werkt nog. DNS-wijzigingen als tijdlijn met
  een diff (`+`/`−`).
- **Domeinformulier**: dezelfde velden, gegroepeerd in kaarten (Adres, Controles, Drempels,
  Meldingen en beschikbaarheidsdoel, Onderhoudsvenster).
- **Website & pagina's**: de bulkselectie is een JS-module in `app.js` (geen inline script meer),
  met een balk die blijft staan zodra er iets is geselecteerd, een teller en de
  bevestigingsdialoog van de huisstijl bij "Verwijderen" (dezelfde tekst als voorheen).
- **Pagina**: inhoudswijzigingen als tijdlijn met een diff-kaart; "Goedkeuren en monitoren"
  blijft de hoofdactie.
- **Ontdekken**: de automatische verversing (was `meta refresh` elke 10 s) is nu het component
  `x-auto-refresh`: pauzeerbaar, standaard uit bij `prefers-reduced-motion`, en zonder
  JavaScript werkt de oude `meta refresh` nog (in `<noscript>`).
- Flash- en validatiemeldingen van `DomainController`, `WebsitePageController`,
  `WebsiteDiscoveryController`, `DomainRequest` en `PageRequest` lopen via `__()`; ook de
  veldnamen in validatiemeldingen (`attributes()`).

### Stap 5 — Incidenten, alerts, alertregels, afhankelijkheden
- **Incident**: bovenaan een **statusbalk** (Open → Bevestigd → In onderzoek → Opgelost →
  Gesloten) met de acties die nu kunnen ernaast; op mobiel direct eronder. Links de samenvatting
  en de **tijdlijn** (verticale lijn, kleur per soort gebeurtenis) met het notitieveld, rechts een
  kolom met Toewijzing, Oplossen, Sluiten, Ernst en Eerdere incidenten.
- **Alerts** en **alertregels**: de eigen `<style>`-blokken (5 views, samen ruim 600 regels) zijn
  weg. Het alertregelformulier toont een **live voorbeeldzin** ("Alert als CPU-gebruik ≥ 85%
  gedurende 5 minuten"); dezelfde velden.
- Verwijderen van een alertregel ging via een inline `onsubmit="return confirm(…)"`; nu via de
  bevestigingsdialoog van de huisstijl, met dezelfde tekst.
- **Afhankelijkheden**: nieuwe stijl; de typelabels en de redenen van voorgestelde relaties
  (`DependencyGraph`) zijn vertaald.
- Duur (`IncidentMetrics::humanDuration`) volgt nu de taal (stond vast op Nederlands).

### Stap 6 — Rapporten, afdrukversie, statuspagina's
- **Rapporten**: periodekeuze en exports bovenaan; tabellen worden op mobiel kaarten. De
  CSV-exports zijn ongewijzigd (ook de kolomkoppen: dat is een exportformaat).
- **Afdrukversie**: altijd licht, op dezelfde tokens en Inter, zonder inline script
  (de knop "Afdrukken / opslaan als PDF" werkt via `app.js`).
- **Openbare statuspagina**: licht en donker volgens het systeem, zonder eigen `<style>`.
  - **Leesbare tekstkleur bij elke accentkleur**: de accentkleur van de klant wordt als
    tekstkleur (links) alleen gebruikt als het contrast minstens 4,5:1 is op het oppervlak van
    dat thema; anders valt de pagina terug op het neutrale accent. Tekst óp de accentkleur
    (de actieve taalknop) is zwart of wit, wat het hoogste contrast geeft. De berekening staat in
    `App\Support\Contrast` (WCAG 2.1, met tests); de kleuren gaan als data-attributen naar de
    pagina en `app.js` zet ze via het CSSOM (geen inline style).
  - In het beheerformulier verschijnt een waarschuwing als de gekozen kleur in licht en/of donker
    te weinig contrast heeft.
  - **Automatisch verversen** (elke minuut) is **pauzeerbaar** en staat **uit bij
    `prefers-reduced-motion`** (de bezoeker kan het met één klik aanzetten). De keuze geldt per
    tabblad. Zonder JavaScript ververst de pagina zoals voorheen.
- **Statuspagina's (beheer)**: onderdelen toevoegen en verwijderen zonder inline script.

### Stap 7 — Beheer, profiel, inloggen
- Gebruikers, webhooks (lijst, formulier, detail), auditlog, systeemstatus, agentreleases,
  meldingen, zoeken, profiel (met tweestapsverificatie) en API-tokens in de nieuwe stijl en
  vertaald. De gastlayout was al in PR 1 omgebouwd.
- **`serverscope.css` verwijderd** en de **Tailwind-preflight aan**. De laatste klassen die
  schermen nog gebruikten (`.actions`, `.ov-row`, `.pagination`) staan nu in `app.css`.
- **`LayoutTest`**: de ene goedgekeurde regel controleert nu op de Vite-build
  (`build/assets/app-….css`) in plaats van op `css/serverscope.css`.
- **Zoeken**: resultaten per type, de zoekterm wordt gemarkeerd (door `app.js`; de HTML blijft
  gelijk, zodat `SearchJsonTest` ongewijzigd slaagt).
- De teksten van de systeemstatus (`SystemHealth`) zijn vertaald; de tijd van de laatste
  hartslag volgt de taal (stond vast op Nederlands).

### Stap 8 — Berichten in de taal van de ontvanger
- `User` implementeert `HasLocalePreference`: Laravel verstuurt e-mail en in-app-meldingen in
  `users.locale`, ook vanuit de queue. Het onderwerp in de verzendgeschiedenis staat in de taal
  waarin de ontvanger het kreeg.
- **Discord** en de leesbare tekst van een **webhook**-testlevering: in de taal van wie het
  endpoint heeft aangemaakt (`created_by`), anders de app-standaard (`en` in productie).
- **De JSON van webhooks is gelijk gebleven**: dezelfde sleutels, en het incidentdeel (de
  API-resource) is in elke taal identiek; een test bewaakt dat.
- Laravels mailsjabloon ("Hello!", "Regards,") heeft nu een Nederlandse vertaling
  (`lang/nl.json`). Voorheen was die tekst in elke mail Engels.
- Meldingen die al in de database staan, blijven zoals ze zijn opgeslagen.

## Gevonden fouten (bestonden al op `main`)
1. **Een alertregel uitschakelen kon niet**: een uitgevinkte checkbox stuurt niets mee en de
   controller eist `is_enabled` ("Het veld is enabled is verplicht"). De tests stuurden `false`
   en zagen het dus niet. Opgelost in het formulier met een verborgen `0` (zoals de andere
   formulieren al doen); de controller is ongewijzigd. Er is een test bij.
2. **Een statuspagina aanmaken met de standaard lege onderdeelrij faalde**
   ("components.0.subject is verplicht"), terwijl het formulier zegt: "Lege rijen worden
   genegeerd". `app.js` laat volledig lege rijen nu weg bij het verzenden. De controller is
   ongewijzigd; **zonder JavaScript** blijft het gedrag van `main`.
3. Op de webhookpagina liet een verborgen label (voor schermlezers) in een tabelkop de pagina
   5 px te breed worden; opgelost in `app.css` (geldt voor alle tabellen).

## Keuzes die ik zelf maakte
- **Kaarten en koppen**: het domeinformulier kreeg de koppen "Adres" en "Drempels" (plan §4); de
  notities staan bij Adres.
- **Sectienavigatie op de domeinpagina** in plaats van verborgen tabs, net als op de
  serverpagina.
- **Statusbalk**: de rechterkolom van een incident is niet plakkend, omdat hij hoger kan zijn dan
  het scherm. De knop "Oplossen" bij de statusbalk springt naar het bestaande oplosformulier
  (`#resolve`).
- **Tijden** in alerts staan nu in de weergavetijdzone, zoals overal in de app (voorheen ruwe
  UTC). Getallen, datums en percentages volgen de taal (de goedgekeurde regel).
- **Automatisch verversen**: één component voor de ontdekkingspagina (10 s) en de openbare
  statuspagina (60 s). Gepauzeerd blijft gepauzeerd in dat tabblad (`sessionStorage`).
- **Webhook- en Discordtaal zonder maker**: de app-standaard (`config('app.locale')`), dus `en`
  in productie.
- **Labels met een punt** (`incident.opened`): `Label::of` haalt de hele groep op, zodat zulke
  codes ook vertaald worden.
- **Tekstlinks** in lopende tekst zijn onderstreept (na de preflight had alleen de kleur ze
  onderscheiden).
- **Lege toestanden**: de bestaande teksten bleken de goedgekeurde voorstellen al te dekken; waar
  een knop ontbrak, staat die er nu ("Domein toevoegen", "Statuspagina maken", "Webhook
  toevoegen", "Alertregel aanmaken").

## Tekstwijzigingen in het Nederlands
Het Nederlands van alle bestaande teksten is gelijk gebleven. Nieuw zijn alleen:
- de koppen "Adres" en "Drempels" in het domeinformulier;
- de namen in de sectienavigatie van de domeinpagina;
- de voorbeeldzin in het alertregelformulier;
- de knop "Oplossen" bij de statusbalk;
- de teksten van het pauzeerbare verversen ("Ververst automatisch elke 60 s", "Pauzeren",
  "Hervatten", "Nu verversen", "Automatisch verversen staat uit (minder beweging)");
- de contrastwaarschuwing en de uitleg bij de accentkleur;
- de Nederlandse mailtekst van Laravel (`lang/nl.json`), zie stap 8.

## Wat Nederlands blijft (bewust)
- Teksten die in de database staan: incidenttitels en -beschrijvingen, tijdlijngebeurtenissen,
  controleberichten, samenvattingen van paginawijzigingen, auditregels en de redenen in de
  verzendgeschiedenis. Dit is de blokkade vóór Engelstalige klanten in
  [`docs/AUDIT.md`](../AUDIT.md) §8.
- `install.sh`, de agent en het updateprogramma; de meldingen van de API.
- De uitzonderingen van `IncidentManager` (een ongeldige statusovergang) en de kolomkoppen van de
  CSV-exports.

RESULTS_PLACEHOLDER
