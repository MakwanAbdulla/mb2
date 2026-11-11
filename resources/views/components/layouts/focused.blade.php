@php
    $user     = auth()->user();
    $theme    = $user?->ui_theme ?? 'system';
    $initDark = $theme === 'dark' ? 'dark' : '';
    $useJs    = $theme === 'system' || ! auth()->check();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $initDark }}">
<head>
    <script>
        (function(){
            var t = localStorage.getItem('ui_theme') || '{{ $theme }}';
            if (t === 'dark') document.documentElement.classList.add('dark');
            else if (t === 'light') document.documentElement.classList.remove('dark');
            else if (window.matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.classList.add('dark');
        })();
    </script>
    <style>html{background:#fff}html.dark{background:#1c1a1b;color-scheme:dark}</style>
    @include('partials.head')
    <meta name="ui-theme" content="{{ $theme }}">
    <script>
        document.addEventListener('livewire:navigated', function(){
            var m = document.querySelector('meta[name="ui-theme"]');
            var t = localStorage.getItem('ui_theme') || (m ? m.content : 'system');
            if (t === 'dark') document.documentElement.classList.add('dark');
            else if (t === 'light') document.documentElement.classList.remove('dark');
            else document.documentElement.classList.toggle('dark', window.matchMedia('(prefers-color-scheme: dark)').matches);
        });
    </script>
</head>
<body class="flex min-h-screen flex-col bg-white dark:bg-warm-dark">

    {{-- ── Topbar (no hamburger, no sidebar) ──────────────────────────────── --}}
    <header class="sticky top-0 z-30 border-b border-zinc-200 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/80 dark:border-zinc-700 dark:bg-zinc-900/95 dark:supports-[backdrop-filter]:bg-zinc-900/80">
        <div class="mx-auto flex h-14 max-w-2xl items-center gap-3 px-4 sm:px-6">

            <a href="/" class="flex items-center" wire:navigate>
                <img src="{{ asset('armagedon-logo-light.svg') }}" alt="{{ config('app.name') }}" class="h-8 dark:hidden">
                <img src="{{ asset('armagedon-logo-dark.svg') }}" alt="{{ config('app.name') }}" class="h-8 hidden dark:block">
            </a>

            <div class="flex-1"></div>

            @auth
                @livewire('theme-toggle')

                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open"
                            class="size-8 overflow-hidden rounded-full ring-2 ring-zinc-200 hover:ring-brand dark:ring-zinc-700 dark:hover:ring-brand"
                            aria-label="Kontomeny">
                        @if($user->avatar_path)
                            <img src="{{ $user->avatarUrl(64) }}" alt="{{ $user->name }}" class="size-full object-cover">
                        @else
                            <span class="flex size-full items-center justify-center bg-zinc-200 text-xs font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300">
                                {{ collect(explode(' ', $user->name))->map(fn ($n) => mb_substr($n, 0, 1))->take(2)->implode('') }}
                            </span>
                        @endif
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute right-0 top-full z-50 mt-1 min-w-[200px] overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="border-b border-zinc-100 px-4 py-3 dark:border-zinc-700">
                            <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $user->name }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $user->email }}</p>
                        </div>
                        <a href="{{ route('dashboard') }}" wire:navigate
                           class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                            Armagedon &amp; jag
                        </a>
                        <hr class="border-zinc-100 dark:border-zinc-700">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit"
                                    class="flex w-full items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                Logga ut
                            </button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>
    </header>

    {{-- ── Main content ─────────────────────────────────────────────────────── --}}
    <main class="flex-1">
        {{ $slot }}
    </main>

    @fluxScripts
</body>
</html>
