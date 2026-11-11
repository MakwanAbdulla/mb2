# UI — layouter, navigering och gemensamma komponenter

Den här sidan är till för utvecklare (och Claude) som ska jobba med det som
är gemensamt för alla sidor i membrum2: layouterna, sidhuvudet, sidopanelen,
modulernas undermenyer, temat (ljust/mörkt), CSS-klasserna och de delade
Blade-komponenterna. Den förklarar hur det är byggt, var koden finns och hur
du hittar issues som rör det.

Hur det ska *se ut* — färger, typografi, komponentmönster — står i
[brand.md](../design/brand.md). Hur modulsidor med undermeny byggs står i
[ui-patterns.md](../design/ui-patterns.md). Den här sidan beskriver koden runt
omkring.

---

## Vad räknas till UI-modulen?

Etiketten `modul:ui` används för det som inte hör till en enskild modul:

- layouterna i `resources/views/components/layouts/`
- sidhuvudet, kontomenyn och sidopanelen (vänstermenyn för inloggade)
- modulernas undermenyer (`components/*-nav.blade.php`) som mönster —
  innehållet i en undermeny hör till respektive modul
- tema (ljust/mörkt/system)
- `resources/css/app.css` och de gemensamma CSS-klasserna
- delade komponenter: `bootstrap-icon`, `sidebar-link`, `page-header`,
  `permission-hint`, `help-icon` m.fl.
- hjälptexter vid formulärfält (epic #52)

Den **publika webbplatsens** innehåll och toppmeny (CMS-navigeringen) hör
till `modul:webb`, se [cms.md](cms.md).

---

## Hur det hänger ihop

```
Livewire-/Volt-komponent
  → layout('components.layouts.public')         (standard i config/livewire.php)
  → layouts/public.blade.php
       ├─ temaskript (före första målning)
       ├─ partials/head.blade.php               meta, Vite, @fluxAppearance, Trix
       ├─ <header>  logga, CMS-toppmeny, tema, notiser, kontomeny
       │    └─ partials/notice-banner.blade.php  driftmeddelanden
       ├─ <aside>   sidopanel (inloggad) / CMS-meny (gäst, mobil)
       ├─ modulens undermeny  <x-*-nav />        vald efter route-namn
       │    eller $header-slotten               <x-slot:header>
       ├─ <main>    {{ $slot }}
       └─ <footer>  texter och länkar från help_text('footer.*')
```

| Del | Vad den gör | Kod |
|---|---|---|
| Layouter | Sidans skal | `resources/views/components/layouts/` |
| Head | Meta, CSS/JS, Flux, Trix (från unpkg) | `resources/views/partials/head.blade.php` |
| Driftmeddelanden | Roterande banner under sidhuvudet | `partials/notice-banner.blade.php`, `Notice` (admin: `/admin/notices`, `notices.manage`) |
| Sidopanel | Länkar för inloggade, lägena *Jag*/*Admin* | `layouts/public.blade.php`, `<x-sidebar-link>` |
| Undermenyer | Pill-meny per adminmodul | `components/*-nav.blade.php` (15 st.) |
| Tema | Ljust/mörkt/system per användare | `users.ui_theme`, `livewire/theme-toggle.blade.php` |
| CSS | Tailwind v4, Flux, varumärkesfärger, `.btn`/`.inp`/`.lbl` | `resources/css/app.css` |
| Ikoner | Bootstrap Icons som inline-SVG | `<x-bootstrap-icon>`, `php artisan icons:sync` |
| Grafisk profil | Fristående stilguide, används för PDF | `/grafiskprofil/{light,dark}`, `grafisk-profil.blade.php` |

---

## Layouter

| Layout | Används av | Har |
|---|---|---|
| `public` | Nästan alla sidor (~140), standard för Livewire | Allt ovan |
| `auth` | Inloggning, registrering, lösenord, tvåfaktor (`resources/views/auth/`) | Bara logga + innehåll, centrerat |
| `onboarding` | *Bli medlem* (`membership.apply`, `membership.payment`) och väntesidorna för Swish/Stripe (`resources/views/payment/`, `membership/`) | Stegindikator, *Avbryt* |
| `focused` | Medlemskortet (`pages/member-card`) | Sidhuvud utan sidopanel, smal |
| `scanner` | Kiosken (`scanner/kiosk`) och turneringslottningen (`admin/tournaments/draw`) | Bara logga + innehåll |
| `app` | **Äldre:** `MeetingView`, `MyMeetings`, `SubmissionShow` | En egen meny med andra länkar. Ska bort, se #122 |

Livewire-komponenter väljer layout med `layout('components.layouts.x')` (Volt)
eller `->layout(...)` (klassbaserade). Utan val blir det `public`
(`config/livewire.php` → `component_layout`).

**Ny sida:** använd `public`. Gör ingen ny layout utan att ta upp det i en
issue först — varje layout har i dag en egen kopia av temaskriptet och
kontomenyn, och de har redan glidit isär (#123).

### Sidhuvudet och `$header`

Under sidhuvudet visar `public` antingen en moduls undermeny eller, om ingen
matchar, komponentens `<x-slot:header>`. Undermenyn väljs i en lång
`@if/@elseif` på route-namn längst ner i `public.blade.php`:

```blade
@elseif(request()->routeIs('admin.statistics.*'))
    <x-statistics-nav />
```

Det betyder att **en sida i en modul med undermeny inte visar sin egen
`$header`**. Behövs rubrik och knappar — lägg dem i innehållet.

En sida som kan visas både fristående och som flik i en annan sida tar en
`$embedded`-prop, se [ui-patterns.md](../design/ui-patterns.md#the-embedded-prop).

---

## Sidopanelen

Sidopanelen finns bara i `public`. Alla länkar är hårdkodade i
`public.blade.php` med `<x-sidebar-link>`.

- **Lägen.** Den som är personal (`User::isStaff()`) får en växel *Jag* /
  *Admin*. Valt läge sparas i `localStorage` (`sidebar_mode`, Alpine
  `$persist`). Står man på en `admin.*`- eller `booking.admin.*`-route är
  standardläget *Admin*.
- **Grupper** (*Armagedon & jag*, *Lokal & bokning* …) går att fälla ihop,
  men det sparas inte.
- **Fullt medlemskap.** Länkar som kräver betalt medlemskap visas bara om
  `$hasFullAccess` (personal eller `Member::hasFullAccess()`). *Boka lokal*
  visas låst i stället för att döljas. Samma regel finns som `User::canBook()`
  och används av bokningssidorna, API:t och toppmenyn i `app`-layouten.
  *Mina beställningar* och *Mina anmälningar* visas för alla inloggade, eftersom
  man kan ha sådana utan att vara medlem (#160).
- **Behörigheter.** Varje adminlänk omges av `@can` eller
  `hasAnyPermission([...])`. Villkoret ska vara **samma** som sidans
  `abort_unless` i `mount()`. Så är det inte alltid i dag, se #125.
- **Mobil.** Under `lg` är panelen en utfällbar meny (hamburgaren) och visar
  också CMS-menyn under *Webbplatsen*.
- **Kiosk.** Med `session('kiosk_mode')` loggas användaren ut efter
  `config('app.kiosk_timeout_minutes')` utan aktivitet.

`<x-sidebar-link>` tar `href`, `icon`, `active`, `permission` och `target`.
Med `permission` visas ett lås med behörighetens namn för den som har
`users.manage-roles` (`<x-permission-hint>`), så att administratörer ser vad
som styr länken.

### Lägg till en länk

Följ modulchecklistan i `CLAUDE.md`. För sidopanelen:

1. Lägg länken i rätt grupp i `public.blade.php` och omge den med samma
   behörighet som sidan kräver.
2. Om behörigheten är ny: lägg den även i `User::isStaff()`, annars syns inte
   adminläget för den som bara har den.
3. Har modulen en undermeny: lägg route-mönstret både i länkens `:active`
   och i `@elseif`-kedjan som väljer `<x-*-nav />`.
4. Testa som en användare med just den **rollen**, inte som admin — admin
   passerar alla behörighetskontroller.

Navigeringen ska byggas om, se #122. Där finns förslaget om ett gemensamt
menyregister som ersätter de hårdkodade länkarna.

---

## Tema (ljust/mörkt)

`users.ui_theme` är `light`, `dark` eller `system` (`User::THEME_*`).
Tailwind använder klassen `dark` på `<html>`
(`@custom-variant dark (&:where(.dark, .dark *))` i `app.css`).

1. Servern sätter `class="dark"` om användaren valt mörkt.
2. Ett inline-skript **före** `partials.head` läser
   `localStorage['ui_theme']` (vinner) eller serverns värde och sätter eller
   tar bort `dark`. `system` följer `prefers-color-scheme`.
3. Efter `wire:navigate` körs samma logik igen på `livewire:navigated`.
4. Temaknappen (`theme-toggle`) och inställningssidan
   (`dashboard/settings`) sparar i databasen *och* i `localStorage`.

**Känt fel:** `@fluxAppearance` i `partials/head.blade.php` körs efter steg 2
och tillämpar operativsystemets tema, så det valda temat skrivs över vid
sidladdning (#123). Rätta det innan du bygger vidare på temalogiken.

Varje vy ska ha `dark:`-varianter. Bakgrunder: `bg-white` /
`dark:bg-warm-dark` för sidan, `dark:bg-zinc-900` för sidhuvud och paneler.

---

## CSS och komponenter

### Två sorters knappar och fält

Det finns två parallella system, och båda används:

| | Flux UI Free | Egna klasser i `app.css` |
|---|---|---|
| Knappar | `<flux:button>` (~160) | `.btn .btn-primary` … (~100 vyer) |
| Fält | `<flux:input>`, `<flux:select>`, `<flux:textarea>`, `<flux:label>` | `.inp`, `.lbl` |
| Övrigt | `<flux:modal>`, `<flux:checkbox>` | — |

Flux Free saknar `flux:tabs`, `flux:tab`, `flux:option` och `size="lg"` på
knappar (se `CLAUDE.md`). Följ det som redan används i filen du ändrar.

`.btn-sm` används i ~50 vyer men finns inte i `app.css`, och den grafiska
profilen beskriver flera klasser annorlunda än `app.css` (#124). **Det som
står i `app.css` är det som syns** — brand.md och `/grafiskprofil` stämmer
inte på alla punkter.

### Delade komponenter

| Komponent | Gör | Anmärkning |
|---|---|---|
| `<x-bootstrap-icon name="…" class="size-5" />` | Inline-SVG från `public/images/icons/bootstrap/` | Saknad ikon ger ingenting, inget fel |
| `<x-page-header title="…" subtitle="…" />` | Rubrikband med brand-gradient | `permission` visar lås, `editUrl` visar penna för `cms.edit` |
| `<x-sidebar-link>` | Länk i sidopanelen | Se ovan |
| `<x-permission-hint permission="…" />` | Lås med behörighetens namn, bara för `users.manage-roles` | Läser alla behörigheters etiketter en gång per request |
| `<x-help-icon section="…" />` | Info-ikon som länkar till `/hjalp#…` | Används inte någonstans i dag |
| `<x-account-select>`, `<x-member-search>`, `<x-document-viewer>` … | Modulspecifika | Hör till respektive modul |
| `<x-section-meetings>` | — | Används inte |

Planerade komponenter som ska ersätta upprepad markup: `<x-status-badge>`
(#37), `<x-action-link>` (#38), `<x-flash-message>` (#39), sortering upp/ner
(#40), `<x-pill-nav>` (#41) och `<x-help-text>` (#45). De är små och passar
bra som första uppgift.

### Ikoner

`public/images/icons/bootstrap/` är gitignorerad. Kör
`php artisan icons:sync` efter kloning; Dockers entrypoint gör det första
gången. Sök ikoner på [icons.getbootstrap.com](https://icons.getbootstrap.com).

---

## Kända avvikelser

Kontrollerat 2026-10-23. Varje punkt har en issue — stryk punkten här i
samma PR som stänger issuen.

| Issue | Avvikelse |
|---|---|
| #112 | Sidopanelen är tom för en medlem om någon tidigare valt *Admin* i samma webbläsare: `sidebar_mode` ligger kvar i `localStorage`, och medlemmen har ingen växel. |
| #113 | *Mina hyror* i sidopanelen pekar på `#` (rättas i PR #114). |
| #123 | `@fluxAppearance` skriver över det valda temat vid sidladdning. Blinkar i `public`/`focused`, fel hela tiden i `app`, `auth`, `onboarding` och `scanner`. |
| #124 | `.btn-sm` saknas i `app.css`. `.btn-secondary`, `.btn-danger`, `.lbl`, `.inp` och `.shimmer-*` skiljer sig mellan brand.md/grafiska profilen och `app.css`. |
| #125 | Flera behörigheter ger adminläget men ingen länk: t.ex. rollen `tag-manager` (RFID-taggar kräver `rentals.manage` i menyn), `payments.view` utan medlemsbehörighet, `bookings.statistics`, `notices.manage`/`help.manage` utan `cms.edit`. |
| #126 | `/admin/enkater` har ingen länk, utom i den äldre layouten `app`. |

**Lärdom:** när en meny och en sida har var sitt behörighetsvillkor glider
de isär. Testa menyn som en användare med en enda roll, och kontrollera att
varje sida under `/admin` har `abort_unless` i `mount()` — en saknad länk
gömmer inte en sida.

---

## Hitta och skapa issues

Allt som rör layouterna, navigeringen för inloggade, temat och de gemensamma
komponenterna har etiketten **`modul:ui`**. Se
[issues.md](../guides/issues.md) för hur man tar en issue, skapar branch och
länkar PR:en.

| Vill du … | Filter |
|---|---|
| se allt öppet för UI | [`label:modul:ui is:open`](https://github.com/Membrum2/membrum2/issues?q=is%3Aopen+label%3Amodul%3Aui) |
| hitta en bra första uppgift | [`label:modul:ui label:"good first issue"`](https://github.com/Membrum2/membrum2/issues?q=is%3Aopen+label%3Amodul%3Aui+label%3A%22good+first+issue%22) |
| se buggar | [`label:modul:ui label:bug`](https://github.com/Membrum2/membrum2/issues?q=is%3Aopen+label%3Amodul%3Aui+label%3Abug) |
| se större arbeten | [`label:modul:ui label:epic`](https://github.com/Membrum2/membrum2/issues?q=is%3Aopen+label%3Amodul%3Aui+label%3Aepic) |

Med `gh`:

```bash
gh issue list --label modul:ui
gh issue list --label modul:ui --label "good first issue"
```

**När du skapar en issue** om UI: sätt `modul:ui` plus `bug`, `enhancement`
eller `refaktorering`. Gäller det innehållet eller menyn på den publika
webbplatsen är det `modul:webb`. Gäller det en enskild moduls sidor är det
den modulens etikett, även om felet syns i layouten.

### Pågående och planerat arbete

Läget 2026-10-23 — kolla filtren ovan för det aktuella.

| Issue | Handlar om |
|---|---|
| #122 Översyn av menysystemet | Kartlägga och bygga om navigeringen: lägen, menyregister, snabbsök, en layout, mobil. Bör göras innan större ändringar i sidopanelen. |
| #52 Hjälptexter vid formulärfält (epic) | Komponenten `<x-help-text>` (#45) och sedan modul för modul (#46–#51) |
| #42 Rich text-editor för mailinnehåll | WYSIWYG i stället för `<textarea>` med rå HTML i utskick och mailmallar |
| #37–#41 | Små komponenter som tar bort upprepad markup (`good first issue`) |

---

## Tester

| Test | Täcker |
|---|---|
| `tests/Feature/BladeViewsCompileTest.php` | Att alla Blade-vyer kompilerar |

Layouterna, sidopanelens behörighetsvillkor och temat saknar egna tester.
Ett behörighetstest tvärs över alla moduler finns som #101. Hur man testar
för hand står i [manual-testing.md](../guides/manual-testing.md).
