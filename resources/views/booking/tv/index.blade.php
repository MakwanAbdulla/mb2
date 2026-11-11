<!DOCTYPE html>
<html lang="sv" class="bg-zinc-950 text-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rumsstatus — Armagedon</title>
    @if ($nextChangeAt)
        <meta http-equiv="refresh" content="{{ max(30, now()->diffInSeconds($nextChangeAt)) }}">
    @else
        <meta http-equiv="refresh" content="120">
    @endif
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: system-ui, sans-serif; }
        .brand { color: #fddd10; }
        .bg-brand { background-color: #fddd10; }
        .bg-free { background-color: #052e16; border-color: #166534; }
        .bg-busy { background-color: #450a0a; border-color: #7f1d1d; }
    </style>
</head>
<body class="bg-zinc-950 min-h-screen p-4">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-3xl font-bold text-white">Rumsstatus</h1>
        <div class="text-right">
            <p class="text-zinc-400 text-sm">{{ $generatedAt->locale('sv')->isoFormat('dddd D MMMM') }}</p>
            <p class="text-2xl font-mono font-bold brand">{{ $generatedAt->format('H:i') }}</p>
        </div>
    </div>

    {{-- Meddelanden --}}
    @foreach ($notices as $notice)
        @php
            $cls = match($notice->type) {
                'success' => 'border-green-700 bg-green-950 text-green-200',
                'warning' => 'border-amber-700 bg-amber-950 text-amber-200',
                'error'   => 'border-red-700 bg-red-950 text-red-200',
                default   => 'border-blue-700 bg-blue-950 text-blue-200',
            };
        @endphp
        <div class="mb-4 rounded-xl border px-5 py-3 {{ $cls }}">
            @if ($notice->title)<strong>{{ $notice->title }}:</strong>@endif
            {{ $notice->message }}
        </div>
    @endforeach

    {{-- Rumsrutnät --}}
    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr))">
        @foreach ($roomData as $data)
            @php
                $room     = $data['room'];
                $current  = $data['current'];
                $upcoming = $data['upcoming'];
                $free     = $current === null;
            @endphp
            <div class="rounded-2xl border-2 p-5 {{ $free ? 'bg-free border-green-800' : 'bg-busy border-red-900' }}">
                <div class="flex items-start justify-between gap-2 mb-3">
                    <h2 class="text-xl font-bold text-white leading-tight">{{ $room->name }}</h2>
                    @if ($room->color)
                        <span class="inline-block h-4 w-4 rounded-full shrink-0 mt-0.5"
                              style="background-color: {{ $room->color }}"></span>
                    @endif
                </div>

                @if ($free)
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-3 w-3 rounded-full bg-green-500"></span>
                        <span class="text-lg font-semibold text-green-300">Ledigt</span>
                    </div>

                    @if ($upcoming->isNotEmpty())
                        <div class="mt-3 space-y-1.5">
                            <p class="text-xs uppercase tracking-wide text-zinc-500">Kommande</p>
                            @foreach ($upcoming as $b)
                                <div class="flex items-baseline justify-between text-sm">
                                    <span class="text-zinc-300 truncate">{{ $b->title }}</span>
                                    <span class="ml-2 text-zinc-400 shrink-0 tabular-nums">{{ $b->starts_at->format('H:i') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-3 text-sm text-zinc-500">Inga fler bokningar idag.</p>
                    @endif
                @else
                    <div class="flex items-center gap-2 mb-2">
                        <span class="inline-block h-3 w-3 rounded-full bg-red-500 animate-pulse"></span>
                        <span class="text-lg font-semibold text-red-300">Upptaget</span>
                    </div>
                    <p class="font-semibold text-white text-base truncate">{{ $current->title }}</p>
                    <p class="text-sm text-zinc-400 tabular-nums mt-0.5">
                        {{ $current->starts_at->format('H:i') }} – {{ $current->ends_at->format('H:i') }}
                    </p>

                    @if ($upcoming->isNotEmpty())
                        <div class="mt-3 space-y-1.5">
                            <p class="text-xs uppercase tracking-wide text-zinc-600">Nästa</p>
                            @foreach ($upcoming as $b)
                                <div class="flex items-baseline justify-between text-sm">
                                    <span class="text-zinc-400 truncate">{{ $b->title }}</span>
                                    <span class="ml-2 text-zinc-500 shrink-0 tabular-nums">{{ $b->starts_at->format('H:i') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    {{-- Footer --}}
    <div class="mt-8 flex items-center justify-between text-xs text-zinc-600">
        <span>Armagedon Sällskapsspel</span>
        <span>Uppdateras automatiskt</span>
    </div>

</body>
</html>
