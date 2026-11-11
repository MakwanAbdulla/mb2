@php
    $user     = auth()->user();
    $theme    = $user?->ui_theme ?? 'system';
    $initDark = $theme === 'dark' ? 'dark' : '';
    $useJs    = $theme === 'system' || ! auth()->check();
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
<body class="min-h-screen bg-white dark:bg-warm-dark flex flex-col items-center justify-start py-12 px-4">

    <div class="mb-10 flex justify-center">
        <img src="{{ asset('armagedon-logo-light.svg') }}" alt="{{ config('app.name') }}" class="h-14 dark:hidden">
        <img src="{{ asset('armagedon-logo-dark.svg') }}" alt="{{ config('app.name') }}" class="h-14 hidden dark:block">
    </div>

    <div class="w-full max-w-lg">
        {{ $slot }}
    </div>

    @fluxScripts
</body>
</html>
