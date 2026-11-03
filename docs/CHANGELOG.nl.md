# Wat is er nieuw in ServerScope

## 3 november 2026 — Je eigen dashboard

### Nieuw

- **Dashboard indelen**: met "Indelen" zet je de widgets in je eigen volgorde
  (slepen, ook met touch of de pijltjestoetsen), kies je per widget de grootte
  (S, M, L) en verberg je wat je niet nodig hebt. Begin eventueel met een
  kant-en-klare indeling (Ontwikkelaar, Manager, NOC). Elke persoon heeft een
  eigen indeling; de standaardindeling is het dashboard zoals je het kende.

## 3 november 2026 — ServerScope als app

### Nieuw

- **Installeerbaar als app**: zet ServerScope op het beginscherm van je
  telefoon of installeer hem op je computer. Met eigen icoon, snelle start en
  een nette offline-pagina. Gegevens worden nooit offline bewaard: alles blijft
  live.
- **Tabbalk op je telefoon**: Overzicht, Incidenten (met teller), Servers en
  Meer (het volledige menu).
- **Swipen om te bevestigen**: veeg een open incident in de lijst naar links
  en tik op "Bevestigen".
- **Pushmeldingen**: zet bij Profiel → Meldingen push aan op dit apparaat en
  incidenten komen als melding op je telefoon of computer binnen. Push volgt
  dezelfde voorkeuren als e-mail en in-app; die blijven ongewijzigd.

## 3 november 2026 — Thema's

### Nieuw

- **Extra thema's**: naast licht en donker (de standaard, of volgens je
  systeem) kun je nu expliciet kiezen voor **Midnight** (diep blauw met een
  neongloed), **Terminal** (groen op zwart, monospace) of **Hoog contrast**
  (maximaal contrast, duidelijke randen en focus). Kies bij Profiel → Weergave
  of via Ctrl/⌘ K ("thema"). De themaknop bovenin wisselt zoals altijd tussen
  systeem, licht en donker en zet je terug naar de standaard.

## 3 november 2026 — Alles live

### Nieuw

- **Live lijsten**: de lijsten met servers, websites, incidenten en alerts
  werken zichzelf elke 30 seconden bij, zonder herladen en met je filters en
  sortering. Nieuwe rijen schuiven in, een rij die herstelt licht groen op,
  een rij die faalt rood, en de tellers tellen naar hun nieuwe waarde. Het
  pauzeert als je typt, iets selecteert, een menu open hebt of rijen aanvinkt.
- **Menu**: het aantal open incidenten in het menu loopt live mee.

### Opgelost

- **Commandopalet**: in Safari klapte het palet in tot een smalle strook.

## 3 november 2026 — Commandopalet

### Nieuw

- **Commandopalet** (Ctrl/⌘ K): naast zoeken nu ook opdrachten. Ga naar elke
  pagina, snelle filters ("servers offline", "trage websites", "onbevestigde
  incidenten"), thema wisselen en focusmodus. Met een lege zoekvraag zie je
  wat je recent bekeek. Het gekozen resultaat krijgt rechts een live preview.
  Opdrachten veranderen alleen de weergave, nooit gegevens.

### Verbeterd

- **Dashboard**: de hartslag staat nu ín mission control, direct onder de
  afgelopen 24 uur, en is compacter, zodat je hem ziet zodra je de app opent.
  De tegels tonen eerst de problemen en daarna "+N meer".

## 3 november 2026 — Snel bekijken

### Nieuw

- **Zijpaneel**: klik op een server, website of incident en er schuift een
  paneel van rechts in met status, kerncijfers, een grafiekje, services en
  open incidenten. Je lijst blijft staan. Shift-, Cmd- of Ctrl-klik opent de
  pagina zoals altijd.
- **Hover-kaarten**: houd de muis even op een server, website of incident en
  je ziet een mini-kaart met status, cijfers en grafiekje.
- **Hartslag** staat nu direct onder mission control, boven de details.

## 3 november 2026 — Mission control

### Nieuw

- **Mission control** bovenaan het dashboard: één gezondheidsscore (0–100)
  met een zin die zegt wat er speelt, levende tegels voor elke server en
  website, en een tijdlijn van de afgelopen 24 uur met incidenten, deploys en
  DNS-wijzigingen. Elk probleem dat punten kost staat erbij, met een link.
  Het dashboard zoals je het kende staat eronder als "Details".
- **Focusmodus**: toont alleen wat aandacht nodig heeft, of "Niets te doen"
  (onthouden per browser).

## 3 november 2026 — Tijdreis, wandscherm en levend logo

### Nieuw

- **Tijdreis** (NEXUS › Tijdreis): de afgelopen 24 uur of 7 dagen als film
  over de skyline. Torens groeien en krimpen met de CPU, incidenten flitsen
  voorbij. De **regisseursversie** monteert zichzelf: rustige uren razen
  voorbij, bij elk incident vertraagt de film met de camera op de getroffen
  toren en een bijschrift, met een openingstitel en een samenvatting aan het
  eind. Scrubben met de schuif, vrij afspelen op 1–8× en **video
  exporteren** (MP4 of WebM, opgenomen in je eigen browser).
- **Wandscherm** (NEXUS › Wandscherm): een tv-modus die zelf regisseert.
  Rustig door skyline, wereldbol, hartslag en een samenvatting; bij een nieuw
  incident direct een grote melding en de camera op de oorzaak. Volledig
  scherm, scherm blijft aan, en optioneel als **schermbeveiliging** na 5–30
  minuten niets doen.
- **Levend logo en tabblad-icoon**: het logo en het icoon kloppen in het
  ritme van je infrastructuur en worden oranje of rood bij problemen; het
  tabblad toont het aantal actieve incidenten, ook op de achtergrond.

### Verbeterd

- **Menu**: NEXUS klapt in; je ziet Skyline, Wereldbol, Tijdreis en Wandscherm
  als je op een NEXUS-pagina bent of hem openklapt (onthouden per browser).
- **Wandscherm**: bij een incident komen alle scènes aan bod (ook Wereldbol en
  Samenvatting), niet alleen de toren en de hartslag.

- De hartslag op telefoons: naam, lijn en cijfers onder elkaar, leesbaar.

## 3 november 2026 — Hartslag, skyline en statusbadges

### Nieuw

- **Hartslag op het dashboard**: elke server krijgt een live ECG-lijn. Het
  tempo volgt de CPU, de breedte van de slag de traagste website op de
  server. Bij een storing slaat de lijn op hol; een offline server geeft
  geen signaal. Elke regel zegt het ritme en de cijfers ook in woorden.
- **Skyline** (NEXUS › Skyline): je servers als een stad bij nacht.
  Drukkere servers staan hoger, verlichte ramen zijn draaiende services,
  daklichten zijn de websites. Faalt er iets, dan knippert het licht; meldt
  een server zich niet meer, dan gaat het licht uit.
- **Statusbadges**: live, geanimeerde SVG-badges van een publieke
  statuspagina (algemeen of per onderdeel) voor je README of website, met
  kant-en-klare Markdown op de bewerkpagina. Alleen de publieke naam en
  status; licht of donker, Nederlands of Engels.

### Verbeterd

- De groene "Bereikbaar"-labels (wereldbol, abonnement) zijn weer groen.

## 3 november 2026 — Een mooiere wereldbol

### Verbeterd

- **Wereldbol**: een nieuw uiterlijk — een donkere oceaan met een
  gloeiende rand en atmosfeer, de continenten als fijne ronde stippen,
  gloeiende spelden met een lichtstraal en labels als kaartjes.
  Meetlocaties op (bijna) dezelfde plek delen één speld met één label.
- **Stand-by**: een werkende probe die niets te bevestigen had, staat op
  "Stand-by" in plaats van "Niet gemeten". "Niet gemeten" blijft voor een
  probe die niet reageert.
- **Eigen menu-item**: de wereldbol staat nu in het menu, direct onder
  NEXUS.

## 2 november 2026 — Stromen, statuspagina-hero en wereldbol

### Nieuw

- **Stromen in de 3D-kaart**: licht stroomt over de verbindingen van wat
  levert naar wat het gebruikt — drukkere servers sturen meer, snellere
  websites stromen sneller — en hoopt zich op waar iets faalt. De stromen
  tonen gemeten belasting (CPU, responstijd), geen gemeten verkeer.
- **3D-afbeelding op je statuspagina** (optioneel, per statuspagina): je
  onderdelen rond een kern in de algemene status, met stromend licht als
  ze werken en een zichtbare opstopping bij een storing. Alleen wat de
  pagina al toont wordt gebruikt.
- **Wereldbol** (NEXUS › Wereldbol): vanaf waar ServerScope meet, en per
  website of hij vanaf elke locatie bereikbaar was.

## 2 november 2026 — NEXUS in 3D

### Nieuw

- De NEXUS-kaart heeft een **3D**-weergave: servers staan als torens,
  services als ringen eromheen, websites draaien om de server waarop ze
  draaien en pagina's zweven boven hun website. Sleep om te draaien, scroll
  om te zoomen, klik voor details.
- Actieve incidenten zie je als een puls bij de resource die faalt en als
  golven die over de verbindingen lopen naar alles wat ervan afhangt.
- Filters, zoeken, de impactweergave en het zijpaneel werken zoals op de
  2D-kaart; je keuze voor de weergave wordt onthouden.

### Opgelost

- Het NEXUS-zijpaneel toont niet langer het woord "null" bij lege onderdelen.

## 2 november 2026 — Oorzaakanalyse: regels en AI samen

### Verbeterd

- De incidentpagina heeft nu één sectie **Oorzaakanalyse**: wat is
  waargenomen, de meest waarschijnlijke verklaring volgens vaste regels, de
  interpretatie daarvan door AI (Pro en Premium), andere verklaringen, wat
  onzeker blijft en wat je nu kunt controleren.
- De AI interpreteert nu het onderzoek zelf. Ze verwijst naar hetzelfde
  bewijs (E1, E2, …) en dezelfde kandidaten, kan "oorzaak onbekend"
  antwoorden, en haar zekerheid wordt getoond als inschatting, nooit als
  kans. Verwijzingen naar bewijs dat niet bestaat worden verwijderd en het
  aantal wordt getoond.
- Geen extra AI-gebruik: nog steeds hooguit één automatische analyse per
  nieuw incident, met dezelfde abonnementen, limieten en wachttijden.

## 2 november 2026 — NEXUS Intelligence: onderzoek zonder AI

### Nieuw

**Onderzoek van incidenten (elk abonnement, geen AI)**
- ServerScope kan nu een incident onderzoeken: het verzamelt het bewijs
  eromheen (incidenten, controles, servicewijzigingen, DNS-wijzigingen,
  deployments, configuratiewijzigingen, agentupdates, metingen, onderhoud)
  en past vaste regels toe om mogelijke oorzaken voor te stellen.
- Elke mogelijke oorzaak toont de zekerheid (hoog, gemiddeld, laag of
  onvoldoende), het ondersteunende bewijs, het tegenstrijdige bewijs en
  welke informatie ontbreekt. "Oorzaak niet vastgesteld" is een geldig
  antwoord: ServerScope gokt niet.
- Mogelijke impact: wat afhangt van de verdachte resource, via welke
  afhankelijkheid, en of het faalde, in orde was of niet is waargenomen.
- Vervolgstappen om zelf te controleren. ServerScope wijzigt zelf niets.
- Een onderzoek start automatisch als een incident opent, heropent of wordt
  opgelost, en beheerders kunnen het opnieuw uitvoeren. Elke uitvoering
  blijft ongewijzigd bewaard, zodat je ze kunt vergelijken.
- Te vinden op de incidentpagina, vanuit Time Machine en vanuit het
  incidentblok op de NEXUS-kaart ("Tonen op de NEXUS-kaart" laat de impact
  zien).
- De AI-analyse (Pro en Premium) is ongewijzigd en blijft apart.

## 26 oktober 2026 — Websitemonitoring, slimme incidenten en voorspellingen

Deze update maakt van ServerScope meer dan een uptime-monitor: je bewaakt nu
complete websites pagina voor pagina, gerelateerde storingen worden
samengevoegd tot één incident, en ServerScope waarschuwt vooraf als een
schijf vol dreigt te raken. Alle bestaande monitoring, historie en
koppelingen blijven werken zoals je gewend bent.

### Nieuw

**Monitoring per pagina**
- Voeg naast de startpagina ook andere pagina's toe, zoals `/prijzen`,
  `/login` of `/api/health`. Elke pagina heeft een eigen interval, eigen
  drempels, eigen meldingen en een eigen geschiedenis.
- Per pagina kun je controleren op statuscode, maximale responstijd, het
  eindadres na redirects, tekst die er wel of juist níet op mag staan, en
  response-headers (bijvoorbeeld `Strict-Transport-Security`).
- ServerScope maakt onderscheid tussen een netwerkfout, een time-out, een
  DNS-fout, een certificaatfout, een redirectfout, een verkeerde statuscode
  en een mislukte inhoudscontrole.
- Een trage pagina krijgt de status "verminderd" en telt niet als storing.
- Je kunt pagina's pauzeren zonder hun geschiedenis kwijt te raken.

**Pagina's automatisch ontdekken**
- ServerScope zoekt pagina's via robots.txt, XML-sitemaps (ook sitemapindexen
  en gecomprimeerde sitemaps) en interne links.
- Standaard veilig: maximaal 50 pagina's, alleen je eigen domein, en
  gevonden pagina's wachten op jouw goedkeuring. Ruimere instellingen moet je
  bewust bevestigen.
- Gevonden pagina's keur je in één keer goed of af met bulkacties.

**Websiteoverzicht**
- Per website zie je hoeveel pagina's gezond, verminderd of onbereikbaar
  zijn en welke pagina's niet recent zijn gecontroleerd.
- Daarnaast de gemiddelde responstijd en de p50/p95/p99-waarden,
  beschikbaarheid per dag, actieve incidenten en recente wijzigingen aan
  inhoud, DNS en certificaten.
- Zoeken, sorteren, filteren en bulkacties in de paginalijst.

**Wijzigingen in inhoud volgen**
- ServerScope ziet wanneer de tekst van een pagina verandert en laat zien
  wat er is toegevoegd en verwijderd.
- Datums, tijden, tellers en sessiecodes worden standaard genegeerd. Je kunt
  ook één deel van de pagina kiezen of dynamische blokken uitsluiten.
- Een wijziging is nooit automatisch een storing. Verwachte wijzigingen
  bevestig je met één klik, en je kunt per pagina meldingen inschakelen.
- Je kunt ook gekozen response-headers volgen, zodat je ziet wanneer
  bijvoorbeeld een beveiligingsheader verandert.

**Slimme incidenten**
- Je legt vast welke website op welke server draait en welke diensten
  ervan afhangen (bijvoorbeeld server → Nginx → website → MySQL). Valt een
  server uit, dan krijg je één incident met de getroffen websites eronder,
  in plaats van een stroom losse meldingen.
- Als meerdere pagina's om dezelfde reden falen, wordt dat één
  websiteincident met per pagina de details. Een enkele pagina met een 404
  blijft een eigen incident.
- Bij elk gegroepeerd incident staat waarom het is gegroepeerd. Storingen
  die alleen toevallig tegelijk gebeuren, worden nooit samengevoegd.
- ServerScope stelt zelf relaties voor (bijvoorbeeld als een domein naar
  het IP-adres van een server wijst). Die worden pas gebruikt als jij ze
  bevestigt.

**Diensten op je servers**
- De agent kan nu Nginx, Apache, MySQL, PostgreSQL, Redis, PHP-FPM en
  andere systemd-diensten bewaken. Je kiest zelf welke.
- ServerScope maakt duidelijk onderscheid tussen een gestopte dienst, een
  monitoringagent die niet reageert en een server die echt onbereikbaar is.
  Bij die laatste twee wordt gekeken of websites op die server nog
  reageren.

**Voorspellingen en afwijkingen**
- ServerScope vergelijkt CPU, geheugen, swap, load, netwerk, schijfgroei,
  responstijd en foutpercentage met de eigen historie van de afgelopen
  week. Alleen duidelijke, grote afwijkingen worden getoond.
- Een capaciteitsprognose laat zien wanneer een schijf of het geheugen vol
  raakt als de huidige trend doorzet, met een bandbreedte en een
  betrouwbaarheid.
- Elke voorspelling vermeldt welke periode is gebruikt, wat de trend is en
  waarom de waarschuwing er is. Met te weinig gegevens wordt niets
  voorspeld.
- Voorspellende meldingen kun je per server uitzetten en de gevoeligheid
  kun je per server instellen.

**Systeemstatus**
- Een nieuwe pagina laat zien of ServerScope zelf goed werkt: draait de
  scheduler, loopt de wachtrij niet vast, worden de controles op tijd
  uitgevoerd en komen meldingen aan.

### Verbeterd

- **Vier ernstniveaus:** Laag, Gemiddeld, Hoog en Kritiek. "Hoog" is nieuw,
  voor alertregels, incidenten, meldingsvoorkeuren en webhooks.
- **Herstelbevestiging:** een alertregel kan pas herstel melden na een
  instelbaar aantal goede metingen op rij. Dat voorkomt herstelmeldingen
  bij een korte dip.
- **Detectietijd (MTTD):** naast MTTA en MTTR zie je nu hoe snel een
  probleem na de eerste mislukte controle als incident werd gemeld.
- **Herhaling:** bij een incident zie je hoe vaak hetzelfde probleem eerder
  optrad.
- **Meldingen per resource:** zet meldingen uit voor een server, domein,
  pagina of dienst. Incidenten worden dan nog wel vastgelegd.
- **Certificaatgeschiedenis:** elk certificaat dat bij een domein is gezien,
  met uitgever, geldigheid en wanneer het in gebruik was.
- **Rapporten:**
  - beschikbaarheidsdoelen per server, domein en pagina;
  - beschikbaarheid per pagina;
  - MTTD en de totale duur van incidenten;
  - terugkerende problemen;
  - een overzicht van hoeveel van je omgeving echt is gecontroleerd;
  - een export van alle incidenten;
  - een afdrukbare versie, die je ook als PDF kunt opslaan.
- **Serverpagina:** grafieken voor swap, netwerkverkeer, schijfactiviteit
  en netwerkfouten.
- **Dataretentie:** ook de nieuwe gegevens, zoals paginacontroles en
  voorspellingen, kun je na een ingestelde periode laten opruimen.

### Agent 0.2.0

- Nieuw: bewaking van diensten via `SERVERSCOPE_SERVICES`, schijfactiviteit,
  netwerkfouten, het aantal processen en een controlesom van de agent zelf.
- De agent leest alleen gegevens uit en voert nooit commando's op
  afstand uit.
- Agent 0.1.0 blijft werken. Upgraden is nodig voor de nieuwe
  servergegevens.

### API

- Nieuwe endpoints voor pagina's, paginacontroles, ontdekken,
  afhankelijkheden, diensten, voorspellingen en het toewijzen van
  incidenten.
- Incidenten bevatten nu ook de detectietijd, het hoofdincident en de reden
  van groepering.
- Schrijfverzoeken ondersteunen `Idempotency-Key`, zodat een herhaald
  verzoek niet twee keer wordt uitgevoerd.
- Bestaande endpoints en velden zijn niet gewijzigd.

### Beveiliging

- Elke controle en elke ontdekking gebruikt dezelfde bescherming als de
  bestaande domeinmonitoring. Interne, lokale en cloud-metadata-adressen
  worden geblokkeerd, ook na een redirect, en een DNS-antwoord kan
  achteraf niet naar een intern adres worden omgebogen.
- Sitemaps met verborgen verwijzingen naar bestanden worden geweigerd.
- Nieuwe beheeracties worden vastgelegd in het auditlog.

### Gewijzigd gedrag

- Wie een incident met de hand oplost, moet nu kort de oorzaak en de
  oplossing beschrijven.
- Een incident dat onder een ander incident is gegroepeerd, stuurt geen
  aparte melding zolang het hoofdincident actief is. Algemene webhooks
  ontvangen wel alle incidenten, met een verwijzing naar het hoofdincident.

### Goed om te weten

- Incidenten van verschillende servers en websites worden alleen
  gegroepeerd als je hun relatie hebt vastgelegd onder **Afhankelijkheden**.
- De interface is voorlopig alleen in het Nederlands.
- "Geen problemen gevonden" betekent dat de ingestelde controles niets
  melden. Het is geen garantie dat een server of website veilig is.
