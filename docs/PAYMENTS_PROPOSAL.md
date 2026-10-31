# Betalingen (fase 2)

**Status:** het provider-neutrale skelet staat er (uitgeschakeld). Wat er al is: `subscriptions` en
`billing_events` (idempotentie), `App\Billing\PaymentProvider` (interface), `BillingManager`
(webhook-event -> plan_status, coulance, opzegging, downgrade naar Free), `/billing` (pagina),
`POST /api/webhooks/payments` (404 zolang uit), `serverscope:team:plan` (handmatig activeren, bijv. op factuur)
en `serverscope:billing:expire-grace`. Alle sleutels, prijs-ID's en KvK/btw-gegevens komen uit `.env`
(zie `.env.example`, blok "Billing").

**Mollie-driver (gebouwd, getest tegen een nagebootste API):** eerste betaling via iDEAL/kaart, na `paid` maakt
de webhook een Mollie-abonnement aan dat per incasso verlengt; verlengingen komen als eigen betalingen binnen;
mislukte verlenging = coulanceperiode, opzeggen = doorlopen tot einde periode. Mollie-webhooks bevatten alleen een
betaal-id en geen handtekening: de betaling wordt opnieuw bij Mollie opgehaald, de URL bevat een geheim token en het
betaalde bedrag moet gelijk zijn aan de ingestelde prijs. De prijs-variabelen bevatten bij Mollie het bedrag (bijv. `19.00`).

**Oud lijstje (nu grotendeels gedaan, alleen invullen en echt testen):** een driver in `App\Billing\Providers` (checkoutUrl,
parseWebhook met handtekeningcontrole, cancel), registreren in `BillingManager::DRIVERS`,
`.env` invullen, `SERVERSCOPE_BILLING_ENABLED=true`, factuur/btw volgens sectie 5. Die driver is niet
gebouwd en dus ook niet getest tegen een echte provider.

## Uitgangspunt (fase 1, gebouwd)
`teams.plan` (free|pro|premium) en `teams.plan_status` (active|pending_payment).
Limieten staan in `config/serverscope.php` (`plans`) en gaan via `App\Support\PlanLimits`.
Pro/Premium zonder betaling draait op Free-limieten. Fase 2 hoeft dus alleen
`plan_status` betrouwbaar te laten volgen op de betaalstatus.

## 1. Provider
| | Mollie | Stripe |
|---|---|---|
| iDEAL / NL | native, iDEAL standaard | via Stripe Payments, iDEAL beschikbaar |
| Terugkerend | iDEAL kan niet zelf herhalen: eerste betaling (iDEAL of kaart) maakt een SEPA-mandaat, daarna incasso (Mollie Subscriptions) | Stripe Billing: abonnementen, proration, klantenportaal, Tax |
| Facturen / btw | geen eigen facturen; zelf genereren | Stripe Invoices + Stripe Tax (OSS) |
| Opzet | eenvoudiger, EU-gericht | meer functies, meer te beheren |

**Aanbeveling:** Stripe Billing (facturen, btw, klantenportaal, proration kosten weinig bouwwerk) als
facturatie-automatisering belangrijk is; Mollie als iDEAL-first en eenvoud zwaarder wegen.
Beide bieden een testmodus. Keuze graag van de eigenaar.

## 2. Abonnementsmodel
- Prijzen uit Info (`Plan.price_monthly` / `price_yearly`); ServerScope kent ze nu niet.
  Voorstel: prijs-ID's per plan en interval in config/.env (`SERVERSCOPE_PRICE_PRO_MONTHLY`, …),
  Info blijft de weergave, de provider de waarheid voor bedragen. Test dat beide overeenkomen.
- Tabel `subscriptions` (team_id, provider, provider_customer_id, provider_subscription_id, plan, interval, status, current_period_end, cancel_at_period_end).
- Checkout: knop "Betaal nu" in de pending-banner → hosted checkout van de provider → terug naar ServerScope.
  Nooit kaart-/bankgegevens in onze app.

## 3. Webhook
- Route `/webhooks/payments` buiten auth/tenant, handtekening verifiëren, idempotent op event-ID, rate limit.
- Succesvolle betaling → `plan_status = active` (plan uit de bij de provider vastgelegde prijs, niet uit request of hidden field).
- Mislukte verlenging → `past_due`, mail aan owner, korte coulanceperiode (voorstel 7 dagen), daarna terug naar Free-limieten.
- Terugkeer van gebruiker vanuit checkout activeert nooit zelf; alleen de webhook.

## 4. Mislukte betaling en opzegging
- Opzeggen: loopt door tot einde periode (`cancel_at_period_end`), daarna `plan = free`, `plan_status = active`.
- Downgrade: data blijft. Bestaande servers/domeinen boven de Free-limiet blijven bestaan maar kunnen niet worden uitgebreid
  (nu blokkeren de limieten alleen nieuwe items). Open vraag: moet monitoring boven de limiet pauzeren? Retentie wordt wel
  toegepast door `serverscope:prune` (dus oudere historie verdwijnt na downgrade — dit moet vooraf in de UI en mail staan).

## 5. Facturen en btw
- Stripe: Stripe Invoices + Tax; Mollie: zelf facturen (PDF, doorlopend nummer, btw-nummer klant, verleggingsregeling EU-B2B).
- Prijzen incl. of excl. btw tonen is nog een besluit (NL B2C: incl.; B2B: excl.). KvK/btw-gegevens van de eigenaar nodig.

## 6. Testmodus
Aparte sleutels via `.env` (`*_TEST_*`), nooit in de repo; webhooktests met gefakete payloads; handmatig testen met
provider-testkaarten/iDEAL-testbank. Productiesleutels pas na akkoord.

## 7. Voorwaarden vooraf
Openbare registratie blijft uit tot AUDIT.md-blockers 1, 2 en 9 zijn opgelost (domeinen uniek per team, geen account-enumeratie, e-mailverificatie).
Ook nodig: privacyverklaring, algemene voorwaarden en herroepingsrecht-tekst (juridisch beoordelen).

## Te kiezen
1. Provider (Mollie of Stripe)? 2. Btw weergave? 3. Coulanceperiode? 4. Pauzeren boven Free-limiet na downgrade? 5. Jaarkorting uit Info overnemen?
