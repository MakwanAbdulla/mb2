@props(['section'])

@php
    $meetings = $section->meetings()
        ->whereNotIn('status', ['draft', 'cancelled'])
        ->orderBy('scheduled_at', 'desc')
        ->limit(10)
        ->get();
@endphp

@if ($meetings->isNotEmpty())
    <div class="space-y-3">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Möten</h3>
        @foreach ($meetings as $m)
            <x-meeting-card :meeting="$m" />
        @endforeach
    </div>
@endif
