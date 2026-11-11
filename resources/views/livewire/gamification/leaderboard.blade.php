<?php

use App\Services\GamificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Topplistan');

new class extends Component {

    public int $year;
    public int $month;

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
        $this->year  = now()->year;
        $this->month = now()->month;
    }

    #[\Livewire\Attributes\Computed]
    public function leaderboard(): \Illuminate\Support\Collection
    {
        return app(GamificationService::class)->leaderboard($this->year, $this->month, 20);
    }

    #[\Livewire\Attributes\Computed]
    public function myEntry(): ?array
    {
        $member = Auth::user()?->member;
        if (! $member) {
            return null;
        }

        $position = \App\Models\Gamification\XpLog::selectRaw('member_id, SUM(points) as monthly_xp')
            ->whereYear('created_at', $this->year)
            ->whereMonth('created_at', $this->month)
            ->groupBy('member_id')
            ->orderByDesc('monthly_xp')
            ->get()
            ->search(fn ($row) => $row->member_id === $member->id);

        $myXp = app(GamificationService::class)->monthlyXp($member, $this->year, $this->month);

        return [
            'position'   => $position !== false ? $position + 1 : null,
            'monthly_xp' => $myXp,
            'level'      => app(GamificationService::class)->level(app(GamificationService::class)->totalXp($member)),
            'member_id'  => $member->id,
        ];
    }

    #[\Livewire\Attributes\Computed]
    public function months(): array
    {
        $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Maj', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];
        $result = [];
        for ($m = 1; $m <= 12; $m++) {
            $result[$m] = $monthNames[$m - 1];
        }
        return $result;
    }
}; ?>

<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Topplistan</h1>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Månadens mest aktiva medlemmar</p>
        </div>

        {{-- Month/year picker --}}
        <div class="flex items-center gap-2">
            <select wire:model.live="year"
                    class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                @for ($y = now()->year; $y >= now()->year - 3; $y--)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endfor
            </select>
            <select wire:model.live="month"
                    class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                @foreach($this->months as $num => $name)
                    <option value="{{ $num }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- My position (if outside top 20) --}}
    @if($this->myEntry && ($this->myEntry['position'] === null || $this->myEntry['position'] > 20))
        <div class="rounded-xl border-2 border-brand/40 bg-brand/5 dark:border-brand/30 dark:bg-brand/10 px-5 py-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">Din placering</span>
                    <span class="text-lg font-bold text-zinc-900 dark:text-white">
                        {{ $this->myEntry['position'] ? '#' . $this->myEntry['position'] : 'Ej placerad' }}
                    </span>
                </div>
                <span class="text-sm font-semibold tabular-nums text-zinc-700 dark:text-zinc-300">
                    {{ $this->myEntry['monthly_xp'] }} XP
                </span>
            </div>
        </div>
    @endif

    {{-- Leaderboard --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        @forelse($this->leaderboard as $i => $entry)
            @php $isMe = $this->myEntry && $entry['member_id'] === $this->myEntry['member_id']; @endphp
            <div @class([
                    'flex items-center gap-4 px-5 py-3.5 border-b border-zinc-100 dark:border-zinc-800 last:border-0',
                    'bg-brand/5 dark:bg-brand/10' => $isMe,
                ])>

                {{-- Rank --}}
                <div class="w-8 text-center shrink-0">
                    @if ($i === 0)
                        <span class="text-xl">🥇</span>
                    @elseif ($i === 1)
                        <span class="text-xl">🥈</span>
                    @elseif ($i === 2)
                        <span class="text-xl">🥉</span>
                    @else
                        <span class="text-sm font-semibold tabular-nums text-zinc-400 dark:text-zinc-500">#{{ $i + 1 }}</span>
                    @endif
                </div>

                {{-- Name + level --}}
                <div class="flex-1 min-w-0">
                    <span class="font-medium text-zinc-900 dark:text-white truncate">
                        {{ $entry['name'] }}
                        @if($isMe) <span class="text-xs text-zinc-400">(du)</span> @endif
                    </span>
                    <div class="flex items-center gap-1.5 mt-0.5">
                        <span class="text-xs text-zinc-400 dark:text-zinc-500">
                            Lv.{{ $entry['level']['level'] }} {{ $entry['level']['name'] }}
                        </span>
                    </div>
                </div>

                {{-- XP --}}
                <div class="shrink-0 text-right">
                    <span class="text-sm font-bold tabular-nums text-zinc-900 dark:text-white">{{ $entry['monthly_xp'] }}</span>
                    <span class="text-xs text-zinc-400 ml-0.5">XP</span>
                </div>
            </div>
        @empty
            <div class="px-5 py-12 text-center text-sm text-zinc-400 dark:text-zinc-500">
                Inga poäng registrerade den här månaden ännu.
            </div>
        @endforelse
    </div>

    <div class="flex items-center justify-center gap-4 text-sm">
        <a href="{{ route('gamification.tasks') }}" wire:navigate
           class="text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 flex items-center gap-1.5">
            <x-bootstrap-icon name="check2-square" class="size-4" />
            Se tillgängliga tasks
        </a>
    </div>
    <p class="text-center text-xs text-zinc-400 dark:text-zinc-500">
        Poäng tjänas genom att delta i evenemang, förnya medlemskap, ge kudos och tasks.
    </p>
</div>
