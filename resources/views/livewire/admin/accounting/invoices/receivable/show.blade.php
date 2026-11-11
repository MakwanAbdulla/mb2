<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\FiscalYear;
use App\Models\AuditLog;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\InvoiceSequence;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\JournalSeries;
use App\Support\StoredUpload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Kundfaktura');

new class extends Component {
    use WithFileUploads;

    public Invoice $invoice;
    public $newDocument = null;

    public function mount(Invoice $invoice): void
    {
        abort_unless(Auth::user()->canAny(['accounting.manage', 'accounting.view']), 403);
        abort_unless($invoice->type === Invoice::TYPE_RECEIVABLE, 404);
        $this->invoice = $invoice;
    }

    #[Computed]
    public function invoice(): Invoice
    {
        return $this->invoice->fresh(['customer', 'paymentTerm', 'bankAccount', 'lines.account', 'lines.costCenter',
            'createdBy', 'journalEntry', 'paymentJournalEntry']);
    }

    #[Computed]
    public function documents()
    {
        return $this->invoice->documents()->get();
    }

    // ── Post ──────────────────────────────────────────────────────────────────

    public function post(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        abort_unless($this->invoice->isDraft(), 403);

        $inv = $this->invoice;

        $fy = FiscalYear::where('is_locked', false)
            ->where('starts_at', '<=', $inv->invoice_date)
            ->where('ends_at', '>=', $inv->invoice_date)
            ->first();

        if (! $fy) {
            $this->addError('post', 'Inget öppet räkenskapsår täcker fakturadatumet.');
            return;
        }

        $series = JournalSeries::where('default_for', 'ar')->first();
        if (! $series) {
            $this->addError('post', 'Verifikationsserie för kundfakturor (default_for=ar) saknas.');
            return;
        }

        // 1510 Kundfordringar — AR receivable account
        $arAccount = Account::where('code', '1510')->first();
        if (! $arAccount) {
            $this->addError('post', 'Konto 1510 (Kundfordringar) saknas i kontoplanen.');
            return;
        }

        DB::transaction(function () use ($inv, $fy, $series, $arAccount) {
            // Assign invoice number if not yet set
            $invoiceNumber = $inv->invoice_number
                ?? InvoiceSequence::next(InvoiceSequence::SERIES_AR, $inv->invoice_date->year);

            $maxNumber = JournalEntry::where('fiscal_year_id', $fy->id)
                ->where('series', $series->code)
                ->lockForUpdate()
                ->max('number') ?? 0;

            $entry = JournalEntry::create([
                'fiscal_year_id' => $fy->id,
                'series'         => $series->code,
                'number'         => $maxNumber + 1,
                'date'           => $inv->invoice_date,
                'description'    => 'Kundfaktura ' . ($inv->customer?->name ?? '') . " #{$invoiceNumber}",
                'source_type'    => Invoice::class,
                'source_id'      => $inv->id,
                'created_by'     => Auth::id(),
            ]);

            // Debit: 1510 Kundfordringar — full invoice total
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id'       => $arAccount->id,
                'cost_center_id'   => null,
                'debit'            => $inv->total,
                'credit'           => 0,
                'description'      => 'Kundfordran',
            ]);

            // Credit lines — one per invoice line (revenue)
            foreach ($inv->lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id'       => $line->account_id,
                    'cost_center_id'   => $line->cost_center_id,
                    'debit'            => 0,
                    'credit'           => $line->amount_ex_vat,
                    'description'      => $line->description ?: null,
                ]);

                // VAT credit line
                if ($line->vat_amount > 0) {
                    $vatCode    = match ((int) $line->vat_rate) { 25 => '2611', 12 => '2621', 6 => '2631', default => null };
                    $vatAccount = $vatCode ? Account::where('code', $vatCode)->first() : null;

                    if ($vatAccount) {
                        JournalLine::create([
                            'journal_entry_id' => $entry->id,
                            'account_id'       => $vatAccount->id,
                            'cost_center_id'   => null,
                            'debit'            => 0,
                            'credit'           => $line->vat_amount,
                            'description'      => "Utgående moms {$line->vat_rate}%",
                        ]);
                    } else {
                        // No VAT account — add VAT to revenue line
                        JournalLine::where('journal_entry_id', $entry->id)
                            ->where('account_id', $line->account_id)
                            ->latest('id')
                            ->first()
                            ?->increment('credit', $line->vat_amount);
                    }
                }
            }

            $inv->update([
                'status'           => Invoice::STATUS_POSTED,
                'invoice_number'   => $invoiceNumber,
                'journal_entry_id' => $entry->id,
            ]);

            AccountingDocument::where('invoice_id', $inv->id)
                ->whereNull('journal_entry_id')
                ->update(['journal_entry_id' => $entry->id, 'linked_at' => Carbon::now()]);
        });

        AuditLog::record('invoice_posted', 'accounting', 'Kundfaktura ' . ($inv->customer?->name ?? '') . ' #' . ($inv->invoice_number ?? $inv->id), $inv);
        unset($this->invoice);
    }

    public function markPaid(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        abort_unless($this->invoice->isPosted(), 403);

        $this->invoice->update([
            'status'  => Invoice::STATUS_PAID,
            'paid_at' => now(),
        ]);
        AuditLog::record('invoice_paid', 'accounting', 'Kundfaktura ' . ($this->invoice->customer?->name ?? '') . ' #' . ($this->invoice->invoice_number ?? $this->invoice->id), $this->invoice);
        unset($this->invoice);
    }

    public function sendInvoice(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        abort_unless($this->invoice->isPosted() || $this->invoice->isPaid(), 403);
        abort_unless($this->invoice->invoice_number, 403);

        $email = $this->invoice->customer?->email;
        if (! $email) {
            $this->addError('send', 'Kunden saknar e-postadress.');
            return;
        }

        \Illuminate\Support\Facades\Mail::to($email)
            ->send(new \App\Mail\ArInvoiceMail($this->invoice));

        $this->invoice->update(['sent_at' => now()]);
        AuditLog::record('invoice_sent', 'accounting', 'Kundfaktura #' . $this->invoice->invoice_number . ' → ' . $email, $this->invoice);
        unset($this->invoice);
    }

    public function cancel(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        abort_unless($this->invoice->isDraft(), 403);

        $this->invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        AuditLog::record('invoice_cancelled', 'accounting', 'Kundfaktura ' . ($this->invoice->customer?->name ?? '') . ' #' . ($this->invoice->invoice_number ?? $this->invoice->id), $this->invoice);
        unset($this->invoice);
    }

    // ── Documents ─────────────────────────────────────────────────────────────

    public function uploadDocument(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->validate([
            'newDocument' => 'required|file|mimes:pdf,png,jpg,jpeg|max:20480',
        ], [
            'newDocument.required' => 'Välj en fil.',
            'newDocument.mimes'    => 'Endast PDF, PNG och JPEG accepteras.',
        ]);

        $path   = AccountingDocument::buildPath($this->newDocument->getClientOriginalExtension());
        $upload = StoredUpload::store($this->newDocument, 'local', dirname($path), basename($path));

        $upload->persist(fn (StoredUpload $upload) => AccountingDocument::create([
            'type'             => AccountingDocument::TYPE_INVOICE_ATTACHMENT,
            'invoice_id'       => $this->invoice->id,
            'journal_entry_id' => $this->invoice->journal_entry_id,
            'fiscal_year_id'   => null,
            'disk'             => $upload->disk,
            'path'             => $upload->path,
            'original_name'    => $upload->originalName,
            'mime_type'        => $upload->mimeType,
            'size_bytes'       => $upload->sizeBytes,
            'uploaded_by'      => Auth::id(),
            'linked_at'        => $this->invoice->journal_entry_id ? now() : null,
        ]));

        $this->newDocument = null;
        unset($this->documents);
    }

    public function deleteDocument(int $docId): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        AccountingDocument::where('id', $docId)
            ->where('invoice_id', $this->invoice->id)
            ->firstOrFail()
            ->delete();

        unset($this->documents);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'     => 'Utkast',
            'posted'    => 'Bokförd',
            'paid'      => 'Betald',
            'cancelled' => 'Annullerad',
            default     => $status,
        };
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'draft'     => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
            'posted'    => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
            'paid'      => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
            'cancelled' => 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400',
            default     => 'bg-zinc-100 text-zinc-600',
        };
    }
};
?>

<div>

    {{-- Back --}}
    <div class="mb-6">
        <a href="{{ request()->routeIs('admin.shop.*') ? route('admin.shop.invoices.index') : route('admin.accounting.invoices.receivable.index') }}" wire:navigate
           class="flex items-center gap-1.5 text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
            <x-bootstrap-icon name="arrow-left" class="size-3.5" />
            Kundfakturor
        </a>
    </div>

    @error('post')
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-400">
            {{ $message }}
        </div>
    @enderror
    @error('send')
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-400">
            {{ $message }}
        </div>
    @enderror

    <div class="grid gap-6 lg:grid-cols-[1fr_280px]">

        <div class="space-y-6">

            {{-- Invoice header --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-4">Fakturahuvud</h2>
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-zinc-400">Kund</dt>
                        <dd class="mt-0.5 font-medium text-zinc-900 dark:text-white">{{ $this->invoice->customer?->name ?? '–' }}</dd>
                        @if ($this->invoice->customer?->org_number)
                            <dd class="text-xs text-zinc-400 font-mono">{{ $this->invoice->customer->org_number }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-400">Fakturanummer</dt>
                        <dd class="mt-0.5 font-mono text-zinc-700 dark:text-zinc-300">
                            {{ $this->invoice->invoice_number ?? '(tilldelas vid bokföring)' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-400">Fakturadatum</dt>
                        <dd class="mt-0.5 text-zinc-700 dark:text-zinc-300">{{ $this->invoice->invoice_date->format('d/m Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-400">Betalningsvillkor</dt>
                        <dd class="mt-0.5 text-zinc-700 dark:text-zinc-300">{{ $this->invoice->paymentTerm?->name ?? '–' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-400">Förfallodatum</dt>
                        <dd class="mt-0.5 {{ $this->invoice->due_date?->isPast() && !$this->invoice->isPaid() ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-zinc-700 dark:text-zinc-300' }}">
                            {{ $this->invoice->due_date?->format('d/m Y') ?? '–' }}
                        </dd>
                    </div>
                    @if ($this->invoice->payment_method)
                    <div>
                        <dt class="text-xs text-zinc-400">Betalsätt</dt>
                        <dd class="mt-0.5 text-zinc-700 dark:text-zinc-300">
                            {{ $this->invoice->paymentMethodLabel() }}
                            @if ($this->invoice->bankAccount)
                                @if ($this->invoice->payment_method === 'bankgiro' && $this->invoice->bankAccount->bankgiro)
                                    <span class="font-mono text-xs text-zinc-400 ml-1">{{ $this->invoice->bankAccount->bankgiro }}</span>
                                @elseif ($this->invoice->payment_method === 'swish' && $this->invoice->bankAccount->swish_number)
                                    <span class="font-mono text-xs text-zinc-400 ml-1">{{ $this->invoice->bankAccount->swish_number }}</span>
                                @endif
                            @endif
                        </dd>
                    </div>
                    @endif
                    @if ($this->invoice->ocr_number)
                    <div>
                        <dt class="text-xs text-zinc-400">OCR-nummer</dt>
                        <dd class="mt-0.5 font-mono font-semibold text-zinc-900 dark:text-white tracking-wider">
                            {{ $this->invoice->ocr_number }}
                        </dd>
                    </div>
                    @endif
                    @if ($this->invoice->sent_at)
                    <div>
                        <dt class="text-xs text-zinc-400">Skickad</dt>
                        <dd class="mt-0.5 text-zinc-700 dark:text-zinc-300">{{ $this->invoice->sent_at->format('d/m Y H:i') }}</dd>
                    </div>
                    @endif
                    @if ($this->invoice->paid_at)
                    <div>
                        <dt class="text-xs text-zinc-400">Betalad</dt>
                        <dd class="mt-0.5 text-green-700 dark:text-green-400 font-medium">{{ $this->invoice->paid_at->format('d/m Y') }}</dd>
                    </div>
                    @endif
                    <div>
                        <dt class="text-xs text-zinc-400">Skapad av</dt>
                        <dd class="mt-0.5 text-zinc-700 dark:text-zinc-300">{{ $this->invoice->createdBy?->name ?? '–' }}</dd>
                    </div>
                </dl>
                @if ($this->invoice->notes)
                    <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <p class="text-xs text-zinc-400 mb-1">Anteckningar</p>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $this->invoice->notes }}</p>
                    </div>
                @endif
            </div>

            {{-- Lines table --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 overflow-hidden">
                <div class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800 px-5 py-3">
                    <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Fakturarader</span>
                </div>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs font-medium text-zinc-500 dark:text-zinc-400">
                        <tr class="border-b border-zinc-100 dark:border-zinc-800">
                            <th class="px-5 py-2">Beskrivning</th>
                            <th class="px-5 py-2 w-32">Konto</th>
                            <th class="px-5 py-2 w-24 text-right">Ex. moms</th>
                            <th class="px-5 py-2 w-16 text-right">Moms%</th>
                            <th class="px-5 py-2 w-24 text-right">Moms</th>
                            <th class="px-5 py-2 w-24 text-right">Totalt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                        @foreach ($this->invoice->lines->sortBy('sort_order') as $line)
                            <tr>
                                <td class="px-5 py-3 text-zinc-700 dark:text-zinc-300">{{ $line->description ?: '–' }}</td>
                                <td class="px-5 py-3 font-mono text-xs">
                                    <span class="font-semibold">{{ $line->account?->code }}</span>
                                    <span class="text-zinc-400 block truncate max-w-[8rem]">{{ $line->account?->name }}</span>
                                </td>
                                <td class="px-5 py-3 text-right font-mono text-xs">{{ format_ore($line->amount_ex_vat) }}</td>
                                <td class="px-5 py-3 text-right text-xs text-zinc-400">{{ $line->vat_rate > 0 ? "{$line->vat_rate}%" : '–' }}</td>
                                <td class="px-5 py-3 text-right font-mono text-xs">{{ format_ore($line->vat_amount) }}</td>
                                <td class="px-5 py-3 text-right font-mono text-sm font-medium">{{ format_ore($line->total()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t-2 border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <td colspan="2" class="px-5 py-3 text-sm font-medium text-zinc-500">Summa</td>
                            <td class="px-5 py-3 text-right font-mono text-sm">{{ format_ore($this->invoice->amount_ex_vat) }}</td>
                            <td></td>
                            <td class="px-5 py-3 text-right font-mono text-sm">{{ format_ore($this->invoice->vat_amount) }}</td>
                            <td class="px-5 py-3 text-right font-mono text-base font-bold">{{ format_ore($this->invoice->total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- PDF download --}}
            @if ($this->invoice->invoice_number)
                <div class="flex gap-3">
                    <a href="{{ route('invoice.ar.view', $this->invoice) }}" target="_blank"
                       class="btn btn-ghost btn-sm">
                        <x-bootstrap-icon name="file-earmark-pdf" class="size-4" /> Visa PDF
                    </a>
                    <a href="{{ route('invoice.ar.download', $this->invoice) }}"
                       class="btn btn-ghost btn-sm">
                        <x-bootstrap-icon name="download" class="size-4" /> Ladda ner
                    </a>
                </div>
            @endif

            {{-- Journal entry --}}
            @if ($this->invoice->journalEntry)
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-3">Verifikation</h2>
                    <a href="{{ route('admin.accounting.journal-entries.show', $this->invoice->journalEntry) }}"
                       wire:navigate class="text-sm font-mono hover:underline text-complement-light">
                        {{ $this->invoice->journalEntry->series }}{{ $this->invoice->journalEntry->number }}
                        &mdash; {{ $this->invoice->journalEntry->date->format('d/m Y') }}
                    </a>
                </div>
            @endif

        </div>

        {{-- Sidebar --}}
        <div class="space-y-4">

            {{-- Actions --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5 space-y-3">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Åtgärder</h3>

                @if ($this->invoice->isDraft())
                    @can('accounting.manage')
                        <flux:button wire:click="post" variant="primary" class="w-full">
                            <x-bootstrap-icon name="journal-check" class="size-4" /> Bokför och nummersätt
                        </flux:button>
                        <flux:button wire:click="cancel" variant="ghost" class="w-full"
                            x-data x-on:click="confirm('Annullera fakturan?') || $event.preventDefault()">
                            Annullera
                        </flux:button>
                    @endcan
                @elseif ($this->invoice->isPosted())
                    @can('accounting.manage')
                        @if ($this->invoice->customer?->email && $this->invoice->invoice_number)
                            <flux:button wire:click="sendInvoice" variant="ghost" class="w-full"
                                x-data x-on:click="confirm('Skicka faktura till {{ $this->invoice->customer->email }}?') || $event.preventDefault()">
                                <x-bootstrap-icon name="envelope" class="size-4" />
                                {{ $this->invoice->sent_at ? 'Skicka igen' : 'Skicka till kund' }}
                            </flux:button>
                        @endif
                        <flux:button wire:click="markPaid" variant="primary" class="w-full"
                            x-data x-on:click="confirm('Markera som betald?') || $event.preventDefault()">
                            <x-bootstrap-icon name="cash-coin" class="size-4" /> Markera som betald
                        </flux:button>
                    @endcan
                @elseif ($this->invoice->isPaid())
                    @can('accounting.manage')
                        @if ($this->invoice->customer?->email && $this->invoice->invoice_number)
                            <flux:button wire:click="sendInvoice" variant="ghost" class="w-full"
                                x-data x-on:click="confirm('Skicka faktura till {{ $this->invoice->customer->email }}?') || $event.preventDefault()">
                                <x-bootstrap-icon name="envelope" class="size-4" /> Skicka igen
                            </flux:button>
                        @endif
                    @endcan
                @else
                    <p class="text-sm text-zinc-400 text-center py-2">Inga åtgärder tillgängliga</p>
                @endif
            </div>

            {{-- Summary --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-3">Summering</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-zinc-400">Ex. moms</dt>
                        <dd class="font-mono">{{ format_ore($this->invoice->amount_ex_vat) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-zinc-400">Moms</dt>
                        <dd class="font-mono">{{ format_ore($this->invoice->vat_amount) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-zinc-100 dark:border-zinc-800 pt-2 font-semibold">
                        <dt>Totalt</dt>
                        <dd class="font-mono">{{ format_ore($this->invoice->total) }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Documents --}}
            @can('accounting.manage')
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-3">Dokument</h3>

                @forelse ($this->documents as $doc)
                    <div class="flex items-start gap-2 mb-2 py-1.5 border-b border-zinc-100 dark:border-zinc-800 last:border-0">
                        <x-bootstrap-icon
                            name="{{ $doc->isPdf() ? 'file-earmark-pdf-fill' : 'file-earmark-image-fill' }}"
                            class="size-4 shrink-0 mt-0.5 {{ $doc->isPdf() ? 'text-red-400' : 'text-blue-400' }}" />
                        <div class="min-w-0 flex-1 text-xs">
                            <button
                                @click="$dispatch('open-document', {
                                    url:         '{{ route('document.inline', $doc) }}',
                                    downloadUrl: '{{ route('document.download', $doc) }}',
                                    name:        '{{ addslashes($doc->original_name) }}',
                                    mime:        '{{ $doc->mime_type }}'
                                })"
                                class="font-medium text-zinc-700 dark:text-zinc-200 hover:underline truncate block text-left">
                                {{ $doc->original_name }}
                            </button>
                            <span class="text-zinc-400">{{ $doc->sizeForHumans() }}</span>
                        </div>
                        <button wire:click="deleteDocument({{ $doc->id }})"
                                x-data x-on:click="confirm('Ta bort dokumentet?') || $event.preventDefault()"
                                class="text-zinc-300 hover:text-red-500 dark:hover:text-red-400 shrink-0">
                            <x-bootstrap-icon name="trash" class="size-3.5" />
                        </button>
                    </div>
                @empty
                    <p class="text-xs text-zinc-400 mb-3">Inga dokument.</p>
                @endforelse

                <div class="mt-2">
                    <input type="file" wire:model="newDocument" accept=".pdf,.png,.jpg,.jpeg"
                           class="block w-full text-xs text-zinc-500 dark:text-zinc-400
                                  file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0
                                  file:text-xs file:font-medium file:bg-zinc-100 file:text-zinc-700
                                  dark:file:bg-zinc-800 dark:file:text-zinc-300 cursor-pointer" />
                    @error('newDocument') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    @if ($newDocument)
                        <flux:button wire:click="uploadDocument" variant="primary" size="sm" class="mt-2 w-full">
                            <x-bootstrap-icon name="upload" class="size-3.5" /> Ladda upp
                        </flux:button>
                    @endif
                </div>
            </div>
            @endcan

        </div>
    </div>
</div>
