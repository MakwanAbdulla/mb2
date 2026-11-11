<?php

use App\Models\Gamification\GamificationTask;
use App\Models\Gamification\TaskCompletion;
use App\Models\Membership\Member;
use App\Services\GamificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout, title, uses};

layout('components.layouts.public');
title('Godkännanden');
uses([WithPagination::class]);

new class extends Component {

    public int    $assignTaskId   = 0;
    public string $assignMember   = '';
    public string $assignNote     = '';
    public bool   $assignSent     = false;
    public ?string $assignError   = null;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('gamification.manage'), 403);
    }

    #[\Livewire\Attributes\Computed]
    public function adminTasks()
    {
        return GamificationTask::active()->where('type', GamificationTask::TYPE_ADMIN_ASSIGN)->get(['id', 'title', 'xp_value']);
    }

    #[\Livewire\Attributes\Computed]
    public function memberSuggestions()
    {
        if (strlen($this->assignMember) < 2) {
            return collect();
        }
        return Member::where('status', Member::STATUS_ACTIVE)
            ->where(fn ($q) => $q->where('first_name', 'like', "%{$this->assignMember}%")
                ->orWhere('last_name', 'like', "%{$this->assignMember}%"))
            ->limit(8)
            ->get(['id', 'first_name', 'last_name']);
    }

    public function assign(int $memberId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('gamification.manage'), 403);
        $this->assignError = null;

        if (! $this->assignTaskId) {
            $this->assignError = 'Välj en task.';
            return;
        }

        $task   = GamificationTask::findOrFail($this->assignTaskId);
        $member = Member::findOrFail($memberId);

        app(GamificationService::class)->adminAssignTask($task, $member, Auth::user(), $this->assignNote ?: null);

        $this->assignSent   = true;
        $this->assignMember = '';
        $this->assignNote   = '';
    }

    public function resetAssign(): void
    {
        $this->assignSent = false;
    }

    #[\Livewire\Attributes\Computed]
    public function pending()
    {
        return TaskCompletion::with(['task', 'member', 'givenBy'])
            ->where('status', TaskCompletion::STATUS_PENDING)
            ->latest()
            ->paginate(30);
    }

    public function approve(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('gamification.manage'), 403);
        $completion = TaskCompletion::with('task', 'member')->findOrFail($id);
        app(GamificationService::class)->approveCompletion($completion, Auth::user());
    }

    public function reject(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('gamification.manage'), 403);
        $completion = TaskCompletion::findOrFail($id);
        app(GamificationService::class)->rejectCompletion($completion, Auth::user());
    }
}; ?>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-8">

    {{-- Admin-tilldela direkt --}}
    @if($this->adminTasks->isNotEmpty())
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tilldela task direkt</h2>
        </div>
        <div class="p-5 space-y-4">
            @if($assignSent)
                <div class="flex items-center justify-between rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 px-4 py-3 text-sm text-green-700 dark:text-green-400">
                    <span>XP utdelat!</span>
                    <button wire:click="resetAssign" class="underline text-xs">Tilldela igen</button>
                </div>
            @else
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="lbl">Task</label>
                        <select wire:model="assignTaskId" class="inp mt-1 w-full">
                            <option value="0">Välj task…</option>
                            @foreach($this->adminTasks as $t)
                                <option value="{{ $t->id }}">{{ $t->title }} (+{{ $t->xp_value }} XP)</option>
                            @endforeach
                        </select>
                    </div>
                    <div x-data="{ open: false }">
                        <label class="lbl">Sök medlem</label>
                        <input wire:model.live.debounce.250ms="assignMember" type="text"
                               placeholder="Skriv namn…" @focus="open = true" @click.outside="open = false"
                               class="inp mt-1 w-full" />
                        @if($this->memberSuggestions->isNotEmpty())
                        <div x-show="open" class="absolute z-20 mt-1 w-64 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                            @foreach($this->memberSuggestions as $m)
                                <button wire:click="assign({{ $m->id }})" @click="open = false"
                                        class="w-full px-4 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                    {{ $m->full_name }}
                                </button>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>
                <div>
                    <label class="lbl">Notering <span class="font-normal text-zinc-400">(valfri)</span></label>
                    <input wire:model="assignNote" type="text" maxlength="100" class="inp mt-1 w-full" />
                </div>
                @if($assignError)
                    <p class="text-xs text-red-600 dark:text-red-400">{{ $assignError }}</p>
                @endif
            @endif
        </div>
    </div>
    @endif

    <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Väntande godkännanden</h1>

    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        @forelse($this->pending as $completion)
            <div class="flex items-start gap-4 border-b border-zinc-100 dark:border-zinc-800 last:border-0 px-5 py-4">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $completion->member?->full_name ?? '—' }}</span>
                        <span class="text-xs text-zinc-400">·</span>
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $completion->task?->title ?? '—' }}</span>
                        <span class="text-xs font-semibold text-brand">+{{ $completion->task?->xp_value }} XP</span>
                    </div>
                    @if($completion->givenBy)
                        <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                            Givet av {{ $completion->givenBy->full_name }}
                        </p>
                    @endif
                    @if($completion->note)
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 italic">"{{ $completion->note }}"</p>
                    @endif
                    <p class="mt-0.5 text-xs text-zinc-400">{{ $completion->created_at->format('d/m Y H:i') }}</p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button wire:click="approve({{ $completion->id }})"
                            class="rounded-lg bg-green-50 px-3 py-1.5 text-sm font-medium text-green-700 hover:bg-green-100 border border-green-200 dark:bg-green-900/20 dark:text-green-400 dark:border-green-800 dark:hover:bg-green-900/40">
                        Godkänn
                    </button>
                    <button wire:click="reject({{ $completion->id }})"
                            class="rounded-lg bg-red-50 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-100 border border-red-200 dark:bg-red-900/20 dark:text-red-400 dark:border-red-800 dark:hover:bg-red-900/40">
                        Avvisa
                    </button>
                </div>
            </div>
        @empty
            <div class="px-5 py-12 text-center text-sm text-zinc-400 dark:text-zinc-500">
                <x-bootstrap-icon name="check-circle" class="size-8 mx-auto mb-2 text-green-400" />
                Inga väntande godkännanden.
            </div>
        @endforelse
    </div>

    @if($this->pending->hasPages())
        <div>{{ $this->pending->links() }}</div>
    @endif
</div>
