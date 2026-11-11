<!DOCTYPE html>
<html lang="sv" class="bg-zinc-950 text-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="15">
    <title>{{ $tournament->title }} — Meddelande</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: system-ui, sans-serif; }
        .brand { color: #fddd10; }
    </style>
</head>
<body class="bg-zinc-950 min-h-screen flex flex-col">

    {{-- Header --}}
    <div class="flex items-center justify-between px-10 py-6 border-b border-zinc-800 shrink-0">
        <h1 class="text-2xl font-bold text-zinc-300">{{ $tournament->title }}</h1>
        <p class="brand text-2xl font-mono font-bold">{{ now()->format('H:i') }}</p>
    </div>

    {{-- Message --}}
    <div class="flex-1 flex items-center justify-center px-16 py-12">
        @if($tournament->tv_message)
            <p class="text-center font-bold leading-snug text-white whitespace-pre-wrap"
               style="font-size: clamp(2rem, 5vw, 5rem)">{{ $tournament->tv_message }}</p>
        @else
            <p class="text-zinc-700 text-3xl">Inget meddelande.</p>
        @endif
    </div>

    {{-- Footer --}}
    <div class="flex items-center justify-between text-zinc-700 text-sm px-10 py-4 border-t border-zinc-900 shrink-0">
        <span>{{ config('app.name') }}</span>
        <div class="flex gap-4">
            <a href="{{ route('tournaments.tv.standings', $tournament) }}" class="hover:text-zinc-500">Ställning</a>
            <a href="{{ route('tournaments.tv.draw', $tournament) }}" class="hover:text-zinc-500">Matchschema</a>
            <a href="{{ route('tournaments.tv.timer', $tournament) }}" class="hover:text-zinc-500">Timer</a>
        </div>
    </div>

</body>
</html>
