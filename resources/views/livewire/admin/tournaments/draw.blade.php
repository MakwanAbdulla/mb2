<?php

use App\Models\Tournament\Tournament;
use App\Models\Tournament\TournamentPhase;
use App\Services\TournamentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.scanner');
title('Lottdragning');

new class extends Component {

    public Tournament      $tournament;
    public TournamentPhase $phase;

    // Draw data: [['id', 'name', 'seed', 'is_seeded'], ...]
    // For pods:    ['layout' => 'pods',    'groups' => [[slot, ...], ...], 'group_label' => 'Bord']
    // For bracket: ['layout' => 'bracket', 'groups' => [[slot, slot], ...], 'group_label' => 'Match']
    // For groups:  ['layout' => 'groups',  'groups' => [['label' => 'Grupp A', 'members' => [...]], ...]]
    public array $draw = [];

    // Flat reveal queue: only unseeded participants in the order they'll be revealed
    public array $revealQueue = [];
    public int   $revealedCount = 0;

    // Auto mode
    public bool   $autoMode    = false;
    public int    $autoSeconds = 3;
    public bool   $done        = false;

    public function mount(Tournament $tournament, TournamentPhase $phase): void
    {
        abort_unless(Auth::check() && $tournament->canBeManagedBy(Auth::user()), 403);
        abort_unless($phase->tournament_id === $tournament->id, 404);
        abort_unless($phase->status === TournamentPhase::STATUS_PENDING, 403);

        $this->tournament = $tournament;
        $this->phase      = $phase;

        $this->draw = app(TournamentService::class)->prepareDraw($phase);

        // Build reveal queue: unseeded slots in draw order
        $this->revealQueue = $this->buildRevealQueue();
    }

    private function buildRevealQueue(): array
    {
        $queue = [];
        $layout = $this->draw['layout'] ?? 'pods';

        foreach ($this->draw['groups'] as $groupIdx => $group) {
            $members = $layout === 'groups' ? ($group['members'] ?? []) : $group;
            foreach ($members as $slotIdx => $slot) {
                if ($slot && ! $slot['is_seeded']) {
                    $queue[] = ['group' => $groupIdx, 'slot' => $slotIdx];
                }
            }
        }

        return $queue;
    }

    public function revealNext(): void
    {
        if ($this->revealedCount >= count($this->revealQueue)) {
            return;
        }
        $this->revealedCount++;

        if ($this->revealedCount >= count($this->revealQueue)) {
            $this->commitDraw();
        }
    }

    private function commitDraw(): void
    {
        $this->done = true;
        app(TournamentService::class)->commitDraw($this->phase, $this->draw);
    }

    #[\Livewire\Attributes\Computed]
    public function revealedSlots(): array
    {
        // Returns a set of ['group:slot'] keys that are currently visible
        $revealed = [];
        for ($i = 0; $i < $this->revealedCount; $i++) {
            $pos = $this->revealQueue[$i];
            $revealed["{$pos['group']}:{$pos['slot']}"] = true;
        }
        return $revealed;
    }

    #[\Livewire\Attributes\Computed]
    public function totalToReveal(): int
    {
        return count($this->revealQueue);
    }

    #[\Livewire\Attributes\Computed]
    public function nextToReveal(): ?array
    {
        if ($this->revealedCount >= count($this->revealQueue)) {
            return null;
        }
        $pos = $this->revealQueue[$this->revealedCount];
        $layout = $this->draw['layout'] ?? 'pods';
        $group  = $this->draw['groups'][$pos['group']];
        $members = $layout === 'groups' ? ($group['members'] ?? []) : $group;
        return $members[$pos['slot']] ?? null;
    }
}; ?>

<div class="min-h-screen bg-zinc-950 text-white flex flex-col"
     x-data="{
         autoMode: $wire.entangle('autoMode'),
         autoSeconds: $wire.entangle('autoSeconds'),
         done: $wire.entangle('done'),
         timer: null,
         startAuto() {
             this.timer = setInterval(() => {
                 if (this.done) { clearInterval(this.timer); return; }
                 $wire.revealNext();
             }, this.autoSeconds * 1000);
         },
         stopAuto() {
             clearInterval(this.timer);
             this.timer = null;
             this.autoMode = false;
         },
         toggleAuto() {
             this.autoMode = !this.autoMode;
             if (this.autoMode) this.startAuto();
             else this.stopAuto();
         }
     }">

    {{-- Header --}}
    <div class="flex items-center justify-between px-8 py-5 border-b border-zinc-800">
        <div>
            <p class="text-xs uppercase tracking-widest text-zinc-500 mb-0.5">Turnering</p>
            <h1 class="text-lg font-bold text-white">{{ $tournament->title }}</h1>
        </div>
        <div class="text-center">
            <p class="text-xs uppercase tracking-widest text-zinc-500 mb-0.5">Fas</p>
            <p class="font-semibold text-zinc-200">{{ $phase->name }} · {{ $phase->formatLabel() }}</p>
        </div>
        <div class="text-right">
            <p class="text-xs uppercase tracking-widest text-zinc-500 mb-0.5">Lottade</p>
            <p class="font-bold text-2xl tabular-nums text-white">
                {{ $revealedCount }} <span class="text-zinc-500 font-normal text-lg">/ {{ $this->totalToReveal }}</span>
            </p>
        </div>
    </div>

    {{-- Main draw area --}}
    <div class="flex-1 px-6 py-6 overflow-y-auto">

        @php
            $layout  = $draw['layout'] ?? 'pods';
            $groups  = $draw['groups'] ?? [];
            $groupLabel = $draw['group_label'] ?? 'Grupp';
            $revealed = $this->revealedSlots;
        @endphp

        <div @class([
            'grid gap-4',
            'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4' => count($groups) >= 4,
            'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3' => count($groups) < 4,
        ])>
            @foreach($groups as $gIdx => $group)
                @php
                    $members = $layout === 'groups' ? ($group['members'] ?? []) : $group;
                    $label   = $layout === 'groups' ? ($group['label'] ?? '') : ($groupLabel . ' ' . ($gIdx + 1));
                @endphp

                <div class="rounded-xl border border-zinc-800 bg-zinc-900 overflow-hidden">
                    <div class="border-b border-zinc-800 bg-zinc-800/60 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-zinc-400 text-center">
                        {{ $label }}
                    </div>
                    <div class="divide-y divide-zinc-800">
                        @foreach($members as $sIdx => $slot)
                            @php
                                $key        = "{$gIdx}:{$sIdx}";
                                $isSeeded   = $slot['is_seeded'] ?? false;
                                $isRevealed = $isSeeded || isset($revealed[$key]);
                            @endphp

                            {{-- wire:key changes when revealed → Livewire recreates element → x-init fires animation --}}
                            <div wire:key="slot-{{ $key }}-{{ $isRevealed ? '1' : '0' }}"
                                 class="px-4 py-3 min-h-14 flex items-center justify-between gap-2 relative overflow-hidden">

                                @if(! $isRevealed)
                                    {{-- Unrevealed: dark card with ? --}}
                                    <div class="absolute inset-0 flex items-center justify-center bg-zinc-800">
                                        <span class="text-3xl font-bold text-zinc-600">?</span>
                                    </div>
                                @else
                                    {{-- Revealed: animate in via x-init --}}
                                    <div class="flex items-center gap-2 w-full"
                                         @if(! $isSeeded)
                                         x-data
                                         x-init="
                                             $el.style.opacity = '0';
                                             $el.style.transform = 'translateY(8px) scale(0.95)';
                                             requestAnimationFrame(() => {
                                                 $el.style.transition = 'opacity 0.45s ease, transform 0.45s ease';
                                                 $el.style.opacity = '1';
                                                 $el.style.transform = 'translateY(0) scale(1)';
                                             });
                                         "
                                         @endif>

                                        @if($slot)
                                            @if($isSeeded)
                                                <span class="shrink-0 size-6 rounded-full bg-yellow-500/20 border border-yellow-500/40 flex items-center justify-center text-xs font-bold text-yellow-400 tabular-nums">
                                                    {{ $slot['seed'] }}
                                                </span>
                                            @else
                                                <span class="shrink-0 size-6 rounded-full bg-zinc-700 flex items-center justify-center text-xs text-zinc-400">
                                                    {{ $sIdx + 1 }}
                                                </span>
                                            @endif
                                            <span @class([
                                                'text-sm font-medium truncate',
                                                'text-yellow-300' => $isSeeded,
                                                'text-white'      => ! $isSeeded,
                                            ])>{{ $slot['name'] }}</span>
                                        @else
                                            <span class="text-xs text-zinc-600 italic">Bye</span>
                                        @endif
                                    </div>
                                @endif

                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

    </div>

    {{-- Footer controls --}}
    <div class="sticky bottom-0 border-t border-zinc-800 bg-zinc-950/95 backdrop-blur px-6 py-4">

        @if($done)
            {{-- Done --}}
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-2 text-green-400">
                    <x-bootstrap-icon name="check-circle-fill" class="size-5" />
                    <span class="font-semibold">Lottningen klar! Fasen är startad.</span>
                </div>
                <a href="{{ route('admin.tournaments.manage', $tournament) }}" wire:navigate
                   class="rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-zinc-900 hover:bg-zinc-100">
                    Till turneringen →
                </a>
            </div>

        @elseif($this->totalToReveal === 0)
            {{-- All seeded, nothing to reveal --}}
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-zinc-400">Alla deltagare är seedade — ingen lottning behövs.</span>
                <button wire:click="revealNext" class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                    Bekräfta &amp; starta
                </button>
            </div>

        @else
            <div class="flex items-center gap-4 flex-wrap">

                {{-- Next to reveal preview --}}
                @if($this->nextToReveal)
                    <div class="flex-1 min-w-0">
                        <p class="text-xs text-zinc-500 mb-0.5">Nästa</p>
                        <p class="text-sm text-zinc-300 truncate">
                            <span class="text-zinc-600">···</span>
                        </p>
                    </div>
                @endif

                {{-- Auto controls --}}
                <div class="flex items-center gap-2 shrink-0" x-data>
                    <div class="flex items-center gap-1.5 rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-1.5">
                        <span class="text-xs text-zinc-500">Auto</span>
                        <input type="number" min="1" max="30"
                               wire:model.lazy="autoSeconds"
                               class="w-12 bg-transparent text-sm text-zinc-200 text-center focus:outline-none tabular-nums"
                               x-bind:disabled="autoMode" />
                        <span class="text-xs text-zinc-500">s</span>
                    </div>

                    <button @click="toggleAuto()"
                            :class="autoMode
                                ? 'bg-amber-500 hover:bg-amber-400 text-zinc-900'
                                : 'bg-zinc-800 hover:bg-zinc-700 text-zinc-200'"
                            class="rounded-lg px-4 py-2 text-sm font-medium transition-colors">
                        <span x-show="!autoMode">▶ Auto</span>
                        <span x-show="autoMode">⏸ Pausa</span>
                    </button>
                </div>

                {{-- Manual reveal --}}
                <button wire:click="revealNext"
                        x-bind:disabled="autoMode"
                        :class="autoMode ? 'opacity-30 cursor-not-allowed' : 'hover:bg-brand-saffron cursor-pointer'"
                        class="rounded-lg bg-brand px-6 py-2.5 text-sm font-bold text-brand-dark transition-colors">
                    Dra nästa
                </button>
            </div>
        @endif
    </div>

</div>
