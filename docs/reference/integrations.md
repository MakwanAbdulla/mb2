# Externa integrationer

## BSK (Borås Spelkonvent)

BSK körs parallellt med membrum2 — de är oberoende system som kommunicerar via API.

**Arkitekturbeslut: kontolänkning, inte SSO**
- Full SSO avvisades: membrum2 skulle bli en kritisk dependency för BSK
- Konton länkas via e-post — BSK-användaren väljer att länka sitt membrum2-konto
- BSK lagrar `membrum2_member_id` på sin användare

**Integrationspunkter (fas 3, minimala):**
1. **Planerat, inte byggt** — ett endpoint där BSK kan fråga membrum2 vid kassa om rabatt gäller (ja/nej). `POST /api/v1/verify-member-qr` (`MemberCardController@verify`) existerar men löser ett annat problem (kiosk-incheckning via roterande QR-token från `/mitt-kort`, kräver att personen fysiskt visar sin kod) — inte lämpad för ett serverbaserat BSK-anrop vid kassa.
2. BSK exponerar registreringshistorik → membrum2 hämtar till medlemsprofil (ersätter CogWorks-data) — **planerat, inte byggt**

**Autentisering:** Sanctum API-nyckel per system (server-to-server). Enkel, ingen komplex cross-system-auth.

---

## Paxton Net2 (RFID-dörrsystem)

Paxton-servern är lokal (LAN) och kan inte nås direkt från webbservern (delat webbhotell). Lösning: en fristående polling-agent.

**Arkitektur:**
```
membrum2 REST API  ←── polling var 2:e minut ──  Node.js-agent  ──→  Paxton Net2
(internet)                                        (samma dator     (lokalt LAN)
                                                  som Paxton)
```

**Paxton-version:** Föreningen kör Net2 Lite — verifiera API-tillgång innan agenten byggs. Alternativ i prioritetsordning:
1. Net2 Web API (REST, nyare/Pro-versioner)
2. COM-automation via `win32ole` (Windows-only)
3. Direkt SQL Server ODBC (fragilt vid uppgraderingar)

**RFID UID-hantering:**
- USB-läsaren returnerar t.ex. `1312345678`
- Paxton vill **inte** ha de 2 första siffrorna → agenten strippar dem → `12345678`
- `member_tags.tag_uid` lagrar Paxton-formatet (utan prefix)

**API-endpoints för agenten:**
- `GET /api/v1/paxton/sync-queue` — taggar med `paxton_sync_status = pending`
- `POST /api/v1/paxton/sync-confirm` — `{ tag_id, status: "synced"|"failed", error? }`

**Tagglivscykel → Paxton-åtgärder:**

| Taggstatus | Paxton-åtgärd |
|---|---|
| active | Lägg till cardholder + card, eller aktivera |
| lost / broken | Deaktivera card (behåll cardholder) |
| deactivated | Deaktivera cardholder + card |
| ersätts av ny | Lägg till nytt card, deaktivera gammalt |

**Agentinstallation:**
- Node.js LTS på Windows, körs som tjänst via `node-windows` eller `pm2`
- `.env`: `MEMBRUM2_API_URL`, `MEMBRUM2_API_TOKEN`, `PAXTON_*`
- Polling-intervall: konfigurerbart, default 120 sekunder

---

## Kioskkassan (ESP32, fristående projekt)

Självbetjäning för godisförsäljningen i lokalen. En ESP32-S3 med kamera och pekskärm identifierar medlemmen med QR-koden från `/mitt-kort` och anropar `POST /api/v1/verify-member-qr` (`MemberCardController::verify`). Firmwaren ligger i ett eget repo (`~/esp32-projects/kiosk-kassa`), utanför membrum2.

Den första planen var en Raspberry Pi med en RFID-läsare som matar ut `13` + Paxton-numret, och den valdes bort till förmån för QR-koden. Resonemangen nedan gäller fortfarande:

**Credits (saldo), inte byggt:**
- Laddning online (Stripe/Swish) eller vid bemannad kassa.
- Juridik: troligen undantaget "begränsat nätverk" i betaltjänstlagen (högst 350 EUR utestående), men konsumentskyddsregler om återbetalning och förfall gäller ändå. Måste kontrolleras innan det byggs.
- En laddning bokförs som skuld (konto 2890 e.l.) tills den används.

**Betalkanaler:**

| Metod | Kostnad | Kommentar |
|---|---|---|
| Credits (laddade via Swish) | 1,50 kr/laddning | Billigast vid stora laddningar |
| Swish Företag direkt | 1,50 kr/köp | OK för enstaka köp |
| Kort (SumUp) | 1,49 % | Bra för stora belopp |

**Koppling till befintlig kod:** KIOSK-kostnadsstället följer med automatiskt via `bank_accounts.cost_center_id`, så SumUp-transaktioner hamnar rätt. Om kassan ska kunna skanna varor behövs ett `ean`-fält på `ProductVariant`.

---

## CogWorks

Användes i BSK för SSO + aktivitetsrapportering + onlinebetalningar.

- **Engångsmigration**: `php artisan import:cogworks` — importerar historisk data till membrum2
- **Avvecklas när membrum2 är live** — ingen långsiktig betalningsintegration via CogWorks
- **Stripe ersätter CogWorks** för onlinebetalningar (live i BSK först, sedan membrum2)
- Under övergångsperioden räcker bankimport-matchning via CogWorks-kontoreferens

---

## Actorsmartbook (Borås Stad bidragsrapportering)

Externt system för ansökan om Borås Stads föreningsbidrag. Exportformat okänt (avvaktar credentials).

**Status:** Planera för det, implementera inte än.

---

## Stripe

Ersätter CogWorks för onlinebetalningar. Öppen fråga: separata Stripe-konton per organisation (rekommenderat) eller gemensamt?

Troligen live i BSK först, sedan membrum2.

---

## Bankgiro / ISO 20022

BGMax-formatet avvecklades av Bankgirot september 2025 — **bygg ingen BGMax-parser**.

Nuvarande lösning: kontoutdrags-CSV/TXT matchas mot bankgiro-referens (OCR-matchning). ISO 20022 ersätter BGMax fullt ut när Swedbank specificerat det nya formatet.

---

## Discord

Implementerat (fas 6) — `App\Services\DiscordService`.
- OAuth-länkning av Discord-konto (`discord_id`, token lagras för refresh)
- Rollsynk (Spatie-roller → Discord-roller) — `php artisan discord:sync-roles`, körs dagligen 04:15
- Linked Role-metadata (Discords "Linked Roles"-funktion) pushas per användare
- Webhooks för notifieringar (t.ex. `notifyEventPublished()` vid publicerat evenemang)
- Daglig bokningssammanfattning — `php artisan discord:booking-summary` postar dagens bekräftade bokningar, lediga rum och publicerade evenemang (public/members_only) via webhooken i `discord.webhook.bookings` (`--date=Y-m-d` för annat datum). Tom inställning = inget skickas. Körs dagligen 07:00. Webhooken ställs in under `/admin/installningar?tab=discord`. Bokningar som pågår över flera dagar visas på varje dag; formateringen delas med översikten via `BookingDaySummary`.
- Bokningsöversikt för kommande 7 dagar — ett Discord-meddelande (samma webhook som sammanfattningen) som redigeras i stället för att nya postas. `BookingObserver` och massåtgärderna i `BookingManager`/`MyBookings` anropar `BookingOverviewService::queueRefresh()`, som via `defer()` uppdaterar en gång efter requesten oavsett hur många bokningar som ändrats. Bara ändringar av status, tid, rum eller titel inom perioden räknas. `php artisan discord:booking-overview` körs dagligen 00:05. Meddelandets ID sparas i `discord.bookings_overview.message_id`; ett nytt postas bara om Discord svarar 404 (meddelandet raderat), övriga fel loggas. Fäst meddelandet i kanalen manuellt.
- Slash-kommandon `/idag`, `/vecka` och `/ledigt [datum]` — Discord anropar `POST /discord/interactions` (`DiscordInteractionController`), som verifierar signaturen med `DISCORD_PUBLIC_KEY` och svarar via `DiscordCommandHandler`. Svaren syns bara för den som skrev kommandot. Innehållet byggs av `BookingDaySummary::dayEmbed()`, samma som morgonmeddelandet. Kommandona registreras på servern med `php artisan discord:register-commands`, som `app:post-deploy` kör vid varje deploy. Den som har ett kopplat konto och är medlem (`User::isMember()`) ser allt. Andra ser bara publika evenemang och rummen utan bokningarnas titlar, och får då en länk för att koppla sitt Discord-konto. Anrop med en tidsstämpel äldre än 5 minuter nekas.

**Metadata-schemat:** innan Linked Role-metadata kan pushas per användare måste
schemat vara registrerat hos Discord (`php artisan discord:register-metadata`).
Det görs av `app:post-deploy` vid varje deploy, så ändringar i
`RegisterDiscordMetadata`-kommandots fält (`is_member` m.fl.) går ut automatiskt.

Setup/driftdetaljer (bot-behörigheter, rollhierarki): se [deployment.md](../guides/deployment.md#discord).

---

## Inleed DocSign (BankID-signering)

Implementerat i #134 — `App\Services\Signing\DocsignClient` (API) och `SigningService` (flöden). Admin: `/admin/signeringar`. Behörigheter: `signing.create` skickar egna dokument och ser och pausar/avbryter sina egna ärenden, `signing.view` ser alla ärenden och saldot, `signing.manage` gör allt, inklusive signeringslänkar och inställningar.

**Flöde**
1. Ett ärende skapas (sida eller `POST /api/v1/admin/signing-requests`) med PDF:er och undertecknare. Undertecknare registreras hos DocSign med vårt id som `external_id`, med prefix per installation (`membrum.local:12`), eftersom miljöerna delar ett DocSign-konto.
2. `POST /documents` med bilagorna som base64 och callback-adresser per ärende: `/docsign/callback/{ärende}/{token}/{signed|completed}`. Token sparas bara som hash.
3. **Callbacks** (GET med `document_id` och ev. `party_id`, oautentiserade) tas bara emot från Inleeds adresser `194.68.59.169` och `194.68.59.115` (`DOCSIGN_CALLBACK_IPS`) och med rätt token. De loggas, och sedan hämtas status alltid från API:t. Det tas aldrig från anropet.
4. När alla har signerat laddas den signerade PDF:en ner, med `signed_sha256`, och `SigningRequestCompleted` skickas. Den som skapade ärendet får en notis.
5. `signing:sync` (var 15:e minut) är reserv för uteblivna callbacks. Den synkar ändrade undertecknare och saldot, och notifierar `signing.manage` vid lågt saldo (högst en gång per dygn).

**Värt att veta** (testat mot API:t 2026-10-23, detaljer i #134)
- Fel kommer med **HTTP 200** och `success: false`.
- Id:n är ibland heltal och ibland strängar, och görs om till strängar. `signed_pdf_url` är `""` tills dokumentet är signerat.
- `created_at` är svensk tid utan tidszon, `signed_at` är UTC.
- **1,25 kr per signatur**, som dras när dokumentet signeras. Saldot kontrolleras före utskick.
- **Den signerade PDF:en har ingen digital signatur**, bara en extra sida. `signed_sha256` är vårt bevis för att filen är oförändrad sedan den hämtades.
- Dokument har inget referensfält, så vår referens läggs sist i namnet: `Protokoll 12/3 [#3f9a1c]` (`signing_requests.reference`). Vid timeout letas dokumentet upp på det namnet innan ett nytt försök görs.
- **`POST /document/edit`** (`document_id`, `action`, testat 2026-10-27): `pause` bara från pending, `resume` bara från paused, `delete` bara på pausade eller färdiga dokument (därför pausar **Avbryt ärendet** först). `archive`/`unarchive` gäller bara färdiga dokument och syns bara i DocSigns webbgränssnitt, inte i API:t, så vi använder det inte. **En okänd action ger `success: true` utan att något händer**, så klienten kontrollerar den själv. Okänt id ger HTTP 404.
- **`POST /party/edit`** med `action: delete` raderar en undertecknare, även om den finns på ett pågående dokument (dokumentet behåller sin kopia). Redan raderad ger HTTP 404, vilket vi räknar som klart. Undertecknare kan inte ändras.

**Miljö**
- `DOCSIGN_API_KEY`: utan nyckel är allt avstängt.
- `DOCSIGN_DEMO`: gratis testdokument. Standard är på överallt utom när `APP_ENV=production`.
- `DOCSIGN_EXTERNAL_ID_PREFIX`: standard är värdnamnet i `APP_URL`.
- `DOCSIGN_EXTERNAL_RETENTION_YEARS`: `signing:prune-external` (månadsvis) anonymiserar externa undertecknare så här många år efter att deras ärenden stängts. Avstängt tills det sätts. De raderas sedan också hos DocSign. Misslyckade raderingar görs om vid nästa körning och listas på `/admin/signeringar`.

**Testa:** `php artisan signing:test-send {email}` (demo som standard) och `php artisan signing:show {id?}`.

---

## Google Workspace

Implementerat (fas 7) — `App\Services\GoogleWorkspaceService`. Två helt separata
Google-integrationer, lätt att blanda ihop:

1. **SSO-inloggning** (`GOOGLE_CLIENT_ID`/`SECRET`) — vanlig OAuth-login via
   Laravel Socialite, ingen Workspace-koppling. WorkOS ersattes pga kostnad.
2. **Workspace-provisionering** (`GOOGLE_WORKSPACE_*`) — ett service-konto med
   domänomfattande delegering (DWD) som administrerar organisationens Google
   Workspace-domän direkt. Inte alla medlemmar får Google-konton (business-regel,
   avgörs av styrelsen/kansliet).

Vad provisioneringen faktiskt gör (`GoogleWorkspaceService`, ~2100 rader):
- Skapa/avaktivera/radera konton, lösenordsåterställning, alias — `workspace:sync-*`-kommandon
- Delade brevlådor + delegater (kansliinkorg m.fl.) — synkas dagligen via `workspace:sync-delegates`
- Delade Drives + medlemskap per sektion/roll — `workspace:sync-drives`
- Inkorgsläsning/svar för delade mailboxar (används av kansliinkorgen i admin)
- Google Meet-space-skapande, användningsrapporter

**Engångs/manuell körning:** `php artisan workspace:import` (`--dry-run` för
att bara se matchningar) matchar befintliga `@armagedon.se`-konton mot
medlemmar och skapar `WorkspaceAccount`-poster. Körs av `DatabaseSeeder` på
en helt ny installation (hoppas över tyst om Workspace inte är konfigurerat),
men behöver köras manuellt igen om konton skapas i Workspace direkt (utanför
appen) som sedan behöver matchas mot medlemmar.

Setup/driftdetaljer (DWD, exakta scopes, de två skilda credential-typerna): se
[deployment.md](../guides/deployment.md#google-workspace).
