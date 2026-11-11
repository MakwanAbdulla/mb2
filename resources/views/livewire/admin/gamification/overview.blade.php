<?php

use App\Models\Gamification\XpLog;
use App\Models\Membership\Member;
use App\Services\GamificationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout, title, uses};

layout('components.layouts.public');
title('Gamification');
uses([WithPagination::class]);

new class extends Component {

    public string $search = '';
    public int    $year;
    public int    $month;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('gamification.manage'), 403);
        $this->year  = now()->year;
        $this->month = now()->month;
    }

    #[\Livewire\Attributes\Computed]
    public function logs()
    {
        return XpLog::with(['member:id,first_name,last_name', 'giver:id,first_name,last_name'])
            ->when($this->search, function ($q) {
                $q->whereHas('member', fn ($mq) => $mq->where('first_name', 'like', "%{$this->search}%")
                    ->orWhere('last_name', 'like', "%{$this->search}%"));
            })
            ->orderByDesc('created_at')
            ->paginate(50);
    }

    #[\Livewire\Attributes\Computed]
    public function leaderboard(): \Illuminate\Support\Collection
    {
        return app(GamificationService::class)->leaderboard($this->year, $this->month, 10);
    }

    public function sourceLabel(string $type): string
    {
        return match ($type) {
            XpLog::SOURCE_MEMBERSHIP_RENEWAL  => 'Förnyat medlemskap',
            XpLog::SOURCE_EVENT_CHECKIN       => 'Event check-in',
            XpLog::SOURCE_EVENT_REGISTRATION  => 'Eventregistrering',
            XpLog::SOURCE_PROFILE_AVATAR      => 'Profilbild uppladdad',
            XpLog::SOURCE_PROFILE_COMPLETE    => 'Profil komplett',
            XpLog::SOURCE_PEER_KUDOS          => 'Kudos',
            XpLog::SOURCE_BOOKING                   => 'Bokning godkänd',
            XpLog::SOURCE_TOURNAMENT_PLACEMENT      => 'Turneringsplacering',
            default => $type,
        };
    }
}; ?>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 space-y-8">

    {{-- Leaderboard preview --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        <div class="flex items-center justify-between border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                Topplistan — {{ \Carbon\Carbon::createFromDate($year, $month)->translatedFormat('F Y') }}
            </h2>
            <div class="flex gap-2">
                <select wire:model.live="year" class="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                    @for ($y = now()->year; $y >= now()->year - 3; $y--)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endfor
                </select>
                <select wire:model.live="month" class="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                    @foreach(['Jan','Feb','Mar','Apr','Maj','Jun','Jul','Aug','Sep','Okt','Nov','Dec'] as $i => $m)
                        <option value="{{ $i + 1 }}">{{ $m }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse($this->leaderboard as $i => $entry)
                <div class="flex items-center gap-4 px-5 py-3">
                    <span class="w-6 text-sm text-zinc-400 tabular-nums">#{{ $i + 1 }}</span>
                    <span class="flex-1 text-sm font-medium text-zinc-900 dark:text-white">{{ $entry['name'] }}</span>
                    <span class="text-xs text-zinc-400">Lv.{{ $entry['level']['level'] }} {{ $entry['level']['name'] }}</span>
                    <span class="text-sm font-bold tabular-nums text-zinc-700 dark:text-zinc-300">{{ $entry['monthly_xp'] }} XP</span>
                </div>
            @empty
                <p class="px-5 py-6 text-sm text-zinc-400 dark:text-zinc-500 text-center">Inga poäng denna månad.</p>
            @endforelse
        </div>
    </div>

    {{-- XP log --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        <div class="flex items-center justify-between border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-3 gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 shrink-0">XP-logg</h2>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Sök på namn…"
                   class="w-48 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
        </div>
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 dark:border-zinc-800">
                <tr>
                    <th class="px-5 py-2.5 text-left font-medium text-zinc-500 dark:text-zinc-400">Tidpunkt</th>
                    <th class="px-5 py-2.5 text-left font-medium text-zinc-500 dark:text-zinc-400">Medlem</th>
                    <th class="px-5 py-2.5 text-left font-medium text-zinc-500 dark:text-zinc-400">Källa</th>
                    <th class="px-5 py-2.5 text-left font-medium text-zinc-500 dark:text-zinc-400">Från</th>
                    <th class="px-5 py-2.5 text-right font-medium text-zinc-500 dark:text-zinc-400">XP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($this->logs as $log)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-5 py-2.5 text-xs text-zinc-400 tabular-nums whitespace-nowrap">{{ $log->created_at->format('d/m Y H:i') }}</td>
                        <td class="px-5 py-2.5 font-medium text-zinc-900 dark:text-white">{{ $log->member?->full_name ?? '—' }}</td>
                        <td class="px-5 py-2.5 text-zinc-600 dark:text-zinc-400">{{ $this->sourceLabel($log->source_type) }}
                            @if($log->note) <span class="text-zinc-400 text-xs ml-1">· {{ $log->note }}</span> @endif
                        </td>
                        <td class="px-5 py-2.5 text-zinc-500 dark:text-zinc-400 text-xs">{{ $log->giver?->full_name ?? '—' }}</td>
                        <td class="px-5 py-2.5 text-right font-bold tabular-nums text-zinc-900 dark:text-white">+{{ $log->points }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-8 text-center text-sm text-zinc-400">Ingen logg ännu.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-5 py-3 border-t border-zinc-100 dark:border-zinc-800">
            {{ $this->logs->links() }}
        </div>
    </div>

</div>
