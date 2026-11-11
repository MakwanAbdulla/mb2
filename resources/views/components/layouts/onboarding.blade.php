@php
    $user    = auth()->user();
    $theme   = $user?->ui_theme ?? 'system';
    $initDark = $theme === 'dark' ? 'dark' : '';
    $useJs   = $theme === 'system' || ! auth()->check();

    $currentRoute = request()->route()?->getName() ?? '';
    $steps = [
        ['route' => 'membership.apply',   'label' => 'Uppgifter'],
        ['route' => 'membership.payment', 'label' => 'Betalning'],
    ];
    $currentStep = collect($steps)->search(fn ($s) => $s['route'] === $currentRoute) ?? 0;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $initDark }}">
<head>
    @if($useJs)
        <script>
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        </script>
    @endif
    @include('partials.head')
</head>
<body class="min-h-screen bg-zinc-50 dark:bg-warm-dark">

    <div class="flex min-h-screen flex-col">

        {{-- Header --}}
        <header class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mx-auto flex h-14 max-w-2xl items-center justify-between px-4 sm:px-6">
                <a href="{{ route('home') }}" wire:navigate>
                    <img src="{{ asset('armagedon-logo-light.svg') }}" alt="{{ config('app.name') }}" class="h-8 dark:hidden">
                    <img src="{{ asset('armagedon-logo-dark.svg') }}" alt="{{ config('app.name') }}" class="h-8 hidden dark:block">
                </a>
                <a href="{{ route('dashboard') }}" wire:navigate
                   class="text-sm text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                    Avbryt
                </a>
            </div>
        </header>

        {{-- Stepper --}}
        <div class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mx-auto max-w-2xl px-4 sm:px-6">
                <ol class="flex">
                    @foreach($steps as $i => $step)
                        @php
                            $isDone    = $i < $currentStep;
                            $isCurrent = $i === $currentStep;
                        @endphp
                        <li class="flex items-center gap-2 py-3 pr-6">
                            <span @class([
                                'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                'bg-brand text-brand-dark'      => $isCurrent,
                                'bg-green-500 text-white'       => $isDone,
                                'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400' => ! $isCurrent && ! $isDone,
                            ])>
                                @if($isDone)
                                    <x-bootstrap-icon name="check" class="size-3.5" />
                                @else
                                    {{ $i + 1 }}
                                @endif
                            </span>
                            <span @class([
                                'text-sm font-medium',
                                'text-zinc-900 dark:text-white'   => $isCurrent,
                                'text-green-600 dark:text-green-400' => $isDone,
                                'text-zinc-400 dark:text-zinc-500'   => ! $isCurrent && ! $isDone,
                            ])>
                                {{ $step['label'] }}
                            </span>
                            @if(! $loop->last)
                                <x-bootstrap-icon name="chevron-right" class="ml-4 size-3.5 text-zinc-300 dark:text-zinc-600" />
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>

        {{-- Content --}}
        <main class="flex-1">
            {{ $slot }}
        </main>

    </div>

    @livewireScripts
</body>
</html>
