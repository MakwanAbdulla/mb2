<?php

use App\Services\GamificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {

    #[\Livewire\Attributes\Computed]
    public function stats(): ?array
    {
        $member = Auth::user()?->member;
        if (! $member) {
            return null;
        }

        $service  = app(GamificationService::class);
        $totalXp  = $service->totalXp($member);
        $level    = $service->level($totalXp);
        $badges   = $member->badges()->orderByDesc('awarded_at')->get();
        $monthlyXp = $service->monthlyXp($member);

        return compact('totalXp', 'level', 'badges', 'monthlyXp');
    }
}; ?>

@if($this->stats)
@php $s = $this->stats; @endphp
<div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">

    {{-- Level header --}}
    <div class="flex items-center justify-between px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500">Level {{ $s['level']['level'] }}</span>
            </div>
            <p class="text-lg font-bold text-zinc-900 dark:text-white mt-0.5">{{ $s['level']['name'] }}</p>
        </div>
        <div class="text-right">
            <p class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ number_format($s['totalXp']) }}</p>
            <p class="text-xs text-zinc-400 dark:text-zinc-500">totala XP</p>
        </div>
    </div>

    {{-- Progress bar --}}
    <div class="px-5 py-3 border-b border-zinc-100 dark:border-zinc-800">
        @if($s['level']['xp_next'])
            <div class="flex items-center justify-between text-xs text-zinc-400 dark:text-zinc-500 mb-1.5">
                <span>{{ $s['level']['name'] }}</span>
                <span>{{ \App\Services\GamificationService::LEVELS[$s['level']['level'] + 1]['name'] ?? '' }}</span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                <div class="h-full rounded-full bg-brand transition-all duration-500"
                     style="width: {{ $s['level']['progress_pct'] }}%"></div>
            </div>
            <p class="mt-1.5 text-xs text-zinc-400 dark:text-zinc-500 text-right">
                {{ $s['level']['xp_next'] - $s['totalXp'] }} XP kvar till nästa level
            </p>
        @else
            <p class="text-xs text-zinc-400 dark:text-zinc-500 text-center">Maximalt level uppnått! 🎉</p>
        @endif
    </div>

    {{-- This month --}}
    <div class="flex items-center justify-between px-5 py-3 border-b border-zinc-100 dark:border-zinc-800">
        <span class="text-sm text-zinc-500 dark:text-zinc-400">Den här månaden</span>
        <span class="text-sm font-semibold tabular-nums text-zinc-800 dark:text-zinc-200">
            +{{ $s['monthlyXp'] }} XP
        </span>
    </div>

    {{-- Badges --}}
    @if($s['badges']->isNotEmpty())
    <div class="px-5 py-3">
        <p class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-zinc-500 mb-2">Badges</p>
        <div class="flex flex-wrap gap-2">
            @foreach($s['badges'] as $badge)
                <span class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-xs font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                      title="{{ $badge->awarded_at->format('d/m Y') }}">
                    <x-bootstrap-icon name="{{ \App\Services\GamificationService::badgeIcon($badge->badge_key) }}" class="size-3.5 text-zinc-400" />
                    {{ \App\Services\GamificationService::badgeLabel($badge->badge_key) }}
                </span>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Link to leaderboard --}}
    <div class="border-t border-zinc-100 dark:border-zinc-800 px-5 py-2.5">
        <a href="{{ route('gamification.leaderboard') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 flex items-center gap-1.5">
            <x-bootstrap-icon name="bar-chart" class="size-3.5" />
            Se topplistan
        </a>
    </div>
</div>
@endif
