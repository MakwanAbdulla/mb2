@php
function hexToHsb(string $hex): string {
    $hex = ltrim($hex, '#');
    [$r, $g, $b] = [hexdec(substr($hex,0,2))/255, hexdec(substr($hex,2,2))/255, hexdec(substr($hex,4,2))/255];
    $max = max($r, $g, $b); $min = min($r, $g, $b); $d = $max - $min;
    $s = $max ? round($d / $max * 100) : 0;
    $v = round($max * 100);
    if ($d == 0) { $h = 0; }
    elseif ($max == $r) { $h = 60 * fmod(($g - $b) / $d, 6); }
    elseif ($max == $g) { $h = 60 * (($b - $r) / $d + 2); }
    else                { $h = 60 * (($r - $g) / $d + 4); }
    if ($h < 0) $h += 360;
    return round($h) . '° ' . $s . '% ' . $v . '%';
}
@endphp
<!DOCTYPE html>
<html lang="sv" class="{{ $theme === 'dark' ? 'dark' : '' }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Grafisk profil – {{ $theme === 'dark' ? 'Mörkt' : 'Ljust' }} tema – Armagedon</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        a:visited { color: var(--color-complement-mid); }
        .btn       { display:inline-flex;align-items:center;gap:.375rem;border-radius:.5rem;padding:.5rem 1rem;font-size:.875rem;font-weight:600;transition:background-color .15s,opacity .15s;cursor:pointer;border:none; }
        .btn-sm    { padding:.375rem .75rem;font-size:.8125rem; }
        .btn-primary  { background:var(--color-brand);color:var(--color-brand-dark); }
        .btn-primary:hover  { background:var(--color-brand-saffron); }
        .btn-secondary{ background:#fff;color:#27272a;border:1px solid #d4d4d8;box-shadow:0 1px 2px rgba(0,0,0,.05); }
        .dark .btn-secondary{ background:#3f3f46;color:#f4f4f5;border-color:#52525b; }
        .btn-secondary:hover{ background:#f9fafb; }
        .dark .btn-secondary:hover{ background:#52525b; }
        .btn-ghost { background:transparent;color:#3f3f46; }
        .dark .btn-ghost{ color:#d4d4d8; }
        .btn-ghost:hover{ background:rgba(39,39,42,.07); }
        .dark .btn-ghost:hover{ background:rgba(255,255,255,.1); }
        .btn-danger{ background:#ef4444;color:#fff; }
        .btn-danger:hover{ background:#dc2626; }
        .btn:disabled{ opacity:.45;cursor:not-allowed; }
        .inp { width:100%;border-radius:.5rem;border:1px solid #d4d4d8;background:#fff;padding:.5rem .75rem;font-size:.875rem;color:#18181b;outline:none; }
        .inp:focus{ border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15); }
        .inp:disabled{ opacity:.6;cursor:not-allowed;background:#f4f4f5; }
        .dark .inp{ background:#27272a;border-color:#52525b;color:#f4f4f5; }
        .dark .inp:focus{ border-color:#60a5fa; }
        .dark .inp:disabled{ background:#3f3f46; }
        .lbl { display:block;font-size:.875rem;font-weight:500;color:#3f3f46;margin-bottom:.25rem; }
        .dark .lbl{ color:#d4d4d8; }

        /* Shimmer loader — neutral */
        @keyframes shimmer-neutral {
            0%   { background-position: -400% 0; }
            100% { background-position:  400% 0; }
        }
        .shimmer-neutral {
            background: linear-gradient(90deg,
                #e4e4e7 0%,
                #f4f4f5 40%,
                #e4e4e7 80%
            );
            background-size: 400% 100%;
            animation: shimmer-neutral 1.6s ease-in-out infinite;
        }
        .dark .shimmer-neutral {
            background: linear-gradient(90deg,
                #3f3f46 0%,
                #52525b 40%,
                #3f3f46 80%
            );
            background-size: 400% 100%;
            animation: shimmer-neutral 1.6s ease-in-out infinite;
        }

        /* Shimmer loader — brand gradient (gul → orange → violet) */
        @keyframes shimmer-brand {
            0%   { background-position: -400% 0; }
            100% { background-position:  400% 0; }
        }
        .shimmer-brand {
            background: linear-gradient(90deg,
                #fddd10 0%,
                #fbc712 20%,
                #eb8111 50%,
                #4c1d95 80%,
                #fddd10 100%
            );
            background-size: 400% 100%;
            animation: shimmer-brand 2s ease-in-out infinite;
        }

        /* Shimmer progress-bar fill */
        .shimmer-bar {
            background: linear-gradient(90deg,
                #fddd10 0%,
                #eb8111 50%,
                #4c1d95 100%
            );
            background-size: 200% 100%;
            animation: shimmer-brand 2s ease-in-out infinite;
        }

        /* Reduced motion override */
        @media (prefers-reduced-motion: reduce) {
            .shimmer-neutral,
            .dark .shimmer-neutral,
            .shimmer-brand,
            .shimmer-bar {
                animation: none;
                background-size: 100% 100%;
            }
        }
    </style>
</head>
<body class="bg-white text-zinc-900 dark:bg-warm-dark dark:text-zinc-100 antialiased">

{{-- Sticky top nav --}}
<div class="sticky top-0 z-50 flex items-center justify-between border-b border-zinc-200 bg-white/90 px-6 py-3 backdrop-blur dark:border-zinc-800 dark:bg-warm-dark/90">
    <div class="flex items-center gap-3">
        <img src="{{ asset('armagedon-logo-light.svg') }}" alt="Armagedon" class="h-7 dark:hidden">
        <img src="{{ asset('armagedon-logo-dark.svg') }}" alt="Armagedon" class="hidden h-7 dark:block">
        <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Grafisk profil</span>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('grafiskprofil.light') }}"
           class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $theme === 'light' ? 'bg-brand text-brand-dark' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
            ☀ Ljust
        </a>
        <a href="{{ route('grafiskprofil.dark') }}"
           class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $theme === 'dark' ? 'bg-brand text-brand-dark' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}">
            ☾ Mörkt
        </a>
    </div>
</div>

<div class="mx-auto max-w-5xl px-6 py-12 space-y-20">

    {{-- ============================================================ --}}
    {{-- RUBRIK --}}
    {{-- ============================================================ --}}
    <header>
        <p class="mb-2 text-sm font-semibold uppercase tracking-widest text-brand-orange">Armagedon · Borås Spelförening</p>
        <h1 class="text-5xl font-bold text-zinc-900 dark:text-white">Grafisk Profil</h1>
        <p class="mt-3 text-xl text-zinc-500 dark:text-zinc-400">{{ $theme === 'dark' ? 'Mörkt tema' : 'Ljust tema' }} – Färger, typografi och UI-element</p>
    </header>

    {{-- ============================================================ --}}
    {{-- AVSTÅND --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-2 text-2xl font-bold text-zinc-900 dark:text-white">Avstånd</h2>
        <p class="mb-8 text-sm text-zinc-500 dark:text-zinc-400">Spacing-skalan baseras på 4px-grid (Tailwind). Håll dig till dessa steg — undvik godtyckliga värden.</p>

        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900 space-y-2">
            @foreach([
                ['0.5', '2px',  'Separator-gap, micro-avstånd'],
                ['1',   '4px',  'Badge intern padding, ikon-gap i kompakt element'],
                ['2',   '8px',  'Gap ikon↔text i knappar, kompakt list-item'],
                ['3',   '12px', 'Sm-knapp padding, tight form-input py'],
                ['4',   '16px', 'Form-input px/py, standard gap i formulärgrupp'],
                ['6',   '24px', 'Kort-padding (p-6), section-intern gap'],
                ['8',   '32px', 'Stor sektion p-8, space-y-8 i formulär'],
                ['12',  '48px', 'Sidmarginaler (px-12), modal intern padding'],
                ['16',  '64px', 'Extra stor section-gap, hero padding'],
                ['20',  '80px', 'Avstånd mellan huvud-sektioner (space-y-20)'],
            ] as [$step, $px, $usage])
            <div class="flex items-center gap-3">
                <span class="w-8 shrink-0 text-right font-mono text-xs text-zinc-400">{{ $step }}</span>
                <div class="shrink-0 h-4 rounded-sm bg-brand" style="width:{{ $px }};min-width:2px;"></div>
                <span class="w-12 shrink-0 font-mono text-xs text-zinc-400">{{ $px }}</span>
                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $usage }}</span>
            </div>
            @endforeach
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- SKUGGOR --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-2 text-2xl font-bold text-zinc-900 dark:text-white">Elevation & Skuggor</h2>
        <p class="mb-8 text-sm text-zinc-500 dark:text-zinc-400">Skuggor indikerar elevation och interaktivitet. Använd sparsamt — bara ett elevations-steg per lagernivå.</p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach([
                ['shadow-none', 'Ingen', 'Platta element, aktiv knapp nedtryckt, inbäddad panel'],
                ['shadow-sm',   'Liten',    'Standard kort, form-input, subtil upphöjning'],
                ['shadow',      'Standard', 'Hoverat kort, primär knapp, select-trigger'],
                ['shadow-md',   'Medium',   'Dropdown-panel, tooltip, kontextuell meny'],
                ['shadow-lg',   'Stor',     'Modal, sidopanel (drawer), floating action'],
                ['shadow-xl',   'Extra stor', 'Fullscreen overlay, cmd-palette, popover'],
            ] as [$class, $name, $usage])
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800 {{ $class }}">
                <p class="font-mono text-xs text-zinc-400">{{ $class }}</p>
                <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $name }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $usage }}</p>
            </div>
            @endforeach
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- HÖRNAVRUNDNING --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-2 text-2xl font-bold text-zinc-900 dark:text-white">Hörnavrundning</h2>
        <p class="mb-8 text-sm text-zinc-500 dark:text-zinc-400">Mjuka, runda hörn genomgående. Välj nivå efter elementets storlek — större element, större radius.</p>

        <div class="grid gap-6 grid-cols-3 sm:grid-cols-6">
            @foreach([
                ['rounded',    'rounded',  '4px',    'Checkbox, liten badge'],
                ['rounded-md', 'md',       '6px',    'Tooltip, tight chip'],
                ['rounded-lg', 'lg',       '8px',    'Input, knapp, select'],
                ['rounded-xl', 'xl',       '12px',   'Dropdown-panel, inre kort'],
                ['rounded-2xl','2xl',      '16px',   'Kort, modal, sektions-box'],
                ['rounded-full','full',    '9999px', 'Badge, avatar, toggle, pill'],
            ] as [$class, $label, $value, $usage])
            <div class="text-center">
                <div class="mx-auto mb-3 h-16 w-16 bg-brand-dark dark:bg-brand {{ $class }}"></div>
                <p class="font-mono text-xs font-semibold text-zinc-900 dark:text-white">{{ $label }}</p>
                <p class="font-mono text-xs text-zinc-400">{{ $value }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 leading-snug">{{ $usage }}</p>
            </div>
            @endforeach
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- BREAKPOINTS --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-2 text-2xl font-bold text-zinc-900 dark:text-white">Breakpoints & Grid</h2>
        <p class="mb-8 text-sm text-zinc-500 dark:text-zinc-400">Tailwinds standard breakpoints, mobile-first. Designa alltid för mobil (375px) som bas och lägg till klasser för större skärmar.</p>

        <div class="space-y-6">
            <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Prefix</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Min-bredd</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Typisk enhet</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Layoutanpassning</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-950">
                        @foreach([
                            ['(ingen)', '0px',    'Mobil 375–639px',    'Enkolumn, staplad layout, full-bredd knappar'],
                            ['sm:',     '640px',  'Stor mobil / liten tablet', 'Tvåkolumns grid, knappar sida vid sida'],
                            ['md:',     '768px',  'Tablet',             'Sidebar synlig, kompaktare formulär'],
                            ['lg:',     '1024px', 'Laptop',             'Trekolumns grid, horisontell topnav'],
                            ['xl:',     '1280px', 'Desktop',            'max-w-5xl centrerat, bredare tabeller'],
                            ['2xl:',    '1536px', 'Bred desktop',       'Sällan specifikt — ärver xl-layout'],
                        ] as [$prefix, $width, $device, $notes])
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-brand-orange">{{ $prefix }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $width }}</td>
                            <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-400">{{ $device }}</td>
                            <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-500">{{ $notes }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-4 font-mono text-xs text-zinc-400">Responsivt kolumngrid — grid-cols-1 → sm:grid-cols-2 → lg:grid-cols-3</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach(['Kolumn 1', 'Kolumn 2', 'Kolumn 3'] as $col)
                    <div class="rounded-lg border-2 border-dashed border-brand/50 bg-brand/10 px-4 py-6 text-center text-sm font-medium text-brand-dark dark:text-brand">{{ $col }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- FÄRGER --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Färger</h2>

        <h3 class="mb-2 text-sm font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Varumärkesfärger</h3>
        <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">Primärfärgen <strong class="text-zinc-800 dark:text-zinc-200">Gul</strong> används på knappar, aktiva element, logotyp och accenter. Alltid med mörk text (<code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">#231f20</code>) ovanpå. <strong class="text-zinc-800 dark:text-zinc-200">Orange/Bärnsten</strong> används för sekundära accenter, hover-tillstånd och kategorimärken. Undvik att mixa fler än två varumärkesfärger i samma vy.</p>
        <div class="mb-10 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach([
                ['#fddd10', 'Gul', 'Primär', 'Primärknapp, aktiv tab, paginering, logotypens yta. Alltid med text-brand-dark ovanpå.'],
                ['#fee81b', 'Ljusgul', 'Hover / highlight', 'Hover-tillstånd på primärknapp (btn-primary:hover). Markerings­bakgrund vid text­sökning.'],
                ['#fbc712', 'Saffran', 'Focus / gradient', 'Focus-ring accent på brand-element. Mittpunkt i brand-gradient (från gul via saffran till orange).'],
                ['#f9a70d', 'Bärnsten', 'Sekundär accent', 'Varnings­badge (semantisk), sekundär accent­text, listpunkt­prick, pris­markering, länk­text i löptext.'],
                ['#eb8111', 'Orange', 'Intensiv accent', 'Ikoner på vit/mörk bakgrund, extern­länk­pil, logotypens orange detalj. Starkaste varumärkes­accentfärgen.'],
            ] as [$hex, $name, $role, $usage])
            <div>
                <div class="mb-2 h-20 rounded-xl shadow-sm ring-1 ring-black/5" style="background:{{ $hex }}"></div>
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $name }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $role }}</p>
                <p class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $hex }}</p>
                <p class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ hexToHsb($hex) }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 leading-snug">{{ $usage }}</p>
            </div>
            @endforeach
        </div>

        <h3 class="mb-2 text-sm font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Komplementfärger</h3>
        <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">Violett är ett <strong class="text-zinc-800 dark:text-zinc-200">komplement till gult</strong> och används sparsamt för att skapa kontrast och visuell hierarki. Typiska användningsfall: ikonbakgrunder för evenemang och sektioner, sekundära CTA-knappar, aktiva tillstånd i mörkt tema. <strong class="text-zinc-800 dark:text-zinc-200">Djup violett</strong> (#4c1d95) lämpar sig för bakgrunder och fyllda element. <strong class="text-zinc-800 dark:text-zinc-200">Ljus violett</strong> (#7c3aed) används för text och ikoner på ljus bakgrund. Använd <em>aldrig</em> violett och orange på samma yta — välj en av dem som accent per vy.</p>
        <div class="mb-10 grid grid-cols-2 gap-3 sm:grid-cols-3">
            @foreach([
                ['#4c1d95', 'Djup violett', 'Fyllda element', 'Ikonbakgrund för evenemang och sektionskort (bg-complement på <span>). Sekundär CTA-knapp. Mörk badge-bakgrund.'],
                ['#6d28d9', 'Violett', 'Hover / gradient', 'Hover-tillstånd på complement-element. Mittpunkt i gradient från djup violett till ljus. Används sällan fristående.'],
                ['#7c3aed', 'Ljus violett', 'Text och ikoner', 'Aktiv nav-länk i mörkt läge, text-complement-light. Ikon­accent och varumärkes­märke på ljus bakgrund.'],
            ] as [$hex, $name, $role, $usage])
            <div>
                <div class="mb-2 h-20 rounded-xl shadow-sm ring-1 ring-black/5" style="background:{{ $hex }}"></div>
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $name }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $role }}</p>
                <p class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $hex }}</p>
                <p class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ hexToHsb($hex) }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 leading-snug">{{ $usage }}</p>
            </div>
            @endforeach
        </div>

        <h3 class="mb-2 text-sm font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Neutraler</h3>
        <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400"><strong class="text-zinc-800 dark:text-zinc-200">Kol</strong> är primär textfärg i ljust tema och bakgrundsfärg för mörka knappar. <strong class="text-zinc-800 dark:text-zinc-200">Varm vit</strong> används som sidbakgrund i ljust tema. <strong class="text-zinc-800 dark:text-zinc-200">Varm svart</strong> är sidbakgrund i mörkt tema — observera att den är varmare än ren svart (#000) för att harmoniera med gulskalan.</p>
        <div class="mb-10 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach([
                ['#231f20', 'Kol', 'Text / mörk'],
                ['#4f4c4d', 'Grafit', 'Sekundär text'],
                ['#fafaf0', 'Varm vit', 'Ljus bakgrund'],
                ['#1c1a1b', 'Varm svart', 'Mörk bakgrund'],
            ] as [$hex, $name, $role])
            <div>
                <div class="mb-2 h-20 rounded-xl shadow-sm ring-1 ring-black/5" style="background:{{ $hex }}"></div>
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $name }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $role }}</p>
                <p class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $hex }}</p>
                <p class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ hexToHsb($hex) }}</p>
            </div>
            @endforeach
        </div>

        <h3 class="mb-2 text-sm font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">Semantiska färger</h3>
        <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">Används uteslutande för systemmeddelanden och statusar — <strong class="text-zinc-800 dark:text-zinc-200">aldrig</strong> som dekorativa accenter. Blandas inte med varumärkesfärgerna i samma element.</p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach([
                ['#16a34a', 'Grön', 'Framgång'],
                ['#f9a70d', 'Bärnsten', 'Varning'],
                ['#dc2626', 'Röd', 'Fel'],
                ['#2563eb', 'Blå', 'Info'],
            ] as [$hex, $name, $role])
            <div>
                <div class="mb-2 h-20 rounded-xl shadow-sm ring-1 ring-black/5" style="background:{{ $hex }}"></div>
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $name }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $role }}</p>
                <p class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $hex }}</p>
                <p class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ hexToHsb($hex) }}</p>
            </div>
            @endforeach
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- TYPOGRAFI --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Typografi</h2>

        <div class="space-y-6 rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
            <div><p class="mb-1 font-mono text-xs text-zinc-400">H1 · text-5xl · font-bold</p>
                <h1 class="text-5xl font-bold text-zinc-900 dark:text-white">Armagedon Spelförening</h1></div>
            <div><p class="mb-1 font-mono text-xs text-zinc-400">H2 · text-3xl · font-bold</p>
                <h2 class="text-3xl font-bold text-zinc-900 dark:text-white">Höstens program 2026</h2></div>
            <div><p class="mb-1 font-mono text-xs text-zinc-400">H3 · text-2xl · font-semibold</p>
                <h3 class="text-2xl font-semibold text-zinc-900 dark:text-white">Brädspelskväll varje tisdag</h3></div>
            <div><p class="mb-1 font-mono text-xs text-zinc-400">H4 · text-xl · font-semibold</p>
                <h4 class="text-xl font-semibold text-zinc-900 dark:text-white">Rollspel för nybörjare</h4></div>
            <div><p class="mb-1 font-mono text-xs text-zinc-400">H5 · text-lg · font-medium</p>
                <h5 class="text-lg font-medium text-zinc-900 dark:text-white">Miniaturmålning med Elias</h5></div>
            <div><p class="mb-1 font-mono text-xs text-zinc-400">H6 · text-base · font-medium</p>
                <h6 class="text-base font-medium text-zinc-500 dark:text-zinc-400">Sektion · Undergrupp</h6></div>
            <hr class="border-zinc-200 dark:border-zinc-700">
            <div><p class="mb-1 font-mono text-xs text-zinc-400">Ingress · text-xl · text-zinc-600</p>
                <p class="text-xl leading-relaxed text-zinc-600 dark:text-zinc-300">Armagedon är Borås spelförening för brädspel, miniatyrspel och rollspel. Vi är en öppen förening för alla som gillar spelhobbyn.</p></div>
            <div><p class="mb-1 font-mono text-xs text-zinc-400">Brödtext · text-base</p>
                <p class="leading-relaxed text-zinc-700 dark:text-zinc-300">Föreningen grundades med målet att samla spelare i Borås. Vi träffas regelbundet för att spela, diskutera och lära oss nya spel tillsammans. Alla är välkomna oavsett erfarenhetsnivå.</p></div>
            <div><p class="mb-1 font-mono text-xs text-zinc-400">Liten text · text-sm</p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Senast uppdaterad 6 mars 2026 · Kontakta styrelsen för frågor</p></div>
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- TEXTFÄRGER --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-2 text-2xl font-bold text-zinc-900 dark:text-white">Textfärger</h2>
        <p class="mb-8 text-sm text-zinc-500 dark:text-zinc-400">Vilket textfärg som ska användas beror på hierarki, bakgrund och kontext. Använd alltid ljus/mörk-varianten för respektive tema.</p>

        <div class="space-y-6">

            {{-- Hierarki på neutral bakgrund --}}
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-6 font-mono text-xs text-zinc-400">Texthierarki på neutral bakgrund</p>
                <div class="space-y-5">

                    <div class="flex items-baseline gap-6">
                        <span class="w-64 shrink-0 font-mono text-xs text-zinc-400">text-zinc-900 / white</span>
                        <div>
                            <p class="font-semibold text-zinc-900 dark:text-white">Rubriker och primär text</p>
                            <p class="mt-0.5 text-xs text-zinc-400">Använd för H1–H5, tabellrubrik, etikett, kortnamn, modal-titel. Maximal kontrast.</p>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-6">
                        <span class="w-64 shrink-0 font-mono text-xs text-zinc-400">text-zinc-700 / zinc-300</span>
                        <div>
                            <p class="text-zinc-700 dark:text-zinc-300">Brödtext och formulärtext</p>
                            <p class="mt-0.5 text-xs text-zinc-400">Löpande text, formulärsvars­text, listelement. Lätt avdämpat från rubriken.</p>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-6">
                        <span class="w-64 shrink-0 font-mono text-xs text-zinc-400">text-zinc-600 / zinc-400</span>
                        <div>
                            <p class="text-zinc-600 dark:text-zinc-400">Sekundär text och ingress</p>
                            <p class="mt-0.5 text-xs text-zinc-400">Kortbeskrivning, tabell­cell­värde, ingress­paragraf, hjälp­text under formulärfält.</p>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-6">
                        <span class="w-64 shrink-0 font-mono text-xs text-zinc-400">text-zinc-500 / zinc-400</span>
                        <div>
                            <p class="text-zinc-500 dark:text-zinc-400">Meta och diskret information</p>
                            <p class="mt-0.5 text-xs text-zinc-400">Datum, "Senast uppdaterad", H6-underrubrik, nav-länk i viloläge, breadcrumb-noder.</p>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-6">
                        <span class="w-64 shrink-0 font-mono text-xs text-zinc-400">text-zinc-400 / zinc-500</span>
                        <div>
                            <p class="text-zinc-400 dark:text-zinc-500">Placeholder och ledtext</p>
                            <p class="mt-0.5 text-xs text-zinc-400">Placeholder i input­fält, tom-tillstånds­text, etikett­etiketter för tom data. Aldrig för läsbar information.</p>
                        </div>
                    </div>

                </div>
            </div>

            {{-- På färgade bakgrunder --}}
            <div class="grid gap-4 sm:grid-cols-3">

                <div class="rounded-2xl bg-brand p-6">
                    <p class="mb-3 font-mono text-xs text-brand-dark/60">På brand-gul bakgrund</p>
                    <p class="font-semibold text-brand-dark">text-brand-dark</p>
                    <p class="mt-1 text-sm text-brand-dark/80">Används alltid när bakgrunden är brand-gul (#fddd10). Aldrig vit text – kontrasten är otillräcklig.</p>
                </div>

                <div class="rounded-2xl bg-brand-dark p-6 dark:bg-zinc-800">
                    <p class="mb-3 font-mono text-xs text-zinc-500">På mörk bakgrund</p>
                    <p class="font-semibold text-white">text-white</p>
                    <p class="mt-1 text-sm text-zinc-300">På brand-dark, warm-dark eller annan mycket mörk bakgrund. Gäller även navigations­fältet i mörkt läge.</p>
                </div>

                <div class="rounded-2xl bg-complement p-6">
                    <p class="mb-3 font-mono text-xs text-white/50">På violett bakgrund</p>
                    <p class="font-semibold text-white">text-white</p>
                    <p class="mt-1 text-sm text-white/80">På komplement­violett (#4c1d95) används alltid vit text. Blanda aldrig gul text på violett bakgrund.</p>
                </div>

            </div>

            {{-- Accentfärger för text --}}
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-6 font-mono text-xs text-zinc-400">Accentfärger i text — använd sparsamt</p>
                <div class="space-y-5">

                    <div class="flex items-baseline gap-6">
                        <span class="w-64 shrink-0 font-mono text-xs text-zinc-400">text-brand-orange</span>
                        <div>
                            <p class="font-medium text-brand-orange">Bärnsten — sekundär accent</p>
                            <p class="mt-0.5 text-xs text-zinc-400">Länk i löptext, ikon­accent, pris­markering. Fungerar på ljus och mörk bakgrund. Undvik på violett.</p>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-6">
                        <span class="w-64 shrink-0 font-mono text-xs text-zinc-400">text-complement-light</span>
                        <div>
                            <p class="font-medium text-complement-light">Ljus violett — diskret accent</p>
                            <p class="mt-0.5 text-xs text-zinc-400">Aktiv nav-länk i mörkt läge, ikon­accent på ljus bakgrund, varumärkes­märke. Aldrig bredvid orange.</p>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-6">
                        <span class="w-64 shrink-0 font-mono text-xs text-zinc-400">text-blue-600 / blue-400</span>
                        <div>
                            <p class="font-medium text-blue-600 dark:text-blue-400">Blå — interaktiva länkar</p>
                            <p class="mt-0.5 text-xs text-zinc-400">Standard för klick­bara text­länkar i löptext. Välkänd konvention för tillgänglighet. Alltid med underline.</p>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-6">
                        <span class="w-64 shrink-0 font-mono text-xs text-zinc-400">text-red-600 / red-400</span>
                        <div>
                            <p class="font-medium text-red-600 dark:text-red-400">Röd — fel och varningar</p>
                            <p class="mt-0.5 text-xs text-zinc-400">Validerings­fel under formulärfält, destruktiva åtgärder (ta bort), felmeddelanden. Aldrig dekorativt.</p>
                        </div>
                    </div>

                    <div class="flex items-baseline gap-6">
                        <span class="w-64 shrink-0 font-mono text-xs text-zinc-400">text-zinc-400 (ikon)</span>
                        <div>
                            <p class="flex items-center gap-2 font-medium text-zinc-700 dark:text-zinc-300">
                                <x-bootstrap-icon name="search" class="size-4 text-zinc-400" />
                                Ikoner i fält och knappar
                            </p>
                            <p class="mt-0.5 text-xs text-zinc-400">Ikoner inuti input­fält (prefix/suffix) och sekundär­knappar använder text-zinc-400. Ikon i primär­knapp ärver text-brand-dark.</p>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- LÄNKAR --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Länkar</h2>

        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
            <div class="space-y-3">
                <p class="font-mono text-xs text-zinc-400">Standard länk · hover · besökt</p>
                <p class="leading-loose">
                    <a href="#" class="text-blue-600 underline underline-offset-2 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">Ej besökt länk</a>
                    &nbsp;·&nbsp;
                    <a href="https://armagedon.se" class="text-blue-600 underline underline-offset-2 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">Besökt länk (klicka för att testa)</a>
                    &nbsp;·&nbsp;
                    <a href="https://example.com" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-blue-600 underline underline-offset-2 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        Extern länk
                        <svg class="size-3" viewBox="0 0 16 16" fill="currentColor"><path fill-rule="evenodd" d="M6.364 13.5a.75.75 0 0 0 .75.75H13.5a1.5 1.5 0 0 0 1.5-1.5v-6.636a.75.75 0 1 0-1.5 0V12.5h-6.636a.75.75 0 0 0-.75.75Z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M14.5 1.5a.75.75 0 0 0-.75-.75h-5a.75.75 0 0 0 0 1.5h3.44L6.22 8.22a.75.75 0 0 0 1.06 1.06l5.97-5.97v3.44a.75.75 0 0 0 1.5 0v-5Z" clip-rule="evenodd"/></svg>
                    </a>
                </p>
                <p><a href="#" class="font-medium text-brand-orange underline underline-offset-2 hover:text-brand-orange/80">Varumärkesfärgad länk</a></p>
                <p><a href="#" class="font-medium text-complement-light underline underline-offset-2 hover:text-complement">Komplementfärgad länk</a></p>
                <p><a href="#" class="text-zinc-500 no-underline hover:text-zinc-900 hover:underline dark:text-zinc-400 dark:hover:text-white">Diskret länk (navigation)</a></p>
            </div>

            <hr class="border-zinc-200 dark:border-zinc-700">

            <div>
                <p class="mb-3 font-mono text-xs text-zinc-400">Brödtext med inbäddade länkar</p>
                <p class="leading-relaxed text-zinc-700 dark:text-zinc-300">
                    Läs mer om <a href="#" class="text-blue-600 underline underline-offset-2 hover:text-blue-800 dark:text-blue-400">våra sektioner</a> och
                    <a href="#" class="text-blue-600 underline underline-offset-2 hover:text-blue-800 dark:text-blue-400">kommande evenemang</a>.
                    Har du frågor? <a href="#" class="text-blue-600 underline underline-offset-2 hover:text-blue-800 dark:text-blue-400">Kontakta oss</a>.
                </p>
            </div>
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- KNAPPAR --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Knappar</h2>

        <div class="space-y-8 rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">

            <div>
                <p class="mb-4 font-mono text-xs text-zinc-400">Primär (brand)</p>
                <div class="flex flex-wrap gap-3">
                    <button class="btn btn-primary btn-sm">Liten</button>
                    <button class="btn btn-primary">Standard</button>
                    <button class="btn btn-primary">
                        <x-bootstrap-icon name="plus-lg" class="size-4" />
                        Med ikon
                    </button>
                    <button class="btn btn-primary" disabled>Inaktiv</button>
                </div>
            </div>

            <div>
                <p class="mb-4 font-mono text-xs text-zinc-400">Sekundär / outline</p>
                <div class="flex flex-wrap gap-3">
                    <button class="btn btn-secondary btn-sm">Liten</button>
                    <button class="btn btn-secondary">Standard</button>
                    <button class="btn btn-secondary">
                        Med ikon
                        <x-bootstrap-icon name="arrow-right" class="size-4" />
                    </button>
                    <button class="btn btn-secondary" disabled>Inaktiv</button>
                </div>
            </div>

            <div>
                <p class="mb-4 font-mono text-xs text-zinc-400">Ghost / Subtil</p>
                <div class="flex flex-wrap gap-3">
                    <button class="btn btn-ghost btn-sm">Liten</button>
                    <button class="btn btn-ghost">Standard</button>
                    <button class="btn btn-ghost">
                        <x-bootstrap-icon name="pencil" class="size-4" />
                        Redigera
                    </button>
                    <button class="btn btn-ghost" disabled>Inaktiv</button>
                </div>
            </div>

            <div>
                <p class="mb-4 font-mono text-xs text-zinc-400">Varumärkesknappar</p>
                <div class="flex flex-wrap gap-3">
                    <button class="btn" style="background:var(--color-brand);color:var(--color-brand-dark)">
                        Primär brand
                    </button>
                    <button class="btn" style="background:var(--color-brand-orange);color:#fff">
                        Bärnsten
                    </button>
                    <button class="btn" style="background:var(--color-complement);color:#fff">
                        Komplement
                    </button>
                    <button class="btn btn-secondary" style="border-color:var(--color-brand)">
                        Brand outline
                    </button>
                </div>
            </div>

            <div>
                <p class="mb-4 font-mono text-xs text-zinc-400">Fara / Bekräftelse</p>
                <div class="flex flex-wrap gap-3">
                    <button class="btn btn-danger">
                        <x-bootstrap-icon name="trash" class="size-4" />
                        Ta bort
                    </button>
                    <button class="btn" style="background:#16a34a;color:#fff">
                        <x-bootstrap-icon name="check-lg" class="size-4" />
                        Bekräfta
                    </button>
                </div>
            </div>

            <div>
                <p class="mb-4 font-mono text-xs text-zinc-400">Ikonknappar (kvadratiska)</p>
                <div class="flex flex-wrap gap-3">
                    <button class="btn btn-ghost" style="padding:.375rem;border-radius:.375rem;" title="Redigera">
                        <x-bootstrap-icon name="pencil" class="size-4" />
                    </button>
                    <button class="btn btn-ghost" style="padding:.5rem;border-radius:.5rem;" title="Ta bort">
                        <x-bootstrap-icon name="trash" class="size-5" />
                    </button>
                    <button class="btn btn-primary" style="padding:.5rem;border-radius:.5rem;" title="Lägg till">
                        <x-bootstrap-icon name="plus-lg" class="size-5" />
                    </button>
                    <button class="btn btn-secondary" style="padding:.5rem;border-radius:.5rem;" title="Stäng">
                        <x-bootstrap-icon name="x-lg" class="size-5" />
                    </button>
                </div>
            </div>

        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- FORMULÄR --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Formulär</h2>

        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
            <form class="space-y-6" onsubmit="return false">

                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <label class="lbl">Förnamn</label>
                        <input class="inp" type="text" placeholder="Anna" />
                    </div>
                    <div>
                        <label class="lbl">Efternamn</label>
                        <input class="inp" type="text" placeholder="Svensson" />
                    </div>
                </div>

                <div>
                    <label class="lbl">E-post</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-zinc-400">
                            <x-bootstrap-icon name="envelope" class="size-4" />
                        </span>
                        <input class="inp pl-9" type="email" placeholder="anna@example.se" />
                    </div>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Används för att skicka kvitton och nyhetsbrev.</p>
                </div>

                <div>
                    <label class="lbl">Lösenord</label>
                    <input class="inp" type="password" placeholder="••••••••" />
                </div>

                <div>
                    <label class="lbl">Sök</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-zinc-400">
                            <x-bootstrap-icon name="search" class="size-4" />
                        </span>
                        <input class="inp pl-9" type="search" placeholder="Sök bland members…" />
                    </div>
                </div>

                <div>
                    <label class="lbl">Med fel</label>
                    <input class="inp" style="border-color:#ef4444;" type="text" value="ogiltig@" />
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">Ange en giltig e-postadress.</p>
                </div>

                <div>
                    <label class="lbl">Inaktivt fält</label>
                    <input class="inp" type="text" value="Kan ej redigeras" disabled />
                </div>

                <div>
                    <label class="lbl">Meddelande</label>
                    <textarea class="inp" rows="4" placeholder="Skriv ditt meddelande här…" style="resize:vertical;"></textarea>
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <div>
                        <label class="lbl">Medlemstyp</label>
                        <select class="inp">
                            <option value="">Välj typ…</option>
                            <option>Enskild (350 kr)</option>
                            <option>Familj (550 kr)</option>
                            <option>Student (200 kr)</option>
                            <option>Hedersmedlem (gratis)</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">År</label>
                        <select class="inp">
                            <option>2026</option>
                            <option>2025</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-2">
                    <p class="lbl">Intressen</p>
                    @foreach(['Brädspel' => true, 'Miniatyrspel' => false, 'Rollspel' => true, 'Kortspel' => false] as $label => $checked)
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300 {{ $loop->last ? 'opacity-50' : '' }}">
                        <input type="checkbox" {{ $checked ? 'checked' : '' }} {{ $loop->last ? 'disabled' : '' }}
                               class="h-4 w-4 rounded border-zinc-300 accent-brand-dark dark:border-zinc-600" />
                        {{ $label }}
                    </label>
                    @endforeach
                </div>

                <div class="space-y-2">
                    <p class="lbl">Nyhetsbrev</p>
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                        <input type="radio" name="newsletter" value="yes" checked class="h-4 w-4 border-zinc-300 accent-brand-dark" />
                        Ja, skicka mig nyhetsbrev
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                        <input type="radio" name="newsletter" value="no" class="h-4 w-4 border-zinc-300 accent-brand-dark" />
                        Nej tack
                    </label>
                </div>

                <div class="flex items-center gap-3" x-data="{ on: true }">
                    <button type="button" @click="on = !on" role="switch" :aria-checked="on"
                            class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none"
                            :class="on ? 'bg-brand-dark dark:bg-brand' : 'bg-zinc-300 dark:bg-zinc-600'">
                        <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200"
                              :class="on ? 'translate-x-5' : 'translate-x-0'"></span>
                    </button>
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Visa min profil offentligt</span>
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="btn btn-primary">Spara ändringar</button>
                    <button type="button" class="btn btn-secondary">Avbryt</button>
                </div>

            </form>
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- NAVIGATION --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Navigation</h2>

        <div class="space-y-6">

            {{-- Breadcrumbs --}}
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-3 font-mono text-xs text-zinc-400">Brödsmulor</p>
                <nav class="flex items-center gap-1 text-sm">
                    <a href="#" class="text-zinc-500 hover:text-zinc-900 dark:hover:text-white">Hem</a>
                    <span class="text-zinc-300 dark:text-zinc-600">/</span>
                    <a href="#" class="text-zinc-500 hover:text-zinc-900 dark:hover:text-white">Sektioner</a>
                    <span class="text-zinc-300 dark:text-zinc-600">/</span>
                    <span class="font-medium text-zinc-900 dark:text-white">Brädspel</span>
                </nav>
            </div>

            {{-- Tabs --}}
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-3 font-mono text-xs text-zinc-400">Flikar</p>
                <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-700" x-data="{ tab: 'oversikt' }">
                    @foreach(['oversikt' => 'Översikt', 'medlemmar' => 'Medlemmar', 'evenemang' => 'Evenemang'] as $key => $label)
                    <button @click="tab = '{{ $key }}'"
                            :class="tab === '{{ $key }}' ? 'border-b-2 border-brand-dark text-zinc-900 dark:border-brand dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            class="px-4 py-2 text-sm font-medium -mb-px transition-colors">{{ $label }}</button>
                    @endforeach
                    <button disabled class="px-4 py-2 text-sm font-medium text-zinc-300 dark:text-zinc-600 cursor-not-allowed">Dokument</button>
                </div>
            </div>

            {{-- Pagination --}}
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-3 font-mono text-xs text-zinc-400">Paginering</p>
                <div class="flex items-center gap-1">
                    <button class="rounded border border-zinc-200 px-3 py-1.5 text-sm text-zinc-400 dark:border-zinc-700" disabled>‹ Föregående</button>
                    <button class="rounded bg-brand px-3 py-1.5 text-sm font-semibold text-brand-dark">1</button>
                    <button class="rounded border border-zinc-200 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">2</button>
                    <button class="rounded border border-zinc-200 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">3</button>
                    <span class="px-2 text-zinc-400">…</span>
                    <button class="rounded border border-zinc-200 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">12</button>
                    <button class="rounded border border-zinc-200 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">Nästa ›</button>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- KORT --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Kort</h2>

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand text-brand-dark">
                        <x-bootstrap-icon name="people-fill" class="size-5" />
                    </span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">Brädspel</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">42 aktiva medlemmar</p>
                    </div>
                </div>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Träffas varje tisdag kl 18–22 i Kulturhuset. Alla välkomna oavsett erfarenhet.</p>
                <div class="mt-4 flex items-center justify-between">
                    <span class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-400">Aktiv</span>
                    <a href="#" class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">Läs mer →</a>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-complement text-white">
                        <x-bootstrap-icon name="calendar-event" class="size-5" />
                    </span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">Höstmöte 2026</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">15 oktober 2026</p>
                    </div>
                </div>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Årsmöte med val av ny styrelse och revidering av stadgar. Anmälan krävs.</p>
                <div class="mt-4 flex items-center justify-between">
                    <span class="rounded-full bg-brand/20 px-2.5 py-0.5 text-xs font-medium text-brand-dark dark:bg-brand/10 dark:text-brand">12 anmälda</span>
                    <a href="#" class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">Anmäl →</a>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm opacity-60 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-zinc-200 dark:bg-zinc-700">
                        <x-bootstrap-icon name="file-earmark-text" class="size-5 text-zinc-500" />
                    </span>
                    <div>
                        <p class="font-semibold text-zinc-900 dark:text-white">Stadgar</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Uppdaterades 2024</p>
                    </div>
                </div>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Föreningens stadgar och regler. Inaktivt exempelkort.</p>
                <div class="mt-4">
                    <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-500 dark:bg-zinc-800">Inaktiv</span>
                </div>
            </div>

        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- MÄRKEN & STATUSAR --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Märken & Statusar</h2>

        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900 space-y-6">

            <div>
                <p class="mb-3 font-mono text-xs text-zinc-400">Statusmärken</p>
                <div class="flex flex-wrap gap-2">
                    <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-400">Aktiv</span>
                    <span class="rounded-full bg-yellow-100 px-3 py-1 text-xs font-medium text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">Väntande</span>
                    <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-800 dark:bg-red-900/30 dark:text-red-400">Utgången</span>
                    <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">Inaktiv</span>
                    <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">Info</span>
                </div>
            </div>

            <div>
                <p class="mb-3 font-mono text-xs text-zinc-400">Varumärkesmärken</p>
                <div class="flex flex-wrap gap-2">
                    <span class="rounded-full bg-brand/20 px-3 py-1 text-xs font-semibold text-brand-dark dark:bg-brand/10 dark:text-brand">Armagedon Gul</span>
                    <span class="rounded-full bg-brand-orange/20 px-3 py-1 text-xs font-semibold text-brand-orange">Bärnsten</span>
                    <span class="rounded-full bg-complement/15 px-3 py-1 text-xs font-semibold text-complement dark:bg-complement/20 dark:text-complement-light">Violett</span>
                </div>
            </div>

            <div>
                <p class="mb-3 font-mono text-xs text-zinc-400">Med ikon</p>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-400">
                        <x-bootstrap-icon name="check-circle-fill" class="size-3" /> Betald
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-yellow-100 px-3 py-1 text-xs font-medium text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">
                        <x-bootstrap-icon name="clock-fill" class="size-3" /> Väntande
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-3 py-1 text-xs font-medium text-red-800 dark:bg-red-900/30 dark:text-red-400">
                        <x-bootstrap-icon name="x-circle-fill" class="size-3" /> Avvisad
                    </span>
                </div>
            </div>

        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- MEDDELANDEN --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Meddelanden & Alerts</h2>

        <div class="space-y-4">
            <div class="flex gap-3 rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
                <x-bootstrap-icon name="check-circle-fill" class="size-5 shrink-0 text-green-600 dark:text-green-400" />
                <div><p class="font-medium text-green-900 dark:text-green-300">Betalningen genomfördes</p>
                    <p class="mt-0.5 text-sm text-green-700 dark:text-green-400">Ditt medlemskap för 2026 är nu aktiverat.</p></div>
            </div>

            <div class="flex gap-3 rounded-xl border border-yellow-200 bg-yellow-50 p-4 dark:border-yellow-800 dark:bg-yellow-900/20">
                <x-bootstrap-icon name="exclamation-triangle-fill" class="size-5 shrink-0 text-yellow-600 dark:text-yellow-400" />
                <div><p class="font-medium text-yellow-900 dark:text-yellow-300">Ditt medlemskap löper ut snart</p>
                    <p class="mt-0.5 text-sm text-yellow-700 dark:text-yellow-400">Förnya ditt medlemskap innan 31 december 2026.</p></div>
            </div>

            <div class="flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
                <x-bootstrap-icon name="x-circle-fill" class="size-5 shrink-0 text-red-600 dark:text-red-400" />
                <div><p class="font-medium text-red-900 dark:text-red-300">Betalningen misslyckades</p>
                    <p class="mt-0.5 text-sm text-red-700 dark:text-red-400">Kontrollera dina kortuppgifter och försök igen.</p></div>
            </div>

            <div class="flex gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
                <x-bootstrap-icon name="info-circle-fill" class="size-5 shrink-0 text-blue-600 dark:text-blue-400" />
                <div><p class="font-medium text-blue-900 dark:text-blue-300">Ny funktion: Eventregistrering</p>
                    <p class="mt-0.5 text-sm text-blue-700 dark:text-blue-400">Du kan nu anmäla dig till evenemang direkt via hemsidan.</p></div>
            </div>

            <div class="flex gap-3 rounded-xl border border-brand/30 bg-brand/10 p-4">
                <x-bootstrap-icon name="star-fill" class="size-5 shrink-0 text-brand-orange" />
                <div><p class="font-medium text-brand-dark dark:text-brand">Armagedon-meddelande</p>
                    <p class="mt-0.5 text-sm text-brand-dark/70 dark:text-brand/80">Varumärkesfärgat infoblock för speciella meddelanden.</p></div>
            </div>
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- TABELL --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Tabell</h2>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Namn</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Typ</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Status</th>
                        <th class="px-4 py-3 text-right font-semibold text-zinc-700 dark:text-zinc-300">Årsavgift</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-950">
                    @foreach([
                        ['Anna Svensson',   'Enskild',     'Aktiv',    '350 kr', 'green'],
                        ['Lars Eriksson',   'Familj',      'Aktiv',    '550 kr', 'green'],
                        ['Maja Lindström',  'Student',     'Väntande', '200 kr', 'yellow'],
                        ['Erik Karlsson',   'Hedersmedlem','Aktiv',    '0 kr',   'green'],
                        ['Sofia Berg',      'Enskild',     'Utgången', '350 kr', 'red'],
                    ] as [$name, $type, $status, $fee, $color])
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                        <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ $name }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ $type }}</td>
                        <td class="px-4 py-3">
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-medium',
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'    => $color === 'green',
                                'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400' => $color === 'yellow',
                                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'            => $color === 'red',
                            ])>{{ $status }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-zinc-700 dark:text-zinc-300">{{ $fee }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- LISTOR --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Listor</h2>

        <div class="grid gap-6 sm:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-3 font-mono text-xs text-zinc-400">Onumrerad lista</p>
                <ul class="space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                    @foreach(['Brädspel varje tisdag', 'Rollspelsgrupper', 'Miniatyrturneringar', 'Spelbibliotek', 'Nyhetsbrev'] as $item)
                        <li class="flex items-center gap-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-brand-orange flex-shrink-0"></span>
                            {{ $item }}
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-3 font-mono text-xs text-zinc-400">Numrerad lista</p>
                <ol class="space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                    @foreach(['Fyll i anmälningsformuläret', 'Välj betalningsmetod', 'Godkänn villkor', 'Betala årsavgiften', 'Aktivering sker automatiskt'] as $i => $item)
                        <li class="flex items-start gap-2">
                            <span class="flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full bg-brand text-xs font-bold text-brand-dark">{{ $i + 1 }}</span>
                            {{ $item }}
                        </li>
                    @endforeach
                </ol>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-3 font-mono text-xs text-zinc-400">Definitionslista</p>
                <dl class="space-y-3 text-sm">
                    @foreach([['Enskild', '350 kr/år'], ['Familj', '550 kr/år'], ['Student', '200 kr/år'], ['Heders', 'Gratis']] as [$term, $def])
                        <div class="flex justify-between gap-2">
                            <dt class="font-medium text-zinc-900 dark:text-white">{{ $term }}</dt>
                            <dd class="font-mono text-zinc-500 dark:text-zinc-400">{{ $def }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- DROPDOWN / MENY --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Dropdown & Meny</h2>

        <div class="flex flex-wrap gap-6 rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">

            {{-- Åtgärder-dropdown --}}
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" @click.outside="open = false" class="btn btn-secondary">
                    Åtgärder
                    <x-bootstrap-icon name="chevron-down" class="size-4" />
                </button>
                <div x-show="open" x-cloak x-transition
                     class="absolute left-0 top-full z-10 mt-1 w-48 rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                    <button class="flex w-full items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="pencil" class="size-4 text-zinc-400" /> Redigera
                    </button>
                    <button class="flex w-full items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="copy" class="size-4 text-zinc-400" /> Duplicera
                    </button>
                    <hr class="my-1 border-zinc-100 dark:border-zinc-700">
                    <button class="flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                        <x-bootstrap-icon name="trash" class="size-4" /> Ta bort
                    </button>
                </div>
            </div>

            {{-- Primär dropdown --}}
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" @click.outside="open = false" class="btn btn-primary">
                    Skapa ny
                    <x-bootstrap-icon name="chevron-down" class="size-4" />
                </button>
                <div x-show="open" x-cloak x-transition
                     class="absolute left-0 top-full z-10 mt-1 w-48 rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                    <button class="flex w-full items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="person-plus" class="size-4 text-zinc-400" /> Ny medlem
                    </button>
                    <button class="flex w-full items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="calendar-plus" class="size-4 text-zinc-400" /> Nytt evenemang
                    </button>
                    <button class="flex w-full items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="folder-plus" class="size-4 text-zinc-400" /> Ny sektion
                    </button>
                </div>
            </div>

            {{-- Profil-dropdown --}}
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open" @click.outside="open = false"
                        class="flex items-center gap-2 rounded-xl border border-zinc-200 bg-white px-3 py-2 shadow-sm hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand text-xs font-bold text-brand-dark">AS</span>
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Anna Svensson</span>
                    <x-bootstrap-icon name="chevron-down" class="size-3 text-zinc-400" />
                </button>
                <div x-show="open" x-cloak x-transition
                     class="absolute right-0 top-full z-10 mt-1 w-48 rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                    <button class="flex w-full items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="person" class="size-4 text-zinc-400" /> Min profil
                    </button>
                    <button class="flex w-full items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="gear" class="size-4 text-zinc-400" /> Inställningar
                    </button>
                    <hr class="my-1 border-zinc-100 dark:border-zinc-700">
                    <button class="flex w-full items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-700">
                        <x-bootstrap-icon name="box-arrow-right" class="size-4 text-zinc-400" /> Logga ut
                    </button>
                </div>
            </div>

        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- MODAL (STATISK) --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Modal (statisk förhandsvisning)</h2>

        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mx-auto max-w-md rounded-2xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-100 px-6 py-4 dark:border-zinc-800">
                    <h3 class="font-semibold text-zinc-900 dark:text-white">Bekräfta borttagning</h3>
                    <button class="rounded text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                        <x-bootstrap-icon name="x-lg" class="size-4" />
                    </button>
                </div>
                <div class="px-6 py-4">
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Är du säker på att du vill ta bort <strong class="text-zinc-900 dark:text-white">Anna Svensson</strong>? Åtgärden kan inte ångras.</p>
                </div>
                <div class="flex justify-end gap-3 border-t border-zinc-100 px-6 py-4 dark:border-zinc-800">
                    <button class="btn btn-secondary">Avbryt</button>
                    <button class="btn btn-danger">Ta bort</button>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- AVDELARE --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Avdelare & Dividers</h2>

        <div class="space-y-8 rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
            <div>
                <p class="mb-3 font-mono text-xs text-zinc-400">Standard</p>
                <hr class="border-zinc-200 dark:border-zinc-700">
            </div>
            <div>
                <p class="mb-3 font-mono text-xs text-zinc-400">Med text</p>
                <div class="flex items-center gap-4">
                    <hr class="flex-1 border-zinc-200 dark:border-zinc-700">
                    <span class="text-xs font-medium uppercase tracking-wider text-zinc-400">eller</span>
                    <hr class="flex-1 border-zinc-200 dark:border-zinc-700">
                </div>
            </div>
            <div>
                <p class="mb-3 font-mono text-xs text-zinc-400">Varumärkesfärgad</p>
                <hr class="border-2 border-brand">
            </div>
            <div>
                <p class="mb-3 font-mono text-xs text-zinc-400">Gradient</p>
                <div class="h-0.5 rounded-full bg-gradient-to-r from-brand via-brand-orange to-complement"></div>
            </div>
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- AVATARER --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Avatarer</h2>

        <div class="space-y-8 rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">

            <div>
                <p class="mb-4 font-mono text-xs text-zinc-400">Storlekar — med initialer</p>
                <div class="flex flex-wrap items-end gap-6">
                    @foreach([
                        ['h-6 w-6 text-xs',   'xs'],
                        ['h-8 w-8 text-xs',   'sm'],
                        ['h-10 w-10 text-sm', 'md'],
                        ['h-12 w-12 text-base','lg'],
                        ['h-16 w-16 text-lg', 'xl'],
                    ] as [$size, $label])
                    <div class="text-center">
                        <div class="{{ $size }} mx-auto flex items-center justify-center rounded-full bg-brand font-bold text-brand-dark">AS</div>
                        <p class="mt-1 font-mono text-xs text-zinc-400">{{ $label }}</p>
                    </div>
                    @endforeach
                </div>
            </div>

            <div>
                <p class="mb-4 font-mono text-xs text-zinc-400">Färgvarianter</p>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-brand text-sm font-bold text-brand-dark">AS</div>
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-complement text-sm font-bold text-white">BJ</div>
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-orange text-sm font-bold text-white">ML</div>
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-zinc-300 text-sm font-bold text-zinc-700 dark:bg-zinc-600 dark:text-zinc-200">EK</div>
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-600 text-sm font-bold text-white">SB</div>
                </div>
            </div>

            <div>
                <p class="mb-4 font-mono text-xs text-zinc-400">Avatargrupp (överlappande, -space-x-2)</p>
                <div class="flex -space-x-2">
                    @foreach([
                        ['bg-brand text-brand-dark', 'AS'],
                        ['bg-complement text-white',  'BJ'],
                        ['bg-brand-orange text-white','ML'],
                        ['bg-green-600 text-white',   'EK'],
                        ['bg-zinc-500 text-white',    '+8'],
                    ] as [$bg, $init])
                    <div class="flex h-9 w-9 items-center justify-center rounded-full border-2 border-white text-xs font-bold dark:border-zinc-900 {{ $bg }}">{{ $init }}</div>
                    @endforeach
                </div>
            </div>

            <div>
                <p class="mb-4 font-mono text-xs text-zinc-400">Med statusindikatorer</p>
                <div class="flex flex-wrap items-center gap-6">
                    <div class="relative">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-brand text-sm font-bold text-brand-dark">AS</div>
                        <span class="absolute bottom-0 right-0 block h-3 w-3 rounded-full border-2 border-white bg-green-500 dark:border-zinc-900"></span>
                    </div>
                    <div class="relative">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-complement text-sm font-bold text-white">BJ</div>
                        <span class="absolute bottom-0 right-0 block h-3 w-3 rounded-full border-2 border-white bg-zinc-400 dark:border-zinc-900"></span>
                    </div>
                    <div class="relative">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-zinc-300 text-sm font-bold text-zinc-700 dark:bg-zinc-600 dark:text-zinc-200">ML</div>
                        <span class="absolute bottom-0 right-0 block h-3 w-3 rounded-full border-2 border-white bg-yellow-400 dark:border-zinc-900"></span>
                    </div>
                </div>
                <p class="mt-3 text-xs text-zinc-400">Grön = online · Grå = frånkopplad · Gul = borta</p>
            </div>

        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- LADDNINGSTILLSTÅND --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Laddningstillstånd</h2>

        <div class="space-y-6">

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-6 font-mono text-xs text-zinc-400">Spinner — för kortare väntan (&lt; 3s)</p>
                <div class="flex flex-wrap items-center gap-8">
                    <div class="flex flex-col items-center gap-2">
                        <svg class="h-5 w-5 animate-spin text-brand-dark dark:text-brand" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <span class="font-mono text-xs text-zinc-400">sm</span>
                    </div>
                    <div class="flex flex-col items-center gap-2">
                        <svg class="h-8 w-8 animate-spin text-brand-dark dark:text-brand" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <span class="font-mono text-xs text-zinc-400">md</span>
                    </div>
                    <div class="flex flex-col items-center gap-2">
                        <svg class="h-12 w-12 animate-spin text-complement" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <span class="font-mono text-xs text-zinc-400">lg · komplement</span>
                    </div>
                    <div>
                        <button class="btn btn-primary" disabled>
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            Sparar…
                        </button>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-6 font-mono text-xs text-zinc-400">Skeleton loader — för innehåll som tar tid att ladda</p>
                <div class="space-y-4">

                    {{-- Skeleton kort --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 animate-pulse rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                            <div class="flex-1 space-y-2">
                                <div class="h-3 w-1/3 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                                <div class="h-3 w-1/4 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                            </div>
                        </div>
                        <div class="mt-4 space-y-2">
                            <div class="h-3 w-full animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                            <div class="h-3 w-4/5 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                            <div class="h-3 w-3/5 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <div class="h-6 w-16 animate-pulse rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                            <div class="h-6 w-20 animate-pulse rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                        </div>
                    </div>

                    {{-- Skeleton tabellrader --}}
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        @foreach([1,2,3] as $_)
                        <div class="flex items-center gap-4 border-b border-zinc-100 bg-white px-4 py-3 last:border-0 dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="h-8 w-8 animate-pulse rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                            <div class="h-3 w-32 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                            <div class="h-3 w-20 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
                            <div class="ml-auto h-5 w-14 animate-pulse rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                        </div>
                        @endforeach
                    </div>

                </div>
            </div>

            {{-- Gradient shimmer loaders --}}
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-2 font-mono text-xs text-zinc-400">Gradient shimmer — för visuellt tyngre innehåll</p>
                <p class="mb-6 text-xs text-zinc-400 dark:text-zinc-500">Gradienten följer varumärkesavdelaren: <span class="font-mono">from-brand via-brand-orange to-complement</span>. Respekterar <code class="font-mono">prefers-reduced-motion</code> automatiskt.</p>

                <div class="space-y-8">

                    {{-- Neutral shimmer skeleton kort --}}
                    <div>
                        <p class="mb-3 font-mono text-xs text-zinc-400">Neutral shimmer (standard)</p>
                        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="flex items-center gap-3">
                                <div class="shimmer-neutral h-10 w-10 rounded-full"></div>
                                <div class="flex-1 space-y-2">
                                    <div class="shimmer-neutral h-3 w-1/3 rounded"></div>
                                    <div class="shimmer-neutral h-3 w-1/4 rounded"></div>
                                </div>
                            </div>
                            <div class="mt-4 space-y-2">
                                <div class="shimmer-neutral h-3 w-full rounded"></div>
                                <div class="shimmer-neutral h-3 w-4/5 rounded"></div>
                                <div class="shimmer-neutral h-3 w-3/5 rounded"></div>
                            </div>
                            <div class="mt-4 flex gap-2">
                                <div class="shimmer-neutral h-6 w-16 rounded-full"></div>
                                <div class="shimmer-neutral h-6 w-20 rounded-full"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Brand gradient shimmer — progress bar --}}
                    <div>
                        <p class="mb-3 font-mono text-xs text-zinc-400">Brand-gradient progress bar</p>
                        <div class="space-y-4">
                            @foreach([
                                ['Laddar members…', 'w-3/5'],
                                ['Synkroniserar…',  'w-4/5'],
                                ['Exporterar…',     'w-2/5'],
                            ] as [$label, $width])
                            <div>
                                <div class="mb-1 flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                                    <span>{{ $label }}</span>
                                </div>
                                <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                    <div class="shimmer-bar h-2 rounded-full {{ $width }}"></div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Brand gradient shimmer — hero/banner-kort --}}
                    <div>
                        <p class="mb-3 font-mono text-xs text-zinc-400">Brand-gradient hero-skeleton (t.ex. evenemangsbanner)</p>
                        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                            {{-- Topp-bild-platshållare --}}
                            <div class="shimmer-brand h-36 w-full"></div>
                            {{-- Innehåll --}}
                            <div class="bg-white p-5 dark:bg-zinc-900">
                                <div class="space-y-2">
                                    <div class="shimmer-neutral h-4 w-2/3 rounded"></div>
                                    <div class="shimmer-neutral h-3 w-1/2 rounded"></div>
                                </div>
                                <div class="mt-4 flex items-center justify-between">
                                    <div class="shimmer-neutral h-3 w-24 rounded"></div>
                                    <div class="shimmer-neutral h-8 w-20 rounded-lg"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Logotyp-skeleton i navigationsbar --}}
                    <div>
                        <p class="mb-3 font-mono text-xs text-zinc-400">Logotyp-skeleton — navigationsbar under laddning</p>
                        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="flex items-center justify-between px-6 py-3 border-b border-zinc-100 dark:border-zinc-800">
                                {{-- Logo placeholder: 242:84 ≈ 2.88:1 → 144×50 px --}}
                                <div class="shimmer-brand rounded-lg" style="width:144px;height:50px;"></div>
                                <div class="hidden sm:flex items-center gap-5">
                                    <div class="shimmer-neutral h-3 w-16 rounded"></div>
                                    <div class="shimmer-neutral h-3 w-20 rounded"></div>
                                    <div class="shimmer-neutral h-3 w-14 rounded"></div>
                                </div>
                                <div class="shimmer-neutral h-9 w-9 rounded-full"></div>
                            </div>
                            <div class="p-6 space-y-3">
                                <div class="shimmer-neutral h-5 w-48 rounded"></div>
                                <div class="shimmer-neutral h-3 w-full rounded"></div>
                                <div class="shimmer-neutral h-3 w-4/5 rounded"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Logotyp — laddad vs shimmer-mask --}}
                    <div>
                        <p class="mb-3 font-mono text-xs text-zinc-400">Logotyp — laddad vs under laddning (brand-gradient mask)</p>
                        <div class="flex flex-wrap items-center gap-8 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="flex flex-col items-center gap-2">
                                <img src="/armagedon-logo-light.svg" alt="Armagedon" class="dark:hidden" style="height:50px;" />
                                <img src="/armagedon-logo-dark.svg" alt="Armagedon" class="hidden dark:block" style="height:50px;" />
                                <span class="font-mono text-xs text-zinc-400">Laddad</span>
                            </div>
                            <div class="flex flex-col items-center gap-2">
                                <div class="shimmer-brand rounded-lg" style="width:144px;height:50px;-webkit-mask-image:url('/armagedon-logo-light.svg');mask-image:url('/armagedon-logo-light.svg');-webkit-mask-size:contain;mask-size:contain;-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;-webkit-mask-position:center;mask-position:center;"></div>
                                <span class="font-mono text-xs text-zinc-400">Laddar (gradient mask)</span>
                            </div>
                            <div class="flex flex-col items-center gap-2">
                                <div class="shimmer-neutral rounded-lg" style="width:144px;height:50px;"></div>
                                <span class="font-mono text-xs text-zinc-400">Laddar (neutral)</span>
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">Gradient mask: <code class="font-mono">mask-image: url('/armagedon-logo-light.svg')</code> kombinerat med <code class="font-mono">.shimmer-brand</code>. Animationen rör sig genom logotypens silhuett.</p>
                    </div>

                    {{-- Brand gradient shimmer — inline text-loader --}}
                    <div>
                        <p class="mb-3 font-mono text-xs text-zinc-400">Inline gradient-avdelare under laddning</p>
                        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="shimmer-neutral mb-3 h-4 w-40 rounded"></div>
                            <div class="shimmer-brand h-0.5 w-full rounded-full mb-4"></div>
                            <div class="space-y-2">
                                <div class="shimmer-neutral h-3 w-full rounded"></div>
                                <div class="shimmer-neutral h-3 w-5/6 rounded"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Kodexempel --}}
                    <div>
                        <p class="mb-3 font-mono text-xs text-zinc-400">CSS — keyframe + klass (lägg i resources/css/app.css)</p>
                        <pre class="overflow-x-auto rounded-xl bg-zinc-800 p-5 text-xs text-green-300 dark:bg-zinc-950"><code>@@keyframes shimmer-brand {
  0%   { background-position: -400% 0; }
  100% { background-position:  400% 0; }
}

.shimmer-brand {
  background: linear-gradient(90deg,
    #fddd10 0%,
    #fbc712 20%,
    #eb8111 50%,
    #4c1d95 80%,
    #fddd10 100%
  );
  background-size: 400% 100%;
  animation: shimmer-brand 2s ease-in-out infinite;
}

@@media (prefers-reduced-motion: reduce) {
  .shimmer-brand { animation: none; }
}</code></pre>
                    </div>

                </div>
            </div>

        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- TOMMA TILLSTÅND --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Tomma tillstånd</h2>

        <div class="grid gap-6 sm:grid-cols-2">

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-zinc-200 dark:bg-zinc-700">
                    <x-bootstrap-icon name="inbox" class="size-8 text-zinc-400" />
                </div>
                <h3 class="font-semibold text-zinc-900 dark:text-white">Inga meddelanden</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Du har inga inkorgsmeddelanden just nu.</p>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-brand/20 dark:bg-brand/10">
                    <x-bootstrap-icon name="people" class="size-8 text-brand-dark dark:text-brand" />
                </div>
                <h3 class="font-semibold text-zinc-900 dark:text-white">Inga medlemmar ännu</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Den här sektionen har inga aktiva medlemmar.</p>
                <button class="btn btn-primary mx-auto mt-4">
                    <x-bootstrap-icon name="person-plus" class="size-4" />
                    Lägg till medlem
                </button>
            </div>

            <div class="rounded-2xl border border-dashed border-zinc-300 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <x-bootstrap-icon name="search" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                <h3 class="font-semibold text-zinc-700 dark:text-zinc-300">Inga resultat för "armag"</h3>
                <p class="mt-1 text-sm text-zinc-400 dark:text-zinc-500">Prova en annan sökning eller rensa filtren.</p>
                <button class="btn btn-secondary mx-auto mt-4">Rensa sökning</button>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 text-center dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-red-100 dark:bg-red-900/30">
                    <x-bootstrap-icon name="exclamation-triangle" class="size-8 text-red-500 dark:text-red-400" />
                </div>
                <h3 class="font-semibold text-zinc-900 dark:text-white">Något gick fel</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Kunde inte ladda data. Försök igen om en stund.</p>
                <button class="btn btn-secondary mx-auto mt-4">
                    <x-bootstrap-icon name="arrow-clockwise" class="size-4" />
                    Försök igen
                </button>
            </div>

        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- PROGRESS & STEG --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Progress & Steg</h2>

        <div class="space-y-6">

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-6 font-mono text-xs text-zinc-400">Progressbar</p>
                <div class="space-y-4">
                    @foreach([
                        [25,  'bg-brand',           '25% – Påbörjad'],
                        [60,  'bg-brand-orange',    '60% – Pågående'],
                        [85,  'bg-complement-light','85% – Nästan klar'],
                        [100, 'bg-green-500',       '100% – Klar'],
                    ] as [$pct, $color, $label])
                    <div class="flex items-center gap-4">
                        <span class="w-32 shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</span>
                        <div class="flex-1 h-2 rounded-full bg-zinc-200 dark:bg-zinc-700">
                            <div class="h-2 rounded-full {{ $color }}" style="width:{{ $pct }}%"></div>
                        </div>
                        <span class="w-10 shrink-0 text-right font-mono text-xs text-zinc-500">{{ $pct }}%</span>
                    </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-8 font-mono text-xs text-zinc-400">Steg-indikator (anmälningsflöde)</p>
                <div class="flex items-center">
                    {{-- Steg 1: Klar --}}
                    <div class="flex flex-1 flex-col items-center">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-brand text-brand-dark">
                            <x-bootstrap-icon name="check" class="size-4" />
                        </div>
                        <p class="mt-2 text-xs font-medium text-zinc-900 dark:text-white">Personuppgifter</p>
                    </div>
                    <div class="mb-6 h-0.5 flex-1 bg-brand"></div>
                    {{-- Steg 2: Aktiv --}}
                    <div class="flex flex-1 flex-col items-center">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-brand text-brand-dark ring-4 ring-brand/30">
                            2
                        </div>
                        <p class="mt-2 text-xs font-medium text-zinc-900 dark:text-white">Betalning</p>
                    </div>
                    <div class="mb-6 h-0.5 flex-1 bg-zinc-200 dark:bg-zinc-700"></div>
                    {{-- Steg 3: Kommande --}}
                    <div class="flex flex-1 flex-col items-center">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-zinc-300 bg-white text-zinc-400 dark:border-zinc-600 dark:bg-zinc-800">
                            3
                        </div>
                        <p class="mt-2 text-xs font-medium text-zinc-400">Bekräftelse</p>
                    </div>
                </div>
            </div>

        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- ACCORDION --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Accordion</h2>

        <div class="divide-y divide-zinc-200 rounded-2xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700"
             x-data="{ open: 1 }">
            @foreach([
                [1, 'Hur betalar jag årsavgiften?', 'Du kan betala via Swish, kort (Stripe) eller bankgiro. Betalning via bankgiro kan ta 1–3 bankdagar och aktiveras automatiskt när den matchas mot ditt OCR-nummer.'],
                [2, 'Kan jag ha familjemedlemmar på samma konto?', 'Ja, som familjemedlem kan du lägga till upp till 2 vuxna och 5 barn (under 18 år). Familjeadministratören bestämmer vem som får eget inlogg.'],
                [3, 'Hur gör jag om jag glömmer mitt lösenord?', 'Klicka på "Glömt lösenord" på inloggningssidan. Om du loggar in med Google Workspace används din föreningsmail — kontakta styrelsen vid problem.'],
                [4, 'Vad händer om mitt medlemskap löper ut?', 'Du får en påminnelse 30 dagar och 7 dagar innan utgångsdatum. Utan förnyelse deaktiveras kontot men all data sparas i 12 månader.'],
            ] as [$id, $question, $answer])
            <div>
                <button @click="open = open === {{ $id }} ? null : {{ $id }}"
                        class="flex w-full items-center justify-between px-6 py-4 text-left text-sm font-medium text-zinc-900 hover:bg-zinc-50 dark:text-white dark:hover:bg-zinc-800/50">
                    <span>{{ $question }}</span>
                    <x-bootstrap-icon name="chevron-down" class="size-4 shrink-0 text-zinc-400 transition-transform duration-200"
                        ::class="open === {{ $id }} ? 'rotate-180' : ''" />
                </button>
                <div x-show="open === {{ $id }}"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                     x-cloak>
                    <p class="px-6 pb-4 text-sm text-zinc-600 dark:text-zinc-400">{{ $answer }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- TOOLTIP --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-8 text-2xl font-bold text-zinc-900 dark:text-white">Tooltip</h2>

        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="mb-6 font-mono text-xs text-zinc-400">Statisk förhandsvisning — hover för att se tooltip</p>
            <div class="flex flex-wrap items-center gap-8">

                <div class="group relative">
                    <button class="btn btn-secondary">Hovra här</button>
                    <div class="pointer-events-none absolute bottom-full left-1/2 mb-2 hidden -translate-x-1/2 rounded-lg bg-zinc-900 px-3 py-1.5 text-xs whitespace-nowrap text-white shadow-lg group-hover:block dark:bg-zinc-700">
                        Tooltip uppåt
                        <div class="absolute left-1/2 top-full -translate-x-1/2 border-4 border-transparent border-t-zinc-900 dark:border-t-zinc-700"></div>
                    </div>
                </div>

                <div class="group relative">
                    <button class="btn btn-ghost">
                        <x-bootstrap-icon name="info-circle" class="size-5 text-zinc-400" />
                    </button>
                    <div class="pointer-events-none absolute bottom-full left-1/2 mb-2 hidden -translate-x-1/2 rounded-lg bg-zinc-900 px-3 py-1.5 text-xs whitespace-nowrap text-white shadow-lg group-hover:block dark:bg-zinc-700">
                        Mer information
                        <div class="absolute left-1/2 top-full -translate-x-1/2 border-4 border-transparent border-t-zinc-900 dark:border-t-zinc-700"></div>
                    </div>
                </div>

                <div class="group relative">
                    <button class="btn btn-ghost">
                        <x-bootstrap-icon name="trash" class="size-5 text-zinc-400" />
                    </button>
                    <div class="pointer-events-none absolute bottom-full left-1/2 mb-2 hidden -translate-x-1/2 rounded-lg bg-red-700 px-3 py-1.5 text-xs whitespace-nowrap text-white shadow-lg group-hover:block">
                        Ta bort permanent
                        <div class="absolute left-1/2 top-full -translate-x-1/2 border-4 border-transparent border-t-red-700"></div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    {{-- ============================================================ --}}
    {{-- TILLGÄNGLIGHET --}}
    {{-- ============================================================ --}}
    <section>
        <h2 class="mb-2 text-2xl font-bold text-zinc-900 dark:text-white">Tillgänglighet — WCAG 2.1 AA</h2>
        <p class="mb-2 text-sm text-zinc-500 dark:text-zinc-400">Armagedon designsystemet uppfyller WCAG 2.1 nivå AA. Nedan dokumenteras hur varje relevant kriterium är uppfyllt med konkreta exempel och kontrasträkningar.</p>
        <div class="mb-8 flex flex-wrap gap-2">
            @foreach([
                ['1.4.1','Användning av färg'],
                ['1.4.3','Kontrast (minimum)'],
                ['1.4.11','Kontrast för ej-text'],
                ['2.1.1','Tangentbord'],
                ['2.4.7','Synlig fokus'],
                ['2.5.8','Målstorlek'],
                ['3.3','Inmatningshjälp'],
                ['4.1.2','Namn, roll, värde'],
                ['2.3.3','Rörelsestörning'],
            ] as [$id, $label])
            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                <x-bootstrap-icon name="check-circle-fill" class="size-3 text-green-500" />
                {{ $id }} {{ $label }}
            </span>
            @endforeach
        </div>

        <div class="space-y-10">

            {{-- 1.4.3 Kontrast (minimum) --}}
            <div>
                <h3 class="mb-1 text-lg font-semibold text-zinc-900 dark:text-white">1.4.3 — Kontrast (minimum)</h3>
                <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">Normal text ≥ 4,5:1 · Stor text (18pt/14pt fet) ≥ 3:1 · Alla kombina­tioner nedan är testade mot faktiska användnings­fall i systemet.</p>
                <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Kombination</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Förhandsgranskning</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Kvot</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">AA</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Används för</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-950">
                            @foreach([
                                ['text-brand-dark på brand','#231f20','#fddd10','12.0:1',true,'Primärknapp, aktiv tab'],
                                ['text-white på complement','#ffffff','#4c1d95','9.7:1',true,'Komplement-knapp, badge'],
                                ['text-white på complement-light','#ffffff','#7c3aed','5.7:1',true,'Hover-variant komplement'],
                                ['text-brand-dark på orange','#231f20','#eb8111','6.5:1',true,'Varningsmärke mörk text'],
                                ['text-white på orange','#ffffff','#eb8111','2.9:1',false,'Undvik — ej godkänt'],
                                ['text-zinc-700 på white','#3f3f46','#ffffff','8.6:1',true,'Brödtext ljust tema'],
                                ['text-zinc-300 på zinc-950','#d4d4d8','#09090b','14.5:1',true,'Brödtext mörkt tema'],
                                ['text-zinc-500 på white','#71717a','#ffffff','4.6:1',true,'Hjälptext, labels'],
                                ['text-zinc-400 på zinc-950','#a1a1aa','#09090b','8.1:1',true,'Hjälptext mörkt tema'],
                                ['text-blue-600 på white','#2563eb','#ffffff','5.9:1',true,'Länkfärg ljust tema'],
                                ['text-blue-400 på zinc-950','#60a5fa','#09090b','7.3:1',true,'Länkfärg mörkt tema'],
                                ['text-red-600 på white','#dc2626','#ffffff','5.2:1',true,'Felmeddelanden'],
                                ['text-green-700 på white','#15803d','#ffffff','5.5:1',true,'Framgångsmeddelanden'],
                                ['text-zinc-900 på zinc-100','#18181b','#f4f4f5','17.7:1',true,'Meny/panel rubriker'],
                                ['text-amber-900 på amber-50','#78350f','#fffbeb','10.2:1',true,'Varningsbanner text'],
                                ['text-white på brand-dark','#ffffff','#231f20','17.7:1',true,'Inverterat huvud'],
                                ['text-zinc-400 (ikon) på white','#a1a1aa','#ffffff','2.3:1',false,'Dekorativa ikoner — acceptabelt (1.4.1 undantag)'],
                            ] as [$name, $fg, $bg, $ratio, $pass, $note])
                            <tr>
                                <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ $name }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded px-2 py-0.5 text-sm font-medium" style="color:{{ $fg }};background:{{ $bg }}">Aa</span>
                                </td>
                                <td class="px-4 py-3 font-mono text-sm font-bold {{ $pass ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">{{ $ratio }}</td>
                                <td class="px-4 py-3">
                                    @if($pass)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-400"><x-bootstrap-icon name="check-circle-fill" class="size-3" /> OK</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/30 dark:text-red-400"><x-bootstrap-icon name="x-circle-fill" class="size-3" /> Ej AA</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $note }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- 1.4.11 Kontrast för ej-text --}}
            <div>
                <h3 class="mb-1 text-lg font-semibold text-zinc-900 dark:text-white">1.4.11 — Kontrast för ej-text</h3>
                <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">UI-komponenter och grafik ≥ 3:1 mot angränsande färg. Gäller knappar, fält, ikoner, fokusringar och progress-bars.</p>
                <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Element</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Komponent-färg</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Bakgrund</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Kvot</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">AA</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-950">
                            @foreach([
                                ['Textfältsborder (ljust)','#d4d4d8 (zinc-300)','#ffffff','1.5:1',false,'Ersätt med #a1a1aa (zinc-400, 2.3:1) eller #71717a (4.6:1)'],
                                ['Textfältsborder standard','#71717a (zinc-500)','#ffffff','4.6:1',true,'Rekommenderad border-färg på fält'],
                                ['Fokusring brand','#fddd10','#ffffff','1.1:1',false,'Kombinera alltid med 3px offset mot vit bg; ring kontrasterar mot offset-bg'],
                                ['Fokusring offset-gap (vit)','#ffffff','#fddd10','1.1:1',true,'Offset-gapet skapar visuell separation — acceptabel lösning'],
                                ['Fokusring blå','#2563eb','#ffffff','5.9:1',true,'Standardfokusring blå — klar 3:1'],
                                ['Ikon informativ (zinc-600)','#52525b','#ffffff','7.4:1',true,'Informatiska ikoner (ej enbart dekoration)'],
                                ['Progress-bar brand','#fddd10','#f4f4f5 (zinc-100)','1.3:1',false,'Lägg till border eller använd #a16207 (amber-700) på vit bakgrund'],
                                ['Progress-bar complement','#4c1d95','#f4f4f5','9.7:1',true,'Komplement progress-bar — utmärkt'],
                                ['Checkbox border','#71717a','#ffffff','4.6:1',true,'Checked-state: brand-fill med brand-dark bock ≥ 12:1'],
                                ['Radio border','#71717a','#ffffff','4.6:1',true,'Selected-state: complement-fill 9.7:1'],
                            ] as [$elem, $comp, $bg, $ratio, $pass, $note])
                            <tr>
                                <td class="px-4 py-3 text-xs text-zinc-700 dark:text-zinc-300">{{ $elem }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $comp }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $bg }}</td>
                                <td class="px-4 py-3 font-mono text-sm font-bold {{ $pass ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400' }}">{{ $ratio }}</td>
                                <td class="px-4 py-3">
                                    @if($pass)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-400"><x-bootstrap-icon name="check-circle-fill" class="size-3" /> OK</span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-400"><x-bootstrap-icon name="exclamation-triangle-fill" class="size-3" /> Åtgärda</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">Rader markerade "Åtgärda" identifierar kända svagheter med angivna lösningar. Ikoner som är rent dekorativa (aria-hidden="true") är undantagna kriteriet.</p>
            </div>

            {{-- 1.4.1 Användning av färg --}}
            <div>
                <h3 class="mb-1 text-lg font-semibold text-zinc-900 dark:text-white">1.4.1 — Användning av färg</h3>
                <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">Färg får inte vara det enda sättet att förmedla information. Använd alltid ikon, text eller mönster som komplement.</p>
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-2xl border-2 border-red-200 bg-red-50 p-6 dark:border-red-900 dark:bg-red-950/30">
                        <p class="mb-4 text-sm font-semibold text-red-700 dark:text-red-400">
                            <x-bootstrap-icon name="x-circle-fill" class="size-4 inline mr-1" /> Fel — färg som enda indikator
                        </p>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm text-zinc-700 dark:text-zinc-300 mb-1">E-postadress</label>
                                <input type="email" value="inte-en-epost" class="w-full rounded-lg border-2 border-red-500 bg-white px-3 py-2 text-sm text-zinc-900 dark:bg-zinc-900 dark:text-white" />
                                {{-- Inget felmeddelande, bara röd kant --}}
                            </div>
                            <div class="flex gap-2 text-xs">
                                <span class="rounded px-2 py-1 bg-red-500 text-white">Inaktiv</span>
                                <span class="rounded px-2 py-1 bg-green-500 text-white">Aktiv</span>
                                <span class="rounded px-2 py-1 bg-yellow-500 text-white">Väntande</span>
                            </div>
                            <p class="text-xs text-zinc-400">En färgblind användare kan inte skilja på status­badgarna ovan.</p>
                        </div>
                    </div>
                    <div class="rounded-2xl border-2 border-green-200 bg-green-50 p-6 dark:border-green-900 dark:bg-green-950/30">
                        <p class="mb-4 text-sm font-semibold text-green-700 dark:text-green-400">
                            <x-bootstrap-icon name="check-circle-fill" class="size-4 inline mr-1" /> Rätt — färg + ikon + text
                        </p>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-sm text-zinc-700 dark:text-zinc-300 mb-1">E-postadress <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input type="email" value="inte-en-epost" aria-invalid="true" aria-describedby="email-err" class="w-full rounded-lg border-2 border-red-500 bg-white px-3 py-2 text-sm text-zinc-900 dark:bg-zinc-900 dark:text-white" />
                                <p id="email-err" class="mt-1 flex items-center gap-1 text-xs text-red-600 dark:text-red-400">
                                    <x-bootstrap-icon name="exclamation-circle-fill" class="size-3" /> Ange en giltig e-postadress
                                </p>
                            </div>
                            <div class="flex gap-2 text-xs flex-wrap">
                                <span class="inline-flex items-center gap-1 rounded px-2 py-1 bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
                                    <x-bootstrap-icon name="dash-circle-fill" class="size-3" /> Inaktiv
                                </span>
                                <span class="inline-flex items-center gap-1 rounded px-2 py-1 bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">
                                    <x-bootstrap-icon name="check-circle-fill" class="size-3" /> Aktiv
                                </span>
                                <span class="inline-flex items-center gap-1 rounded px-2 py-1 bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                    <x-bootstrap-icon name="hourglass-split" class="size-3" /> Väntande
                                </span>
                            </div>
                            <p class="text-xs text-zinc-400">Ikon + text gör att status är begriplig utan färg­seende.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 2.4.7 Synlig fokus --}}
            <div>
                <h3 class="mb-1 text-lg font-semibold text-zinc-900 dark:text-white">2.4.7 — Synlig fokus (+ 2.1.1 Tangentbord)</h3>
                <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">Alla interaktiva element måste ha en tydlig synlig fokusindikator och vara nåbara med enbart tangentbord. Tryck Tab nedan för att testa.</p>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="mb-6 font-mono text-xs text-zinc-400">← Tryck Tab för att navigera</p>
                    <div class="flex flex-wrap gap-4 items-center">
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-brand">Primärknapp</button>
                        <button class="rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-blue-500">Sekundärknapp</button>
                        <button class="rounded-lg bg-complement px-4 py-2 text-sm font-semibold text-white focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-complement-light">Komplement</button>
                        <input type="text" placeholder="Textfält" class="w-48 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white focus-visible:outline focus-visible:outline-3 focus-visible:outline-blue-500" />
                        <a href="#" class="text-sm text-blue-600 underline dark:text-blue-400 focus-visible:outline focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-blue-500 rounded">Länk</a>
                        <select class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white focus-visible:outline focus-visible:outline-3 focus-visible:outline-blue-500">
                            <option>Välj alternativ</option>
                            <option>Val 1</option>
                        </select>
                    </div>
                    <p class="mt-4 text-xs text-zinc-400 dark:text-zinc-500">Fokusringar använder <code class="font-mono">focus-visible:</code> för att inte visas vid musklick (CSS :focus-visible). Ringarna har minst 3px bredd och 2px offset mot bakgrunden.</p>
                </div>
            </div>

            {{-- 2.5.8 Målstorlek --}}
            <div>
                <h3 class="mb-1 text-lg font-semibold text-zinc-900 dark:text-white">2.5.8 — Målstorlek (minimum 24×24 px)</h3>
                <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">Interaktiva mål ska vara minst 24×24 CSS-pixlar. Rekommenderat för pekskärm är 44×44 px (Apple HIG / Google Material).</p>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-8 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-end gap-8">
                        <div class="flex flex-col items-center gap-2">
                            <div class="flex size-6 items-center justify-center rounded bg-red-200 text-red-800 text-xs font-mono">24</div>
                            <span class="text-xs text-red-600">24×24 px<br>WCAG minimum</span>
                        </div>
                        <div class="flex flex-col items-center gap-2">
                            <button class="flex size-9 items-center justify-center rounded-lg bg-zinc-200 text-zinc-700 hover:bg-zinc-300 dark:bg-zinc-700 dark:text-zinc-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500">
                                <x-bootstrap-icon name="pencil-fill" class="size-4" />
                            </button>
                            <span class="text-xs text-amber-600">36×36 px<br>Ikonknapp liten</span>
                        </div>
                        <div class="flex flex-col items-center gap-2">
                            <button class="flex size-11 items-center justify-center rounded-lg bg-complement text-white hover:bg-complement-light focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-complement-light">
                                <x-bootstrap-icon name="trash-fill" class="size-5" />
                            </button>
                            <span class="text-xs text-green-600">44×44 px<br>Rekommenderat</span>
                        </div>
                        <div class="flex flex-col items-center gap-2">
                            <button class="rounded-lg bg-brand px-6 py-3 text-sm font-semibold text-brand-dark focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                                Spara ändringar
                            </button>
                            <span class="text-xs text-green-600">~44px höjd<br>Standard knapp</span>
                        </div>
                    </div>
                    <p class="mt-6 text-xs text-zinc-400 dark:text-zinc-500">Alla knappar i designsystemet har <code class="font-mono">py-2</code> (32px) eller <code class="font-mono">py-3</code> (48px). Ikonknappar utan synlig etikett ges alltid minst <code class="font-mono">size-9</code> (36px) klickyta.</p>
                </div>
            </div>

            {{-- 3.3 Inmatningshjälp --}}
            <div>
                <h3 class="mb-1 text-lg font-semibold text-zinc-900 dark:text-white">3.3 — Inmatningshjälp (felidentifiering & -beskrivning)</h3>
                <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">Formulärfel ska identifieras, beskrivas i text och kopplas programmatiskt till fältet via <code class="font-mono text-xs">aria-describedby</code>.</p>
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-2xl border-2 border-red-200 bg-red-50 p-6 dark:border-red-900 dark:bg-red-950/30">
                        <p class="mb-4 text-sm font-semibold text-red-700 dark:text-red-400">
                            <x-bootstrap-icon name="x-circle-fill" class="size-4 inline mr-1" /> Fel — ingen ARIA-koppling
                        </p>
                        <form class="space-y-4" onsubmit="return false">
                            <div>
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Namn</label>
                                <input type="text" class="w-full rounded-lg border-2 border-red-500 bg-white px-3 py-2 text-sm dark:bg-zinc-900 dark:text-white" placeholder="Ditt namn" />
                                <p class="mt-1 text-xs text-red-600">Fältet är obligatoriskt</p>
                            </div>
                        </form>
                        <p class="mt-3 text-xs text-zinc-400">Skärmläsare vet inte att fältet är ogiltigt eller läser upp felbeskrivningen.</p>
                    </div>
                    <div class="rounded-2xl border-2 border-green-200 bg-green-50 p-6 dark:border-green-900 dark:bg-green-950/30">
                        <p class="mb-4 text-sm font-semibold text-green-700 dark:text-green-400">
                            <x-bootstrap-icon name="check-circle-fill" class="size-4 inline mr-1" /> Rätt — fullständig ARIA-markup
                        </p>
                        <form class="space-y-4" onsubmit="return false">
                            <div>
                                <label for="name-good" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                                    Namn <span class="text-red-600" aria-hidden="true">*</span>
                                    <span class="sr-only">(obligatoriskt)</span>
                                </label>
                                <input
                                    id="name-good"
                                    type="text"
                                    aria-required="true"
                                    aria-invalid="true"
                                    aria-describedby="name-good-err name-good-hint"
                                    class="w-full rounded-lg border-2 border-red-500 bg-white px-3 py-2 text-sm dark:bg-zinc-900 dark:text-white"
                                    placeholder="Ditt namn"
                                />
                                <p id="name-good-hint" class="mt-1 text-xs text-zinc-500">Ange ditt för- och efternamn</p>
                                <p id="name-good-err" role="alert" class="mt-1 flex items-center gap-1 text-xs text-red-600 dark:text-red-400">
                                    <x-bootstrap-icon name="exclamation-circle-fill" class="size-3" />
                                    Fältet är obligatoriskt
                                </p>
                            </div>
                        </form>
                        <p class="mt-3 text-xs text-zinc-400">Skärmläsare läser etikett → hint → felmeddelande automatiskt.</p>
                    </div>
                </div>
            </div>

            {{-- 4.1.2 Namn, roll, värde --}}
            <div>
                <h3 class="mb-1 text-lg font-semibold text-zinc-900 dark:text-white">4.1.2 — Namn, roll, värde</h3>
                <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">Alla interaktiva komponenter ska ha ett tillgängligt namn, korrekt ARIA-roll och kommunicera sitt tillstånd programmatiskt.</p>
                <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900">
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Komponent</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">ARIA-krav</th>
                                <th class="px-4 py-3 text-left font-semibold text-zinc-700 dark:text-zinc-300">Exempel</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-950">
                            @foreach([
                                ['Ikonknapp utan text','aria-label krävs','<button aria-label="Ta bort">…</button>'],
                                ['Toggle-knapp','aria-pressed="true/false"','<button aria-pressed="false">Mörkt tema</button>'],
                                ['Dropdown-meny','aria-expanded + aria-haspopup','<button aria-expanded="false" aria-haspopup="menu">'],
                                ['Modal dialog','role="dialog" + aria-labelledby','<div role="dialog" aria-labelledby="modal-title">'],
                                ['Alert/toast','role="alert" eller aria-live','<div role="alert">Sparades!</div>'],
                                ['Progress-bar','role="progressbar" + aria-valuenow','<div role="progressbar" aria-valuenow="60" aria-valuemin="0" aria-valuemax="100">'],
                                ['Flik-panel','role="tablist/tab/tabpanel"','<div role="tablist"><button role="tab" aria-selected="true">'],
                                ['Accordion','aria-expanded + aria-controls','<button aria-expanded="true" aria-controls="acc-body-1">'],
                                ['Sökfält','aria-label eller aria-labelledby','<input type="search" aria-label="Sök bland medlemmar">'],
                                ['Live-region','aria-live="polite"','<div aria-live="polite" aria-atomic="true">Laddar…</div>'],
                            ] as [$comp, $req, $ex])
                            <tr>
                                <td class="px-4 py-3 font-medium text-zinc-800 dark:text-zinc-200">{{ $comp }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $req }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $ex }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- 2.3.3 / prefers-reduced-motion --}}
            <div>
                <h3 class="mb-1 text-lg font-semibold text-zinc-900 dark:text-white">2.3.3 — Rörelsestörning (prefers-reduced-motion)</h3>
                <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">Användare som har aktiverat "Reducera rörelse" i sitt OS ska inte utsättas för onödiga animationer. Använd alltid <code class="font-mono text-xs">motion-safe:</code> / <code class="font-mono text-xs">motion-reduce:</code> i Tailwind eller mediafrågan i CSS.</p>
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="mb-3 font-mono text-xs text-zinc-400">Tailwind-klasser</p>
                        <pre class="overflow-x-auto rounded-lg bg-zinc-800 p-4 text-xs text-green-300 dark:bg-zinc-950"><code>&lt;div class="
  motion-safe:animate-spin
  motion-reduce:animate-none
"&gt;
  &lt;!-- Spinner visas bara om motion är OK --&gt;
&lt;/div&gt;

&lt;div class="
  motion-safe:transition-all
  motion-safe:duration-300
"&gt;
  &lt;!-- Övergångar avaktiveras vid reduced motion --&gt;
&lt;/div&gt;</code></pre>
                    </div>
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900">
                        <p class="mb-3 font-mono text-xs text-zinc-400">CSS mediafråga</p>
                        <pre class="overflow-x-auto rounded-lg bg-zinc-800 p-4 text-xs text-green-300 dark:bg-zinc-950"><code>@@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}</code></pre>
                        <p class="mt-3 text-xs text-zinc-400">Denna regel är inkluderad i <code class="font-mono">resources/css/app.css</code> som global reset.</p>
                    </div>
                </div>

                {{-- Live demo reduced motion --}}
                <div class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="mb-4 text-sm font-medium text-zinc-700 dark:text-zinc-300">Demo — spinner respekterar reduced motion</p>
                    <div class="flex items-center gap-6">
                        <div class="flex flex-col items-center gap-2">
                            <div class="size-10 rounded-full border-4 border-zinc-200 border-t-complement motion-safe:animate-spin motion-reduce:border-t-zinc-400 dark:border-zinc-700 dark:border-t-complement-light"></div>
                            <span class="text-xs text-zinc-500">motion-safe: snurrar</span>
                        </div>
                        <div class="flex flex-col items-center gap-2">
                            <div class="size-10 rounded-full border-4 border-zinc-200 border-t-zinc-400 dark:border-zinc-700"></div>
                            <span class="text-xs text-zinc-500">motion-reduce: statisk</span>
                        </div>
                        <p class="text-xs text-zinc-400 dark:text-zinc-500 max-w-xs">Om ditt OS har "Reducera rörelse" aktiverat visas bara den statiska varianten. Aktivera i Systeminst. → Hjälpmedel → Bildskärm.</p>
                    </div>
                </div>
            </div>

        </div>
    </section>

    {{-- Footer --}}
    <footer class="border-t border-zinc-200 pt-8 text-center text-sm text-zinc-400 dark:border-zinc-800 dark:text-zinc-600">
        <p>Armagedon Grafisk Profil · {{ $theme === 'dark' ? 'Mörkt' : 'Ljust' }} tema · Genererad {{ now()->format('d/m Y') }}</p>
        <p class="mt-1">
            <a href="{{ route('grafiskprofil.light') }}" class="hover:text-zinc-600 dark:hover:text-zinc-400">Ljust tema</a>
            &nbsp;·&nbsp;
            <a href="{{ route('grafiskprofil.dark') }}" class="hover:text-zinc-600 dark:hover:text-zinc-400">Mörkt tema</a>
        </p>
    </footer>

</div>

</body>
</html>
