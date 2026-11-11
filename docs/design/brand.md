# Brand identity — Armagedon designsystem

> **OBS:** `app.css` och den här sidan stämmer inte på alla punkter (`.btn-sm`, `.btn-secondary`, `.btn-danger`, `.lbl`, `.inp`, `.shimmer-*`), se #124. Det som står i `resources/css/app.css` är det som syns i appen.

Style guide lives at `/grafiskprofil/light` and `/grafiskprofil/dark` (standalone pages, no layout dependency). Keep these working — they are used for PDF export.

## Logotyp

- `public/armagedon-logo-light.svg` — mörk text, för ljus bakgrund
- `public/armagedon-logo-dark.svg` — vit text, för mörk bakgrund
- Dimensioner: 242×84 px (ratio 2.88:1). Skeleton: `width:144px; height:50px`

Always show both with theme toggle:
```html
<img src="{{ asset('armagedon-logo-light.svg') }}" alt="Armagedon" class="h-8 dark:hidden">
<img src="{{ asset('armagedon-logo-dark.svg') }}" alt="Armagedon" class="hidden h-8 dark:block">
```

## Varumärkesfärger (CSS-variabler i resources/css/app.css)

| Klass | Hex | Användning |
|---|---|---|
| `bg-brand` / `text-brand` | #fddd10 | Primärknapp, aktiv tab — **alltid med `text-brand-dark` ovanpå** |
| `brand-hover` | #fee81b | Hover på primärknapp |
| `bg-brand-saffron` | #fbc712 | Focus-ring, gradient-mittpunkt |
| `brand-amber` | #f9a70d | Varningsbadge (semantisk), prismärkning |
| `text-brand-orange` / `bg-brand-orange` | #eb8111 | Ikoner, extern-länkpil, logotypdetalj |
| `text-brand-dark` / `bg-brand-dark` | #231f20 | Text på gult, primär textfärg ljust tema |
| `bg-warm-dark` | #1c1a1b | Sidbakgrund mörkt tema |
| `bg-complement` | #4c1d95 | Sekundär CTA-knapp, ikonbakgrund |
| `complement-mid` | #6d28d9 | Hover på complement-element |
| `text-complement-light` / `bg-complement-light` | #7c3aed | Aktiv nav mörkt tema |

## Kritiska regler

- **ALDRIG vit text på brand-gult** — kontrast 1.1:1, underkänt WCAG
- **ALDRIG violett + orange på samma yta** — välj EN per vy
- Semantiska färger (grön/röd/blå/amber) **enbart för systemstatus**, aldrig dekorativt
- Status-badges: **alltid ikon + text + färg** (aldrig färg ensam, WCAG 1.4.1)
- Fokusringar: `focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2`

## Brand-gradient (avdelare + shimmer)

```
from-brand via-brand-orange to-complement
#fddd10 → #eb8111 → #4c1d95
```

```html
<!-- Avdelare -->
<div class="h-0.5 rounded-full bg-gradient-to-r from-brand via-brand-orange to-complement"></div>
```

## CSS-hjälpklasser (definierade i app.css)

### Knappar
```css
.btn          /* bas: inline-flex, rounded-lg, px-4 py-2, text-sm font-semibold */
.btn-sm       /* px-3 py-1.5, text-[0.8125rem] */
.btn-primary  /* bg-brand text-brand-dark; hover: bg-brand-saffron */
.btn-secondary /* bg-white border-zinc-300; dark: bg-zinc-700 */
.btn-ghost    /* transparent, text-zinc-700 */
.btn-danger   /* bg-red-500 text-white */
```

### Formulär
```css
.inp   /* full-width, rounded-lg, border zinc-300, px-3 py-2, text-sm; focus: ring */
.lbl   /* block, text-sm font-medium, text-zinc-700, mb-1 */
```

### Shimmer-loaders
```css
.shimmer-neutral  /* grå shimmer, 1.6s; dark-variant inbyggd */
.shimmer-brand    /* brand-gradient, 2s */
.shimmer-bar      /* progress-bar-fill */
```
Alla respekterar `prefers-reduced-motion`.

## Typografi

| Nivå | Klasser |
|---|---|
| H1 | `text-5xl font-bold text-zinc-900 dark:text-white` |
| H2 | `text-3xl font-bold text-zinc-900 dark:text-white` |
| H3 | `text-2xl font-semibold text-zinc-900 dark:text-white` |
| Ingress | `text-xl leading-relaxed text-zinc-600 dark:text-zinc-300` |
| Brödtext | `leading-relaxed text-zinc-700 dark:text-zinc-300` |
| Liten/meta | `text-sm text-zinc-500 dark:text-zinc-400` |

## Textfärger (neutral bakgrund)

| Klass | Användning |
|---|---|
| `text-zinc-900 dark:text-white` | Rubriker, maximal kontrast |
| `text-zinc-700 dark:text-zinc-300` | Brödtext |
| `text-zinc-500 dark:text-zinc-400` | Meta, datum, nav-länk |
| `text-zinc-400 dark:text-zinc-500` | Placeholder — aldrig läsbar info |

## Hörnavrundning

| Klass | Används för |
|---|---|
| `rounded-lg` | Input, knapp |
| `rounded-xl` | Dropdown-panel, inre kort |
| `rounded-2xl` | Kort, modal, sektionsbox |
| `rounded-full` | Badge, avatar, toggle |

## Komponentmönster

### Badge/status
```html
<span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-400">
    <x-bootstrap-icon name="check-circle-fill" class="size-3" /> Aktiv
</span>
```

### Sektionsbox
```html
<div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
```

### Kort
```html
<div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
```

### Tabell
```html
<div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">
  <table class="w-full text-sm">
    <thead>
      <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
        <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">…</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-950">
```

### Tom tillstånd
```html
<div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-zinc-200 dark:bg-zinc-700">
  <x-bootstrap-icon name="inbox" class="size-8 text-zinc-400" />
</div>
```

## Bootstrap Icons

2079 SVGs i `public/images/icons/bootstrap/`. Inline-komponent:
```html
<x-bootstrap-icon name="house" class="size-5" />
```
Admin icon picker: `app/Livewire/Admin/Navigation/BootstrapIconPicker.php`
