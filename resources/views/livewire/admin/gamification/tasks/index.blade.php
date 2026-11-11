<?php

use App\Models\Gamification\GamificationTask;
use App\Models\Gamification\TaskCompletion;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Tasks');

new class extends Component {

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('gamification.manage'), 403);
    }

    #[\Livewire\Attributes\Computed]
    public function tasks()
    {
        return GamificationTask::withCount([
            'completions as approved_count' => fn ($q) => $q->where('status', TaskCompletion::STATUS_APPROVED),
            'completions as pending_count'  => fn ($q) => $q->where('status', TaskCompletion::STATUS_PENDING),
        ])->latest()->get();
    }

    public function toggle(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('gamification.manage'), 403);
        $task = GamificationTask::findOrFail($id);
        $task->update(['is_active' => ! $task->is_active]);
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('gamification.manage'), 403);
        GamificationTask::findOrFail($id)->delete();
    }
}; ?>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Tasks</h1>
        <a href="{{ route('admin.gamification.tasks.create') }}" wire:navigate
           class="btn btn-primary">
            <x-bootstrap-icon name="plus-lg" class="size-4" />
            Ny task
        </a>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                <tr>
                    <th class="px-5 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Task</th>
                    <th class="px-5 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Typ</th>
                    <th class="px-5 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">XP</th>
                    <th class="px-5 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Utdelade</th>
                    <th class="px-5 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Väntar</th>
                    <th class="px-5 py-3 text-center font-semibold text-zinc-600 dark:text-zinc-300">Aktiv</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                @forelse($this->tasks as $task)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-5 py-3">
                            <p class="font-medium text-zinc-900 dark:text-white">{{ $task->title }}</p>
                            @if($task->description)
                                <p class="text-xs text-zinc-400 mt-0.5 truncate max-w-xs">{{ $task->description }}</p>
                            @endif
                            @if($task->expires_at)
                                <p class="text-xs text-amber-500 mt-0.5">Utgår {{ $task->expires_at->format('d/m Y') }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-zinc-600 dark:text-zinc-400">
                            {{ $task->typeLabel() }}
                            @if($task->requires_approval)
                                <span class="ml-1 text-xs text-zinc-400">(kräver godk.)</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-center font-bold tabular-nums text-zinc-900 dark:text-white">{{ $task->xp_value }}</td>
                        <td class="px-5 py-3 text-center tabular-nums text-zinc-600 dark:text-zinc-400">{{ $task->approved_count }}</td>
                        <td class="px-5 py-3 text-center">
                            @if($task->pending_count > 0)
                                <span class="inline-flex size-5 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">{{ $task->pending_count }}</span>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-center">
                            <button wire:click="toggle({{ $task->id }})"
                                    class="text-xs {{ $task->is_active ? 'text-green-600 dark:text-green-400' : 'text-zinc-400 dark:text-zinc-500' }}">
                                <x-bootstrap-icon name="{{ $task->is_active ? 'toggle-on' : 'toggle-off' }}" class="size-5" />
                            </button>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-3 justify-end">
                                <a href="{{ route('admin.gamification.tasks.edit', $task) }}" wire:navigate
                                   class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 underline underline-offset-2">
                                    Redigera
                                </a>
                                <button wire:click="delete({{ $task->id }})"
                                        wire:confirm="Ta bort '{{ $task->title }}'? Historik för utdelade XP berörs inte."
                                        class="text-xs text-red-500 hover:text-red-700 dark:text-red-400">
                                    Ta bort
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-10 text-center text-sm text-zinc-400">Inga tasks skapade ännu.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
