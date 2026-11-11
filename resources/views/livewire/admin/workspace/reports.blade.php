<?php

use App\Services\GoogleWorkspaceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Google Workspace – Rapporter');

new class extends Component {

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('workspace.manage'), 403);
    }

    #[Computed]
    public function usageReport(): ?array
    {
        return app(GoogleWorkspaceService::class)->getUserUsageReport();
    }

    public function refresh(): void
    {
        unset($this->usageReport);
    }
}; ?>

<div>
    <x-workspace-nav />

    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 space-y-6">

        @php
            $report     = $this->usageReport;
            $totalGmail = $report ? array_sum(array_column($report, 'storage_gmail_mb')) : 0;
            $totalDrive = $report ? array_sum(array_column($report, 'storage_drive_mb')) : 0;

            $fmt = function (int $mb): string {
                if ($mb >= 1024) return number_format($mb / 1024, 1) . ' GB';
                if ($mb > 0)     return $mb . ' MB';
                return '< 1 MB';
            };

            // Google returns 1970-01-01 for accounts that have never logged in
            $loginDate = function ($row): ?string {
                if (! $row['last_login']) return null;
                if ($row['last_login']->year < 2000) return null;
                return $row['last_login']->isoFormat('D MMM YYYY');
            };
            $loginAge = function ($row): ?string {
                if (! $row['last_login'] || $row['last_login']->year < 2000) return null;
                return $row['last_login']->diffForHumans();
            };
            $loginDays = function ($row): ?int {
                if (! $row['last_login'] || $row['last_login']->year < 2000) return null;
                return (int) $row['last_login']->diffInDays(now());
            };
        @endphp

        @if($report === null)
            <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                Kunde inte hämta rapportdata. Kontrollera att scopet
                <code class="font-mono">https://www.googleapis.com/auth/admin.reports.usage.readonly</code>
                är aktiverat i Google Admin Console under Domain-wide Delegation.
            </div>
        @else

            {{-- Sammanfattning --}}
            <div class="grid grid-cols-3 gap-4">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Konton</p>
                    <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ count($report) }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Gmail-lagring totalt</p>
                    <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $fmt($totalGmail) }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Drive-lagring totalt</p>
                    <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ $fmt($totalDrive) }}</p>
                </div>
                @php $noLogin = collect($report)->filter(fn($r) => $loginDays($r) === null || $loginDays($r) > 180)->count(); @endphp
                @if($noLogin > 0)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20">
                    <p class="text-xs text-amber-700 dark:text-amber-400">Inaktiva / aldrig inloggade</p>
                    <p class="mt-1 text-2xl font-bold text-amber-900 dark:text-amber-200">{{ $noLogin }}</p>
                </div>
                @endif
            </div>

            {{-- Tabell --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50 px-4 py-2.5 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        Data är typiskt 2–3 dagar gammal.
                    </p>
                    <button wire:click="refresh"
                            wire:loading.attr="disabled"
                            class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 disabled:opacity-50">
                        <span wire:loading.remove wire:target="refresh">↻ Uppdatera</span>
                        <span wire:loading wire:target="refresh">Hämtar…</span>
                    </button>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400">Konto</th>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400">Senaste inloggning</th>
                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-zinc-500 dark:text-zinc-400">Gmail</th>
                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-zinc-500 dark:text-zinc-400">Drive</th>
                            <th class="px-4 py-2.5 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400">2FA</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach(collect($report)->sortBy('last_name') as $row)
                            @php
                                $days       = $loginDays($row);
                                $loginColor = match(true) {
                                    $days === null => 'text-zinc-400',
                                    $days > 180    => 'text-red-500 dark:text-red-400',
                                    $days > 60     => 'text-amber-500 dark:text-amber-400',
                                    default        => 'text-zinc-600 dark:text-zinc-300',
                                };
                            @endphp
                            <tr class="bg-white dark:bg-zinc-900">
                                <td class="px-4 py-2.5">
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ $row['first_name'] }} {{ $row['last_name'] }}
                                    </div>
                                    <div class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $row['email'] }}</div>
                                </td>
                                <td class="px-4 py-2.5">
                                    @if($loginDate($row))
                                        <span class="text-xs {{ $loginColor }}">
                                            {{ $loginDate($row) }}
                                            <span class="text-zinc-400 dark:text-zinc-500">({{ $loginAge($row) }})</span>
                                        </span>
                                    @else
                                        <span class="text-xs text-zinc-400">Aldrig</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <span class="text-xs text-zinc-600 dark:text-zinc-300">{{ $fmt($row['storage_gmail_mb']) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <span class="text-xs text-zinc-600 dark:text-zinc-300">{{ $fmt($row['storage_drive_mb']) }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-center">
                                    @if($row['is_2sv'])
                                        <x-bootstrap-icon name="shield-fill-check" class="size-4 text-green-500 dark:text-green-400 mx-auto" />
                                    @else
                                        <x-bootstrap-icon name="shield-exclamation" class="size-4 text-zinc-300 dark:text-zinc-600 mx-auto" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @endif

    </div>
</div>
