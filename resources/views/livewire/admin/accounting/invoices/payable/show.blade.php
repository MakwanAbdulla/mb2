<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\ApprovalLog;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\Invoice;
use App\Models\AuditLog;
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
title('Leverantörsfaktura');

/**
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\AccountingDocument[] $documents
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\Vendor[]             $vendors
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\Account[]            $accounts
 * @property-read \Illuminate\Support\Collection|\App\Models\Accounting\CostCenter[]         $costCenters
 * @property-read array{ex_vat:int,vat:int,total:int}                                        $totals
 */
new class extends Component {
    use WithFileUploads;

    public Invoice $invoice;
    public $newDocument = null;

    public string $rejectNote = '';
    public bool $showRejectModal = false;

    public function mount(Invoice $invoice): void
    {
        abort_unless(Auth::user()->canAny(['accounting.manage', 'accounting.approve', 'accounting.view']), 403);
        abort_unless($invoice->type === Invoice::TYPE_PAYABLE, 404);

        $this->invoice = $invoice;
    }

    #[Computed]
    public function invoice(): Invoice
    {
        return $this->invoice->fresh(['vendor', 'paymentTerm', 'lines.account', 'lines.costCenter',
            'createdBy', 'approvedBy', 'journalEntry', 'paymentJournalEntry', 'approvalLogs.approvedBy']);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    public function submitForApproval(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        abort_unless($this->invoice->isDraft(), 403);

        $this->invoice->update(['status' => Invoice::STATUS_PENDING_APPROVAL]);
        AuditLog::record('invoice_submitted_for_approval', 'accounting', 'Lev.faktura ' . ($this->invoice->vendor?->name ?? '') . ' #' . ($this->invoice->invoice_number ?? $this->invoice->id), $this->invoice);
        unset($this->invoice);
    }

    public function approve(): void
    {
        abort_unless(Auth::user()->can('accounting.approve'), 403);
        abort_unless($this->invoice->status === Invoice::STATUS_PENDING_APPROVAL, 403);
        abort_if($this->invoice->created_by === Auth::id(), 403, 'Du kan inte attestera en faktura du själv skickat in.');

        $now = Carbon::now();

        $this->invoice->update([
            'status'      => Invoice::STATUS_APPROVED,
            'approved_by' => Auth::id(),
            'approved_at' => $now,
        ]);

        ApprovalLog::create([
            'invoice_id'  => $this->invoice->id,
            'approved_by' => Auth::id(),
            'action'      => ApprovalLog::ACTION_APPROVED,
            'notes'       => null,
            'actioned_at' => $now,
        ]);

        AuditLog::record('invoice_approved', 'accounting', 'Lev.faktura ' . ($this->invoice->vendor?->name ?? '') . ' #' . ($this->invoice->invoice_number ?? $this->invoice->id), $this->invoice);
        unset($this->invoice);
    }

    public function openReject(): void
    {
        abort_unless(Auth::user()->can('accounting.approve'), 403);
        $this->rejectNote      = '';
        $this->showRejectModal = true;
    }

    public function reject(): void
    {
        abort_unless(Auth::user()->can('accounting.approve'), 403);
        abort_unless($this->invoice->status === Invoice::STATUS_PENDING_APPROVAL, 403);

        $this->validate(['rejectNote' => 'required|string|min:5'], [
            'rejectNote.required' => 'Ange anledning till avvisning.',
            'rejectNote.min'      => 'Ange minst 5 tecken.',
        ]);

        $now = Carbon::now();

        $this->invoice->update(['status' => Invoice::STATUS_DRAFT]);

        ApprovalLog::create([
            'invoice_id'  => $this->invoice->id,
            'approved_by' => Auth::id(),
            'action'      => ApprovalLog::ACTION_REJECTED,
            'notes'       => $this->rejectNote,
            'actioned_at' => $now,
        ]);

        AuditLog::record('invoice_rejected', 'accounting', 'Lev.faktura ' . ($this->invoice->vendor?->name ?? '') . ' #' . ($this->invoice->invoice_number ?? $this->invoice->id) . ' — ' . $this->rejectNote, $this->invoice);
        $this->showRejectModal = false;
        $this->rejectNote      = '';
        unset($this->invoice);
    }

    public function post(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        abort_unless($this->invoice->isApproved(), 403);

        $inv = $this->invoice;

        // Find fiscal year covering invoice_date
        $fy = FiscalYear::where('is_locked', false)
            ->where('starts_at', '<=', $inv->invoice_date)
            ->where('ends_at', '>=', $inv->invoice_date)
            ->first();

        if (! $fy) {
            $this->addError('post', 'Inget öppet räkenskapsår täcker fakturadatumet.');
            return;
        }

        // Find AP series (default_for = 'ap')
        $series = JournalSeries::where('default_for', 'ap')->first();
        if (! $series) {
            $this->addError('post', 'Verifikationsserie för leverantörsfakturor (default_for=ap) saknas. Kör JournalSeriesSeeder.');
            return;
        }

        // AP liability account — 2440 Leverantörsskulder
        $apAccount = Account::where('code', '2440')->first();
        if (! $apAccount) {
            $this->addError('post', 'Konto 2440 (Leverantörsskulder) saknas i kontoplanen.');
            return;
        }

        DB::transaction(function () use ($inv, $fy, $series, $apAccount) {
            // Next sequence number (locked for concurrency safety)
            $maxNumber = JournalEntry::where('fiscal_year_id', $fy->id)
                ->where('series', $series->code)
                ->lockForUpdate()
                ->max('number') ?? 0;

            $entry = JournalEntry::create([
                'fiscal_year_id' => $fy->id,
                'series'         => $series->code,
                'number'         => $maxNumber + 1,
                'date'           => $inv->invoice_date,
                'description'    => 'Lev.faktura ' . ($inv->vendor?->name ?? '') . ($inv->invoice_number ? " #{$inv->invoice_number}" : ''),
                'source_type'    => Invoice::class,
                'source_id'      => $inv->id,
                'created_by'     => Auth::id(),
            ]);

            // Debit lines — one per invoice line (expense)
            foreach ($inv->lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id'       => $line->account_id,
                    'cost_center_id'   => $line->cost_center_id,
                    'debit'            => $line->amount_ex_vat,
                    'credit'           => 0,
                    'description'      => $line->description ?: null,
                ]);

                // Separate VAT debit line if applicable
                if ($line->vat_amount > 0) {
                    $vatCode    = match ((int) $line->vat_rate) { 25 => '2641', 12 => '2642', 6 => '2643', default => null };
                    $vatAccount = $vatCode ? Account::where('code', $vatCode)->first() : null;

                    // If no VAT account exists, add VAT to the expense line (fallback for non-VAT-registered)
                    if ($vatAccount) {
                        JournalLine::create([
                            'journal_entry_id' => $entry->id,
                            'account_id'       => $vatAccount->id,
                            'cost_center_id'   => null,
                            'debit'            => $line->vat_amount,
                            'credit'           => 0,
                            'description'      => "Ingående moms {$line->vat_rate}%",
                        ]);
                    } else {
                        // Append VAT to the last debit line for this invoice line (update)
                        JournalLine::where('journal_entry_id', $entry->id)
                            ->where('account_id', $line->account_id)
                            ->latest('id')
                            ->first()
                            ?->increment('debit', $line->vat_amount);
                    }
                }
            }

            // Credit line — Leverantörsskulder (2440) for the full total
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id'       => $apAccount->id,
                'cost_center_id'   => null,
                'debit'            => 0,
                'credit'           => $inv->total,
                'description'      => 'Leverantörsskuld',
            ]);

            // Link invoice → journal entry
            $inv->update([
                'status'           => Invoice::STATUS_POSTED,
                'journal_entry_id' => $entry->id,
            ]);

            // Propagate journal_entry_id to all documents already attached to this invoice
            AccountingDocument::where('invoice_id', $inv->id)
                ->whereNull('journal_entry_id')
                ->update([
                    'journal_entry_id' => $entry->id,
                    'linked_at'        => Carbon::now(),
                ]);
        });

        AuditLog::record('invoice_posted', 'accounting', 'Lev.faktura ' . ($inv->vendor?->name ?? '') . ' #' . ($inv->invoice_number ?? $inv->id), $inv);
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
        AuditLog::record('invoice_paid', 'accounting', 'Lev.faktura ' . ($this->invoice->vendor?->name ?? '') . ' #' . ($this->invoice->invoice_number ?? $this->invoice->id), $this->invoice);
        unset($this->invoice);
    }

    public function cancel(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        abort_unless($this->invoice->isDraft() || $this->invoice->status === Invoice::STATUS_PENDING_APPROVAL, 403);

        $this->invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        AuditLog::record('invoice_cancelled', 'accounting', 'Lev.faktura ' . ($this->invoice->vendor?->name ?? '') . ' #' . ($this->invoice->invoice_number ?? $this->invoice->id), $this->invoice);
        unset($this->invoice);
    }

    // ── Documents ─────────────────────────────────────────────────────────────

    #[Computed]
    public function documents()
    {
        return $this->invoice->documents()->get();
    }

    public function uploadDocument(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->validate([
            'newDocument' => 'required|file|mimes:pdf,png,jpg,jpeg|max:20480',
        ], [
            'newDocument.required' => 'Välj en fil.',
            'newDocument.mimes'    => 'Endast PDF, PNG och JPEG accepteras.',
            'newDocument.max'      => 'Filen får inte vara större än 20 MB.',
        ]);

        $path   = AccountingDocument::buildPath($this->newDocument->getClientOriginalExtension());
        $upload = StoredUpload::store($this->newDocument, 'local', dirname($path), basename($path));

        $upload->persist(fn (StoredUpload $upload) => AccountingDocument::create([
            'type'          => AccountingDocument::TYPE_INVOICE_ATTACHMENT,
            'invoice_id'    => $this->invoice->id,
            'journal_entry_id' => $this->invoice->journal_entry_id,
            'fiscal_year_id'   => null,
            'disk'          => $upload->disk,
            'path'          => $upload->path,
            'original_name' => $upload->originalName,
            'mime_type'     => $upload->mimeType,
            'size_bytes'    => $upload->sizeBytes,
            'uploaded_by'   => Auth::id(),
            'linked_at'     => $this->invoice->journal_entry_id ? now() : null,
        ]));

        $this->newDocument = null;
        unset($this->documents);
    }

    public function deleteDocument(int $docId): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $doc = AccountingDocument::where('id', $docId)
            ->where('invoice_id', $this->invoice->id)
            ->firstOrFail();

        $doc->delete();
        unset($this->documents);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'            => 'Utkast',
            'pending_approval' => 'Väntar attest',
            'approved'         => 'Attesterad',
            'posted'           => 'Bokförd',
            'paid'             => 'Betald',
            'cancelled'        => 'Annullerad',
            default            => $status,
        };
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'draft'            => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
            'pending_approval' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
            'approved'         => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
            'posted'           => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
            'paid'             => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
            'cancelled'        => 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400',
            default            => 'bg-zinc-100 text-zinc-600',
        };
    }
};
?>

<div>

    @error('post')
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-400">
            {{ $message }}
        </div>
    @enderror

    <div class="grid gap-6 lg:grid-cols-[1fr_280px]">

        {{-- Main content --}}
        <div class="space-y-6">

            {{-- Invoice header --}}
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-4">Fakturahuvud</h2>
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-zinc-400">Leverantör</dt>
                        <dd class="mt-0.5 font-medium text-zinc-900 dark:text-white">{{ $this->invoice->vendor?->name ?? '–' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-400">Fakturanummer</dt>
                        <dd class="mt-0.5 font-mono text-zinc-700 dark:text-zinc-300">{{ $this->invoice->invoice_number ?? '–' }}</dd>
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
                            @php $v = $this->invoice->vendor; @endphp
                            @if ($this->invoice->payment_method === 'bankgiro' && $v?->bankgiro)
                                <span class="font-mono text-xs text-zinc-400 ml-1">{{ $v->bankgiro }}</span>
                            @elseif ($this->invoice->payment_method === 'plusgiro' && $v?->plusgiro)
                                <span class="font-mono text-xs text-zinc-400 ml-1">{{ $v->plusgiro }}</span>
                            @elseif ($this->invoice->payment_method === 'bank_transfer' && $v?->iban)
                                <span class="font-mono text-xs text-zinc-400 block">{{ $v->iban }}</span>
                            @endif
                        </dd>
                    </div>
                    @endif
                    <div>
                        <dt class="text-xs text-zinc-400">Registrerad av</dt>
                        <dd class="mt-0.5 text-zinc-700 dark:text-zinc-300">{{ $this->invoice->createdBy?->name ?? '–' }}</dd>
                    </div>
                    @if ($this->invoice->approvedBy)
                        <div>
                            <dt class="text-xs text-zinc-400">Attesterad av</dt>
                            <dd class="mt-0.5 text-zinc-700 dark:text-zinc-300">
                                {{ $this->invoice->approvedBy->name }}
                                <span class="text-xs text-zinc-400 block">{{ $this->invoice->approved_at?->format('d/m Y H:i') }}</span>
                            </dd>
                        </div>
                    @endif
                    @if ($this->invoice->paid_at)
                        <div>
                            <dt class="text-xs text-zinc-400">Betalad</dt>
                            <dd class="mt-0.5 text-green-700 dark:text-green-400 font-medium">{{ $this->invoice->paid_at->format('d/m Y') }}</dd>
                        </div>
                    @endif
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
                            <td colspan="2" class="px-5 py-3 text-sm font-medium text-zinc-500 dark:text-zinc-400">Summa</td>
                            <td class="px-5 py-3 text-right font-mono text-sm">{{ format_ore($this->invoice->amount_ex_vat) }}</td>
                            <td></td>
                            <td class="px-5 py-3 text-right font-mono text-sm">{{ format_ore($this->invoice->vat_amount) }}</td>
                            <td class="px-5 py-3 text-right font-mono text-base font-bold">{{ format_ore($this->invoice->total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Approval history --}}
            @if ($this->invoice->approvalLogs->isNotEmpty())
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-3">Attesthistorik</h2>
                    <ul class="space-y-2">
                        @foreach ($this->invoice->approvalLogs->sortByDesc('actioned_at') as $log)
                            <li class="flex items-start gap-3 text-sm">
                                @if ($log->isApproved())
                                    <x-bootstrap-icon name="check-circle-fill" class="size-4 shrink-0 text-green-500 mt-0.5" />
                                @else
                                    <x-bootstrap-icon name="x-circle-fill" class="size-4 shrink-0 text-red-500 mt-0.5" />
                                @endif
                                <div>
                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $log->approvedBy?->name ?? '–' }}
                                    </span>
                                    <span class="text-zinc-400">
                                        {{ $log->isApproved() ? 'attesterade' : 'avvisade' }}
                                        · {{ $log->actioned_at->format('d/m Y H:i') }}
                                    </span>
                                    @if ($log->notes)
                                        <p class="text-zinc-500 dark:text-zinc-400 text-xs mt-0.5 italic">{{ $log->notes }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Journal entry link --}}
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

        {{-- Sidebar: actions --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5 space-y-3">
                <div class="flex items-center gap-1.5">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Åtgärder</h3>
                </div>

                {{-- Draft → Pending approval --}}
                @if ($this->invoice->isDraft())
                    @can('accounting.manage')
                        <flux:button wire:click="submitForApproval" variant="primary" class="w-full">
                            Skicka för attest <x-permission-hint permission="accounting.manage" />
                        </flux:button>
                        <flux:button wire:click="cancel" variant="danger" class="w-full" x-data x-on:click="confirm('Annullera fakturan?') || $event.preventDefault()">
                            Annullera <x-permission-hint permission="accounting.manage" />
                        </flux:button>
                    @endcan
                @endif

                {{-- Pending approval → Approved/Rejected --}}
                @if ($this->invoice->status === 'pending_approval')
                    @can('accounting.approve')
                        @if ($this->invoice->created_by === Auth::id())
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">
                                Du skickade in den här fakturan och kan inte attestera den själv — någon annan behöver godkänna.
                            </p>
                        @else
                            <flux:button wire:click="approve" variant="primary" class="w-full">
                                <x-bootstrap-icon name="check2" class="size-4" /> Attestera <x-permission-hint permission="accounting.approve" />
                            </flux:button>
                            <flux:button wire:click="openReject" variant="danger" class="w-full">
                                <x-bootstrap-icon name="x-lg" class="size-4" /> Avvisa <x-permission-hint permission="accounting.approve" />
                            </flux:button>
                        @endif
                    @endcan
                    @can('accounting.manage')
                        <flux:button wire:click="cancel" variant="ghost" class="w-full" x-data x-on:click="confirm('Annullera fakturan?') || $event.preventDefault()">
                            Annullera <x-permission-hint permission="accounting.manage" />
                        </flux:button>
                    @endcan
                @endif

                {{-- Approved → Posted --}}
                @if ($this->invoice->isApproved())
                    @can('accounting.manage')
                        <flux:button wire:click="post" variant="primary" class="w-full">
                            <x-bootstrap-icon name="journal-check" class="size-4" /> Bokför <x-permission-hint permission="accounting.manage" />
                        </flux:button>
                    @endcan
                @endif

                {{-- Posted → Paid --}}
                @if ($this->invoice->isPosted())
                    @can('accounting.manage')
                        <flux:button wire:click="markPaid" variant="primary" class="w-full" x-data x-on:click="confirm('Markera fakturan som betald?') || $event.preventDefault()">
                            <x-bootstrap-icon name="cash-coin" class="size-4" /> Markera som betald <x-permission-hint permission="accounting.manage" />
                        </flux:button>
                    @endcan
                @endif

                @if ($this->invoice->isPaid() || $this->invoice->isCancelled())
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

            {{-- Attachment --}}
            @can('accounting.manage')
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 p-5">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-3">
                    Dokument
                </h3>

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
                            <a href="{{ route('document.download', $doc) }}"
                               class="ml-2 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                <x-bootstrap-icon name="download" class="size-3 inline" />
                            </a>
                        </div>
                        <button wire:click="deleteDocument({{ $doc->id }})"
                                x-data x-on:click="confirm('Ta bort dokumentet?') || $event.preventDefault()"
                                class="text-zinc-300 hover:text-red-500 dark:hover:text-red-400 transition-colors shrink-0">
                            <x-bootstrap-icon name="trash" class="size-3.5" />
                        </button>
                    </div>
                @empty
                    <p class="text-xs text-zinc-400 mb-3">Inga dokument uppladdade.</p>
                @endforelse

                <div class="mt-2">
                    <input type="file" wire:model="newDocument" accept=".pdf,.png,.jpg,.jpeg"
                           class="block w-full text-xs text-zinc-500 dark:text-zinc-400
                                  file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0
                                  file:text-xs file:font-medium file:bg-zinc-100 file:text-zinc-700
                                  dark:file:bg-zinc-800 dark:file:text-zinc-300
                                  hover:file:bg-zinc-200 dark:hover:file:bg-zinc-700 cursor-pointer" />
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

    {{-- Reject modal --}}
    <flux:modal wire:model="showRejectModal" class="max-w-md">
        <div class="p-6">
            <h2 class="text-lg font-semibold mb-3">Avvisa faktura</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">Fakturan återgår till utkast-status och handläggaren meddelas.</p>
            <div>
                <flux:label for="rejectNote">Anledning (visas för handläggaren)</flux:label>
                <flux:textarea id="rejectNote" wire:model="rejectNote" rows="3"
                    placeholder="t.ex. Saknar attest från avdelningschef, felaktigt belopp…" />
                @error('rejectNote') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-3 mt-5">
                <flux:button wire:click="$set('showRejectModal', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="reject" variant="danger">Avvisa faktura</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
