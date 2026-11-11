# Bokföringsmodul — Plan

## Översikt

Frivillig men korrekt dubbelbokhållning (dubbel bokföring) med hög automationsgrad.
Föreningen är inte bokföringsskyldig enligt lag, men allt ska balansera.
Kassören är primär användare — systemet föreslår, kassören godkänner.

Kräver ny permission: `accounting.manage`

---

## Datamodell

### Kontoplan

```
accounts
  id, code (t.ex. "1920"), name, type (asset|liability|equity|income|expense)
  vat_code, is_active, sort_order
  parent_id (self-referential för kontogrupper)
```

BAS-inspirerad men förenklad för föreningsbruk. Seedas med relevanta konton.

### Kostnadsställen och projekt

```
cost_centers
  id, code        — t.ex. "BSK", "SPKV", "LOKALER"
  name
  type: cost_center|project
  parent_id       — nullable, self-referential (projekt under kostnadsställe)
  starts_at       — nullable (projekt har start/slut, kostnadsställen är löpande)
  ends_at         — nullable
  is_active
```

`journal_lines` får ett valfritt `cost_center_id` — en verifikationsrad kan
tillhöra ett kostnadsställe eller projekt utan att kontoplanen behöver utökas.

Exempel:
- Kostnadsställe `BSK` — allt som rör Borås Spelkonvent
- Projekt `BSK-2026` (under BSK) — specifikt för konventet 2026
- Kostnadsställe `SPKV` — löpande spelkvällar
- Kostnadsställe `LOKALER` — hyra och lokalkostnader

Rapporter kan filtreras per kostnadsställe/projekt: "Visa resultat enbart för BSK-2026"
utan att kontoplanen behöver vara uppdelad på verksamhet.

### Räkenskapsår

```
fiscal_years
  id, name (t.ex. "2025"), starts_at, ends_at
  is_locked          — stängt år, ingen bokföring tillåten
  closed_at, closed_by
```

### Periodlås

```
period_locks
  id, fiscal_year_id, year, month (1–12)
  locked_at, locked_by   — låst period, ingen ny bokföring tillåten
```

Kassören låser månader löpande efter avstämning. Stängt räkenskapsår låser
alla perioder automatiskt. Systemet nekar journal_entries med date i låst period.

### Ingående balanser

Hanteras som vanliga verifikationer i serie "IB" på räkenskapsårets första dag.
En IB-verifikation per konto med ingående saldo (debet eller kredit).
Genereras automatiskt vid bokslut från föregående års utgående balanser.
Vid systemstart matas IB in manuellt en gång.

### Attestregler

```
approval_rules
  id, name               — t.ex. "Utlägg lågt belopp", "Faktura högt belopp"
  invoice_type           — nullable: payable|receivable|expense|any
  amount_min             — öre, nullable
  amount_max             — öre, nullable
  required_approvals     — antal godkännanden som krävs (1 eller 2)
  approver_role          — Spatie-roll som får attestera (t.ex. "styrelse")
  block_submitter        — bool: den som lade upp får inte attestera (default true)
  block_beneficiary      — bool: den som gynnas (utläggets ägare) får inte attestera (default true)
  is_active

approval_logs
  id, invoice_id, approved_by, approved_at, notes, action (approved|rejected)
```

Jävskontrollen körs vid varje attestförsök:
- `block_submitter=true` → den som skapade/laddade upp fakturan/kvittot är blockerad
- `block_beneficiary=true` → vendor.member_id (utläggets ägare) är blockerad
- Kassören blockeras automatiskt på sitt eget utlägg via båda ovanstående

Regler utvärderas i ordning — första matchande regel avgör krav.
Exempel:
  Utlägg, valfritt belopp → 1 godkännande, roll "styrelse",
                             block_submitter=true, block_beneficiary=true
  Faktura > 10 000 kr     → 2 godkännanden, roll "styrelse"
  Allt annat              → 1 godkännande, roll "kassör"

### Leverantörsregister

```
vendors
  id, name, org_number, bankgiro, plus_giro, email, phone
  default_account_id  — FK → accounts (standardkonto för kostnader)
  default_vat_rate    — 0|6|12|25 (procent)
  notes
  is_active
```

### Kundregister

```
customers
  id, name, org_number, email
  member_id           — nullable FK → members (för föreningsmedlemmar)
  default_account_id  — FK → accounts (standardkonto för intäkter)
```

### Fakturor (AP + AR)

```
invoices
  id, type (payable|receivable)
  vendor_id / customer_id  — beroende på type
  invoice_number, invoice_date, due_date
  amount_ex_vat, vat_amount, total  — öre
  currency (default SEK)
  status: draft|pending_approval|approved|posted|paid|cancelled
  pdf_path             — lagrad fil
  ai_extraction_raw    — rå JSON från LLM (audit-spår)
  journal_entry_id     — satt när kassören godkänner
  payment_journal_entry_id — satt när betalning bokförs
  notes
  created_by, approved_by, approved_at

invoice_lines
  id, invoice_id, account_id
  description, amount_ex_vat, vat_rate, vat_amount, sort_order
```

### Verifikationer

```
journal_entries
  id, fiscal_year_id, series (A|B|...), number (löpnummer per serie)
  date, description
  source_type, source_id  — morph: Invoice|BankTransaction|Manual
  created_by, is_locked
  attachments (morph)

journal_lines
  id, journal_entry_id, account_id
  debit, credit  — öre, aldrig båda > 0 samtidigt
  description
```

Constraint: sum(debit) = sum(credit) per verifikation.

### Bankkonton + kontoutdrag

Föreningen har för närvarande tre bankkonton (alla Swedbank):

| Namn | Bankgiro | Swish | Primär användning |
|---|---|---|---|
| Kiosk | — | ja (Handel) | Fysisk försäljning på plats |
| Medlem | 5702-0778 | — | Huvudkonto, inkommande avgifter |
| Swish | — | ja (Handel) | Swish Handel online |

Bankgiro- och Swishnummer är kopplade till sitt bankkonto — inte till systemet globalt.
Konfigureras i admin, ersätter de gamla SystemSettings-nycklarna
`payment.bankgiro`, `payment.swish_number`, `payment.swish_type`.

Faktiska kontonummer/IBAN/Swishnummer matas in via admin-UI — aldrig i kod.

```
bank_accounts
  id, name              — visningsnamn, t.ex. "Medlem 8032-5"
  account_number        — kontonummer inkl. clearing, t.ex. "8032-5,4 319 657-5"
  clearing_number       — clearingnummer, t.ex. "8032"
  bank_name             — t.ex. "Swedbank"
  account_id            — FK → accounts (bokföringskonto, t.ex. 1920/1921/1922)
  currency              — default SEK
  is_active             — visas i dropdown vid import och betalning
  notes

  -- Betalningsidentifierare (nullable)
  bankgiro              — format XXX-XXXX eller XXXX-XXXX, nullable
  plusgiro              — format XXXXXX-X, nullable
  swish_number          — telefonnummer för Swish Handel, nullable
  swish_type            — private|handel (avgör QR-generering), default handel
  iban                  — IBAN, nullable
```

### Förvalt konto per syfte

Inspirerat av Fortnox-modellen: systemet har konfigurerbara "förvalt konto"-
inställningar per transaktionstyp. Lagras som SystemSettings med FK till
`bank_accounts.id`.

| Inställningsnyckel | Beskrivning |
|---|---|
| `accounting.default_account_main` | Förvalt huvudkonto (utgifter, manuella poster) |
| `accounting.default_account_membership` | Inkommande avgifter (bankgiro + bankstämning) |
| `accounting.default_account_swish_online` | Swish Handel online (kassan) |
| `accounting.default_account_swish_pos` | Swish på plats (kiosk) |
| `accounting.default_account_stripe` | Stripe-utbetalningar |

Admin-sidan visar dessa som dropdowns med de aktiva bankkontona — identisk
UX som Fortnox "Register – Transaktionskonton".

Kassan (`/kassa`) läser `accounting.default_account_swish_online` för att
hämta rätt Swishnummer att visa, och `accounting.default_account_membership`
för bankgiro. Ersätter de gamla `payment.*`-inställningarna.

```
bank_transactions
  id, bank_account_id
  transaction_date, value_date
  amount                — öre, negativt = utgift
  description, reference
  status                — unmatched|matched|posted|ignored
  journal_entry_id      — satt när bokförd
  raw_data              — originalrad från import (JSON)
```

### Utlägg + kortkvitton

Utlägg och kortkvitton lagras **inte** i separata tabeller — de är fakturor
(`invoices`, type=payable) kopplade till en leverantör av typen `member`.

```
vendors                — alla motparter vi betalar till
  id, code            — "L093" (extern leverantör/butik) | "M901" (medlem 901)
  type: external|member
  member_id           — nullable FK → members (om type=member)
  name
  org_number          — nullable (butiker saknar ofta org.nr)

  -- betalvägar (minst en bör finnas för utbetalning)
  bankgiro            — format: XXX-XXXX eller XXXX-XXXX
  plus_giro           — format: XXXXXX-X
  iban                — för SEPA-överföringar, pain.001
  bank_clearing_number — clearingnr till IBAN (4 siffror)

  email, phone
  default_account_id  — FK → accounts
  default_vat_rate    — 0|6|12|25
  notes
  is_active
```

Alla leverantörer och butiker får L-kod. Typ styr vilka flöden som är
tillgängliga — external: faktura + kortkvitto, member: utlägg + kortkvitto.
pain.001-filen använder IBAN; bankgiro/plusgiro används för manuella
betalningar och visas som instruktion på godkänd faktura.

Utlägg = invoice (payable) med vendor.type=member.
Kortkvitto = invoice (payable) med vendor.type=member|merchant,
             kopplat till payment_card_id via morph.

```
payment_cards
  id, member_id, label, last_four
  account_id     — bokföringskonto för kortutlägg
  is_active
```

---

## UX-lager (separerat från datamodellen)

**Datamodellen är unified — UX-lagren är separata och anpassade per roll.**

### Kassörsvyn (admin)
Ser allt som fakturor/leverantörsskulder. Full kontroll.
Godkänner, justerar bokföring, skapar pain.001-betalfil.

### Kortinnehavarens vy
Ser bara sina egna kortkvitton. Enkelt formulär:
- Foto av kvitto
- Datum + belopp (förifylls av AI, kan korrigeras)
- Vad gällde köpet? (fritext + kategoriförslag)
- Skicka in

Systemet skapar vendor M{nummer} om den inte finns, skapar invoice,
kör AI-extraktion i bakgrunden, notifierar kassören.

### Utläggsvy (alla medlemmar)
Identisk UX som kortkvitto men utan payment_card-koppling.
Medlem ser status: "Inkommen → Godkänd → Utbetalad" + belopp.
Kassören ser en separat lista "Utlägg att betala" skild från externa fakturor.

---

## Flöde: Bokslut (årsavstämning)

```
1. Kassören låser alla perioder för räkenskapsåret
2. Systemet beräknar årets resultat:
     sum(intäktskonton 3xxx) − sum(kostnadskonton 4xxx–7xxx)
3. Resultatverifikation skapas automatiskt:
     Debet  8999 Årets resultat   [belopp]
     Kredit 2099 Balanserat res.  [belopp]   (eller debet om förlust)
4. Kassören granskar och bekräftar balansräkningen
5. Räkenskapsåret låses (is_locked=true)
6. IB-verifikationer för nästa år genereras automatiskt från utgående balanser
```

---

## Flöde: Leverantörsfaktura

```
1. Kassören laddar upp PDF
2. LLM (Claude API) extraherar:
     leverantörsnamn, org.nr, fakturanr, datum, förfall,
     belopp exkl moms, moms, totalt, bankgiro
3. System matchar leverantörsnamn → vendors-tabell
4. Bokföringsförslag byggs från vendor.default_account + vat_rate:
     Debet  [default_account]   belopp ex moms
     Debet  2640 Ingående moms  moms
     Kredit 2440 Lev.skulder    totalt
5. Kassören ser förslag + PDF sida vid sida, kan justera rader
6. Kassören godkänner → journal_entry skapas (låst)
7. Betalning registreras (manuellt eller via bankstämning):
     Debet  2440 Lev.skulder    totalt
     Kredit [bank_account]      totalt
```

---

## Flöde: Bankkontoutdrag

```
1. Kassören laddar upp CSV/Excel från banken
2. Parser identifierar format (Swedbank/SEB/Handelsbanken/etc.)
   Alternativt: LLM-tolkning av okänt format
3. Varje rad importeras som bank_transaction (status: unmatched)
4. Auto-matchning körs:
     a. Matcha mot öppna leverantörsskulder (2440) via belopp + datum
     b. Matcha mot Swish-transaktioner (redan importerade)
     c. Matcha mot betalda avgifter/evenemang (redan i systemet)
5. Matchade → status: matched, väntar kassörens bekräftelse
6. Omatchade → kassören ser lista, för varje rad:
     - Länka till befintlig post
     - Välj bokföringsmall (t.ex. "Bankkostnad", "Bidrag LOK-stöd")
     - Skapa manuell verifikation
     - Markera som ignorerad (t.ex. intern överföring)
7. Kassören bekräftar matchade + hanterar omatchade
8. Verifikationer skapas
```

### Bankformat att stödja (prioritetsordning)

1. **SEB** — CSV med semikolon
2. **Swedbank** — CSV
3. **Handelsbanken** — Excel/CSV
4. **Nordea** — CSV
5. **OFX/QIF** — standardformat, fungerar för de flesta banker

---

## Flöde: Utlägg

```
1. Medlem laddar upp kvittofoto + beskrivning av vad det gäller
2. LLM (vision) extraherar: butik, datum, belopp, moms
3. System föreslår konto (från merchant_templates eller AI)
4. Kassören godkänner → journal_entry skapas:
     Debet  [konto]        belopp ex moms
     Debet  2640 Ing. moms moms (om tillämpligt)
     Kredit 2893 Utlägg    totalt  ← skuld till medlemmen
5. Kassören betalar ut — två alternativ:
     a. ISO 20022 pain.001-fil (föredragen):
        Kassören väljer godkända utlägg → system genererar XML-fil →
        kassören laddar upp i nätbanken → banken genomför betalningarna →
        nästa bankimport matchar automatiskt
     b. Manuell banköverföring → kassören markerar som betald manuellt
6. Utbetalning bokförs:
     Debet  2893 Utlägg    totalt
     Kredit 1920 Bank      totalt
7. Bankimport matchar automatiskt mot utbetalningen via belopp + datum
```

Utlägget behandlas alltså som en intern faktura från medlemmen —
samma clearingkonto (2893) som kortutlägg, men med ett explicit
utbetalningssteg som kassören initierar.

---

## Flöde: Betalkort + kvitto

```
1. Kortinnehavaren fotograferar kvitto (mobilvänlig vy, direkt efter köp)
2. LLM (Claude vision) extraherar från foto:
     butik, datum, belopp exkl moms, moms, totalt
     (ev. kortets sista 4 siffror om synliga på kvittot)
3. System slår upp butiknamn → merchant_templates
   → föreslår konto + momssats
4. Kortinnehavaren ser förslaget, kan lägga till notering, skickar in
5. Kassören godkänner (eller avvisar med kommentar)
6. Vid bankimport:
     Korttransaktion (identifierad via last_four) auto-matchas mot
     godkänt kvitto med samma belopp ± datum inom X dagar
7. Matchat → verifikation skapas:
     Debet  [account]         belopp ex moms
     Debet  2640 Ing. moms    moms
     Kredit 2893 Kortutlägg   totalt  (clearingkonto)
   + vid bankpost:
     Debet  2893 Kortutlägg   totalt
     Kredit 1920 Bank         totalt
```

### Saknade kvitton

Daglig (eller schemalagd) kontroll:
- Korttransaktion utan matchat kvitto efter 7 dagar → automatisk påminnelse till kortinnehavaren
- Kassören ser "Saknade kvitton"-lista med alla öppna korttransaktioner

---

## Flöde: Automatiska bokföringar (befintliga betalningar)

Integrationer mot befintliga betalningsflöden skapar automatiskt verifikationer:

| Händelse | Debet | Kredit |
|---|---|---|
| Medlemsavgift betald (Swish) | 1940 Swish clearing | 3010 Medlemsintäkter |
| Medlemsavgift betald (Stripe)* | 1930 Stripe clearing | 3010 Medlemsintäkter |
| Bankgiro-fil matchad | 1920 Bankgiro | 3010 Medlemsintäkter |
| Swish-utbetalning till bank | 1920 Bank | 1940 Swish clearing |
| Stripe-utbetalning till bank* | 1920 Bank | 1930 Stripe clearing |

*Stripe är planerat för membrum2 men ej live ännu. CogWorks används idag
för onlinebetalningar i BSK — membrum2:s betalningskanaler fastställs
när systemet sätts i produktion.

Kräver att dessa flöden kopplar till bokföringsmodulen via `PostingRule`-konfiguration.

```
posting_rules
  id, name, trigger (membership_paid|event_paid|stripe_payout|...)
  debit_account_id, credit_account_id
  is_active
```

---

## Bokföringsmallar (för manuella poster)

```
journal_templates
  id, name (t.ex. "LOK-stöd från Borås Stad", "Kontorsmaterial")
  lines: [{account_id, debit_pct|credit_pct, description}, ...]
```

Kassören väljer mall → beloppet fördelas enligt mallen → justering möjlig.

---

## Budget

### Datamodell

```
budgets
  id, fiscal_year_id, name (t.ex. "Budget 2025", "Reviderad budget Q2")
  is_active        — vilken som används i jämförelserapporter
  is_locked        — låst efter styrelsebeslut
  created_by, notes

budget_lines
  id, budget_id, account_id   — kan peka på konto ELLER kontogrupp (parent)
  jan, feb, mar, apr, maj, jun, jul, aug, sep, okt, nov, dec  — öre per månad
```

`account_id` kan vara en kontogrupp (t.ex. 5000) eller ett enskilt konto (t.ex. 5460).
I rapporten summeras utfall för alla konton under gruppen och jämförs mot budgetraden.
Man behöver alltså inte budgetera per detaljkonto — en rad "Lokalkostnader" mot
kontogruppen räcker, även om bokföringen sker på flera underkonton.

Flera budgetar per räkenskapsår tillåts (ursprungsbudget + reviderade versioner).
Endast `is_active=true` används i jämförelserapporter.

### Budgetarbete

Kassören matar in budget per konto och månad i ett kalkylbladsliknande gränssnitt.
Import från föregående års utfall som startpunkt: "Kopiera utfall 2024 som budget 2025".
Export till Excel för styrelsearbete utanför systemet.
Import från Excel när styrelsen är klar.

---

## Rapporter

### Resultaträkning
- Intäkter vs kostnader per period (månad, kvartal, år)
- Grupperade per kontoklass (3xxx intäkter, 4xxx–7xxx kostnader)
- Kolumner: Utfall period | Utfall ackumulerat | Budget period | Budget ackumulerat | Avvikelse | Avvikelse %

### Balansräkning
- Tillgångar (1xxx), skulder (2xxx), eget kapital vid valfritt datum
- Jämförelsedatum: samma datum föregående år
- Ingående balans + periodens förändring + utgående balans

### Jämförelse år mot år
- Valfria år ställs mot varandra (t.ex. 2023 | 2024 | 2025)
- Per konto eller per kontoklass
- Visar trend och avvikelse

### Budget vs utfall (löpande)
- Primär arbetsrapport för kassören — visar läget just nu
- Kolumner per rad (kontogrupp):
    Utfall jan–[aktuell månad] | Budget jan–[aktuell månad] | Avvikelse kr | Avvikelse % | Helårsbudget | Prognos helår
- Prognos helår = utfall hittills extrapolerat på återstående månader (enkel linjär)
- Trafikljus på avvikelse: grön = inom 5%, gul = 5–15%, röd = >15%
- Drilldown per kontogrupp → underliggande konton → underliggande verifikationer
- Filtrerbar per period (kan köras för valfri ackumulerad period, inte bara jan–idag)

### Kontoutdrag
- Per konto, per period
- Ingående saldo + alla transaktioner + utgående saldo

### Öppna poster
- Obetalda leverantörsfakturor (AP) med förfallodatum och dagar sedan förfall
- Obetalda kundfakturor (AR) dito

### Momsrapport
- Ingående moms (2640) vs utgående moms (2610) per period
- Underlag för momsdeklaration (om föreningen är momspliktig)

### Kassaflöde
- Förändring i likvida medel per period
- Uppdelat på: rörelseverksamhet, investeringar, finansiering

---

## BSK-integration (API)

BSK lever parallellt med membrum2 och skickar bokföringsinformation via API.
BSK autentiserar med ett Sanctum API-token (utfärdat i membrum2 admin).

### Delade bankkonton — grundproblemet

BSK och föreningen delar Stripe, Swish och bankgiro. När en bankimport körs
innehåller kontoutdraget en mix av BSK-biljettbetalningar och föreningens
medlemsavgifter. Stämningen måste kunna skilja dessa åt.

Lösning: BSK skickar **löpande betalningshändelser** till membrum2 i realtid
(eller natt-batch). Varje betalning skapar en väntande journal-rad märkt med
`cost_center=BSK-{år}`. När bankimporten sedan matchas mot dessa rader
kvitteras de automatiskt — precis som föreningens egna betalningar.

### Vad BSK skickar och när

### Betalningskanaler i BSK (nuläge)

- **CogWorks** — onlinebetalningar idag, används av både BSK och föreningen.
  **Skrotas när membrum2 går live.** Ingen långsiktig integration behövs.
- **Stripe** — ersätter CogWorks för onlinebetalningar. Troligen live i BSK
  först, sedan membrum2. Se nedan för separationsstrategi.
- **Zettle** — kortbetalning på plats, **enbart BSK**
- **SumUp** — kortbetalning på plats, **enbart Armagedon (föreningen)**
- **Kontant** — förekommer

### Separationsmekanismer (hur vi vet vem en betalning tillhör)

| Kanal | Identifierare | Automatisk? |
|---|---|---|
| Zettle | Avsändare i bankimport ("Zettle AB") | Ja → alltid BSK |
| SumUp | Avsändare i bankimport ("SumUp Europe") | Ja → alltid föreningen |
| CogWorks | CogWorks-konto i transaktionsreferensen | Ja → mappas mot kostnadsställe |
| Bankgiro | OCR-nummer | Ja → matchas mot faktura/inbetalning |
| Swish | Avsändare + belopp + datum | Delvis → kan behöva manuell hjälp |

Bankimportens matchningslogik konfigureras med dessa regler så att kassören
normalt inte behöver göra något manuellt för återkommande betalningstyper.

### Stripe — membrum2 som betalningshubb

Membrum2 äger Stripe-kontot och är den enda integrationen mot Stripe.
BSK (och eventuellt andra externa system) initierar betalningar via
membrum2:s API — de hanterar aldrig Stripe direkt och behöver inga
egna Stripe-credentials. En webhook-URL, ett Stripe-konto.

**Betalningsflöde (BSK → membrum2 → Stripe):**

```
1. BSK: POST /api/v1/payments/initiate
        { amount: 50000, cost_center: "bsk-2026",
          description: "BSK 2026 biljett", return_url: "..." }
2. Membrum2 skapar Stripe PaymentIntent med metadata
3. Returnerar { checkout_url, payment_id } till BSK
4. Användaren betalar på membrum2:s Stripe checkout (eller BSK embeddar den)
5. Stripe webhook → membrum2
6. Membrum2 bokför automatiskt mot rätt kostnadsställe
7. Membrum2 notifierar BSK: POST till BSK:s callback_url
```

**Bokföring vid utbetalning (Stripe betalar ut netto till bank):**

```
Utbetalning 45 000 kr:
  membrum2 hämtar balance transactions för utbetalningen från Stripe API
  → summerar per cost_center

  Debet  1920 Bank              45 000 kr
  Kredit 1930 Stripe clearing   45 000 kr

  Clearing nollas per cost_center:
  Kredit 3020 BSK-intäkter      30 000 kr  + Debet 1930
  Kredit 3010 Medlemsintäkter   15 000 kr  + Debet 1930
  Debet  6590 Kortavgifter         500 kr  (Stripe-avgifter, fördelas proportionellt)
```

Fullt automatiserbart — ingen manuell hantering vid utbetalning.

### SumUp — netto-bokföring (vald metod)

SumUp betalar ut **nettosumma veckovis** och skickar **månadsvis avstämningsfaktura**
med periodens avgifter. Vald bokföringsmetod:

```
Veckovis utbetalning (netto):
  Debet  1920 Bank          netto-belopp
  Kredit 3010 Försäljning   netto-belopp   ← inte brutto

Månadsvis avgiftsfaktura från SumUp (leverantörsfaktura):
  Debet  3010 Försäljning   avgiftsbelopp  ← minskar intäkten
  Kredit 2440 Lev.skulder   avgiftsbelopp
  (betalas sedan: Debet 2440, Kredit 1920)
```

Avgifterna reducerar alltså försäljningskontot direkt snarare än att bokföras
som en separat kostnad. Enkelt och korrekt för föreningsbruk.

Systemet stödjer detta via `posting_rules` där SumUp-utbetalningar (identifierade
via avsändare i bankimport) automatiskt bokas netto mot försäljningskontot.
Månadsavstämningsfakturan hanteras som en vanlig leverantörsfaktura från SumUp
med debet mot försäljningskontot istället för ett kostnadskonto.

| Händelse i BSK | Frekvens | Endpoint |
|---|---|---|
| CogWorks-betalning mottagen | Realtid eller daglig batch | `POST /api/v1/accounting/bsk/payment` |
| Zettle daglig summering | Natt-batch (Zettle API) | `POST /api/v1/accounting/bsk/daily-summary` |
| SumUp daglig summering | Natt-batch (SumUp API) | `POST /api/v1/accounting/bsk/daily-summary` |
| Kostnad/faktura godkänd | Vid godkännande | `POST /api/v1/accounting/bsk/expense` |
| Återbetalning | Vid återbetalning | `POST /api/v1/accounting/bsk/refund` |
| Konvent stängt — slutavstämning | Engång | `POST /api/v1/accounting/bsk/close` |

### Clearing-konton per kanal

```
1935 Zettle clearing     — utbetalar vanligen varje vardag
1936 SumUp clearing      — utbetalar med liknande frekvens
1937 CogWorks clearing   — utbetalningsschema enligt CogWorks-avtal
```

Varje betalning debiterar clearing-kontot. När utbetalningen landar på banken
matchas den mot clearing-kontot och nollas ut. Kassaavgifter (Zettle/SumUp tar
~1–2%) bokförs som kostnad vid utbetalningen.

### Payload (exempel — daglig Zettle-summering)

```json
{
  "idempotency_key": "bsk-zettle-2026-03-15",
  "konvent_year": 2026,
  "date": "2026-03-15",
  "description": "BSK 2026 — Zettle dagssummering",
  "payment_method": "zettle",
  "lines": [
    { "account_code": "3020", "credit": 320000, "description": "Biljettintäkter" },
    { "account_code": "2610", "credit": 80000,  "description": "Utgående moms 25%" },
    { "account_code": "1935", "debit":  400000, "description": "Zettle clearing" }
  ],
  "cost_center_code": "BSK-2026"
}
```

### Bankstämning med delade konton

```
Bankimport tas emot i membrum2:
  → Varje transaktion matchas mot:
     a. Föreningens egna poster (medlemsavgifter, fakturor etc.)
     b. BSK clearing-utbetalningar (Zettle, SumUp, CogWorks) via belopp + datum
  → Matchningslogiken söker i båda — kontot är känt som delat
  → Omatchat = flaggas manuellt
```

**Öppen fråga:** Zettle och SumUp betalar ut netto (efter avgifter) eller brutto?
Om netto behöver utbetalningsbeloppet stämmas mot clearing minus avgifter.
CogWorks utbetalningsschema behöver också kartläggas.

### Säkerhet och idempotens

- Token med scope `accounting:write` — begränsad behörighet
- `idempotency_key` per request → dubbelpostning blockeras
- Membrum2 validerar `sum(debit) == sum(credit)` innan verifikation skapas
- Kassören ser BSK-verifikationer märkta med källa "BSK" — kan granska
  men normalt inte redigera

### Konfiguration i BSK

- `MEMBRUM2_API_URL` och `MEMBRUM2_API_TOKEN` i `.env`
- `AccountingService` som bygger payloads och anropar membrum2
- Queue-baserad med retry — om membrum2 är nere missas inget

---

## SIE-export

SIE4-format — öppnar i Fortnox, Visma, Bokio etc.
Revisorn kan importera hela räkenskapsåret utan manuellt arbete.

```
app/Services/SieExportService.php
```

---

## Vyer (Livewire/Volt)

```
admin/accounting/dashboard        — översikt, senaste verifikationer, öppna poster
admin/accounting/journal          — verifikationslista + manuell post
admin/accounting/invoices         — fakturalista (AP + AR)
admin/accounting/invoices/upload  — PDF-upload + AI-förslag + godkännande
admin/accounting/bank             — bankkontoöversikt
admin/accounting/bank/import      — kontoutdragsimport + stämning
admin/accounting/cards            — betalkorts-register
admin/accounting/cards/{id}       — kortets kvitton + saknade
admin/accounting/receipts         — alla kvitton, godkännandelista för kassören
admin/accounting/expenses         — utlägg att godkänna + utbetala
admin/accounting/vendors          — leverantörsregister
admin/accounting/customers        — kundregister
admin/accounting/accounts         — kontoplan
admin/accounting/cost-centers     — kostnadsställen och projekt
admin/accounting/reports/result     — resultaträkning
admin/accounting/reports/balance    — balansräkning
admin/accounting/reports/budget     — budget vs utfall
admin/accounting/reports/comparison — jämförelse år mot år
admin/accounting/reports/cashflow   — kassaflöde
admin/accounting/reports/vat        — momsrapport
admin/accounting/budgets            — budgethantering (CRUD + import/export)
admin/accounting/period-close       — periodlåsning + bokslut
admin/accounting/approval-rules     — attestregler (CRUD)
admin/accounting/settings         — räkenskapsår, bokföringsregler, mallar
```

**Medlemsvyer (ej admin, mobilvänliga)**
```
/kvitton              — historik över egna kortkvitton + status
/kvitton/ladda-upp    — foto-upload för kortkvitto
/utlagg               — historik över egna utlägg + utbetalningsstatus
/utlagg/nytt          — foto-upload + beskrivning för utlägg
```

---

## Tekniska beroenden

- **ISO 20022 pain.001** XML-generering för batchutbetalningar (utlägg)
  - Kräver att members har `iban` + `bank_clearing_number` (clearingnr) sparat
  - `app/Services/Pain001ExportService.php`
  - Alla svenska banker tar emot formatet via nätbankens filuppladdning
- **Claude API** (eller annan LLM) för PDF- och bildextraktion — `ANTHROPIC_API_KEY` i `.env`
  - PDF-fakturor: text extraheras → skickas som text till LLM
  - Kvittofoton: bild skickas direkt till vision-API (claude-3-5-sonnet stödjer bilder)
- **smalot/fpdf-parser** eller **spatie/pdf-to-text** för PDF → text
- **PhpSpreadsheet** för Excel-import av bankutdrag
- Befintlig **Stripe webhook** + **Swish-import** kopplas till posting_rules

---

## Implementation (föreslagen ordning)

1. Migrations + modeller + kontoplan-seeder
2. Leverantörs- och kundregister (CRUD)
3. Manuell verifikation (journal_entries + journal_lines)
4. Faktura-upload + AI-extraktion + godkännandeflöde
5. Betalkort-register + kvitto-upload (mobilvänlig) + AI vision-extraktion
6. Bankkontohantering + kontoutdragsimport
7. Auto-stämning: Swish, bankgiro, kortransaktioner mot kvitton
8. Omatchad-hantering + påminnelser om saknade kvitton
9. Koppling till befintliga betalningsflöden (posting_rules)
10. Kostnadsställen + projekt (CRUD + rapportfiltrering)
11. BSK API-endpoints (`/api/v1/accounting/bsk/*`) + idempotens
12. Rapporter
11. SIE-export
12. API-endpoints

---

## Verifikationsserier

Fortnox-export 2026-04-12. Kolumn "Manuell kontering" = kassören kan bokföra manuellt i serien.
Höger panel = "förvalt serie per syfte" (samma koncept som förvalt bankkonto).

| Serie | Benämning | Manuell | Förvalt för |
|---|---|---|---|
| A | Redovisning | ✓ | — |
| B | Kundfakturor | — | Kundfakturor |
| C | Inbetalningar från kunder | — | Inbetalningar från kunder |
| D | Leverantörsfakturor | — | Leverantörsfakturor |
| E | Utbetalningar till leverantörer | — | Utbetalningar till leverantörer |
| F | Kassa | — | — |
| G | Avskrivning | — | Anläggningsregister |
| H | Periodisering | — | Periodisering kund- och leverantörsfakturor |
| I | Bokslut | — | Bokslut **(ej låsbar serie)** |
| J | Kontantfaktura | — | Kontantfaktura |
| K | Kvitto | — | Kvitton |
| L | Lön | — | Lön |
| M | Momsrapport | — | Momsrapport |
| N | Lager | — | Lager |
| S | Kiosk | ✓ | — |
| T | BSK | ✓ | — |
| U | Automatbokföring | ✓ | — |
| V | 40K Turnering | ✓ | — |
| W | CogWork | ✓ | — *(ersätts av Swish/Stripe)* |
| Z | Zettle | ✓ | — |

S hade 1272 poster (Kiosk = dagliga kassatransaktioner), U = 294 (automatbokföring), W = 163 (CogWorks), Z = 14 (Zettle).

### Membrum2 adopterar samma seriebokstäver

Kontinuitet med Fortnox är värdefull — kassören känner igen sig.
Serier lagras i `journal_series`-tabellen, konfigurerbara i admin.

```
journal_series
  id, code (A–Z, single char), name
  is_manual         — kassören kan skapa verifikationer manuellt i serien
  is_lockable       — false = I (Bokslut) — kan inte låsas per period
  default_for       — nullable enum: ar|ap|receipts|payroll|vat|...
  is_system         — true = A/B/C/D/E/U — skapas av systemet automatiskt
  sort_order
```

Förvalt serie per syfte lagras i `accounting_settings` med FK → `journal_series.id`,
identisk struktur som förvalt bankkonto.

### Kiosk-volym — dagssummering vs. per transaktion

S (Kiosk) hade 1272 verifikationer 2025 = ~3,5/dag året runt.
Det antyder att Fortnox bokför varje kassa-kvitto separat.

Membrum2 väljer **dagssummering** för kiosken: en verifikation per dag per kassaflöde
(Swish, kort, kontant separerade). Skäl:
- Enklare att stämma mot bankimport (banken redovisar per dag, inte per köp)
- Färre rader i journal_entries utan att förlora spårbarhet (detaljer finns i order_lines)
- Konsekvent med SumUp:s netto-utbetalning per vecka

Enskilda ordrar går alltid att spåra via `source_type/source_id → Order`.

---

## Automatiska bokföringsregler (posting_rules) — mappning

Från resultatrapporten 2025 vet vi exakt vilka konton som används:

| Händelse | Debet | Kredit | Konto |
|---|---|---|---|
| Medlemsavgift betald | 1933 Bankkonto Medlem | 3080 Medlemsavgifter | |
| Kiosk-försäljning | 1932 Bankkonto Kiosk | 3000 Kiosk och merch | |
| Swish online | 1931 Bankkonto Swish | 3080/3050 beroende på typ | |
| BSK-intäkt | clearing | 3051 BSK | |
| Bankkostnad | 6570 Bankkostnader | 1933 | |
| Kortavgift Swish/kortterm | 6060 Kreditförsäljningskostnader | 1931/1932 | |

---

## Öppna frågor

- ~~Föreningen har tre bankkonton~~ — bekräftat: tre konton hos Swedbank (Kiosk/Swish/Medlem), strukturen är klar
- Ska Stripe-utbetalningar auto-bokföras eller hanteras manuellt?
- Ingångsvärden: bekräftat att vi börjar från noll 2027-01-01 (IB matas manuellt)
- Räkenskapsår: bekräftat kalenderår (jan–dec)
