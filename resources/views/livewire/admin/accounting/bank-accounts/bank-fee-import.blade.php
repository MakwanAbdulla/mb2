<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\BankAccount;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntry;
use App\Services\Accounting\BankFeeMatcherService;
use App\Services\Accounting\SparbankFeeParser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Importera bankavgifter');

new class extends Component {
    use WithFileUploads;

    public $file = null;

    /** Parsed result from SparbankFeeParser */
    public ?array $parsed = null;

    /** Per-line cost center selection, keyed by index */
    public array $costCenterIds = [];

    /** GL account to credit (the bank account being charged) */
    public ?int $creditAccountId = null;

    /** Result after booking */
    public bool $booked = false;
    public ?int $journalEntryId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        // Default credit account: 1933 Bankkonto Medlem
        $account = Account::where('code', '1933')->first();
        $this->creditAccountId = $account?->id;
    }

    #[Computed]
    public function creditAccounts()
    {
        return Account::whereBetween('code', ['1900', '1999'])->orderBy('code')->get();
    }

    #[Computed]
    public function costCenters()
    {
        return CostCenter::active()->orderBy('code')->get();
    }

    public function updatedFile(): void
    {
        $this->reset('parsed', 'costCenterIds', 'booked', 'journalEntryId');

        if (! $this->file) {
            return;
        }

        $path   = $this->file->getRealPath();
        $parser = new SparbankFeeParser();
        $result = $parser->parse($path);

        if (! $result) {
            $this->addError('file', 'Kunde inte läsa PDF-filen. Kontrollera att det är en bankavgiftsfaktura från Sparbanken Sjuhärad.');
            return;
        }

        // Auto-detect cost center per line from bank account swish_number
        $bankAccounts = BankAccount::with('costCenter')
            ->whereNotNull('swish_number')
            ->get();

        foreach ($result['lines'] as $i => $line) {
            $ba = $bankAccounts->first(fn ($a) =>
                preg_replace('/\D/', '', $a->swish_number) === $line['agreement_number']
            );
            $this->costCenterIds[$i] = $ba?->costCenter?->id;
        }

        $this->parsed = $result;
    }

    public function book(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->validate([
            'creditAccountId' => 'required|exists:accounts,id',
        ], [
            'creditAccountId.required' => 'Välj vilket konto bankavgifterna debiteras från.',
        ]);

        if (! $this->parsed || empty($this->parsed['lines'])) {
            return;
        }

        $date = $this->parsed['period_end'];

        $fiscalYear = FiscalYear::where('starts_at', '<=', $date)
            ->where('ends_at', '>=', $date)
            ->where('is_locked', false)
            ->first();

        if (! $fiscalYear) {
            $this->addError('file', 'Inget öppet räkenskapsår täcker perioden ' . $date . '. Skapa eller öppna räkenskapsåret innan du bokar.');
            return;
        }

        $expenseAccount = Account::where('code', '6570')->first();
        $creditAccount  = Account::find($this->creditAccountId);

        if (! $expenseAccount || ! $creditAccount) {
            $this->addError('file', 'Konto 6570 Bankkostnader saknas i kontoplanen.');
            return;
        }

        $lines      = $this->parsed['lines'];
        $totalOre   = $this->parsed['total_ore'];
        $periodFrom = $this->parsed['period_start'];
        $periodTo   = $this->parsed['period_end'];

        DB::transaction(function () use ($fiscalYear, $expenseAccount, $creditAccount, $lines, $totalOre, $periodFrom, $periodTo, $date) {
            $nextNumber = JournalEntry::where('fiscal_year_id', $fiscalYear->id)
                ->where('series', 'U')
                ->lockForUpdate()
                ->max('number') + 1;

            $entry = JournalEntry::create([
                'fiscal_year_id'   => $fiscalYear->id,
                'series'           => 'U',
                'number'           => $nextNumber,
                'date'             => $date,
                'description'      => 'Bankavgifter ' . $periodFrom . ' – ' . $periodTo,
                'is_locked'        => false,
                'requires_receipt' => true,
            ]);

            foreach ($lines as $i => $line) {
                $desc = $line['description'];
                if ($line['count']) {
                    $desc .= ' (' . $line['count'] . ' st)';
                }

                $entry->lines()->create([
                    'account_id'     => $expenseAccount->id,
                    'cost_center_id' => $this->costCenterIds[$i] ?? null,
                    'debit'          => $line['amount_ore'],
                    'credit'         => 0,
                    'description'    => $desc,
                ]);
            }

            $entry->lines()->create([
                'account_id'     => $creditAccount->id,
                'cost_center_id' => null,
                'debit'          => 0,
                'credit'         => $totalOre,
                'description'    => null,
            ]);

            // Mark as bank-fee import so BankFeeMatcherService can link the debit transaction.
            $entry->updateQuietly([
                'source_type' => BankFeeMatcherService::SOURCE_TYPE,
            ]);

            // Store the PDF as a receipt — satisfies requires_receipt immediately.
            if ($this->file) {
                $filePath = AccountingDocument::buildPath('pdf', $fiscalYear->starts_at->year);
                Storage::put($filePath, file_get_contents($this->file->getRealPath()));

                AccountingDocument::create([
                    'type'             => AccountingDocument::TYPE_RECEIPT,
                    'journal_entry_id' => $entry->id,
                    'fiscal_year_id'   => $fiscalYear->id,
                    'disk'             => 'local',
                    'path'             => $filePath,
                    'original_name'    => $this->file->getClientOriginalName(),
                    'mime_type'        => 'application/pdf',
                    'size_bytes'       => filesize($this->file->getRealPath()),
                    'description'      => 'Bankavgiftsfaktura ' . $periodFrom . ' – ' . $periodTo,
                    'uploaded_by'      => Auth::id(),
                    'linked_at'        => now(),
                ]);

                $entry->updateQuietly(['requires_receipt' => false]);
            }

            $this->journalEntryId = $entry->id;
        });

        // Try to link an already-imported bank transaction for this debit.
        if ($this->journalEntryId) {
            $entry = JournalEntry::find($this->journalEntryId);
            if ($entry) {
                app(BankFeeMatcherService::class)->matchExistingTransactions($entry);
            }
        }

        $this->booked = true;
        $this->reset('file', 'parsed', 'costCenterIds');
    }
};
?>

<div>

    {{-- Success banner --}}
    @if ($booked && $journalEntryId)
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 dark:border-green-700/40 dark:bg-green-900/10 px-4 py-4">
            <p class="font-semibold text-green-800 dark:text-green-300 flex items-center gap-1.5 mb-1">
                <x-bootstrap-icon name="check-circle-fill" class="size-4" />
                Bokförd
            </p>
            <p class="text-sm text-green-700 dark:text-green-400">
                Bankavgifterna har skapats som verifikat U{{ $journalEntryId }}.
                <a href="{{ route('admin.accounting.journal-entries.show', $journalEntryId) }}"
                   wire:navigate class="underline">Visa verifikat →</a>
            </p>
        </div>
    @endif

    <div class="max-w-2xl space-y-6">

        {{-- File picker --}}
        <div>
            <label class="lbl">PDF-faktura (bankavgifter)</label>
            <input type="file"
                   wire:model="file"
                   accept=".pdf"
                   class="block w-full text-sm text-zinc-700 dark:text-zinc-300
                          file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-xs file:font-medium
                          file:text-zinc-700 hover:file:bg-zinc-200
                          dark:file:bg-zinc-700 dark:file:text-zinc-300 dark:hover:file:bg-zinc-600" />
            <div wire:loading wire:target="file" class="mt-1 text-xs text-zinc-400">Läser PDF…</div>
            @error('file')
                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        @if ($parsed)
            {{-- Period + total --}}
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 px-4 py-3 text-sm flex items-center justify-between">
                <span class="text-zinc-600 dark:text-zinc-400">
                    Period:
                    <span class="font-medium text-zinc-800 dark:text-zinc-200">
                        {{ \Illuminate\Support\Carbon::parse($parsed['period_start'])->format('d/m Y') }}
                        –
                        {{ \Illuminate\Support\Carbon::parse($parsed['period_end'])->format('d/m Y') }}
                    </span>
                </span>
                <span class="font-semibold tabular-nums text-zinc-800 dark:text-zinc-100">
                    {{ number_format($parsed['total_ore'] / 100, 2, ',', ' ') }} kr
                </span>
            </div>

            {{-- Fee lines table --}}
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-3 py-2">Tjänst</th>
                            <th class="px-3 py-2 w-32">Avtal</th>
                            <th class="px-3 py-2">Kostnadsställe</th>
                            <th class="px-3 py-2 text-right w-24">Belopp</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($parsed['lines'] as $i => $line)
                            <tr>
                                <td class="px-3 py-2">
                                    <div class="text-zinc-800 dark:text-zinc-200">{{ $line['description'] ?: '–' }}</div>
                                    @if ($line['count'])
                                        <div class="text-xs text-zinc-400">
                                            {{ $line['count'] }} st × {{ number_format($line['unit_price_ore'] / 100, 2, ',', ' ') }} kr
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-zinc-500">{{ $line['agreement_number'] }}</td>
                                <td class="px-3 py-2">
                                    <flux:select wire:model="costCenterIds.{{ $i }}" size="sm" class="w-full">
                                        <option value="">– Inget –</option>
                                        @foreach ($this->costCenters as $cc)
                                            <option value="{{ $cc->id }}">{{ $cc->code }} {{ $cc->name }}</option>
                                        @endforeach
                                    </flux:select>
                                </td>
                                <td class="px-3 py-2 text-right font-mono font-semibold tabular-nums text-zinc-700 dark:text-zinc-200">
                                    {{ number_format($line['amount_ore'] / 100, 2, ',', ' ') }} kr
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Credit account --}}
            <div>
                <label class="lbl">Konto att kreditera (det konto avgifterna dras från)</label>
                <flux:select wire:model="creditAccountId" class="w-full max-w-sm">
                    <option value="">– Välj konto –</option>
                    @foreach ($this->creditAccounts as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->code }} {{ $acc->name }}</option>
                    @endforeach
                </flux:select>
                @error('creditAccountId')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            {{-- Book button --}}
            <div class="flex items-center gap-3">
                <flux:button wire:click="book" variant="primary"
                             wire:loading.attr="disabled" wire:target="book">
                    <span wire:loading.remove wire:target="book">
                        Skapa verifikat — {{ number_format($parsed['total_ore'] / 100, 2, ',', ' ') }} kr
                    </span>
                    <span wire:loading wire:target="book">Bokför…</span>
                </flux:button>
            </div>
        @endif

    </div>
</div>
