<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $room->name }} — Armagedon</title>
    @if ($nextChangeAt)
        <meta http-equiv="refresh" content="{{ max(20, now()->diffInSeconds($nextChangeAt)) }}">
    @else
        <meta http-equiv="refresh" content="60">
    @endif
    <script src="https://cdn.tailwindcss.com"></script>
    <style>body { font-family: system-ui, sans-serif; }</style>
</head>
<body class="min-h-screen flex flex-col p-8 {{ $current ? 'bg-red-950' : 'bg-green-950' }}">

    {{-- Rumsnamn --}}
    <div class="flex items-center justify-between mb-8">
        <h1 class="text-4xl font-extrabold text-white tracking-tight">{{ $room->name }}</h1>
        <div class="text-right">
            <p class="text-2xl font-mono font-bold {{ $current ? 'text-red-200' : 'text-green-200' }}">
                {{ $generatedAt->format('H:i') }}
            </p>
            <p class="text-sm {{ $current ? 'text-red-400' : 'text-green-400' }}">
                {{ $generatedAt->locale('sv')->isoFormat('dddd D MMMM') }}
            </p>
        </div>
    </div>

    {{-- Nuläge --}}
    <div class="flex-1 flex flex-col items-center justify-center">
        @if ($current)
            <div class="text-center">
                <span class="inline-flex items-center gap-3 text-2xl font-semibold text-red-200 mb-6">
                    <span class="inline-block h-4 w-4 rounded-full bg-red-500 animate-pulse"></span>
                    Upptaget
                </span>
                <p class="text-5xl font-extrabold text-white mt-2">{{ $current->title }}</p>
                <p class="text-3xl font-mono text-red-300 mt-4 tabular-nums">
                    {{ $current->starts_at->format('H:i') }} – {{ $current->ends_at->format('H:i') }}
                </p>
            </div>
        @else
            <div class="text-center">
                <span class="inline-flex items-center gap-3 text-2xl font-semibold text-green-200">
                    <span class="inline-block h-4 w-4 rounded-full bg-green-400"></span>
                    Ledigt
                </span>
            </div>
        @endif
    </div>

    {{-- Kommande bokningar --}}
    @if ($upcoming->isNotEmpty())
        <div class="mt-8 border-t {{ $current ? 'border-red-900' : 'border-green-900' }} pt-6">
            <h2 class="text-sm font-semibold uppercase tracking-widest {{ $current ? 'text-red-500' : 'text-green-600' }} mb-3">
                Kommande bokningar
            </h2>
            <div class="space-y-3">
                @foreach ($upcoming as $b)
                    <div class="flex items-baseline justify-between">
                        <span class="text-xl font-semibold text-white">{{ $b->title }}</span>
                        <span class="ml-4 text-lg font-mono {{ $current ? 'text-red-300' : 'text-green-300' }} tabular-nums shrink-0">
                            {{ $b->starts_at->format('H:i') }}
                            @if (! $b->starts_at->isToday())
                                <span class="text-sm opacity-70 ml-1">({{ $b->starts_at->locale('sv')->isoFormat('D MMM') }})</span>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

</body>
</html>
