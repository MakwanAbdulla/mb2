# REST API

## Strategi

Varje webbfunktion byggs **alltid** med ett motsvarande API-endpoint i samma session. API:et är förstaklassigt — inte ett tillägg i efterhand.

Pågående arbete med säkerhet, robusthet och luckor samlas i epic **#271 API-översyn**.

## Teknisk specifikation

| Egenskap | Värde |
|---|---|
| Base URL | `/api/v1/` |
| Auth | Laravel Sanctum — Bearer-tokens (extern klient), cookie (same-domain SPA) |
| Routes | `routes/api.php` (registrerad i `bootstrap/app.php`) |
| Controllers | `app/Http/Controllers/Api/V1/` |
| Resources | `app/Http/Resources/` — aldrig råa modeller i svar |
| OpenAPI | `public/api/openapi.yaml` — **måste** täcka varje route (testas, se nedan) |
| Swagger UI | `/api-docs` — läser `openapi.yaml` via CDN |
| Rate limits | `throttle:api` på alla rutter, `throttle:api-auth` på token-utfärdande (se nedan) |

## Autentisering

| Klient | Hur | Token-abilities |
|---|---|---|
| Människa (app, skript) | `POST /auth/tokens` med e-post + lösenord | `*` |
| Integration | API-klient skapad på `/admin/api-klienter` → `POST /auth/client-tokens` med `client_id` + `client_secret` | klientens scopes, eller `*` om inga |
| Webbläsare på samma domän | Sanctum-cookie (stateful) | — |

Alla Bearer-anrop kräver behörigheten `api.access` (`EnsureApiAccess`). En API-klient får ett **servicekonto** (`users.is_service_account = true`) som bara har `api.access` när det skapas.

## Behörigheter och scopes

- Varje endpoint kontrollerar användarens behörighet med Spatie, nästan alltid i controllern (`abort_unless($user->hasPermissionTo('x'), 403)`), i några fall med `can:x` på routen.
- **Scopes** på en API-klient sparas som Sanctum-abilities på token. `User::hasPermissionTo()` kräver då att behörigheten **både** finns i token-scopes **och** hos servicekontot. Det gäller även `hasAnyPermission`, `can:`-middleware och `@can`, eftersom alla går via `hasPermissionTo`.
  - Scopes **begränsar**, de ger aldrig något. Ge servicekontot behörigheterna under Användare.
  - Gäller även servicekonton med rollen `admin`.
  - `api.access` släpps alltid igenom.
  - Klient utan scopes (`*`) = alla servicekontots behörigheter.
- Webbsessioner, SPA-cookies och personliga tokens (`*`) påverkas inte.

Kod: `User::tokenAllowsPermission()` i `app/Models/User.php`. Tester: `tests/Feature/Api/ApiClientTest.php`.

## Rate limits

Definierade i `AppServiceProvider::boot()`:

| Limiter | Gräns | Nyckel |
|---|---|---|
| `api` (alla `/api/v1`-rutter) | 120/min | användare/servicekonto |
| `api` oautentiserat | 60/min | IP |
| `api-auth` (`POST /auth/tokens`, `POST /auth/client-tokens`) | 10/min | IP |

Över gränsen → `429 Too Many Requests` med `Retry-After`.

## Svarsformat

**Fel:** standard Laravel JSON `{"message": "...", "errors": {...}}`. Maskinläsbara felkoder planeras i #261.

**Paginering:**
```json
{"data": [...], "meta": {"current_page": 1, "last_page": 5, ...}, "links": {...}}
```

**Belopp:** alltid öre (heltal) + `_formatted`-kompanjonfält:
```json
{"total_ore": 15000, "total_formatted": "150,00 kr"}
```

**Språk:** API-fel/meddelanden på engelska; svenska etiketter i separata fält.

## Checklista — nytt endpoint

1. Controller i `app/Http/Controllers/Api/V1/`
2. Resource i `app/Http/Resources/`
3. Route i `routes/api.php` under `v1`-prefix, inuti gruppen `auth:sanctum` + `api.access` om den kräver inloggning
4. Behörighetskontroll med `hasPermissionTo()` (då fungerar scopes automatiskt)
5. Uppdatera `public/api/openapi.yaml` — `OpenApiContractTest` failar annars
6. Nya enumvärden → `MetaController` + `/meta` i `openapi.yaml`
7. Pest-test i `tests/Feature/Api/`

## Kontraktstest

`tests/Feature/Api/OpenApiContractTest.php` kontrollerar att:

- specen är giltig, varje operation har `operationId` och varje tagg och `$ref` är definierad
- varje path i specen finns som route, och **varje `/api/v1`-route finns i specen**
- utvalda svar matchar specens scheman

## Täckning

378 routes (2026-11-05). Fullständig och aktuell lista: Swagger UI på `/api-docs` eller `php artisan route:list --path=api/v1`. Översikt per område:

### Publikt (auth valfri)
- `GET /meta` — enum-konstanter
- `GET /pages`, `/pages/{slug}`, `GET /navigation`, `GET /notices`
- `GET /help`, `/help/{groupSlug}` — inloggning visar medlemsinnehåll
- `GET /events`, `/events/{slug}`
- `GET /shop/products`, `/shop/products/{slug}`
- `GET /membership-fees`, `GET /booking-categories`
- `GET /surveys/{token}`, `POST /surveys/{token}/responses`
- `POST /verify-member-qr` — medlemskorts-QR (HMAC)
- `GET /wiki/spaces`, `/wiki/spaces/{slug}`, `…/pages`, `…/pages/{pageSlug}`, `…/revisions` — se Wiki nedan

### Auth
- `POST /auth/tokens`, `POST /auth/client-tokens`, `GET /auth/me`, `DELETE /auth/tokens/current`

### Eget konto
- `GET|POST|PATCH /membership`
- `GET /family`, `DELETE /family/membership`, `POST /family/invitation/accept`, `DELETE /family/invitation`
- `POST /account-deletion-requests`, `DELETE /account-deletion-requests/pending`
- `GET /orders`, `/orders/{order}`
- `GET|POST /expense-claims`, `GET /expense-claims/{id}`, `POST …/approve|reject`
- `GET /gamification/me`, `GET /gamification/leaderboard`, `POST /gamification/kudos/{member}`

### Medlemmar och organisation
- `GET|POST /members`, `GET|PATCH|DELETE /members/{member}`
- `GET /admin/account-deletion-requests`, `POST …/{id}/approve|reject`
- `GET /admin/family-groups`, `GET /admin/family-groups/{id}`, `POST …/remove-member`
- `GET|POST /admin/membership-fees`, `PATCH|DELETE …/{id}`
- `GET|POST /sections`, `GET|PATCH|DELETE /sections/{section}` — ersätts av grupper (#175)
- `GET /org-roles`, `/org-roles/{orgRole}` — ersätts av grupper (#175)
- `GET|POST /roles`, `GET|PATCH|DELETE /roles/{role}`, `PUT /users/{user}/roles`
- `GET|POST /admin/rfid-tags`, `GET|PATCH …/{memberTag}`, `GET|POST /admin/rfid-tags/prices`, `PATCH …/prices/{price}`
- `GET /paxton/sync-queue`, `POST /paxton/sync-confirm`
- `GET|POST /tags`, `DELETE /tags/{tag}`

### Bokning
- `GET|POST /bookings`, `GET|PATCH|DELETE /bookings/{booking}`
- `GET /booking-resources`, `/booking-resources/{id}`
- `GET /recurring-bookings`, `GET|DELETE /recurring-bookings/{id}`
- `GET /venue-applications`, `/venue-applications/{id}`
- Admin: `GET /admin/bookings`, `PATCH /admin/bookings/{booking}`, `GET /admin/booking-statistics`
- Admin: `GET|POST /admin/booking-categories`, `GET|POST /admin/booking-resources` (+ `PATCH|DELETE`)
- Admin: `POST /admin/venue-applications/{id}/approve|reject`

### Evenemang
- `GET|POST /events/{slug}/registrations`, `POST …/registrations/{id}/approve|reject`, `POST /events/{slug}/check-in`
- Admin: `GET|POST /admin/events`, `PATCH /admin/events/{event}`, `POST …/publish|cancel`
- Admin: occurrences (+ check-in), price-tiers, addons — full CRUD
- Admin: `GET /admin/events/{event}/admission`, `POST …/admission/approve|reject|walkin|admit-waitlist`
- `POST /scanner-relay/{session}/scan|heartbeat` — parkopplad scanner till incheckningskiosken
- `GET /tournaments`, `/tournaments/{id}` — publika, läsbara (admin saknas, #269)

### Shop och rabatter
- `GET|DELETE /shop/cart`, `POST /shop/cart/items`, `PATCH|DELETE /shop/cart/items/{id}`, `POST /shop/checkout`
- `POST /discounts/validate`
- Admin: `GET|POST /admin/shop/products` (+ varianter), `GET /admin/shop/orders`, `GET|PATCH …/{order}`
- Admin: `GET|POST /admin/discounts`, `GET|PATCH|DELETE …/{id}`, koder: `POST`, `DELETE`, `GET …/png`, `GET …/zip`

### Uthyrning
- `GET /rentals`
- Admin: `GET|POST /admin/rentals`, `PATCH …/{rental}`
- Admin: `GET|POST /admin/rental-item-types`, `PATCH …/{type}`, `GET|POST …/{type}/prices`, `PATCH …/prices/{price}`

### Bokföring (`/accounting/…`)
- Räkenskapsår: `GET|POST fiscal-years`, `PATCH …/{fy}`, `GET|PUT …/{fy}/opening-balances`, `GET|POST …/{fy}/sie4` (SIE4 export/import)
- Kontoplan: `GET|POST accounts`, `PATCH|DELETE accounts/{account}`
- Verifikationer: `GET|POST journal-entries`, `GET journal-entries/{id}` — storno saknas (#260)
- Serier och mallar: `journal-series`, `journal-templates`, `posting-rules`, `cost-centers`
- Leverantörer/kunder: `GET|POST vendors|customers`, `GET|PATCH …/{id}`
- Fakturor (leverantörsfakturor och kundfakturor): `GET|POST invoices`, `GET|PATCH invoices/{id}`, `POST …/approve|cancel|mark-paid`, `POST invoices/import-zip`
- Attest: `GET|POST approval-rules`, `PATCH|DELETE …/{id}`, `POST …/{id}/toggle`
- Bank: `GET|POST bank-accounts`, `PATCH|DELETE …/{id}`, `POST …/{id}/import|swish-import|bank-fee-import`, `POST lb-return`
- Banktransaktioner: `GET bank-transactions`, `GET|PATCH …/{id}`, `POST …/{id}/confirm-match`, `GET bank-transactions/reconcile-suggestions`
- Underlag: `GET|POST documents`, `DELETE documents/{id}`, `POST|DELETE documents/{id}/link`, `GET|PATCH inbox`, `POST receipt-scan`
- Budget: `GET|POST budgets`, `GET|PATCH budgets/{id}`, `POST …/lines`, `PATCH|DELETE …/lines/{line}`
- Övrigt: `payment-providers`, `payment-terms`, `corporate-cards`, `GET dashboard`
- Revision: `GET revision`, `POST revision/bulk-approve`, `POST|DELETE revision/{entry}/review`
- Rapporter: `GET reports/balance-sheet|income-statement|general-ledger|budget-variance|journal-list|vendor-report` — JSON, eller PDF med `?format=pdf` (alla utom `vendor-report`)

### Möten, ärenden och omröstningar
- `GET|POST /meetings`, `GET|PATCH /meetings/{id}`, dagordning: `GET|POST …/agenda-items`, `PATCH|DELETE …/{item}`, `POST …/attendees/check-in`
- Admin: `/admin/meeting-templates` med punkter — full CRUD
- `GET|POST /submissions`, `GET|PATCH /submissions/{id}`
- `POST /polls`, `GET /polls/pending`, `GET /polls/{poll}`, `POST …/open|close|close-manual|vote`
- `GET|POST /tasks`, `GET|PATCH /tasks/{task}`

### Kommunikation
- `GET|POST /mailings`, `GET|PATCH|DELETE /mailings/{id}`, `POST …/send|cancel`
- Admin: `/admin/mailing-templates` — full CRUD
- Admin: `GET /admin/mail-templates`, `GET|PATCH …/{id}` — transaktionella mail
- Admin: `GET /admin/mail-log`, `GET …/{id}`
- Admin: `/admin/inbox-mailboxes` — full CRUD
- `GET /contact-messages`, `GET|PATCH /contact-messages/{id}`
- Admin: `GET|POST /admin/signing-requests`, `GET …/{id}`, `POST …/pause|resume|cancel` — `sign_url` exponeras aldrig

### Wiki
- `GET /wiki/spaces` — utrymmen anroparen kan läsa, arkiverade utelämnas (auth valfri: utan token bara `public`-utrymmen)
- `GET /wiki/spaces/{slug}` — ett utrymme; 404 även när det inte får läsas, så stängda utrymmen inte avslöjas
- `GET /wiki/spaces/{slug}/pages` — sidorna i ett utrymme utan innehåll (`parent_id` + `sort_order` ger trädet), `GET /wiki/spaces/{slug}/pages/{pageSlug}` — en sida med innehåll (auth valfri)
- `POST /wiki/spaces/{slug}/pages`, `PATCH|DELETE /wiki/spaces/{slug}/pages/{pageSlug}` — kräver token, `api.access` och skrivrätt i utrymmet; innehållet saneras (`WikiHtml`). Valfritt `summary` beskriver ändringen i historiken
- `GET /wiki/spaces/{slug}/pages/{pageSlug}/revisions` — sidans historik, nyast först, utan innehåll (paginerad); `GET …/revisions/{id}` — en revision med innehåll. Samma läsrätt som sidan (auth valfri)

### Innehåll
- Admin: `GET|POST /admin/pages`, `PATCH|DELETE …/{page}`, `POST …/publish|unpublish`
- Admin: `POST /admin/navigation`, `PATCH|DELETE …/{item}`
- Admin: `GET|POST /admin/notices`, `PATCH|DELETE …/{id}`
- Admin: `GET|POST /admin/help/sections`, `PATCH|DELETE …/{id}`, `GET /admin/help/texts`, `PATCH …/{id}`
- Admin: `GET|POST /admin/forms`, `GET|PUT|DELETE …/{form}`
- Admin: `GET|POST /admin/surveys`, `GET|PATCH|DELETE …/{id}`, `GET …/{id}/responses`

### System
- Admin: `GET /admin/settings`, `PATCH /admin/settings/{key}` — krypterade värden maskeras
- Admin: `GET /admin/audit-log`
- Admin: `GET /admin/statistics/members|events|revenue`
- Admin: `GET /admin/workspace/accounts|drives|delegates` — läsbart
- API-klienter hanteras på `/admin/api-klienter` (Livewire, ej REST)

## Kända luckor

Webbfunktioner som saknar API, med issue:

| Område | Issue |
|---|---|
| Storno av verifikation | #260 |
| Bokföring: redigera verifikation, periodlås, bokför/skicka/PDF för fakturor, attestflöde, bokför/ignorera/koppla banktransaktioner | #265 |
| Bekräfta/avslå medlemsbetalningar, Bankgiro-import (`/admin/betalningar`) | #266 |
| Medlemsinbjudan, Google Workspace-åtgärder, avregistreringsrapport | #267 |
| Egna inställningar, profilbild, lösenord, GDPR-export, mina signeringar | #268 |
| Turneringsadministration | #269 |
| Skicka in formulärsvar, gamification-admin, kommunrapport, bokningsaktiviteter | #270 |
| Grupper (ersätter sektioner och org-roller) | #175 |

Planerade förbättringar av hela API:et: Idempotency-Key (#259), felkoder (#261), `dry_run` (#262), bättre beskrivningar i OpenAPI (#263).

## Medvetet utan API

- TV-vyer, PDF-utskrifter av turneringar och ical-flöden — de är redan maskinläsbara eller bara för visning
- Betalnings-callbacks (Stripe, Swish, DocSign) — egna webhook-rutter i `routes/web.php`
- API-klienthantering — bara på webben, så att en läckt token inte kan skapa nya klienter
