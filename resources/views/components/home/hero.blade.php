@props([
    'title',
    'subtitle' => null,
    'editUrl' => null,
    'now' => null,
])

@php
    // The swoosh goes under the last word. A one-word title gets it under that word only.
    $first = Str::contains($title, ' ') ? Str::beforeLast($title, ' ') : '';
    $last  = Str::afterLast($title, ' ');

    $btn = 'gap-2 px-[1.2rem] py-[0.7rem] text-[0.9375rem] leading-[1.2]';
    $btnWhite = "btn {$btn} border border-zinc-200 bg-white text-zinc-900 hover:border-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:hover:border-zinc-400";

    // Weekly game nights. iso = weekday number, 1 = Monday … 7 = Sunday.
    $days = [
        ['iso' => 2, 'short' => 'Tis', 'name' => 'Figurspel', 'when' => 'Varje tisdag kväll'],
        ['iso' => 4, 'short' => 'Tor', 'name' => 'Brädspel',  'when' => 'Klockan 18'],
        ['iso' => 7, 'short' => 'Sön', 'name' => 'Kortspel',  'when' => 'Nästan varje söndag från ca 10', 'discord' => true],
    ];

    // The next game night: the first one from today onwards, otherwise the first one next week.
    $today = now()->dayOfWeekIso;
    $nextIso = collect($days)->firstWhere(fn ($d) => $d['iso'] >= $today)['iso'] ?? $days[0]['iso'];
@endphp

<section class="relative isolate overflow-hidden border-b border-zinc-200 bg-linear-135 from-[#fffbea] via-[#fff8f0] to-[#f6f3ff] dark:border-zinc-800 dark:from-[#262221] dark:via-[#1f1c1c] dark:to-[#211d2a]">

    {{-- Hexagon pattern, like the logo, fading out to the left --}}
    <div aria-hidden="true"
         class="absolute inset-0 -z-10 opacity-5 dark:opacity-[0.06] dark:invert"
         style="background-image: url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='56' height='98' viewBox='0 0 56 98'%3E%3Cpath d='M28 1 L55 17 L55 49 L28 65 L1 49 L1 17 Z M28 65 L28 97' fill='none' stroke='black' stroke-width='1.2'/%3E%3C/svg%3E&quot;); background-size: 56px 98px; mask-image: linear-gradient(100deg, transparent 35%, #000 85%);"></div>

    {{-- Warm glow, top right --}}
    <div aria-hidden="true"
         class="absolute -right-[22rem] -top-[26rem] -z-10 size-[50rem] bg-radial from-[rgba(253,221,16,.22)] to-transparent to-60% dark:from-[rgba(253,221,16,.08)]"></div>

    <div class="mx-auto grid max-w-6xl items-center gap-10 px-5 pt-16 pb-14 md:pt-26 md:pb-20 min-[900px]:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)] min-[900px]:gap-16">
        <div class="max-w-[44rem]">
            <div class="flex items-start gap-2">
                <h1 class="text-[2.5rem] font-bold leading-[1.08] tracking-[-0.02em] text-zinc-900 md:text-[4rem] dark:text-white">
                    {{ $first }} <x-swoosh>{{ $last }}</x-swoosh>
                </h1>
                @if ($editUrl)
                    @can('cms.edit')
                        <a href="{{ $editUrl }}" wire:navigate title="Redigera sida"
                           class="mt-2 rounded p-1 text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-300">
                            <x-bootstrap-icon name="pencil" class="size-4" />
                        </a>
                    @endcan
                @endif
            </div>
            <p class="mt-5 text-[1.0625rem] leading-[1.6] text-zinc-700 md:text-xl dark:text-zinc-300">{{ $subtitle }}</p>

            <div class="mt-8 flex flex-wrap gap-3">
                @guest
                    <a href="{{ route('membership.apply') }}" wire:navigate class="btn-primary {{ $btn }}">
                        Bli medlem <x-bootstrap-icon name="arrow-right" class="size-4" />
                    </a>
                    <a href="{{ route('login') }}" wire:navigate class="{{ $btnWhite }}">Logga in</a>
                @endguest

                @auth
                    <a href="{{ route('dashboard') }}" wire:navigate class="btn-primary {{ $btn }}">
                        <x-bootstrap-icon name="house" class="size-4" /> Min sida
                    </a>
                    @if (auth()->user()->canBook())
                        <a href="{{ route('booking.create') }}" wire:navigate class="{{ $btnWhite }}">
                            <x-bootstrap-icon name="plus-circle" class="size-4" /> Boka lokal
                        </a>
                    @endif
                @endauth

                <a href="https://discord.armagedon.se" class="btn {{ $btn }} bg-[#5865f2] text-white hover:bg-[#4752c4]">
                    <x-bootstrap-icon name="discord" class="size-4" /> Discord
                </a>
            </div>
        </div>

        {{-- Week card: the weekly game nights --}}
        <aside aria-label="Veckans spelkvällar"
               class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-[0_1px_2px_rgba(0,0,0,.04),0_12px_32px_-12px_rgba(35,31,32,.18)] dark:border-zinc-700 dark:bg-zinc-800 dark:shadow-[0_12px_32px_-12px_rgba(0,0,0,.6)]">

            {{-- "Just nu i lokalen": hidden until there is a data source that can fill $now, e.g. "Brädspel, till 22:00" --}}
            @if ($now)
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-2 border-b border-zinc-200 bg-green-100 px-5 py-3 text-sm text-green-800 dark:border-zinc-700 dark:bg-green-600/20 dark:text-green-400">
                    <span class="relative flex size-2.5" aria-hidden="true">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-50"></span>
                        <span class="relative inline-flex size-2.5 rounded-full bg-current"></span>
                    </span>
                    <span><b class="font-semibold">Just nu i lokalen:</b> {{ $now }}</span>
                </div>
            @endif

            <div class="flex items-center justify-between gap-4 px-5 pt-4 pb-2">
                <span class="inline-flex items-center gap-2 font-semibold text-zinc-900 dark:text-white">
                    <x-bootstrap-icon name="calendar-week" class="size-4 text-brand-orange" /> Veckans spelkvällar
                </span>
            </div>

            <ul class="space-y-0.5 px-2 pt-1 pb-2">
                @foreach ($days as $day)
                    @php($isNext = $day['iso'] === $nextIso)
                    <li @class(['flex items-center gap-3.5 rounded-xl px-3 py-2.5', 'bg-[#fefce8] dark:bg-[rgba(253,221,16,.08)]' => $isNext])>
                        <span @class([
                            'inline-flex size-11 shrink-0 items-center justify-center rounded-[.625rem] border text-[.8125rem] font-bold uppercase tracking-[.04em]',
                            'border-brand bg-brand text-brand-dark' => $isNext,
                            'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-[#232021]' => ! $isNext,
                            'text-red-600 dark:text-red-400' => ! $isNext && $day['iso'] === 7,
                            'text-zinc-700 dark:text-zinc-300' => ! $isNext && $day['iso'] !== 7,
                        ])>{{ $day['short'] }}</span>

                        <span class="flex min-w-0 flex-1 flex-col">
                            <b class="font-semibold text-zinc-900 dark:text-white">{{ $day['name'] }}</b>
                            <small class="text-[.8125rem] text-zinc-500 dark:text-zinc-400">
                                {{ $day['when'] }}
                                @if ($day['discord'] ?? false)
                                    <a href="https://discord.armagedon.se" aria-label="Kolla på Discord om det är speldag"
                                       class="ml-1 inline-flex size-[1.35rem] items-center justify-center rounded-full bg-[#5865f2] align-middle text-white hover:bg-[#4752c4]">
                                        <x-bootstrap-icon name="discord" class="size-3" />
                                    </a>
                                @endif
                            </small>
                        </span>

                        @if ($isNext)
                            <span class="inline-flex items-center gap-1.5 text-[.6875rem] font-semibold uppercase tracking-[.08em] text-zinc-700 dark:text-zinc-300">
                                <span class="size-[.45rem] rounded-full bg-brand-orange"></span> Nästa
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="flex items-center gap-2 border-t border-zinc-200 bg-zinc-50 px-5 py-3 text-[.8125rem] text-zinc-500 dark:border-zinc-700 dark:bg-[#232021] dark:text-zinc-400">
                <x-bootstrap-icon name="geo-alt-fill" class="size-4 text-brand-orange" /> Katrinehillsgatan 7b, 504 52 Borås
            </div>
        </aside>
    </div>
</section>
