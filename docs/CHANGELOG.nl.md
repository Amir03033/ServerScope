# Wat is er nieuw in ServerScope

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
