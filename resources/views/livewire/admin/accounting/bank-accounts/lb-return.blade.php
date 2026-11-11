<?php

use App\Services\Accounting\LbReturnParser;
use Illuminate\Support\Facades\Auth;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Betalningsbevakning (LB återredovisning)');

new class extends Component {
    use WithFileUploads;

    public $file = null;

    /** Parsed result from LbReturnParser::parse() */
    public ?array $result = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    public function updatedFile(): void
    {
        $this->result = null;

        if (! $this->file) {
            return;
        }

        $content = file_get_contents($this->file->getRealPath());
        $parser  = new LbReturnParser();
        $this->result = $parser->parse($content);
    }

    public function resetForm(): void
    {
        $this->file   = null;
        $this->result = null;
    }

    /** Format öre integer as "1 234,56" (no currency symbol). */
    private function fmt(int $ore): string
    {
        return number_format(abs($ore) / 100, 2, ',', ' ');
    }
};
?>

<div>

    <div class="space-y-6 max-w-4xl">

        {{-- Upload card --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-5 bg-white dark:bg-zinc-900">
            <h2 class="font-semibold text-sm mb-3">LB återredovisningsfil</h2>
            <div class="flex items-start gap-4">
                <div class="flex-1">
                    <input type="file"
                           wire:model.live="file"
                           accept=".txt"
                           class="block w-full text-sm text-zinc-500
                                  file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0
                                  file:text-sm file:font-medium
                                  file:bg-zinc-100 file:text-zinc-700
                                  dark:file:bg-zinc-700 dark:file:text-zinc-200
                                  hover:file:bg-zinc-200 dark:hover:file:bg-zinc-600" />
                    <p class="mt-1.5 text-xs text-zinc-400">
                        Bankgirot → Betalningsbevakning → LB återredovisning (TXT-format).
                        Används för att bekräfta att leverantörsbetalningar (LB-filen) har genomförts.
                    </p>
                </div>
                @if ($result)
                    <button wire:click="resetForm" class="btn btn-ghost btn-sm shrink-0">
                        Rensa
                    </button>
                @endif
            </div>
        </div>

        @if ($result)

            {{-- Parse errors --}}
            @if (count($result['parse_errors']) > 0)
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800
                            dark:border-red-700/40 dark:bg-red-900/20 dark:text-red-300">
                    <p class="font-medium mb-1">Tolkningsvarningar</p>
                    <ul class="list-disc list-inside space-y-0.5 text-xs">
                        @foreach ($result['parse_errors'] as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- File header info --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-zinc-800">
                <div class="px-5 py-3">
                    <h2 class="font-semibold text-sm">Filinformation</h2>
                </div>
                <div class="px-5 py-4 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div>
                        <p class="text-xs text-zinc-400 mb-0.5">BG-nummer</p>
                        <p class="font-mono font-medium">{{ $result['bg_number'] ?: '–' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-zinc-400 mb-0.5">Typ</p>
                        <p class="font-medium">{{ $result['type_label'] ?: '–' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-zinc-400 mb-0.5">Fildatum</p>
                        <p class="font-mono">{{ $result['file_date'] ?: '–' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-zinc-400 mb-0.5">Betalningsdatum</p>
                        <p class="font-mono">{{ $result['payment_date'] ?: '–' }}</p>
                    </div>
                </div>
            </div>

            {{-- Summary cards --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">

                {{-- Inläst --}}
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
                    <p class="text-xs text-zinc-400 mb-1">Inläst</p>
                    <p class="text-lg font-mono font-semibold text-zinc-800 dark:text-zinc-100">
                        {{ $this->fmt($result['read_invoices']) }}
                    </p>
                    <p class="text-xs text-zinc-400 mt-0.5">
                        @if ($result['read_credits'] !== 0)
                            + {{ $this->fmt($result['read_credits']) }} kr kreditfakturor
                        @else
                            fakturor
                        @endif
                    </p>
                </div>

                {{-- Betalda --}}
                <div class="rounded-xl border border-green-200 dark:border-green-800/40 bg-green-50 dark:bg-green-900/20 p-4">
                    <p class="text-xs text-green-600 dark:text-green-400 mb-1">Betalda</p>
                    <p class="text-lg font-mono font-semibold text-green-700 dark:text-green-300">
                        {{ $this->fmt($result['paid_invoices']) }}
                    </p>
                    <p class="text-xs text-green-600 dark:text-green-500 mt-0.5">fakturor</p>
                </div>

                {{-- Avvisade --}}
                <div class="rounded-xl border {{ $result['rejected_invoices'] !== 0 ? 'border-red-200 dark:border-red-800/40 bg-red-50 dark:bg-red-900/20' : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900' }} p-4">
                    <p class="text-xs {{ $result['rejected_invoices'] !== 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-400' }} mb-1">
                        Avvisade
                    </p>
                    <p class="text-lg font-mono font-semibold {{ $result['rejected_invoices'] !== 0 ? 'text-red-700 dark:text-red-300' : 'text-zinc-500 dark:text-zinc-400' }}">
                        {{ $this->fmt($result['rejected_invoices']) }}
                    </p>
                    <p class="text-xs {{ $result['rejected_invoices'] !== 0 ? 'text-red-600 dark:text-red-500' : 'text-zinc-400' }} mt-0.5">
                        fakturor
                    </p>
                </div>

                {{-- Makulerade --}}
                <div class="rounded-xl border {{ $result['cancelled_invoices'] !== 0 ? 'border-amber-200 dark:border-amber-800/40 bg-amber-50 dark:bg-amber-900/20' : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900' }} p-4">
                    <p class="text-xs {{ $result['cancelled_invoices'] !== 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-400' }} mb-1">
                        Makulerade
                    </p>
                    <p class="text-lg font-mono font-semibold {{ $result['cancelled_invoices'] !== 0 ? 'text-amber-700 dark:text-amber-300' : 'text-zinc-500 dark:text-zinc-400' }}">
                        {{ $this->fmt($result['cancelled_invoices']) }}
                    </p>
                    <p class="text-xs {{ $result['cancelled_invoices'] !== 0 ? 'text-amber-600 dark:text-amber-500' : 'text-zinc-400' }} mt-0.5">
                        fakturor
                    </p>
                </div>
            </div>

            {{-- Credit rows (only show if non-zero) --}}
            @if ($result['settled_credits'] !== 0 || $result['rejected_credits'] !== 0 || $result['cancelled_credits'] !== 0)
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-5 py-4">
                    <h3 class="text-sm font-semibold mb-3">Kreditfakturor</h3>
                    <div class="grid grid-cols-3 gap-4 text-sm">
                        <div>
                            <p class="text-xs text-zinc-400">Avräknade</p>
                            <p class="font-mono font-medium">{{ $this->fmt($result['settled_credits']) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-zinc-400">Avvisade</p>
                            <p class="font-mono font-medium {{ $result['rejected_credits'] !== 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                {{ $this->fmt($result['rejected_credits']) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-zinc-400">Makulerade</p>
                            <p class="font-mono font-medium {{ $result['cancelled_credits'] !== 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                                {{ $this->fmt($result['cancelled_credits']) }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Individual rejected transactions (record 14) --}}
            @if (count($result['transactions']) > 0)
                <div class="rounded-xl border border-red-200 dark:border-red-800/40 bg-white dark:bg-zinc-900">
                    <div class="px-5 py-3 border-b border-red-100 dark:border-red-800/30">
                        <h2 class="font-semibold text-sm text-red-700 dark:text-red-400">
                            <x-bootstrap-icon name="exclamation-triangle-fill" class="size-4 inline mr-1" />
                            Avvisade/justerade betalningar ({{ count($result['transactions']) }} st)
                        </h2>
                        <p class="text-xs text-zinc-400 mt-0.5">
                            Dessa betalningar genomfördes inte och behöver åtgärdas.
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-zinc-400 dark:text-zinc-500 border-b border-zinc-100 dark:border-zinc-800">
                                <tr>
                                    <th class="px-4 py-2 text-left">BG mottagare</th>
                                    <th class="px-4 py-2 text-left">Referens</th>
                                    <th class="px-4 py-2 text-right w-28">Belopp</th>
                                    <th class="px-4 py-2 text-left w-24">Förfallodag</th>
                                    <th class="px-4 py-2 text-left w-16">Kod</th>
                                    <th class="px-4 py-2 text-left">Text</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                                @foreach ($result['transactions'] as $tx)
                                    <tr class="hover:bg-red-50/50 dark:hover:bg-red-900/10">
                                        <td class="px-4 py-2 font-mono text-zinc-600 dark:text-zinc-300">
                                            {{ $tx['bg_payee'] ?: '–' }}
                                        </td>
                                        <td class="px-4 py-2 text-zinc-500">
                                            {{ $tx['reference'] ?: '–' }}
                                        </td>
                                        <td class="px-4 py-2 font-mono text-right {{ $tx['amount'] >= 0 ? 'text-zinc-700 dark:text-zinc-300' : 'text-red-600 dark:text-red-400' }}">
                                            {{ $this->fmt($tx['amount']) }}
                                        </td>
                                        <td class="px-4 py-2 font-mono text-zinc-500">
                                            {{ $tx['due_date'] ?: '–' }}
                                        </td>
                                        <td class="px-4 py-2 font-mono font-bold text-red-600 dark:text-red-400">
                                            {{ $tx['status'] ?: '–' }}
                                        </td>
                                        <td class="px-4 py-2 text-zinc-500 max-w-[200px] truncate">
                                            {{ $tx['text'] ?: '–' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @elseif ($result['rejected_invoices'] === 0 && $result['cancelled_invoices'] === 0)
                {{-- All good --}}
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800
                            dark:border-green-700/40 dark:bg-green-900/20 dark:text-green-300 flex items-center gap-2">
                    <x-bootstrap-icon name="check-circle-fill" class="size-4 shrink-0" />
                    Alla betalningar genomfördes utan anmärkning.
                </div>
            @endif

            {{-- Footer: record count --}}
            @if ($result['record_count'])
                <p class="text-xs text-zinc-300 dark:text-zinc-600 text-right">
                    {{ $result['record_count'] }} poster i filen
                </p>
            @endif

        @endif

        {{-- Info box --}}
        <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 px-4 py-3 text-xs text-zinc-400 dark:text-zinc-500">
            <p class="font-medium text-zinc-500 dark:text-zinc-400 mb-1">Om LB återredovisning</p>
            <p>
                När du skickar en LB-betalningsfil (leverantörsbetalningar) till Bankgirot
                bekräftar de genomförandet med en LB återredovisningsfil.
                Filen innehåller totalsummor för betalda, avvisade och makulerade fakturor.
                Avvisade betalningar (kod 14) behöver hanteras manuellt — kontakta leverantören.
            </p>
        </div>

    </div>
</div>
