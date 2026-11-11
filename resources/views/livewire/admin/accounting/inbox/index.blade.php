<?php

use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\AccountingInboxEmail;
use App\Models\Accounting\AccountingInboxItem;
use App\Models\Accounting\AccountingInboxSender;
use App\Models\Accounting\CorporateCard;
use App\Models\Accounting\ExpenseClaim;
use App\Models\Accounting\PaymentProvider;
use App\Models\AuditLog;
use App\Services\Accounting\CardReceiptMatcher;
use App\Services\Accounting\InboxMailFetcher;
use App\Services\Accounting\PostingRuleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Fakturainkorg');

new class extends Component {

    #[Url]
    public string $tab = 'approval'; // 'inbox' | 'approval' | 'pending' | 'all' | 'claims' | 'restlista' | 'senders'

    public ?array $lastFetch = null;

    // Email viewer modal
    public ?int $viewingEmailId = null;

    // Promote-to-document modal (single item)
    public ?int   $promotingId       = null;
    public string $docType           = AccountingDocument::TYPE_INVOICE_ATTACHMENT;
    public string $docDesc           = '';
    public string $promoteProviderId = '';

    // Email-level promote (all items from one email → one document)
    // NOTE: $showEmailPromoteModal controls the flux:modal (boolean); $promotingEmailId
    // holds the actual email ID. Separating them prevents Flux from overwriting the
    // int ID when it sets the model to true/false to control open/close state.
    public bool   $showEmailPromoteModal   = false;
    public ?int   $promotingEmailId        = null;
    public string $emailDocType            = AccountingDocument::TYPE_INVOICE_ATTACHMENT;
    public string $emailDocDesc            = '';
    public string $emailPromoteProviderId  = '';
    public string $emailSuggestedAccountId = '';  // from posting rule

    // Approve modal (unknown sender → decide + optionally whitelist/blacklist)
    public ?int   $approvingId      = null;
    public bool   $addToAllowlist   = false;
    public bool   $addToBlocklist   = false;
    public string $senderLabelInput = '';

    // Ignore modal
    public ?int   $ignoringId  = null;
    public string $ignoreNote  = '';

    // Expense claim promote modal
    public ?int   $promotingClaimId  = null;
    public string $claimDocType      = AccountingDocument::TYPE_RECEIPT;
    public string $claimDocDesc      = '';

    // Expense claim reject modal
    public ?int   $rejectingClaimId = null;
    public string $claimRejectNote  = '';

    // Sender form
    public bool   $showSenderForm       = false;
    public string $senderType           = AccountingInboxSender::TYPE_ALLOWED;
    public string $senderPattern        = '';
    public string $senderLabel          = '';
    public string $senderNotes          = '';
    public string $senderPaymentProvider = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    #[Computed]
    public function emails()
    {
        return AccountingInboxEmail::with('items')->latest('received_at')->paginate(30);
    }

    #[Computed]
    public function items()
    {
        $q = AccountingInboxItem::with('paymentProvider')->latest('received_at');

        match ($this->tab) {
            'approval' => $q->where('status', AccountingInboxItem::STATUS_NEEDS_APPROVAL),
            'pending'  => $q->where('status', AccountingInboxItem::STATUS_PENDING),
            default    => null,
        };

        return $q->paginate(30);
    }

    #[Computed]
    public function viewingEmail(): ?AccountingInboxEmail
    {
        if (! $this->viewingEmailId) {
            return null;
        }

        return AccountingInboxEmail::with('items')->find($this->viewingEmailId);
    }

    #[Computed]
    public function approvalCount(): int
    {
        return AccountingInboxItem::where('status', AccountingInboxItem::STATUS_NEEDS_APPROVAL)->count();
    }

    #[Computed]
    public function pendingCount(): int
    {
        return AccountingInboxItem::where('status', AccountingInboxItem::STATUS_PENDING)->count();
    }

    #[Computed]
    public function expenseClaims()
    {
        return ExpenseClaim::with(['member', 'costCenter'])
            ->where('status', ExpenseClaim::STATUS_SUBMITTED)
            ->latest('submitted_at')
            ->get();
    }

    #[Computed]
    public function expenseClaimCount(): int
    {
        return ExpenseClaim::where('status', ExpenseClaim::STATUS_SUBMITTED)->count();
    }

    /** Restlista per kort — alla korttransaktioner utan kvitto, grupperade per kortinnehavare. */
    #[Computed]
    public function restlistaByCard(): \Illuminate\Support\Collection
    {
        return CardReceiptMatcher::allMissingByCard();
    }

    #[Computed]
    public function restlistaTotal(): int
    {
        return $this->restlistaByCard->sum('count');
    }

    #[Computed]
    public function senders()
    {
        return AccountingInboxSender::with('paymentProvider')
            ->orderBy('type')->orderByDesc('is_active')->orderBy('pattern')->get();
    }

    #[Computed]
    public function paymentProviders()
    {
        return PaymentProvider::active()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function isConfigured(): bool
    {
        return InboxMailFetcher::isConfigured();
    }

    // ── Fetch ─────────────────────────────────────────────────────────────────

    public function fetch(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        if (! $this->isConfigured) {
            session()->flash('error', 'IMAP är inte konfigurerat.');
            return;
        }

        try {
            $this->lastFetch = (new InboxMailFetcher())->fetch();
        } catch (\Throwable $e) {
            session()->flash('error', 'Fel vid hämtning: ' . $e->getMessage());
            return;
        }

        unset($this->items, $this->emails, $this->approvalCount, $this->pendingCount);

        AuditLog::record('accounting_inbox_fetched', 'accounting', 'Hämtade ny post från inkorg', null);

        // Switch to approval tab if new items need review
        if (($this->lastFetch['needs_approval'] ?? 0) > 0) {
            $this->tab = 'approval';
        }
    }

    // ── Email viewer ──────────────────────────────────────────────────────────

    public function viewEmail(int $id): void
    {
        $this->viewingEmailId = $id;
        unset($this->viewingEmail);
    }

    // ── Approve unknown sender ────────────────────────────────────────────────

    public function openApprove(int $id): void
    {
        $item = AccountingInboxItem::findOrFail($id);
        $this->approvingId      = $id;
        $this->addToAllowlist   = false;
        $this->addToBlocklist   = false;
        $this->senderLabelInput = '';
        $this->docType          = AccountingDocument::TYPE_INVOICE_ATTACHMENT;
        $this->docDesc          = $item->subject ?? $item->filename;
    }

    public function approve(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $item = AccountingInboxItem::findOrFail($this->approvingId);

        if ($this->addToAllowlist) {
            $this->createSenderEntry($item->from_email, AccountingInboxSender::TYPE_ALLOWED);
        }

        // Promote to document
        $this->promotingId = $this->approvingId;
        $this->approvingId = null;
        $this->promote();

        AuditLog::record('inbox_item_approved', 'accounting', $item->from_email . ': ' . ($item->subject ?? $item->filename), $item);
    }

    public function rejectUnknown(int $id): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $item = AccountingInboxItem::findOrFail($id);

        if ($this->addToBlocklist) {
            $this->createSenderEntry($item->from_email, AccountingInboxSender::TYPE_BLOCKED);
        }

        $item->update(['status' => AccountingInboxItem::STATUS_IGNORED]);
        AuditLog::record('inbox_item_rejected', 'accounting', $item->from_email . ': ' . ($item->subject ?? $item->filename), $item);
        $this->approvingId = null;
        unset($this->items, $this->approvalCount);
    }

    // ── Promote to AccountingDocument ─────────────────────────────────────────

    public function openPromote(int $id): void
    {
        $item = AccountingInboxItem::findOrFail($id);
        $this->promotingId       = $id;
        $this->docType           = AccountingDocument::TYPE_INVOICE_ATTACHMENT;
        $this->docDesc           = $item->subject ?? $item->filename;
        $this->promoteProviderId = (string) ($item->payment_provider_id ?? '');
    }

    public function promote(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $item = AccountingInboxItem::findOrFail($this->promotingId);

        if (! $item->fileExists()) {
            session()->flash('error', 'Filen saknas på disk.');
            $this->promotingId = null;
            return;
        }

        $ext     = strtolower(pathinfo($item->path, PATHINFO_EXTENSION)) ?: 'bin';
        $newPath = AccountingDocument::buildPath($ext);

        Storage::copy($item->path, $newPath);

        $doc = AccountingDocument::create([
            'type'          => $this->docType,
            'disk'          => 'local',
            'path'          => $newPath,
            'original_name' => $item->filename,
            'mime_type'     => $item->mime_type,
            'size_bytes'    => $item->size_bytes,
            'description'   => trim($this->docDesc) ?: null,
            'uploaded_by'   => Auth::id(),
        ]);

        $item->update([
            'status'                 => AccountingInboxItem::STATUS_LINKED,
            'accounting_document_id' => $doc->id,
            'payment_provider_id'    => $this->promoteProviderId ? (int) $this->promoteProviderId : $item->payment_provider_id,
        ]);

        AuditLog::record('inbox_item_promoted', 'accounting', $item->from_email . ': ' . ($item->filename ?? $item->subject), $doc);

        $this->promotingId       = null;
        $this->promoteProviderId = '';
        unset($this->items, $this->pendingCount, $this->approvalCount);
    }

    // ── Email-level promote ───────────────────────────────────────────────────

    public function openEmailPromote(int $emailId): void
    {
        $email = AccountingInboxEmail::with('items')->findOrFail($emailId);

        $this->promotingEmailId        = $emailId;
        $this->showEmailPromoteModal   = true;
        $this->emailDocType            = AccountingDocument::TYPE_INVOICE_ATTACHMENT;
        $this->emailDocDesc           = $email->subject ?? '';
        $this->emailPromoteProviderId = '';
        $this->emailSuggestedAccountId = '';

        // Look up first item's provider as default
        $firstItem = $email->items->where('status', 'pending')->first();
        if ($firstItem?->payment_provider_id) {
            $this->emailPromoteProviderId = (string) $firstItem->payment_provider_id;
        }

        // Try to find a matching posting rule for account suggestion
        $rule = app(\App\Services\Accounting\PostingRuleService::class)->suggestForEmail($email);
        if ($rule) {
            $this->emailSuggestedAccountId = (string) ($rule->counter_account_id ?? '');
        }
    }

    public function closeEmailPromote(): void
    {
        $this->promotingEmailId      = null;
        $this->showEmailPromoteModal = false;
    }

    public function promoteEmail(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $email = AccountingInboxEmail::with('items')->findOrFail($this->promotingEmailId);
        $items = $email->items->where('status', AccountingInboxItem::STATUS_PENDING);

        if ($items->isEmpty()) {
            session()->flash('error', 'Inga väntande bilagor att importera.');
            $this->promotingEmailId      = null;
            $this->showEmailPromoteModal = false;
            return;
        }

        // All items must exist on disk
        foreach ($items as $item) {
            if (! $item->fileExists()) {
                session()->flash('error', "Filen saknas på disk: {$item->filename}");
                $this->promotingEmailId      = null;
                $this->showEmailPromoteModal = false;
                return;
            }
        }

        // Create one AccountingDocument per item — each PDF is its own billing document
        // with its own auto-booked journal entry (rule matched per item by filename).
        $providerId  = $this->emailPromoteProviderId ? (int) $this->emailPromoteProviderId : null;
        $bookedCount = 0;
        $createdDocs = [];   // [doc => entry|null] — track for orphan linking below

        foreach ($items as $item) {
            $ext     = strtolower(pathinfo($item->path, PATHINFO_EXTENSION)) ?: 'bin';
            $docPath = AccountingDocument::buildPath($ext);

            Storage::copy($item->path, $docPath);

            $doc = AccountingDocument::create([
                'type'          => $this->emailDocType,
                'disk'          => 'local',
                'path'          => $docPath,
                'original_name' => $item->filename,
                'mime_type'     => $item->mime_type,
                'size_bytes'    => $item->size_bytes,
                'description'   => trim($this->emailDocDesc) ?: null,
                'uploaded_by'   => Auth::id(),
            ]);

            $item->update([
                'status'                 => AccountingInboxItem::STATUS_LINKED,
                'accounting_document_id' => $doc->id,
                'payment_provider_id'    => $providerId ?? $item->payment_provider_id,
            ]);

            $bookedEntry = null;

            // Auto-book: find matching rule for this specific item (matches by filename too)
            $rule = app(PostingRuleService::class)->suggestForItem($email, $item);
            if ($rule && $rule->debit_account_id && $rule->credit_account_id && $rule->series
                && $item->extracted_net_amount_ore && $item->extracted_period_start) {
                try {
                    $bookedEntry = app(PostingRuleService::class)->postFromInboxEmail(
                        email:     $email,
                        rule:      $rule,
                        amountOre: $item->extracted_net_amount_ore,
                        date:      $item->extracted_period_start,
                        createdBy: Auth::id(),
                    );
                    $doc->update(['journal_entry_id' => $bookedEntry->id]);
                    $bookedCount++;
                } catch (\Throwable $e) {
                    session()->flash('warning', "Bilagan {$item->filename} importerades men bokföringen misslyckades: " . $e->getMessage());
                }
            }

            $createdDocs[] = ['doc' => $doc, 'entry' => $bookedEntry];
        }

        // If exactly one journal entry was created in this batch, link all documents
        // that didn't trigger their own booking to it as supporting attachments.
        $bookedEntries = array_values(array_filter(array_column($createdDocs, 'entry')));
        if (count($bookedEntries) === 1) {
            $singleEntry = $bookedEntries[0];
            foreach ($createdDocs as $row) {
                if ($row['entry'] === null) {
                    $row['doc']->update(['journal_entry_id' => $singleEntry->id]);
                }
            }
        }

        AuditLog::record('inbox_email_promoted', 'accounting', ($email->from_email ?? '') . ': ' . ($email->subject ?? '') . ' (' . $items->count() . ' filer)', null);

        $this->promotingEmailId        = null;
        $this->showEmailPromoteModal   = false;
        $this->emailSuggestedAccountId = '';
        unset($this->items, $this->emails, $this->pendingCount, $this->approvalCount);
    }

    // ── Ignore ────────────────────────────────────────────────────────────────

    public function openIgnore(int $id): void
    {
        $this->ignoringId = $id;
        $this->ignoreNote = '';
    }

    public function ignore(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $ignoredItem = AccountingInboxItem::findOrFail($this->ignoringId);
        $ignoredItem->update([
            'status' => AccountingInboxItem::STATUS_IGNORED,
            'notes'  => trim($this->ignoreNote) ?: null,
        ]);

        AuditLog::record('inbox_item_ignored', 'accounting', $ignoredItem->from_email . ': ' . ($ignoredItem->subject ?? $ignoredItem->filename), $ignoredItem);

        $this->ignoringId = null;
        unset($this->items, $this->pendingCount, $this->approvalCount);
    }

    public function restore(int $id): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $restoredItem = AccountingInboxItem::findOrFail($id);
        $restoredItem->update([
            'status' => AccountingInboxItem::STATUS_PENDING,
            'notes'  => null,
        ]);

        AuditLog::record('inbox_item_restored', 'accounting', $restoredItem->from_email . ': ' . ($restoredItem->subject ?? $restoredItem->filename), $restoredItem);

        unset($this->items, $this->pendingCount);
    }

    // ── Expense claim promote ─────────────────────────────────────────────────

    public function openPromoteClaim(int $id): void
    {
        $claim = ExpenseClaim::findOrFail($id);
        $this->promotingClaimId = $id;
        $this->claimDocType     = AccountingDocument::TYPE_RECEIPT;
        $this->claimDocDesc     = $claim->description;
    }

    public function promoteClaim(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $claim = ExpenseClaim::findOrFail($this->promotingClaimId);

        if (! $claim->receiptExists()) {
            session()->flash('error', 'Kvittofilen saknas på disk.');
            $this->promotingClaimId = null;
            return;
        }

        $ext     = strtolower(pathinfo($claim->receipt_path, PATHINFO_EXTENSION)) ?: 'bin';
        $newPath = AccountingDocument::buildPath($ext);

        Storage::copy($claim->receipt_path, $newPath);

        $doc = AccountingDocument::create([
            'type'          => $this->claimDocType,
            'disk'          => 'local',
            'path'          => $newPath,
            'original_name' => $claim->receipt_original_name,
            'mime_type'     => $claim->receipt_mime_type,
            'size_bytes'    => $claim->receipt_size_bytes,
            'description'   => trim($this->claimDocDesc) ?: null,
            'uploaded_by'   => Auth::id(),
        ]);

        $claim->update([
            'status'          => ExpenseClaim::STATUS_APPROVED,
            'reviewed_by'     => Auth::id(),
            'reviewed_at'     => now(),
            'journal_entry_id' => null,
        ]);

        AuditLog::record('expense_claim_promoted', 'accounting', $claim->description . ' (' . ($claim->member->full_name ?? '–') . ')', $claim);

        $this->promotingClaimId = null;
        $this->claimDocDesc     = '';
        unset($this->expenseClaims, $this->expenseClaimCount);

        session()->flash('success', 'Utlägget godkänt och kvittot importerat som bokföringsdokument.');
    }

    // ── Expense claim reject ──────────────────────────────────────────────────

    public function openRejectClaim(int $id): void
    {
        $this->rejectingClaimId = $id;
        $this->claimRejectNote  = '';
    }

    public function rejectClaim(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $rejectedClaim = ExpenseClaim::findOrFail($this->rejectingClaimId);
        $rejectedClaim->update([
            'status'      => ExpenseClaim::STATUS_REJECTED,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'review_note' => trim($this->claimRejectNote) ?: null,
        ]);

        AuditLog::record('expense_claim_rejected', 'accounting', $rejectedClaim->description . ' (' . ($rejectedClaim->member->full_name ?? '–') . ')', $rejectedClaim);

        $this->rejectingClaimId = null;
        $this->claimRejectNote  = '';
        unset($this->expenseClaims, $this->expenseClaimCount);
    }

    // ── Sender list ───────────────────────────────────────────────────────────

    public function saveSender(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $this->validate([
            'senderPattern' => 'required|string|max:255|unique:accounting_inbox_senders,pattern',
            'senderLabel'   => 'nullable|string|max:100',
            'senderType'    => 'required|in:allowed,blocked',
        ]);

        $this->createSenderEntry(
            $this->senderPattern,
            $this->senderType,
            $this->senderLabel ?: null,
            $this->senderNotes ?: null,
            $this->senderPaymentProvider ? (int) $this->senderPaymentProvider : null,
        );

        AuditLog::record('inbox_sender_saved', 'accounting', $this->senderPattern . ' (' . $this->senderType . ')', null);

        $this->senderPattern         = '';
        $this->senderLabel           = '';
        $this->senderNotes           = '';
        $this->senderPaymentProvider = '';
        $this->showSenderForm        = false;
    }

    public function toggleSender(int $id): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        $s = AccountingInboxSender::findOrFail($id);
        $s->update(['is_active' => ! $s->is_active]);
        AuditLog::record('inbox_sender_toggled', 'accounting', $s->pattern . ' → ' . ($s->is_active ? 'aktiv' : 'inaktiv'), $s);
        unset($this->senders);
    }

    public function deleteSender(int $id): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        $sender = AccountingInboxSender::findOrFail($id);
        $senderPattern = $sender->pattern;
        $sender->delete();
        AuditLog::record('inbox_sender_deleted', 'accounting', $senderPattern, null);
        unset($this->senders);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createSenderEntry(
        string  $pattern,
        string  $type,
        ?string $label      = null,
        ?string $notes      = null,
        ?int    $providerId = null,
    ): void {
        $pattern = strtolower(trim($pattern));

        AccountingInboxSender::firstOrCreate(
            ['pattern' => $pattern],
            [
                'type'                => $type,
                'label'               => $label,
                'notes'               => $notes,
                'payment_provider_id' => $providerId,
                'is_active'           => true,
                'created_by'          => Auth::id(),
            ]
        );

        unset($this->senders);
    }
};
?>

<div>

    <div class="space-y-5">

        {{-- Config warning --}}
        @if (! $this->isConfigured)
            <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800/40 dark:bg-amber-900/20
                        px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
                <p class="font-medium">IMAP inte konfigurerat</p>
                <p class="mt-0.5 text-xs font-mono">
                    ACCOUNTING_INBOX_HOST · ACCOUNTING_INBOX_USERNAME · ACCOUNTING_INBOX_PASSWORD
                </p>
            </div>
        @endif

        {{-- Fetch result --}}
        @if ($lastFetch)
            <div class="rounded-lg border border-green-200 bg-green-50 dark:border-green-700/40 dark:bg-green-900/20
                        px-4 py-3 text-sm text-green-800 dark:text-green-300">
                <div class="flex items-center gap-2">
                    <x-bootstrap-icon name="check-circle-fill" class="size-4 shrink-0" />
                    <span>
                        {{ $lastFetch['messages'] }} meddelanden granskade —
                        <strong>{{ $lastFetch['imported'] }}</strong> importerade direkt,
                        @if ($lastFetch['needs_approval'] > 0)
                            <strong class="text-amber-700 dark:text-amber-400">
                                {{ $lastFetch['needs_approval'] }} kräver godkännande
                            </strong>,
                        @endif
                        @if ($lastFetch['skipped_duplicate'] > 0)
                            {{ $lastFetch['skipped_duplicate'] }} dubbletter,
                        @endif
                        @if ($lastFetch['skipped_blocked'] > 0)
                            {{ $lastFetch['skipped_blocked'] }} blockerade.
                        @endif
                    </span>
                </div>
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 dark:border-red-800/40 dark:bg-red-900/20
                        px-4 py-3 text-sm text-red-800 dark:text-red-300">{{ session('error') }}</div>
        @endif

        {{-- Fetch action --}}
        <div class="flex justify-end mb-4">
            <flux:button wire:click="fetch" wire:loading.attr="disabled"
                         variant="primary" size="sm">
                <span wire:loading.remove wire:target="fetch">
                    <x-bootstrap-icon name="envelope-arrow-down" class="size-3.5 inline mr-1" />
                    Hämta ny post
                </span>
                <span wire:loading wire:target="fetch">Hämtar…</span>
            </flux:button>
        </div>

        {{-- ── INBOX TAB (all received emails) ────────────────────────────── --}}
        @if ($tab === 'inbox')

            @if ($this->emails->isEmpty())
                <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 px-5 py-10 text-center text-sm text-zinc-400">
                    Inga mail mottagna ännu. Klicka "Hämta ny post" för att läsa inkorgen.
                </div>
            @else
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800 text-xs text-zinc-500 dark:text-zinc-400 uppercase tracking-wider text-left">
                            <tr>
                                <th class="px-4 py-3">Avsändare / Ämne</th>
                                <th class="px-4 py-3 w-24 text-center">Avsändare</th>
                                <th class="px-4 py-3 w-20 text-center">Bilagor</th>
                                <th class="px-4 py-3 w-28">Mottagen</th>
                                <th class="px-4 py-3 w-16"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                            @foreach ($this->emails as $email)
                                @php
                                    [$clsBadge, $clsLabel] = match ($email->sender_classification) {
                                        'allowed'  => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Betrodd'],
                                        'blocked'  => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'Blockerad'],
                                        default    => ['bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400', 'Okänd'],
                                    };
                                @endphp
                                <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40
                                           {{ $email->isBlocked() ? 'opacity-50' : '' }}">
                                    <td class="px-4 py-3 max-w-[260px]">
                                        <p class="font-medium text-zinc-700 dark:text-zinc-200 truncate text-xs">
                                            {{ $email->from_name ?: $email->from_email }}
                                        </p>
                                        @if ($email->from_name)
                                            <p class="text-xs text-zinc-400 font-mono truncate">{{ $email->from_email }}</p>
                                        @endif
                                        @if ($email->subject)
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate italic mt-0.5">
                                                {{ $email->subject }}
                                            </p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $clsBadge }}">
                                            {{ $clsLabel }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if ($email->has_attachments)
                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                                {{ $email->items->count() ?: '–' }}
                                            </span>
                                        @else
                                            <span class="text-xs text-zinc-300 dark:text-zinc-600">Inga</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-zinc-400 font-mono whitespace-nowrap">
                                        {{ $email->received_at?->format('d/m Y') ?? '–' }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-3">
                                            @if ($email->has_attachments && $email->items->where('status', 'pending')->isNotEmpty())
                                                <flux:button wire:click="openEmailPromote({{ $email->id }})"
                                                             variant="primary" size="xs">
                                                    Importera
                                                </flux:button>
                                            @endif
                                            <button wire:click="viewEmail({{ $email->id }})"
                                                    class="text-xs text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200
                                                           flex items-center gap-1">
                                                <x-bootstrap-icon name="envelope-open" class="size-3.5" />
                                                Läs
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $this->emails->links() }}
            @endif

        {{-- ── ITEMS TABLE (approval / pending / all) ──────────────────────── --}}
        @elseif ($tab !== 'senders')

            @if ($this->items->isEmpty())
                <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 px-5 py-10 text-center text-sm text-zinc-400">
                    @if ($tab === 'approval') Inga okända avsändare väntar på godkännande.
                    @elseif ($tab === 'pending') Inga dokument redo att länka.
                    @else Inga dokument importerade ännu.
                    @endif
                </div>
            @else

                @if ($tab === 'approval')
                    <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800/40 dark:bg-amber-900/10
                                px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
                        <x-bootstrap-icon name="shield-exclamation" class="size-4 inline mr-1" />
                        Dessa dokument kom från avsändare som inte finns i avsändarlistan.
                        Godkänn för att importera, eller avvisa för att ignorera.
                        Du kan också lägga till avsändaren direkt i listan.
                    </div>
                @endif

                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800 text-xs text-zinc-500 dark:text-zinc-400 uppercase tracking-wider text-left">
                            <tr>
                                <th class="px-4 py-3 w-8"></th>
                                <th class="px-4 py-3">Avsändare / Ämne</th>
                                <th class="px-4 py-3">Fil</th>
                                <th class="px-4 py-3 w-28">Mottagen</th>
                                @if ($tab === 'all')
                                    <th class="px-4 py-3 w-24 text-center">Status</th>
                                @endif
                                <th class="px-4 py-3 w-44"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                            @foreach ($this->items as $item)
                                @php
                                    $statusMap = [
                                        'needs_approval' => ['Godkänn',  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
                                        'pending'        => ['Väntande', 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'],
                                        'linked'         => ['Länkad',   'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'],
                                        'ignored'        => ['Ignorerad','bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400'],
                                    ];
                                    [$statusLabel, $statusCls] = $statusMap[$item->status] ?? ['?', ''];
                                @endphp
                                <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40">
                                    <td class="px-4 py-3">
                                        @if ($item->isPdf())
                                            <x-bootstrap-icon name="file-earmark-pdf" class="size-5 text-red-400" />
                                        @elseif ($item->isImage())
                                            <x-bootstrap-icon name="file-earmark-image" class="size-5 text-blue-400" />
                                        @else
                                            <x-bootstrap-icon name="file-earmark" class="size-5 text-zinc-400" />
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 max-w-[200px]">
                                        <p class="font-medium text-zinc-700 dark:text-zinc-200 truncate text-xs">
                                            {{ $item->from_name ?: $item->from_email }}
                                        </p>
                                        @if ($item->original_from_email && $item->original_from_email !== $item->from_email)
                                            <p class="text-xs text-zinc-400 font-mono truncate" title="Ursprunglig avsändare">
                                                ↳ {{ $item->original_from_email }}
                                            </p>
                                        @elseif ($item->from_name)
                                            <p class="text-xs text-zinc-400 font-mono truncate">{{ $item->from_email }}</p>
                                        @endif
                                        @if ($item->subject)
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate italic mt-0.5">
                                                {{ $item->subject }}
                                            </p>
                                        @endif
                                        @if ($item->paymentProvider)
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium mt-1
                                                         bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300">
                                                <x-bootstrap-icon name="credit-card" class="size-3" />
                                                {{ $item->paymentProvider->name }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-xs text-zinc-700 dark:text-zinc-300 truncate max-w-[160px]">
                                            {{ $item->filename }}
                                        </p>
                                        <p class="text-xs text-zinc-400">{{ $item->sizeForHumans() }}</p>
                                        @if ($item->extracted_doc_type)
                                            @php
                                                $docTypeBadge = match ($item->extracted_doc_type) {
                                                    'betalningsrapport'   => ['bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',   'Betalningsrapp.'],
                                                    'utbetalningsrapport' => ['bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300', 'Utbetalningsrapp.'],
                                                    'faktura'             => ['bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300', 'Faktura'],
                                                    default               => ['bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400', $item->extracted_doc_type],
                                                };
                                            @endphp
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium mt-1 {{ $docTypeBadge[0] }}">
                                                {{ $docTypeBadge[1] }}
                                            </span>
                                        @endif
                                        @if ($item->extracted_net_amount_ore !== null)
                                            <p class="text-xs font-mono font-semibold text-zinc-700 dark:text-zinc-200 mt-0.5">
                                                {{ $item->formatOre($item->extracted_net_amount_ore) }}
                                            </p>
                                        @endif
                                        @if ($item->extracted_period_start)
                                            <p class="text-xs text-zinc-400 font-mono mt-0.5">
                                                {{ $item->extracted_period_start->format('Y-m') }}
                                            </p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-zinc-400 font-mono whitespace-nowrap">
                                        {{ $item->received_at?->format('d/m Y') ?? '–' }}
                                    </td>
                                    @if ($tab === 'all')
                                        <td class="px-4 py-3 text-center">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $statusCls }}">
                                                {{ $statusLabel }}
                                            </span>
                                        </td>
                                    @endif
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            @if ($item->status === 'needs_approval')
                                                <flux:button wire:click="openApprove({{ $item->id }})"
                                                             variant="primary" size="xs">
                                                    Godkänn
                                                </flux:button>
                                                <button wire:click="openIgnore({{ $item->id }})"
                                                        class="text-xs text-zinc-400 hover:text-red-600 dark:hover:text-red-400">
                                                    Avvisa
                                                </button>
                                            @elseif ($item->status === 'pending')
                                                <flux:button wire:click="openPromote({{ $item->id }})"
                                                             variant="primary" size="xs">
                                                    Importera
                                                </flux:button>
                                                <button wire:click="openIgnore({{ $item->id }})"
                                                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                                    Ignorera
                                                </button>
                                            @elseif ($item->status === 'ignored')
                                                <button wire:click="restore({{ $item->id }})"
                                                        class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                                    Återställ
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $this->items->links() }}
            @endif

        {{-- ── UTLÄGG TAB ───────────────────────────────────────────────────── --}}
        @elseif ($tab === 'claims')

            @if ($this->expenseClaims->isEmpty())
                <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 px-5 py-10 text-center text-sm text-zinc-400">
                    Inga inlämnade utlägg väntar på granskning.
                </div>
            @else
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800 text-xs text-zinc-500 dark:text-zinc-400 uppercase tracking-wider text-left">
                            <tr>
                                <th class="px-4 py-3 w-8"></th>
                                <th class="px-4 py-3">Medlem</th>
                                <th class="px-4 py-3">Beskrivning</th>
                                <th class="px-4 py-3 w-28 text-right">Belopp</th>
                                <th class="px-4 py-3 w-28">Inköp</th>
                                <th class="px-4 py-3 w-28">Inlämnat</th>
                                <th class="px-4 py-3 w-44"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                            @foreach ($this->expenseClaims as $claim)
                                <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40">
                                    <td class="px-4 py-3">
                                        @if ($claim->isPdf())
                                            <x-bootstrap-icon name="file-earmark-pdf" class="size-5 text-red-400" />
                                        @elseif ($claim->isImage())
                                            <x-bootstrap-icon name="file-earmark-image" class="size-5 text-blue-400" />
                                        @else
                                            <x-bootstrap-icon name="file-earmark" class="size-5 text-zinc-400" />
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-xs font-medium text-zinc-700 dark:text-zinc-200">
                                            {{ $claim->member->full_name ?? '–' }}
                                        </p>
                                        @if ($claim->costCenter)
                                            <span class="text-xs text-zinc-400">{{ $claim->costCenter->name }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-xs text-zinc-700 dark:text-zinc-300 max-w-[200px] truncate">
                                            {{ $claim->description }}
                                        </p>
                                        <p class="text-xs text-zinc-400">{{ $claim->sizeForHumans() }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono text-xs tabular-nums font-semibold text-zinc-700 dark:text-zinc-200">
                                        {{ format_ore($claim->amount_ore) }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-zinc-400 font-mono whitespace-nowrap">
                                        {{ $claim->purchased_at->format('d/m Y') }}
                                    </td>
                                    <td class="px-4 py-3 text-xs text-zinc-400 font-mono whitespace-nowrap">
                                        {{ $claim->submitted_at?->format('d/m Y') ?? '–' }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="{{ route('expense-claims.receipt.inline', $claim) }}"
                                               target="_blank"
                                               class="text-xs text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 flex items-center gap-1">
                                                <x-bootstrap-icon name="eye" class="size-3.5" />
                                                Kvitto
                                            </a>
                                            <flux:button wire:click="openPromoteClaim({{ $claim->id }})"
                                                         variant="primary" size="xs">
                                                Godkänn
                                            </flux:button>
                                            <button wire:click="openRejectClaim({{ $claim->id }})"
                                                    class="text-xs text-zinc-400 hover:text-red-600 dark:hover:text-red-400">
                                                Avvisa
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        {{-- ── RESTLISTA TAB ────────────────────────────────────────────────── --}}
        @elseif ($tab === 'restlista')

            @if ($this->restlistaByCard->isEmpty())
                <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 px-5 py-10 text-center text-sm text-zinc-400">
                    <x-bootstrap-icon name="check-circle-fill" class="mx-auto mb-2 size-8 text-green-400" />
                    Alla kortköp har kvitton. Inga saknade.
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($this->restlistaByCard as $row)
                        @php $card = $row['card']; @endphp
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                            {{-- Card header --}}
                            <div class="flex items-center justify-between px-4 py-3 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                                <div class="flex items-center gap-3">
                                    <x-bootstrap-icon name="credit-card" class="size-4 text-zinc-400" />
                                    <div>
                                        <span class="font-mono font-semibold text-sm text-zinc-800 dark:text-zinc-100">
                                            {{ $card->card_reference }}
                                        </span>
                                        @if ($card->label)
                                            <span class="ml-1.5 text-xs text-zinc-400">{{ $card->label }}</span>
                                        @endif
                                    </div>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $card->member->full_name }}
                                    </span>
                                </div>
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold
                                             bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">
                                    <x-bootstrap-icon name="exclamation-circle-fill" class="size-3" />
                                    {{ $row['count'] }} saknar kvitto
                                </span>
                            </div>

                            {{-- Transactions without receipts --}}
                            <table class="w-full text-sm">
                                <thead class="text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    <tr>
                                        <th class="px-4 py-2 w-28">Datum</th>
                                        <th class="px-4 py-2">Butik</th>
                                        <th class="px-4 py-2 w-28 text-right">Belopp</th>
                                        <th class="px-4 py-2 w-40"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                                    @foreach (CardReceiptMatcher::missingReceiptsQuery($card)->get() as $tx)
                                        <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40">
                                            <td class="px-4 py-2.5 text-xs font-mono text-zinc-500 whitespace-nowrap">
                                                {{ $tx->transaction_date?->format('d/m Y') ?? '–' }}
                                            </td>
                                            <td class="px-4 py-2.5 text-xs text-zinc-700 dark:text-zinc-300">
                                                {{ $tx->description }}
                                            </td>
                                            <td class="px-4 py-2.5 text-right font-mono text-xs font-semibold text-zinc-800 dark:text-zinc-100 tabular-nums">
                                                {{ format_ore(abs($tx->amount)) }}
                                            </td>
                                            <td class="px-4 py-2.5 text-right">
                                                <a href="{{ route('admin.accounting.bank-transactions.show', $tx) }}"
                                                   wire:navigate
                                                   class="text-xs text-complement-light hover:underline">
                                                    Visa transaktion →
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
            @endif

        {{-- ── SENDERS TAB ─────────────────────────────────────────────────── --}}
        @else
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
                <div class="px-5 py-3 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                    <div>
                        <h2 class="font-semibold text-sm">Avsändarlista</h2>
                        <p class="text-xs text-zinc-400 mt-0.5">
                            Mönster: <code class="font-mono">faktura@foo.se</code> (exakt) eller
                            <code class="font-mono">@foo.se</code> (hel domän).
                            Okända avsändare hamnar alltid i godkännandeflödet.
                        </p>
                    </div>
                    <flux:button wire:click="$set('showSenderForm', true)" variant="ghost" size="sm">
                        + Lägg till
                    </flux:button>
                </div>

                @if ($showSenderForm)
                    <div class="px-5 py-4 border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50">
                        <div class="grid gap-3 sm:grid-cols-5">
                            <div>
                                <label class="lbl">Typ</label>
                                <select wire:model="senderType" class="inp mt-1 w-full text-sm">
                                    <option value="allowed">Betrodd (auto)</option>
                                    <option value="blocked">Blockerad</option>
                                </select>
                            </div>
                            <div>
                                <label class="lbl">Mönster *</label>
                                <input type="text"
                                       wire:model="senderPattern"
                                       placeholder="@fortnox.se"
                                       class="inp mt-1 w-full text-sm font-mono" />
                                @error('senderPattern')
                                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label class="lbl">Namn</label>
                                <input type="text" wire:model="senderLabel"
                                       placeholder="Fortnox AB"
                                       class="inp mt-1 w-full text-sm" />
                            </div>
                            <div>
                                <label class="lbl">Betaltjänst</label>
                                <select wire:model="senderPaymentProvider" class="inp mt-1 w-full text-sm">
                                    <option value="">–</option>
                                    @foreach ($this->paymentProviders as $pp)
                                        <option value="{{ $pp->id }}">{{ $pp->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="lbl">Anteckning</label>
                                <input type="text" wire:model="senderNotes"
                                       class="inp mt-1 w-full text-sm" />
                            </div>
                        </div>
                        <div class="flex gap-2 mt-3">
                            <flux:button wire:click="saveSender" variant="primary" size="sm">Spara</flux:button>
                            <flux:button wire:click="$set('showSenderForm', false)" variant="ghost" size="sm">Avbryt</flux:button>
                        </div>
                    </div>
                @endif

                @if ($this->senders->isEmpty())
                    <div class="px-5 py-8 text-center text-sm text-zinc-400">
                        Inga avsändare tillagda — alla okända avsändare kräver manuellt godkännande.
                    </div>
                @else
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($this->senders as $sender)
                                @php
                                    $typeStyle = $sender->isAllowed()
                                        ? 'text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20'
                                        : 'text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20';
                                    $typeLabel = $sender->isAllowed() ? 'Betrodd' : 'Blockerad';
                                @endphp
                                <tr class="{{ $sender->is_active ? '' : 'opacity-40' }}">
                                    <td class="px-5 py-3 w-28">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $typeStyle }}">
                                            {{ $typeLabel }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="flex items-center gap-2">
                                            <x-bootstrap-icon
                                                name="{{ $sender->isDomainPattern() ? 'globe' : 'envelope' }}"
                                                class="size-3.5 text-zinc-400 shrink-0" />
                                            <span class="font-mono text-sm text-zinc-700 dark:text-zinc-200">
                                                {{ $sender->pattern }}
                                            </span>
                                            @if ($sender->label)
                                                <span class="text-zinc-400 text-xs">— {{ $sender->label }}</span>
                                            @endif
                                        </div>
                                        @if ($sender->notes)
                                            <p class="text-xs text-zinc-400 mt-0.5 ml-5">{{ $sender->notes }}</p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3">
                                        @if ($sender->paymentProvider)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium
                                                         bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300">
                                                <x-bootstrap-icon name="credit-card" class="size-3" />
                                                {{ $sender->paymentProvider->name }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <div class="flex items-center justify-end gap-3">
                                            <button wire:click="toggleSender({{ $sender->id }})"
                                                    class="text-xs {{ $sender->is_active ? 'text-zinc-400 hover:text-zinc-600' : 'text-green-600 hover:text-green-700' }}">
                                                {{ $sender->is_active ? 'Inaktivera' : 'Aktivera' }}
                                            </button>
                                            <button wire:click="deleteSender({{ $sender->id }})"
                                                    wire:confirm="Ta bort {{ $sender->pattern }}?"
                                                    class="text-xs text-zinc-300 hover:text-red-600 dark:text-zinc-600 dark:hover:text-red-400">
                                                Ta bort
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif

    </div>

    {{-- ── Email viewer modal ────────────────────────────────────────────────── --}}
    <flux:modal wire:model.boolean="viewingEmailId" class="max-w-2xl" @close="viewingEmailId = null">
        @if ($this->viewingEmail)
            @php $ve = $this->viewingEmail; @endphp
            <div class="p-5 space-y-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold leading-tight">
                            {{ $ve->subject ?: '(inget ämne)' }}
                        </h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                            <span class="font-medium text-zinc-700 dark:text-zinc-200">
                                {{ $ve->senderLabel() }}
                            </span>
                            @if ($ve->received_at)
                                · {{ $ve->received_at->format('d/m Y H:i') }}
                            @endif
                        </p>
                    </div>
                    @php
                        [$veBadgeCls, $veBadgeLabel] = match ($ve->sender_classification) {
                            'allowed' => ['bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', 'Betrodd avsändare'],
                            'blocked' => ['bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', 'Blockerad avsändare'],
                            default   => ['bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400', 'Okänd avsändare'],
                        };
                    @endphp
                    <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $veBadgeCls }}">
                        {{ $veBadgeLabel }}
                    </span>
                </div>

                {{-- Body text --}}
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50
                            px-4 py-3 max-h-72 overflow-y-auto">
                    @if ($ve->body_text)
                        <pre class="text-xs text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap font-sans leading-relaxed">{{ $ve->body_text }}</pre>
                    @elseif ($ve->body_html)
                        <p class="text-xs text-zinc-400 italic">
                            Mailet har bara HTML-innehåll (visas ej av säkerhetsskäl).
                        </p>
                    @else
                        <p class="text-xs text-zinc-400 italic">Inget textinnehåll.</p>
                    @endif
                </div>

                {{-- Linked attachment items --}}
                @if ($ve->items->isNotEmpty())
                    <div>
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1.5">
                            Bilagor importerade från detta mail
                        </p>
                        <div class="space-y-1">
                            @foreach ($ve->items as $vi)
                                @php
                                    $statusMap = [
                                        'needs_approval' => ['Godkänn',  'text-amber-600 dark:text-amber-400'],
                                        'pending'        => ['Väntande', 'text-blue-600 dark:text-blue-400'],
                                        'linked'         => ['Länkad',   'text-green-600 dark:text-green-400'],
                                        'ignored'        => ['Ignorerad','text-zinc-400'],
                                    ];
                                    [$viLabel, $viCls] = $statusMap[$vi->status] ?? ['?', ''];
                                @endphp
                                <div class="flex items-center justify-between rounded bg-white dark:bg-zinc-900
                                            border border-zinc-100 dark:border-zinc-800 px-3 py-1.5 text-xs">
                                    <div class="flex items-center gap-2">
                                        @if ($vi->isPdf())
                                            <x-bootstrap-icon name="file-earmark-pdf" class="size-4 text-red-400 shrink-0" />
                                        @elseif ($vi->isImage())
                                            <x-bootstrap-icon name="file-earmark-image" class="size-4 text-blue-400 shrink-0" />
                                        @else
                                            <x-bootstrap-icon name="file-earmark" class="size-4 text-zinc-400 shrink-0" />
                                        @endif
                                        <span class="text-zinc-700 dark:text-zinc-300">{{ $vi->filename }}</span>
                                        <span class="text-zinc-400">{{ $vi->sizeForHumans() }}</span>
                                    </div>
                                    <span class="font-medium {{ $viCls }}">{{ $viLabel }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @elseif (! $ve->has_attachments)
                    <p class="text-xs text-zinc-400 italic">
                        Detta mail hade inga bilagor.
                    </p>
                @elseif ($ve->isBlocked())
                    <p class="text-xs text-zinc-400 italic">
                        Bilagor importerades inte — avsändaren var blockerad vid mottagning.
                    </p>
                @endif

                <div class="flex justify-end">
                    <flux:button wire:click="$set('viewingEmailId', null)" variant="ghost" size="sm">Stäng</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- ── Approve modal ─────────────────────────────────────────────────────── --}}
    <flux:modal wire:model.boolean="approvingId" class="max-w-lg">
        @if ($approvingId)
            @php $item = AccountingInboxItem::find($approvingId); @endphp
            @if ($item)
                <div class="p-5 space-y-4">
                    <h2 class="text-base font-semibold">Godkänn dokument från okänd avsändare</h2>

                    <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 px-3 py-2 text-xs space-y-0.5">
                        <p><span class="text-zinc-400">Från:</span> {{ $item->senderLabel() }}</p>
                        <p><span class="text-zinc-400">Ämne:</span> {{ $item->subject ?: '–' }}</p>
                        <p><span class="text-zinc-400">Fil:</span> {{ $item->filename }} ({{ $item->sizeForHumans() }})</p>
                    </div>

                    <div>
                        <label class="lbl">Dokumenttyp</label>
                        <select wire:model="docType" class="inp mt-1 w-full text-sm">
                            <option value="{{ AccountingDocument::TYPE_INVOICE_ATTACHMENT }}">Fakturakopia</option>
                            <option value="{{ AccountingDocument::TYPE_RECEIPT }}">Kvitto</option>
                            <option value="{{ AccountingDocument::TYPE_CONTRACT }}">Avtal</option>
                            <option value="{{ AccountingDocument::TYPE_OTHER }}">Övrigt</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Beskrivning</label>
                        <input type="text" wire:model="docDesc" maxlength="500" class="inp mt-1 w-full text-sm" />
                    </div>

                    <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 px-3 py-3 space-y-2">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Lägg till i avsändarlistan</p>
                        <label class="flex items-center gap-2 cursor-pointer text-sm">
                            <input type="checkbox" wire:model="addToAllowlist"
                                   class="rounded border-zinc-300 dark:border-zinc-600" />
                            <span>Lägg till <span class="font-mono text-xs">{{ $item->from_email }}</span> som betrodd</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="$set('approvingId', null)" variant="ghost">Avbryt</flux:button>
                        <flux:button wire:click="approve" variant="primary">Godkänn och importera</flux:button>
                    </div>
                </div>
            @endif
        @endif
    </flux:modal>

    {{-- ── Email-level promote modal ─────────────────────────────────────────── --}}
    <flux:modal wire:model.boolean="showEmailPromoteModal" class="max-w-lg">
        @if ($promotingEmailId)
            @php $pemail = AccountingInboxEmail::with('items')->find($promotingEmailId); @endphp
            @if ($pemail)
                @php $pendingItems = $pemail->items->where('status', 'pending'); @endphp
                <div class="p-5 space-y-4">
                    <h2 class="text-base font-semibold">Importera som verifikat</h2>

                    {{-- File list --}}
                    <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 px-3 py-2 text-xs space-y-1.5">
                        <p class="text-zinc-400 font-medium">Bilagor ({{ $pendingItems->count() }} st):</p>
                        @foreach ($pendingItems as $pi)
                            <div class="flex items-center gap-2 flex-wrap">
                                @if ($pi->isPdf())
                                    <x-bootstrap-icon name="file-earmark-pdf" class="size-4 text-red-400 shrink-0" />
                                @else
                                    <x-bootstrap-icon name="file-earmark-image" class="size-4 text-blue-400 shrink-0" />
                                @endif
                                <span class="font-mono text-zinc-600 dark:text-zinc-300 truncate">{{ $pi->filename }}</span>
                                <span class="text-zinc-400 shrink-0">{{ $pi->sizeForHumans() }}</span>
                                @if ($pi->extracted_net_amount_ore !== null)
                                    <span class="font-mono font-semibold text-zinc-700 dark:text-zinc-200 shrink-0">
                                        {{ $pi->formatOre($pi->extracted_net_amount_ore) }}
                                    </span>
                                @endif
                                @if ($pi->extracted_period_start)
                                    <span class="text-zinc-400 shrink-0 font-mono">
                                        {{ $pi->extracted_period_start->format('Y-m') }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                        @php
                            // Build per-item rule matches for auto-booking preview
                            $peService = app(\App\Services\Accounting\PostingRuleService::class);
                            $autoBookableItems = $pendingItems->map(function ($pi) use ($pemail, $peService) {
                                $rule = $peService->suggestForItem($pemail, $pi);
                                $canBook = $rule
                                    && $rule->debit_account_id
                                    && $rule->credit_account_id
                                    && $rule->series
                                    && $pi->extracted_net_amount_ore
                                    && $pi->extracted_period_start;
                                return ['item' => $pi, 'rule' => $rule, 'canBook' => $canBook];
                            })->filter(fn ($r) => $r['canBook']);
                            $bookedPreviewCount = $autoBookableItems->count();
                        @endphp
                        @if ($bookedPreviewCount > 0)
                            <p class="text-zinc-400 mt-1 pt-1 border-t border-zinc-100 dark:border-zinc-800 text-xs flex items-center gap-1">
                                <x-bootstrap-icon name="check-circle" class="size-3 text-green-500 shrink-0" />
                                {{ $bookedPreviewCount }} av {{ $pendingItems->count() }} filer bokförs automatiskt
                            </p>
                        @else
                            <p class="text-zinc-400 mt-1 pt-1 border-t border-zinc-100 dark:border-zinc-800">
                                Varje fil skapar ett eget bilage-dokument.
                            </p>
                        @endif
                    </div>

                    @if ($bookedPreviewCount > 0)
                        <div class="rounded-lg border border-green-200 bg-green-50
                                    dark:border-green-700/40 dark:bg-green-900/10 px-3 py-2 text-xs space-y-2">
                            <p class="font-medium text-green-800 dark:text-green-300 flex items-center gap-1.5">
                                <x-bootstrap-icon name="check-circle" class="size-3.5 shrink-0" />
                                Verifikat skapas automatiskt per fil
                            </p>
                            @foreach ($autoBookableItems as $abi)
                                @php $abItem = $abi['item']; $abRule = $abi['rule']; @endphp
                                <div class="font-mono text-green-700 dark:text-green-400 space-y-0.5 pl-5 pb-1 border-b border-green-100 dark:border-green-800/40 last:border-0 last:pb-0">
                                    <p class="font-sans text-zinc-500 dark:text-zinc-400 mb-0.5 truncate">{{ $abItem->filename }}</p>
                                    <p>
                                        <span class="w-14 inline-block text-zinc-400">Debet</span>
                                        {{ $abRule->debitAccount->code }} {{ $abRule->debitAccount->name }}
                                        — {{ $abItem->formatOre($abItem->extracted_net_amount_ore) }}
                                    </p>
                                    <p>
                                        <span class="w-14 inline-block text-zinc-400">Kredit</span>
                                        {{ $abRule->creditAccount->code }} {{ $abRule->creditAccount->name }}
                                        — {{ $abItem->formatOre($abItem->extracted_net_amount_ore) }}
                                    </p>
                                    <p class="text-zinc-400 font-sans">
                                        Datum: {{ $abItem->extracted_period_start->format('Y-m-d') }}
                                        · Serie {{ $abRule->series }}
                                        @if ($abRule->costCenter) · {{ $abRule->costCenter->code }} @endif
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div>
                        <label class="lbl">Dokumenttyp</label>
                        <select wire:model="emailDocType" class="inp mt-1 w-full text-sm">
                            <option value="{{ AccountingDocument::TYPE_INVOICE_ATTACHMENT }}">Fakturakopia</option>
                            <option value="{{ AccountingDocument::TYPE_RECEIPT }}">Kvitto</option>
                            <option value="{{ AccountingDocument::TYPE_CONTRACT }}">Avtal</option>
                            <option value="{{ AccountingDocument::TYPE_OTHER }}">Övrigt</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Beskrivning</label>
                        <input type="text" wire:model="emailDocDesc" maxlength="500" class="inp mt-1 w-full text-sm" />
                    </div>
                    <div>
                        <label class="lbl">Betaltjänst</label>
                        <select wire:model="emailPromoteProviderId" class="inp mt-1 w-full text-sm">
                            <option value="">– Ingen / okänd –</option>
                            @foreach ($this->paymentProviders as $pp)
                                <option value="{{ $pp->id }}">{{ $pp->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="closeEmailPromote" variant="ghost">Avbryt</flux:button>
                        <flux:button wire:click="promoteEmail" variant="primary">
                            Importera {{ $pendingItems->count() }}
                            {{ $pendingItems->count() === 1 ? 'fil' : 'filer' }}
                        </flux:button>
                    </div>
                </div>
            @endif
        @endif
    </flux:modal>

    {{-- ── Promote modal ─────────────────────────────────────────────────────── --}}
    <flux:modal wire:model.boolean="promotingId" class="max-w-md">
        @if ($promotingId)
            @php $pitem = AccountingInboxItem::find($promotingId); @endphp
            @if ($pitem)
                <div class="p-5 space-y-4">
                    <h2 class="text-base font-semibold">Importera dokument</h2>
                    <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 px-3 py-2 text-xs space-y-0.5">
                        <p><span class="text-zinc-400">Från:</span> {{ $pitem->senderLabel() }}</p>
                        <p><span class="text-zinc-400">Fil:</span> {{ $pitem->filename }} ({{ $pitem->sizeForHumans() }})</p>
                        @if ($pitem->extracted_doc_type || $pitem->extracted_net_amount_ore !== null)
                            <p class="pt-1 flex items-center gap-2 flex-wrap">
                                @if ($pitem->extracted_doc_type)
                                    <span class="font-medium text-zinc-600 dark:text-zinc-300 capitalize">
                                        {{ $pitem->extracted_doc_type }}
                                    </span>
                                @endif
                                @if ($pitem->extracted_net_amount_ore !== null)
                                    <span class="font-mono font-semibold text-zinc-700 dark:text-zinc-200">
                                        {{ $pitem->formatOre($pitem->extracted_net_amount_ore) }}
                                    </span>
                                @endif
                                @if ($pitem->extracted_period_start)
                                    <span class="text-zinc-400 font-mono">
                                        {{ $pitem->extracted_period_start->format('Y-m') }}
                                    </span>
                                @endif
                            </p>
                        @endif
                    </div>
                    <div>
                        <label class="lbl">Dokumenttyp</label>
                        <select wire:model="docType" class="inp mt-1 w-full text-sm">
                            <option value="{{ AccountingDocument::TYPE_INVOICE_ATTACHMENT }}">Fakturakopia</option>
                            <option value="{{ AccountingDocument::TYPE_RECEIPT }}">Kvitto</option>
                            <option value="{{ AccountingDocument::TYPE_CONTRACT }}">Avtal</option>
                            <option value="{{ AccountingDocument::TYPE_OTHER }}">Övrigt</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Beskrivning</label>
                        <input type="text" wire:model="docDesc" maxlength="500" class="inp mt-1 w-full text-sm" />
                    </div>
                    <div>
                        <label class="lbl">Betaltjänst</label>
                        <select wire:model="promoteProviderId" class="inp mt-1 w-full text-sm">
                            <option value="">– Ingen / okänd –</option>
                            @foreach ($this->paymentProviders as $pp)
                                <option value="{{ $pp->id }}">{{ $pp->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-zinc-400 mt-1">
                            Kopplar dokumentet till rätt betaltjänst i bokföringen.
                        </p>
                    </div>
                    <div class="flex justify-end gap-3">
                        <flux:button wire:click="$set('promotingId', null)" variant="ghost">Avbryt</flux:button>
                        <flux:button wire:click="promote" variant="primary">Importera</flux:button>
                    </div>
                </div>
            @endif
        @endif
    </flux:modal>

    {{-- ── Expense claim promote modal ──────────────────────────────────────── --}}
    <flux:modal wire:model.boolean="promotingClaimId" class="max-w-md">
        @if ($promotingClaimId)
            @php $pclaim = ExpenseClaim::with('member')->find($promotingClaimId); @endphp
            @if ($pclaim)
                <div class="p-5 space-y-4">
                    <h2 class="text-base font-semibold">Godkänn utlägg</h2>

                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3 text-sm space-y-1">
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Medlem</span>
                            <span class="font-medium">{{ $pclaim->member->full_name ?? '–' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Beskrivning</span>
                            <span class="font-medium">{{ $pclaim->description }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Belopp</span>
                            <span class="font-mono font-semibold">{{ format_ore($pclaim->amount_ore) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Inköpsdatum</span>
                            <span class="font-mono">{{ $pclaim->purchased_at->format('d/m Y') }}</span>
                        </div>
                    </div>

                    <div>
                        <label class="lbl">Dokumenttyp</label>
                        <select wire:model="claimDocType" class="inp mt-1 w-full text-sm">
                            <option value="{{ AccountingDocument::TYPE_RECEIPT }}">Kvitto</option>
                            <option value="{{ AccountingDocument::TYPE_INVOICE_ATTACHMENT }}">Fakturabilaga</option>
                            <option value="{{ AccountingDocument::TYPE_CONTRACT }}">Avtal</option>
                            <option value="{{ AccountingDocument::TYPE_OTHER }}">Övrigt</option>
                        </select>
                    </div>

                    <div>
                        <label class="lbl">Beskrivning (visas på dokumentet)</label>
                        <input type="text" wire:model="claimDocDesc"
                               class="inp mt-1 w-full text-sm"
                               placeholder="{{ $pclaim->description }}" />
                    </div>

                    <p class="text-xs text-zinc-400">
                        Kvittofilen kopieras till bokföringsdokument. Utlägget markeras som godkänt
                        och medlemmen kan se statusen i sitt konto.
                    </p>

                    <div class="flex justify-end gap-2 pt-1">
                        <flux:button wire:click="$set('promotingClaimId', null)" variant="ghost">Avbryt</flux:button>
                        <flux:button wire:click="promoteClaim" variant="primary">Godkänn och importera</flux:button>
                    </div>
                </div>
            @endif
        @endif
    </flux:modal>

    {{-- ── Expense claim reject modal ───────────────────────────────────────── --}}
    <flux:modal wire:model.boolean="rejectingClaimId" class="max-w-sm">
        @if ($rejectingClaimId)
            @php $rclaim = ExpenseClaim::with('member')->find($rejectingClaimId); @endphp
            <div class="p-5 space-y-4">
                <h2 class="text-base font-semibold">Avvisa utlägg</h2>
                @if ($rclaim)
                    <p class="text-sm text-zinc-500">
                        {{ $rclaim->member->full_name ?? '–' }} — {{ $rclaim->description }}
                        ({{ format_ore($rclaim->amount_ore) }})
                    </p>
                @endif
                <div>
                    <label class="lbl">Anledning <span class="font-normal text-zinc-400">(visas för medlemmen)</span></label>
                    <textarea wire:model="claimRejectNote"
                              rows="3"
                              class="inp mt-1 w-full text-sm"
                              placeholder="T.ex. Saknar tydligt kvitto, eller kostnaden ryms inte i budget."></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('rejectingClaimId', null)" variant="ghost">Avbryt</flux:button>
                    <flux:button wire:click="rejectClaim" variant="danger">Avvisa</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- ── Ignore modal ──────────────────────────────────────────────────────── --}}
    <flux:modal wire:model.boolean="ignoringId" class="max-w-sm">
        @if ($ignoringId)
            @php $iitem = AccountingInboxItem::find($ignoringId); @endphp
            <div class="p-5 space-y-4">
                <h2 class="text-base font-semibold">
                    {{ $iitem?->status === 'needs_approval' ? 'Avvisa dokument' : 'Ignorera dokument' }}
                </h2>

                @if ($iitem?->status === 'needs_approval')
                    <div class="rounded-lg border border-zinc-100 dark:border-zinc-800 px-3 py-3 space-y-2">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Lägg till i avsändarlistan</p>
                        <label class="flex items-center gap-2 cursor-pointer text-sm">
                            <input type="checkbox" wire:model="addToBlocklist"
                                   class="rounded border-zinc-300 dark:border-zinc-600" />
                            <span>Blockera <span class="font-mono text-xs">{{ $iitem->from_email }}</span> automatiskt</span>
                        </label>
                    </div>
                @endif

                <div>
                    <label class="lbl">Anteckning <span class="text-zinc-400 font-normal">(valfritt)</span></label>
                    <input type="text" wire:model="ignoreNote" placeholder="Spam, dubblettfaktura, …"
                           class="inp mt-1 w-full text-sm" />
                </div>
                <div class="flex justify-end gap-3">
                    <flux:button wire:click="$set('ignoringId', null)" variant="ghost">Avbryt</flux:button>
                    <flux:button wire:click="{{ $iitem?->status === 'needs_approval' ? 'rejectUnknown(' . ($ignoringId ?? 0) . ')' : 'ignore()' }}"
                                 variant="ghost" class="text-zinc-500">
                        {{ $iitem?->status === 'needs_approval' ? 'Avvisa' : 'Ignorera' }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
