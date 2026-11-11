<?php

use App\Models\Tournament\Tournament;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Turneringar');

new class extends Component {

    #[\Livewire\Attributes\Computed]
    public function tournaments()
    {
        return Tournament::where('is_public', true)
            ->whereNotIn('status', [Tournament::STATUS_DRAFT, Tournament::STATUS_CANCELLED])
            ->withCount('participants')
            ->latest()
            ->get();
    }
}; ?>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-6">

    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Turneringar</h1>

    @forelse($this->tournaments as $t)
        <a href="{{ route('tournaments.show', $t) }}" wire:navigate
           class="block overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 hover:border-zinc-300 dark:hover:border-zinc-600 transition-colors">
            <div class="p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ $t->title }}</h2>
                        @if($t->description)
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400 line-clamp-2">{{ $t->description }}</p>
                        @endif
                    </div>
                    <span @class([
                        'shrink-0 text-xs px-2.5 py-1 rounded-full font-medium',
                        'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' => $t->status === 'registration',
                        'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' => $t->status === 'ongoing',
                        'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $t->status === 'completed',
                    ])>{{ $t->statusLabel() }}</span>
                </div>
                <div class="mt-3 flex items-center gap-4 text-xs text-zinc-400">
                    <span><x-bootstrap-icon name="people" class="size-3.5 inline mr-1" />{{ $t->participants_count }} deltagare</span>
                    @if($t->event) <span>{{ $t->event->title }}</span> @endif
                    @if($t->section) <span>{{ $t->section->name }}</span> @endif
                </div>
            </div>
        </a>
    @empty
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-5 py-12 text-center text-sm text-zinc-400">
            Inga aktiva turneringar just nu.
        </div>
    @endforelse
</div>
