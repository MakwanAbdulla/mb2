# Membrum2 som betalningsgateway

> **⚠️ PLANERAT — EJ IMPLEMENTERAT.** Allt nedan (endpoints, `PaymentGatewayService`,
> `PaymentCallbackService`, `payment_clients`/`payments`-tabellerna) är ett designförslag,
> inte byggd funktionalitet. Inget av detta finns i koden (`grep` efter
> `PaymentGatewayService`, `payment_clients`, `PaymentCheckoutController` etc. ger noll
> träffar, och `routes/api.php` saknar en `/payments`-rutt helt). membrum2:s faktiska
> betalningar (Stripe, Swish, Bankgiro, manuellt) går via `routes/web.php` (`StripeController`,
> `SwishController`) och hanterar bara membrum2:s egen kassa — inte en delad gateway för
> externa system som BSK. Den riktiga nuvarande betalningsflödet är inte dokumenterat
> någonstans än; detta dokument beskriver bara den framtida, inte byggda, visionen.

## Översikt (framtida design — se varningen ovan)

Membrum2 äger betalningsinfrastrukturen för hela organisationen — ett Stripe-konto,
en webhook-URL, ett ställe där bokföringen sker. Externa system (BSK och andra)
initierar betalningar via API och får tillbaka en betalningslänk att skicka
användaren till. Membrum2 hanterar flödet, bekräftar, bokför och skickar tillbaka
användaren till avsändarens URL.

---

## API: Initiera betalning

```
POST /api/v1/payments
Authorization: Bearer {token}
```

```json
{
  "idempotency_key": "bsk-order-4521",
  "amount": 50000,
  "currency": "SEK",
  "description": "BSK 2026 — 2 vuxenbiljetter",
  "cost_center": "bsk-2026",
  "customer": {
    "name": "Anna Andersson",
    "email": "anna@example.com"
  },
  "metadata": {
    "bsk_order_id": "4521",
    "bsk_konvent_id": "12"
  },
  "return_urls": {
    "success": "https://www.borasspelkonvent.se/biljetter/tack?order=4521",
    "cancel":  "https://www.borasspelkonvent.se/biljetter/avbruten?order=4521",
    "failure": "https://www.borasspelkonvent.se/biljetter/fel?order=4521"
  },
  "callback_url": "https://www.borasspelkonvent.se/api/payment-webhook"
}
```

**Svar:**

```json
{
  "payment_id": "pay_abc123",
  "checkout_url": "https://www.armagedon.se/betala/pay_abc123",
  "expires_at": "2026-03-15T14:30:00Z"
}
```

BSK skickar användaren till `checkout_url`. Betalningen upphör om användaren
inte slutför den inom `expires_at`.

---

## Betalningssidans layouter

Membrum2 visar en landningssida under betalningsflödet. Utseendet anpassas
efter vem som initierade betalningen (`source`), men Armagedon är alltid
synligt som den som hanterar betalningen.

### Branding per avsändare

**BSK-betalningar:**
- BSK:s logga + namn prominänt
- Undertitel: "Betalningen hanteras av Armagedon spelförening"
- Färger och känsla: BSK:s profil
- Användaren förstår kopplingen utan att behöva känna till Armagedon i förväg

**Armagedon-betalningar (medlemsavgifter etc.):**
- Armagedon-logga och profil
- Ingen hänvisning till annan organisation

Branding-konfiguration (`payment_clients`-tabell, en rad per extern klient):
```
id, name ("BSK"), slug ("bsk")
logo_path, primary_color
tagline  — t.ex. "Borås Spelkonvent 2026"
handled_by_text  — t.ex. "Betalningen hanteras av Armagedon spelförening"
```

### Layout A — Genomför betalning
Visas när användaren landar på `checkout_url`.

- Avsändarens logga + namn + tagline
- "Betalningen hanteras av [handled_by_text]" (diskret fotnot om annan org)
- Ordersammanfattning (beskrivning, belopp)
- Stripe Payment Element (kort, Swish etc.)
- "Avbryt"-länk → redirect till `return_urls.cancel`

### Layout B — Bekräftelse / Fel
Visas efter genomförd eller misslyckad betalning, innan redirect.

**Lyckad:**
- Avsändarens logga
- Grön bekräftelse, orderreferens
- "Du skickas tillbaka till [avsändare]..." med countdown (3 sek) + manuell länk
- Auto-redirect → `return_urls.success`

**Misslyckad / avbruten:**
- Tydligt felmeddelande eller "Betalning avbruten"
- Möjlighet att försöka igen (tillbaka till Layout A)
- Länk → `return_urls.failure` / `return_urls.cancel`

Layouterna är fristående — ingen Flux, ingen app-nav. Ren Blade-vy med
Tailwind och Alpine. Liknande `enkater/svara`-sidan i struktur.

---

## Webhook tillbaka till avsändaren

När betalningen är bekräftad av Stripe skickar membrum2 en webhook
till `callback_url`:

```json
{
  "event": "payment.completed",
  "payment_id": "pay_abc123",
  "idempotency_key": "bsk-order-4521",
  "amount": 50000,
  "currency": "SEK",
  "paid_at": "2026-03-15T14:12:33Z",
  "metadata": {
    "bsk_order_id": "4521",
    "bsk_konvent_id": "12"
  }
}
```

Vid misslyckad betalning:

```json
{
  "event": "payment.failed",
  "payment_id": "pay_abc123",
  "idempotency_key": "bsk-order-4521",
  "reason": "card_declined"
}
```

Webhooks signeras med HMAC-SHA256 (`X-Membrum-Signature` header) —
BSK verifierar signaturen med en delad hemlighet konfigurerad i BSK:s `.env`.

---

## Återbetalningar

```
POST /api/v1/payments/{payment_id}/refund
```

```json
{
  "amount": 25000,
  "reason": "Arrangörsbyte — delåterbetalning"
}
```

Membrum2 hanterar Stripe-återbetalningen och bokför automatiskt.
Webhook skickas till `callback_url` med `event: "payment.refunded"`.

---

## Säkerhet

- API-token med scope `payments:write` — utfärdas per extern klient (BSK får sitt)
- `idempotency_key` krävs — skyddar mot dubbeldebitering vid retry
- `checkout_url` är engångs-URL med tidsbegränsning
- HMAC-signatur på utgående webhooks
- `callback_url` måste vara HTTPS

---

## Teknisk implementation

```
routes/web.php:
  GET  /betala/{payment_id}           → PaymentCheckoutController (layout A)
  GET  /betala/{payment_id}/tack      → PaymentSuccessController  (layout B, lyckad)
  GET  /betala/{payment_id}/avbruten  → PaymentCancelController   (layout B, avbruten)

routes/api.php:
  POST /api/v1/payments               → Api\V1\PaymentController@initiate
  POST /api/v1/payments/{id}/refund   → Api\V1\PaymentController@refund
  GET  /api/v1/payments/{id}          → Api\V1\PaymentController@show

app/Http/Controllers/
  PaymentCheckoutController.php  — visar betalningssidan med Stripe Payment Element
  PaymentSuccessController.php   — bekräftelse + auto-redirect
  PaymentCancelController.php    — avbruten/fel + retry-möjlighet
  Api/V1/PaymentController.php   — API-endpoints

app/Services/
  PaymentGatewayService.php      — skapar PaymentIntent, hanterar webhook, bokför
  PaymentCallbackService.php     — skickar webhook till avsändaren med HMAC-signatur

database/migrations:
  payment_clients                — en rad per extern klient (BSK etc.) med branding
  payments                       — lagrar alla betalningar oavsett källa
    id, idempotency_key (unique)
    source (membrum|bsk|...)
    cost_center, amount, currency
    status (pending|completed|failed|refunded)
    stripe_payment_intent_id
    customer_name, customer_email
    metadata (JSON)
    return_url_success, return_url_cancel, return_url_failure
    callback_url
    paid_at, expires_at
    journal_entry_id
```

---

## Öppna frågor

- ~~Branding per avsändare~~ — löst: BSK-logga + "hanteras av Armagedon"-text
- Swish på betalningssidan — QR-kod eller telefonnummer-inmatning?
- Behöver BSK kunna lista/söka sina egna betalningar via API?
