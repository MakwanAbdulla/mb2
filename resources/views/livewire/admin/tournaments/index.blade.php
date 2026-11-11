<?php

use App\Models\Tournament\Tournament;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Turneringar');

new class extends Component {

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->canAny(['tournaments.manage', 'tournaments.view']), 403);
    }

    #[\Livewire\Attributes\Computed]
    public function tournaments()
    {
        return Tournament::withCount('participants')
            ->latest()
            ->get();
    }
}; ?>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Turneringar</h1>
        @can('tournaments.manage')
        <a href="{{ route('admin.tournaments.create') }}" wire:navigate class="btn btn-primary">
            <x-bootstrap-icon name="plus-lg" class="size-4" />
            Ny turnering
        </a>
        @endcan
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        @forelse($this->tournaments as $t)
            <div class="flex items-center gap-4 border-b border-zinc-100 dark:border-zinc-800 last:border-0 px-5 py-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <a href="{{ route('admin.tournaments.manage', $t) }}" wire:navigate
                           class="font-medium text-zinc-900 dark:text-white hover:underline">{{ $t->title }}</a>
                        <span @class([
                            'text-xs px-2 py-0.5 rounded-full font-medium',
                            'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => $t->status === 'draft',
                            'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' => $t->status === 'registration',
                            'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' => $t->status === 'ongoing',
                            'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $t->status === 'completed',
                            'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400' => $t->status === 'cancelled',
                        ])>{{ $t->statusLabel() }}</span>
                    </div>
                    <div class="flex items-center gap-3 mt-1 text-xs text-zinc-400">
                        <span>{{ $t->participants_count }} deltagare</span>
                        @if($t->event) <span>· {{ $t->event->title }}</span> @endif
                        @if($t->section) <span>· {{ $t->section->name }}</span> @endif
                        <span>· {{ $t->created_at->format('d/m Y') }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    @can('tournaments.manage')
                    <a href="{{ route('admin.tournaments.edit', $t) }}" wire:navigate
                       class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 underline underline-offset-2">Redigera</a>
                    @endcan
                    <a href="{{ route('admin.tournaments.manage', $t) }}" wire:navigate
                       class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 underline underline-offset-2">Hantera</a>
                </div>
            </div>
        @empty
            <div class="px-5 py-12 text-center text-sm text-zinc-400">Inga turneringar skapade ännu.</div>
        @endforelse
    </div>
</div>
