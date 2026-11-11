<?php

use App\Models\Accounting\FiscalYear;
use App\Services\Accounting\AccountingReportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Leverantörsrapport');

new class extends Component {

    public ?int $fiscalYearId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['accounting.view', 'accounting.manage', 'accounting.admin']), 403);

        $fy = FiscalYear::where('is_locked', false)
            ->where('starts_at', '<=', today())
            ->where('ends_at', '>=', today())
            ->first()
            ?? FiscalYear::orderByDesc('starts_at')->first();

        $this->fiscalYearId = $fy?->id;
    }

    #[Computed]
    public function fiscalYears()
    {
        return FiscalYear::orderByDesc('starts_at')->get();
    }

    #[Computed]
    public function report(): ?array
    {
        if (! $this->fiscalYearId) {
            return null;
        }

        return app(AccountingReportService::class)->vendorReport($this->fiscalYearId);
    }
};
?>

<div>

    {{-- Filters --}}
    <div class="mb-5 flex flex-wrap gap-3">
        <div>
            <label class="lbl">Räkenskapsår</label>
            <flux:select wire:model.live="fiscalYearId" class="w-52">
                <option value="">Välj räkenskapsår…</option>
                @foreach ($this->fiscalYears as $fy)
                    <option value="{{ $fy->id }}">{{ $fy->name }}</option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($this->report)
        @php $r = $this->report; @endphp

        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3 w-24">Kod</th>
                        <th class="px-4 py-3">Leverantör</th>
                        <th class="px-4 py-3 w-20 text-right">Antal</th>
                        <th class="px-4 py-3 w-32 text-right">Fakturerat</th>
                        <th class="px-4 py-3 w-32 text-right">Betalt</th>
                        <th class="px-4 py-3 w-32 text-right">Obetalt</th>
                        <th class="px-4 py-3 w-24 text-right">Förfallna</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($r['vendors'] as $v)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-4 py-2.5 font-mono text-xs font-semibold">{{ $v['code'] }}</td>
                            <td class="px-4 py-2.5 text-zinc-700 dark:text-zinc-300">
                                <a href="{{ route('admin.accounting.vendors.show', $v['id']) }}" wire:navigate
                                   class="hover:underline">{{ $v['name'] }}</a>
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">{{ $v['invoice_count'] }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">{{ format_ore($v['total_ore']) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">{{ format_ore($v['paid_ore']) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">{{ format_ore($v['unpaid_ore']) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs {{ $v['overdue_count'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                {{ $v['overdue_count'] ?: '–' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">
                                Inga leverantörsfakturor för valt räkenskapsår.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if (! empty($r['vendors']))
                    <tfoot class="border-t-2 border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 font-semibold text-sm">
                        <tr>
                            <td colspan="2" class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                                Summa ({{ count($r['vendors']) }} leverantörer)
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ $r['totals']['invoice_count'] }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ format_ore($r['totals']['total_ore']) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ format_ore($r['totals']['paid_ore']) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ format_ore($r['totals']['unpaid_ore']) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ $r['totals']['overdue_count'] ?: '–' }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @else
        <div class="py-16 text-center text-sm text-zinc-400">Välj ett räkenskapsår för att visa leverantörsrapport.</div>
    @endif
</div>
