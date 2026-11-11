# CMS — webbplatsens innehåll

Den här sidan är till för utvecklare (och Claude) som ska jobba med CMS-delen
av membrum2. Den förklarar vad modulen är till för, hur den är byggd och var
koden finns, och hur du hittar issues som rör den.

**Hur man använder CMS:et** som redaktör står i appens hjälpsidor, inte här:
`/hjalp/admin-system` → *CMS och navigation*, *Skapa ny CMS-sida*,
*Hantera navigationsträdet* och *Publicera och avpublicera sidor*.
Ändras beteendet för redaktörer — uppdatera de hjälpsektionerna också
(se [Hjälpsektioner](#hjälpsektioner-hjalp)).

---

## Vad är ett CMS?

Ett CMS (*content management system*) låter personer som inte är utvecklare
skapa och ändra innehållet på en webbplats — texter, bilder, menyer — via ett
gränssnitt i webbläsaren, utan att röra koden eller göra en driftsättning.
Koden bestämmer *hur* sidorna ser ut och fungerar; CMS:et bestämmer *vad* som
står på dem.

## Varför har membrum2 ett CMS?

Föreningens publika webbplats — startsidan, *Om oss*, aktivitetssidorna,
stadgarna, kontaktsidan — ligger i samma system som medlemsregistret,
evenemangen och bokföringen. Det ger två saker:

1. **Styrelsen och webbredaktörerna kan hålla webbplatsen uppdaterad själva.**
   Rollen `website-editor` (Webbredaktör) har bara CMS-behörigheter och kommer
   inte åt medlemmar eller ekonomi.
2. **Sidorna kan visa levande data från systemet** via *widgets*: en text om
   medlemskap kan innehålla `[membership-fees]` och visar då alltid de
   avgifter som faktiskt gäller, och `[organizational-role slug="styrelsen"]`
   visar den sittande styrelsen. Ingen behöver komma ihåg att uppdatera texten.

CMS:et är medvetet enkelt: sidor med HTML-innehåll, en meny och widgets.
Det är inte en sidbyggare med layoutblock (det finns planer på det, se
[Pågående och planerat](#pågående-och-planerat-arbete)).

---

## Hur det hänger ihop

```
Besökare → GET /om-oss/var-lokal
        → routes/web.php: catch-all  Volt::route('/{path}', 'pages.show')   (sist i filen)
        → pages/show.blade.php: Page::published() via path_prefix + slug
        → cms_content($page->content)          app/helpers.php
        → WidgetService::render()              byter [tagg] mot renderade Blade-vyer
        → components/layouts/public.blade.php  sidhuvud, CMS-meny, sidopanel
```

| Del | Vad den gör | Kod |
|---|---|---|
| Sidor | Innehåll med utkast/publicering, URL via slug | `App\Models\Content\Page`, `pages/show.blade.php` |
| Editorn | Trix-editor + paneler för bilder, ikoner, widgets, färger | `App\Livewire\Admin\Pages\Edit`, `admin/pages/` |
| Widgets | `[tagg]`-kortkoder som blir dynamiskt innehåll | `App\Services\WidgetService`, `components/cms/` |
| Navigation | Toppmenyn på webbplatsen | `App\Models\Content\NavigationItem` |
| Hjälptexter | Korta texter bredvid fält i appen, redigerbara utan deploy | `App\Services\HelpTextService`, `help_text()` |
| Hjälpsektioner | Hjälpsidorna på `/hjalp` | `App\Models\Content\HelpSection` |

Tabellerna beskrivs i [data-model.md](data-model.md#cms-pages--navigation),
API:et i [api.md](api.md#cms--navigation).

---

## Sidor

### URL:er och routing

- En sidas URL är `/{slug}` eller `/{path_prefix}/{slug}` (`Page::url()`).
  Bara **en** nivå prefix stöds — `/om-oss/var-lokal` fungerar,
  `/a/b/c` ger 404.
- Alla sidor nås via **catch-all-routen sist i `routes/web.php`**. Nya routes
  måste därför läggas **före** den. Och tvärtom: en CMS-sida vars slug krockar
  med en befintlig route (t.ex. `dashboard`) går aldrig att nå.
- Tre sidor har egna routes och specialbehandling:

  | Slug | Route | Vy | Anmärkning |
  |---|---|---|---|
  | `hem` | `/` (`home`) | `pages/home.blade.php` | Visar knappen *Bli medlem* om sidan saknas |
  | `kontakt` | `/kontakt` (`page.contact`) | `pages/contact.blade.php` | CMS-text + kontaktformulär (Livewire) |
  | `om-oss` | via catch-all | `pages/show.blade.php` | |

  De tre finns i `Page::PROTECTED_SLUGS` och kan inte tas bort i admin.

### Utkast och publicering

`pages` har dubbla kolumner för det redigerbara: `title`/`title_draft`,
`subtitle`/`subtitle_draft`, `content`/`content_draft`, plus `has_draft`.

- **Besökare ser bara de publicerade kolumnerna** (`content`, inte `content_draft`).
- Spara i editorn (`Edit::update()`) skriver bara till `*_draft` och sätter
  `has_draft = true`.
- *Spara och publicera* (`Edit::saveAndPublish()` → `Page::publishDraft()`)
  kopierar utkastet till de publicerade kolumnerna och tömmer utkastet.
- *Kasta utkast* → `Page::discardDraft()`.
- Slug, prefix, publiceringstider m.m. har **inget** utkast — de sparas direkt.

`Page::scopePublished()` kräver `is_published = true` och att nu ligger mellan
`published_at` och `unpublished_at` (båda valfria). Så går det att schemalägga
publicering utan något cron-jobb.

Sidor och menyposter använder soft deletes och en stabil `seeder_key`, se
[Seeder vs migration](../conventions.md#seeder-vs-migration-för-systemdata).
Startinnehållet (sidorna och menyn) skapas i dag av `PageSeeder`, som bara körs
lokalt. **En ny systemsida som måste finnas i produktion läggs till via en
migration** med `updateOrInsert` på `seeder_key`.

### Innehållet är rå HTML

Innehållet sparas som HTML från Trix och skrivs ut med `{!! !!}` utan
sanering. Det är bara säkert för att endast betrodda användare (`cms.edit`)
kan redigera. Öppna aldrig redigering för fler roller utan att först lägga
till HTML-sanering.

### Editorn

`/admin/pages/{page}/edit` är en klassbaserad Livewire-komponent
(`App\Livewire\Admin\Pages\Edit` + `livewire/admin/pages/edit.blade.php`).
Sidlistan (`index`) och *Ny sida* (`create`) är Volt. Under editorn finns en
panel med fyra flikar:

| Flik | Komponent | Gör |
|---|---|---|
| Bilder | `App\Livewire\Admin\Pages\ImageBrowser` | Listar och laddar upp bilder. Uppladdade hamnar på disken `public` under `pages/` (kräver `php artisan storage:link`); bilder i `public/images/` visas också. |
| Ikoner | `App\Livewire\Admin\Pages\BootstrapIconBrowser` | Söker bland Bootstrap-ikonerna (`php artisan icons:sync`) |
| Widgets | `<x-cms.widget-reference />` | Listar alla registrerade widgets från `WidgetService::all()` — en ny widget dyker upp här automatiskt |
| Färger | `<x-cms.color-reference />` | Föreningens grafiska profil och färgade rutor att infoga |

Vid sparning varnar editorn för `[taggar]` som inte är registrerade
(`$unknownWidgetsWarning`), men sparar ändå.

---

## Widgets

En widget är en kortkod i sidinnehållet som byts mot en renderad Blade-vy
när sidan visas.

```
[membership-fees]
[organizational-role slug="styrelsen" year="2026"]
```

**Så fungerar `WidgetService::render()`:**

- Taggnamn: små bokstäver, siffror och bindestreck (`[a-z0-9-]+`).
- Attribut: `namn="värde"`. **Använd dubbla citattecken** — enkla citattecken
  matchar taggen men värdet tolkas inte.
- Vyn får alla attribut som strängar i `$params`. Booleska värden tolkas i vyn
  med `filter_var(..., FILTER_VALIDATE_BOOLEAN)`.
- Okänd tagg lämnas orörd i texten.
- Kraschar vyn: felet rapporteras, loggas i granskningsloggen som
  `widget_error` (område `cms`), och taggen ersätts med en HTML-kommentar.
  Resten av sidan visas som vanligt.
- `WidgetService` är en singleton (`AppServiceProvider::register()`);
  widgetarna registreras i `AppServiceProvider::boot()`.

**Registrerade widgets:**

| Tagg | Vy (`components/cms/`) | Visar |
|---|---|---|
| `membership-fees` | `membership-fees` | Aktiva medlemsavgifter för i år (och nästa år om inlagda) |
| `organizational-role` | `organizational-role` | Ledamöter i en roll, t.ex. styrelsen (`slug`, valfritt `year`) |
| `page` | `page-link` | Länk till en CMS-sida (`id`) |
| `menu` | `menu-link` | Länk till ett menyalternativ (`id`) |
| `route` | `route-link` | Länk till en namngiven route (`name`, övriga attribut blir routeparametrar) |
| `url` | `url-link` | Länk till extern adress (`url`) |

Länkwidgetarna tar dessutom `text`, `new_tab` och `box` (visa som markerad ruta).

`cms_content()` används i `pages/show.blade.php` och `pages/contact.blade.php`.
Ska en annan vy visa CMS-innehåll — använd `cms_content()`, inte
`{!! $page->content !!}`.

### Steg för steg: lägg till en widget

Exempel: en widget `[next-events]` som visar kommande evenemang.

1. **Skapa vyn** `resources/views/components/cms/next-events.blade.php`.
   Den får `$params` (strängar). Hantera saknade eller felaktiga parametrar med
   en diskret text i stället för att krascha — se `url-link.blade.php`.
   Använd `not-prose` på block som inte ska få textstilar, svenska texter och
   `dark:`-varianter (se [brand.md](../design/brand.md)).

   ```blade
   @php
       $limit  = (int) ($params['limit'] ?? 3);
       $events = \App\Models\Events\Event::query() /* ... */ ->limit($limit)->get();
   @endphp
   <div class="not-prose my-4 space-y-2">
       @forelse($events as $event)
           {{-- ... --}}
       @empty
           <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga kommande evenemang.</p>
       @endforelse
   </div>
   ```

2. **Registrera den** i `app/Providers/AppServiceProvider.php`, efter de
   befintliga `$widgets->register(...)`. `label`, `description` och
   parameterbeskrivningarna visas för redaktören i Widgets-fliken — skriv dem
   på svenska.

   ```php
   $widgets->register(
       tag: 'next-events',
       view: 'components.cms.next-events',
       label: 'Kommande evenemang',
       description: 'Visar de närmaste publicerade evenemangen.',
       params: [
           ['name' => 'limit', 'required' => false, 'description' => 'Antal evenemang (standard: 3).'],
       ],
       example: '[next-events limit="5"]',
   );
   ```

3. **Skriv tester** i `tests/Feature/Services/`, enligt mönstret i
   `WidgetServiceCmsLinksTest.php`: rendera med
   `app(WidgetService::class)->render('[next-events]')` och kontrollera
   HTML:en. Testa även saknade parametrar och tomt resultat.

4. **Prova i editorn**: widgeten syns i Widgets-fliken. Infoga den på en sida,
   publicera och titta på den publika sidan.

5. **Uppdatera tabellen över widgets** ovan.

En widget behöver ingen ny behörighet, ingen API-endpoint och ingen ändring
i `MetaController` — checklistan för nya moduler i `CLAUDE.md` gäller inte.

---

## Navigation

`navigation_items` är ett träd (`parent_id`) med poster som har en `label`
och antingen `route_name` eller `url` — eller ingetdera, och är då en rubrik
för en undermeny. `resolvedUrl()` föredrar `route_name` om routen finns.

Toppmenyn byggs högst upp i `components/layouts/public.blade.php`:

```php
$navItems = NavigationItem::active()->topnav()->rootItems()->with('children')->get();
```

Layouten visar två nivåer: rotposter och deras barn som rullgardinsmeny.
Admin: `/admin/navigation` (Volt, `admin/navigation/`).

Menyn på webbplatsen ska inte förväxlas med **sidopanelen** (vänstermenyn för
inloggade). Sidopanelens länkar är hårdkodade i `public.blade.php` och hör
till `modul:ui`, inte CMS:et, se [ui.md](ui.md#sidopanelen).

---

## Hjälptexter och hjälpsektioner

Båda räknas till CMS:et i admin, men är innehåll *i appen*, inte på
webbplatsen.

### Hjälptexter

Korta nyckel–värde-texter som visas bredvid fält och formulär:
`help_text('nyckel')` (eller `help_text('nyckel', 'url')`).
`HelpTextService` läser alla texter en gång per request. En saknad nyckel ger
tom sträng och en varning i loggen. Nya nycklar läggs till via migration
(`help_texts.key` är den stabila nyckeln). Admin: `/admin/help-texts`.

### Hjälpsektioner (`/hjalp`)

Hjälpsidorna: `help-page.blade.php` (översikt) och `help-group.blade.php`
(`/hjalp/{grupp}`). **Grupperna är definierade i kod** i
`HelpSection::groups()`; sektionerna ligger i `help_sections` och kopplas till
en grupp via `group_label`. Synlighet styrs per sektion av `show_public`,
`show_auth` och `required_permission`. Innehållet läggs in via migrationer
(`*_seed_help_sections*.php`). Admin: `/admin/hjalp-sektioner` (`help.manage`).

---

## Behörigheter

| Behörighet | Ger | Kontrolleras i |
|---|---|---|
| `cms.edit` | Redigera sidor, navigation och hjälptexter | `Edit::mount()`, `admin/pages/`, `admin/navigation/`, `admin/help-texts/`, redigeringslänken i `page-header` |
| `cms.publish` | Publicera sidor | Bara i adminmenyn och `User::isStaff()`, se #116 |
| `help.manage` | Hantera hjälpsektioner | `admin/help-sections/`, `help-group.blade.php` |

Rollen `website-editor` har alla tre (plus `notices.manage`).
Testkonto: `redaktor@example.com`. Observera att admin alltid passerar
`hasPermissionTo()` — testa behörigheter med redaktörskontot, inte admin.

---

## Kända avvikelser

Kontrollerat 2026-10-23. Varje punkt har en issue — stryk punkten här i
samma PR som stänger issuen.

| Issue | Avvikelse |
|---|---|
| #103 | Startsidan renderar inte widgets: `home.blade.php` använder `{!! $page->content !!}` i stället för `cms_content()`. |
| #115 | Admin-API:t (`PageAdminController`, `NavigationAdminController`) kräver `pages.view` / `pages.manage`, som inte finns. Alla utom admin får 500 `PermissionDoesNotExist`. Admin märks inte, eftersom `User::hasPermissionTo()` alltid är `true` för rollen `admin`. `CmsAdminTest` skapar behörigheterna själv och missar felet. |
| #116 | `cms.publish` kontrolleras inte: `Edit::saveAndPublish()` kräver bara `cms.edit`. |
| #117 | `navigation_items.requires_auth` och `permission` filtreras inte. `scopeForUser()` anropas inte (och verkar felaktig), så alla aktiva menyalternativ visas för alla. |
| #118 | Menyalternativ med `location = footer` visas aldrig; layouten hämtar bara `topnav`. |
| #119 | `pages.show_in_nav`, `nav_title` och `sort_order` sparas men påverkar inte menyn (`Page::scopeInNav()` anropas inte). |
| #120 | CMS-hjälpsektionerna kräver `content.manage` / `pages.manage` (finns inte) och nämner ett inloggningskrav för sidor som inte finns. |

**Lärdom för behörighetskod:** testa som en användare med rätt *roll*, inte
som admin, och skapa inte behörigheter i testet som `PermissionSeeder` inte
skapar. Annars döljs den här typen av fel.

---

## Hitta och skapa issues

Allt som rör CMS:et och den publika webbplatsen har etiketten **`modul:webb`**
(sidor, navigering, nyheter, sidtexter, mediebibliotek). Se
[issues.md](../guides/issues.md) för hur man tar en issue, skapar branch och
länkar PR:en.

| Vill du … | Filter |
|---|---|
| se allt öppet för webben | [`label:modul:webb is:open`](https://github.com/Membrum2/membrum2/issues?q=is%3Aopen+label%3Amodul%3Awebb) |
| hitta en bra första uppgift | [`label:modul:webb label:"good first issue"`](https://github.com/Membrum2/membrum2/issues?q=is%3Aopen+label%3Amodul%3Awebb+label%3A%22good+first+issue%22) |
| se buggar | [`label:modul:webb label:bug`](https://github.com/Membrum2/membrum2/issues?q=is%3Aopen+label%3Amodul%3Awebb+label%3Abug) |
| se större arbeten | [`label:modul:webb label:epic`](https://github.com/Membrum2/membrum2/issues?q=is%3Aopen+label%3Amodul%3Awebb+label%3Aepic) |

Med `gh`:

```bash
gh issue list --label modul:webb
gh issue list --label modul:webb --label "good first issue"
```

**När du skapar en issue** om CMS:et: sätt `modul:webb` plus `bug` eller
`enhancement` (`refaktorering`, `test` eller `documentation` när det passar
bättre). Lämna `prio:` till den som går igenom backloggen om du är osäker.
Gäller det sidopanelen eller layouten för inloggade, inte webbplatsens
innehåll, är det `modul:ui`.

### Pågående och planerat arbete

Läget 2026-10-23 — kolla filtren ovan för det aktuella.

| Epic | Handlar om | Delissues |
|---|---|---|
| #107 Ny startsida med knappar och puffar | Startsida byggd av block, knappar och puffar som widgets | #103 (bugg, `good first issue`), #104, #105, #106 |
| #57 Nyheter på webbplatsen | Nyhetsmodell, admin, startsida, notiser, RSS/Discord | #53–#56 |
| #61 Mediebibliotek | Bildöversikt, optimering, nedladdningsbara filer | #58–#60 |
| #65 Referenslänkar | Spåra varifrån besökare kommer, QR-koder | #62, #63 (`good first issue`) |

Övrigt: #108 (kontaktformuläret som mejl till kansliet), #97 (praktisk test
av webbplatsen, se [manual-testing.md](../guides/manual-testing.md)) och
buggarna #115–#120 från genomgången av modulen, se
[Kända avvikelser](#kända-avvikelser).

---

## Tester

| Test | Täcker |
|---|---|
| `tests/Feature/Services/WidgetServiceCmsLinksTest.php` | Länkwidgetarna `page`, `menu`, `route`, `url` |
| `tests/Feature/Api/CmsAdminTest.php` | Admin-API för sidor och navigation |
| `tests/Feature/Api/OpenApiContractTest.php` | API:et mot `public/api/openapi.yaml` |

Editorn, utkastflödet och de publika sidorna saknar egna tester.
