<?php

use App\Models\Gamification\GamificationTask;
use App\Models\Gamification\TaskCompletion;
use App\Services\GamificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Tasks');

new class extends Component {

    public array $notes      = [];
    public array $claimed    = [];
    public array $errors_map = [];

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
    }

    #[\Livewire\Attributes\Computed]
    public function claimableTasks()
    {
        return GamificationTask::active()
            ->where('type', GamificationTask::TYPE_CLAIMABLE)
            ->get();
    }

    #[\Livewire\Attributes\Computed]
    public function peerTasks()
    {
        return GamificationTask::active()
            ->where('type', GamificationTask::TYPE_PEER)
            ->get();
    }

    public function claim(int $taskId): void
    {
        $member = Auth::user()?->member;
        if (! $member) return;

        $task = GamificationTask::findOrFail($taskId);
        unset($this->errors_map[$taskId]);

        try {
            app(GamificationService::class)->memberClaimTask($task, $member, $this->notes[$taskId] ?? null);
            $this->claimed[$taskId] = true;
            unset($this->notes[$taskId]);
        } catch (\RuntimeException $e) {
            $this->errors_map[$taskId] = $e->getMessage();
        }
    }
}; ?>

<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 space-y-8">

    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Tasks</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Rapportera aktiviteter och få XP. Alla kräver admin-godkännande.</p>
    </div>

    @if($this->claimableTasks->isEmpty() && $this->peerTasks->isEmpty())
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-5 py-12 text-center text-sm text-zinc-400">
            Inga tasks tillgängliga just nu.
        </div>
    @endif

    {{-- Claimable tasks --}}
    @if($this->claimableTasks->isNotEmpty())
        <div>
            <h2 class="text-sm font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500 mb-3">Jag har gjort detta</h2>
            <div class="space-y-3">
                @foreach($this->claimableTasks as $task)
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                        <div class="flex items-start justify-between gap-4 px-5 py-4">
                            <div>
                                <p class="font-medium text-zinc-900 dark:text-white">{{ $task->title }}</p>
                                @if($task->description)
                                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $task->description }}</p>
                                @endif
                                @if($task->expires_at)
                                    <p class="mt-1 text-xs text-amber-500">Utgår {{ $task->expires_at->format('d/m Y') }}</p>
                                @endif
                            </div>
                            <span class="shrink-0 text-sm font-bold text-brand">+{{ $task->xp_value }} XP</span>
                        </div>

                        @if(isset($this->claimed[$task->id]))
                            <div class="border-t border-zinc-100 dark:border-zinc-800 px-5 py-3 text-sm text-green-600 dark:text-green-400 flex items-center gap-1.5">
                                <x-bootstrap-icon name="check-circle-fill" class="size-4" />
                                Anmält! Väntar på godkännande.
                            </div>
                        @else
                            <div class="border-t border-zinc-100 dark:border-zinc-800 px-5 py-3 flex items-start gap-2">
                                <input wire:model="notes.{{ $task->id }}" type="text" maxlength="100"
                                       placeholder="Valfri kommentar…"
                                       class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                                <button wire:click="claim({{ $task->id }})"
                                        class="shrink-0 rounded-lg bg-brand px-3 py-1.5 text-sm font-medium text-brand-dark hover:bg-brand-saffron">
                                    Anmäl
                                </button>
                            </div>
                            @if(isset($this->errors_map[$task->id]))
                                <p class="px-5 pb-3 text-xs text-red-600 dark:text-red-400">{{ $this->errors_map[$task->id] }}</p>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Peer tasks info --}}
    @if($this->peerTasks->isNotEmpty())
        <div>
            <h2 class="text-sm font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500 mb-3">Ge till annan medlem</h2>
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($this->peerTasks as $task)
                    <div class="flex items-center justify-between px-5 py-3.5">
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">{{ $task->title }}</p>
                            @if($task->description)
                                <p class="text-xs text-zinc-400 mt-0.5">{{ $task->description }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <span class="text-sm font-bold text-brand">+{{ $task->xp_value }} XP</span>
                            <span class="text-xs text-zinc-400">Max {{ $task->daily_gives_per_member }}/dag</span>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-2">
                Peer-tasks ges via en members profilsida (kommer snart).
            </p>
        </div>
    @endif

</div>
