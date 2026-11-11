<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 space-y-6">

    {{-- Header --}}
    <div>
        <a href="{{ route('submissions.index') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Mina ärenden</a>
        <div class="mt-1 flex items-start gap-3">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white flex-1">{{ $submission->title }}</h1>
            <span class="mt-1 shrink-0 rounded-full bg-zinc-100 dark:bg-zinc-800 px-2.5 py-1 text-xs font-medium text-zinc-600 dark:text-zinc-300">
                {{ $submission->typeLabel() }}
            </span>
        </div>
    </div>

    {{-- Ärendebeskrivning --}}
    @if ($submission->body)
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Ärendebeskrivning</p>
            <p class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $submission->body }}</p>
        </div>
    @endif

    {{-- Omröstning --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm overflow-hidden">
        <div class="px-4 py-3">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Per capsulam / omröstning</p>
        </div>
        <livewire:shared.poll-panel
            :voteable-type="'submission'"
            :voteable-id="$submission->id"
            :key="'poll-sub-'.$submission->id" />
    </div>

</div>
