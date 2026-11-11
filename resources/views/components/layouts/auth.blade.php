<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-screen flex-col items-center justify-center bg-zinc-50 p-4 dark:bg-warm-dark">
        <a href="/" wire:navigate class="mb-8 block">
            <img src="{{ asset('armagedon-logo-light.svg') }}" alt="{{ config('app.name') }}" class="h-16 dark:hidden">
            <img src="{{ asset('armagedon-logo-dark.svg') }}" alt="{{ config('app.name') }}" class="h-16 hidden dark:block">
        </a>

        <div class="w-full max-w-sm">
            {{ $slot }}
        </div>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
