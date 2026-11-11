{{--
    Färgreferens — Armagedon grafisk profil + infogningsbara färgade rutor.
    Usage: <x-cms.color-reference />
    Requires the insertWidget(tag) JS function in the parent view.
--}}
@php
    $profil = [
        ['namn' => 'Brand (gul)',       'hex' => '#fddd10', 'text' => '#231f20'],
        ['namn' => 'Brand light',       'hex' => '#fee81b', 'text' => '#231f20'],
        ['namn' => 'Brand saffron',     'hex' => '#fbc712', 'text' => '#231f20'],
        ['namn' => 'Brand amber',       'hex' => '#f9a70d', 'text' => '#231f20'],
        ['namn' => 'Brand orange',      'hex' => '#eb8111', 'text' => '#fff'],
        ['namn' => 'Brand dark',        'hex' => '#231f20', 'text' => '#fddd10'],
        ['namn' => 'Brand gray',        'hex' => '#4f4c4d', 'text' => '#fff'],
        ['namn' => 'Complement',        'hex' => '#4c1d95', 'text' => '#fff'],
        ['namn' => 'Complement mid',    'hex' => '#6d28d9', 'text' => '#fff'],
        ['namn' => 'Complement light',  'hex' => '#7c3aed', 'text' => '#fff'],
    ];

    $rutor = [
        ['etikett' => 'Gul ruta',       'hex' => '#fddd10', 'text' => '#231f20'],
        ['etikett' => 'Orange ruta',    'hex' => '#eb8111', 'text' => '#fff'],
        ['etikett' => 'Mörk ruta',      'hex' => '#231f20', 'text' => '#fddd10'],
        ['etikett' => 'Grå ruta',       'hex' => '#4f4c4d', 'text' => '#fff'],
        ['etikett' => 'Violett ruta',   'hex' => '#4c1d95', 'text' => '#fff'],
        ['etikett' => 'Ljusviolett',    'hex' => '#7c3aed', 'text' => '#fff'],
    ];
@endphp

<div class="space-y-5">

    {{-- Profilfärger --}}
    <div>
        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Profilfärger</p>
        <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Klicka på en färg för att kopiera hex-koden.</p>
        <div class="grid grid-cols-5 gap-2">
            @foreach($profil as $farg)
                <div x-data="{ copied: false }" class="text-center">
                    <button
                        type="button"
                        @click="
                            navigator.clipboard.writeText({{ Js::from($farg['hex']) }});
                            copied = true;
                            setTimeout(() => copied = false, 1500);
                        "
                        class="w-full aspect-square rounded-lg border border-black/10 dark:border-white/10 shadow-xs transition hover:scale-105 hover:shadow-md"
                        style="background-color: {{ $farg['hex'] }}"
                        title="{{ $farg['namn'] }} — {{ $farg['hex'] }}"
                    ></button>
                    <p class="mt-1 text-xs leading-tight text-zinc-600 dark:text-zinc-400">{{ $farg['namn'] }}</p>
                    <p x-show="!copied" class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $farg['hex'] }}</p>
                    <p x-show="copied" x-cloak class="text-xs text-green-600 dark:text-green-400">✓ Kopierad!</p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Färgade rutor --}}
    <div>
        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Färgade rutor</p>
        <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Klicka för att infoga en färgad ruta i innehållet.</p>
        <div class="space-y-2">
            @foreach($rutor as $ruta)
                @php
                    $html = '<div style="background-color:' . $ruta['hex'] . ';color:' . $ruta['text'] . ';padding:0.875rem 1.25rem;border-radius:0.5rem;margin:0.75rem 0;">Skriv innehåll här</div>';
                @endphp
                <div x-data="{ inserted: false }" class="flex items-center gap-3">
                    <button
                        type="button"
                        @click="
                            insertWidget({{ Js::from($html) }});
                            inserted = true;
                            setTimeout(() => inserted = false, 1500);
                        "
                        class="flex w-full items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-left text-xs transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                    >
                        <span class="size-5 shrink-0 rounded border border-black/10" style="background-color: {{ $ruta['hex'] }}"></span>
                        <span class="text-zinc-700 dark:text-zinc-300">{{ $ruta['etikett'] }}</span>
                        <span class="ml-auto font-mono text-zinc-400 dark:text-zinc-500">{{ $ruta['hex'] }}</span>
                    </button>
                    <span x-show="inserted" x-cloak class="shrink-0 text-xs text-green-600 dark:text-green-400">✓ Infogad!</span>
                </div>
            @endforeach
        </div>
    </div>

</div>
