<?php

use App\Models\Gamification\GamificationTask;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Task');

new class extends Component {

    public ?int    $taskId      = null;
    public string  $title       = '';
    public string  $description = '';
    public int     $xpValue     = 25;
    public string  $type        = GamificationTask::TYPE_ADMIN_ASSIGN;
    public bool    $requiresApproval          = true;
    public int     $dailyCompletionsPerMember = 1;
    public int     $dailyGivesPerMember       = 3;
    public bool    $isActive    = true;
    public string  $expiresAt   = '';

    public function mount(?GamificationTask $task = null): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('gamification.manage'), 403);

        if ($task && $task->exists) {
            $this->taskId                   = $task->id;
            $this->title                    = $task->title;
            $this->description              = $task->description ?? '';
            $this->xpValue                  = $task->xp_value;
            $this->type                     = $task->type;
            $this->requiresApproval         = $task->requires_approval;
            $this->dailyCompletionsPerMember = $task->daily_completions_per_member;
            $this->dailyGivesPerMember      = $task->daily_gives_per_member;
            $this->isActive                 = $task->is_active;
            $this->expiresAt                = $task->expires_at?->format('Y-m-d') ?? '';
        }
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('gamification.manage'), 403);

        $this->validate([
            'title'                    => 'required|string|max:120',
            'description'              => 'nullable|string|max:500',
            'xpValue'                  => 'required|integer|min:1|max:1000',
            'type'                     => 'required|in:admin_assign,claimable,peer',
            'dailyCompletionsPerMember' => 'required|integer|min:1|max:99',
            'dailyGivesPerMember'      => 'required|integer|min:1|max:99',
            'expiresAt'                => 'nullable|date|after:today',
        ]);

        // Claimable tasks always require approval
        if ($this->type === GamificationTask::TYPE_CLAIMABLE) {
            $this->requiresApproval = true;
        }

        $data = [
            'title'                       => $this->title,
            'description'                 => $this->description ?: null,
            'xp_value'                    => $this->xpValue,
            'type'                        => $this->type,
            'requires_approval'           => $this->requiresApproval,
            'daily_completions_per_member' => $this->dailyCompletionsPerMember,
            'daily_gives_per_member'      => $this->dailyGivesPerMember,
            'is_active'                   => $this->isActive,
            'expires_at'                  => $this->expiresAt ?: null,
        ];

        if ($this->taskId) {
            GamificationTask::findOrFail($this->taskId)->update($data);
        } else {
            GamificationTask::create($data + ['created_by_user_id' => Auth::id()]);
        }

        $this->redirect(route('admin.gamification.tasks.index'), navigate: true);
    }
}; ?>

<div class="mx-auto max-w-xl px-4 py-8 sm:px-6">

    <h1 class="text-xl font-bold text-zinc-900 dark:text-white mb-6">
        {{ $taskId ? 'Redigera task' : 'Ny task' }}
    </h1>

    <form wire:submit="save" class="space-y-5">
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">

            <div class="p-5 space-y-4">
                <div>
                    <label class="lbl">Titel <span class="text-red-500">*</span></label>
                    <input wire:model="title" type="text" maxlength="120" autofocus
                           class="inp mt-1 w-full @error('title') border-red-300 @enderror" />
                    @error('title') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="lbl">Beskrivning</label>
                    <textarea wire:model="description" rows="2" maxlength="500"
                              class="inp mt-1 w-full resize-none @error('description') border-red-300 @enderror"></textarea>
                    @error('description') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="lbl">XP-värde <span class="text-red-500">*</span></label>
                        <input wire:model="xpValue" type="number" min="1" max="1000"
                               class="inp mt-1 w-24 @error('xpValue') border-red-300 @enderror" />
                        @error('xpValue') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">Utgångsdatum</label>
                        <input wire:model="expiresAt" type="date"
                               class="inp mt-1 @error('expiresAt') border-red-300 @enderror" />
                        @error('expiresAt') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="p-5 space-y-4">
                <div>
                    <label class="lbl">Typ <span class="text-red-500">*</span></label>
                    <div class="mt-2 space-y-2">
                        @foreach([
                            GamificationTask::TYPE_ADMIN_ASSIGN => ['Admin tilldelar', 'Bara admin kan ge ut denna task till en specifik medlem.'],
                            GamificationTask::TYPE_CLAIMABLE    => ['Självrapporterad', 'Medlem anmäler att de utfört tasken — kräver alltid admin-godkännande.'],
                            GamificationTask::TYPE_PEER         => ['Ge till annan', 'En medlem kan ge denna task till en annan medlem.'],
                        ] as $val => [$label, $desc])
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="radio" wire:model.live="type" value="{{ $val }}"
                                       class="mt-0.5 accent-complement" />
                                <span>
                                    <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $label }}</span>
                                    <span class="block text-xs text-zinc-400 dark:text-zinc-500">{{ $desc }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                @if($type === 'peer')
                    <div class="flex items-center gap-2 mt-1">
                        <input type="checkbox" wire:model="requiresApproval" id="req_approval"
                               class="size-4 rounded accent-complement" />
                        <label for="req_approval" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            Kräver admin-godkännande
                        </label>
                    </div>
                @endif
            </div>

            <div class="p-5 grid grid-cols-2 gap-4">
                <div>
                    <label class="lbl">Max mottagningar per dag <span class="text-zinc-400 font-normal">(per medlem)</span></label>
                    <input wire:model="dailyCompletionsPerMember" type="number" min="1" max="99"
                           class="inp mt-1 w-20 @error('dailyCompletionsPerMember') border-red-300 @enderror" />
                    <p class="mt-1 text-xs text-zinc-400">Hur många gånger per dag en och samma person kan få XP för denna task.</p>
                    @error('dailyCompletionsPerMember') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                @if($type === 'peer')
                    <div>
                        <label class="lbl">Max utdelningar per dag <span class="text-zinc-400 font-normal">(per givare)</span></label>
                        <input wire:model="dailyGivesPerMember" type="number" min="1" max="99"
                               class="inp mt-1 w-20 @error('dailyGivesPerMember') border-red-300 @enderror" />
                        <p class="mt-1 text-xs text-zinc-400">Hur många gånger per dag en givare kan dela ut denna task.</p>
                        @error('dailyGivesPerMember') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-3 px-5 py-4">
                <input type="checkbox" wire:model="isActive" id="is_active"
                       class="size-4 rounded accent-complement" />
                <label for="is_active" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">Aktiv</label>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.gamification.tasks.index') }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">Avbryt</a>
            <button type="submit" class="btn btn-primary">Spara</button>
        </div>
    </form>
</div>
