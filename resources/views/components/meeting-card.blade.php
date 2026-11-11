@props(['meeting'])

<a href="{{ route('meetings.show', $meeting) }}" wire:navigate
   class="block rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm px-5 py-4 hover:border-brand/40 hover:shadow-md transition-all">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $meeting->title }}</span>
                @if ($meeting->published_at)
                    <span class="rounded-full bg-green-100 dark:bg-green-900 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-300">
                        Justerat
                    </span>
                @endif
            </div>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                {{ $meeting->scheduled_at->isoFormat('D MMMM YYYY, HH:mm') }}
                @if ($meeting->location) · {{ $meeting->location }} @endif
                @if ($meeting->section) · {{ $meeting->section->name }} @endif
            </p>
        </div>
        <x-bootstrap-icon name="chevron-right" class="size-5 shrink-0 text-zinc-400 mt-0.5" />
    </div>
</a>
