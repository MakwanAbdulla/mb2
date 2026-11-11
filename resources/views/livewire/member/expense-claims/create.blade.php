<?php

use App\Models\AuditLog;
use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\CorporateCard;
use App\Models\Accounting\CostCenter;
use App\Models\Accounting\ExpenseClaim;
use App\Services\Accounting\CardReceiptMatcher;
use App\Support\StoredUpload;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Skicka in utlägg');

new class extends Component {
    use WithFileUploads;

    // Pre-fill from restlista link (?tx=123 or ?card=456)
    #[Url]
    public ?int $tx   = null;
    #[Url]
    public ?int $card = null;

    public string $claimType   = ExpenseClaim::CLAIM_TYPE_REIMBURSEMENT;
    public string $description = '';
    public string $purchasedAt = '';
    public string $amount      = '';
    public string $costCenterId    = '';
    public string $corporateCardId = '';
    public $receipt = null;

    // Pre-linked bank transaction (when coming from restlista)
    public ?int $linkedTxId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->member !== null, 403);

        $this->purchasedAt = today()->format('Y-m-d');

        // If member has a card, default to card_receipt
        $memberCard = $this->memberCard;
        if ($memberCard) {
            $this->claimType      = ExpenseClaim::CLAIM_TYPE_CARD_RECEIPT;
            $this->corporateCardId = (string) $memberCard->id;
        }

        // Override with URL params — pre-fill from restlista
        if ($this->card) {
            $c = CorporateCard::find($this->card);
            if ($c && $c->member_id === Auth::user()->member->id) {
                $this->claimType       = ExpenseClaim::CLAIM_TYPE_CARD_RECEIPT;
                $this->corporateCardId = (string) $c->id;
            }
        }

        if ($this->tx) {
            $bankTx = BankTransaction::find($this->tx);
            if ($bankTx && $bankTx->corporate_card_id
                && $bankTx->corporateCard?->member_id === Auth::user()->member->id) {
                $this->linkedTxId      = $bankTx->id;
                $this->claimType       = ExpenseClaim::CLAIM_TYPE_CARD_RECEIPT;
                $this->corporateCardId = (string) $bankTx->corporate_card_id;
                $this->purchasedAt     = $bankTx->transaction_date?->format('Y-m-d') ?? $this->purchasedAt;
                $this->amount          = number_format(abs($bankTx->amount) / 100, 2, '.', '');
                $this->description     = $bankTx->description;
            }
        }
    }

    #[Computed]
    public function memberCard(): ?CorporateCard
    {
        return CorporateCard::where('member_id', Auth::user()->member->id)
            ->where('is_active', true)
            ->first();
    }

    #[Computed]
    public function hasAnyCard(): bool
    {
        return CorporateCard::where('member_id', Auth::user()->member->id)->exists();
    }

    #[Computed]
    public function costCenters()
    {
        return CostCenter::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function linkedTransaction(): ?BankTransaction
    {
        return $this->linkedTxId ? BankTransaction::find($this->linkedTxId) : null;
    }

    public function save(): void
    {
        $member = Auth::user()->member;
        abort_unless($member !== null, 403);

        // Validate card ownership when claim_type = card_receipt
        $cardRules = $this->claimType === ExpenseClaim::CLAIM_TYPE_CARD_RECEIPT
            ? ['corporateCardId' => 'required|exists:corporate_cards,id']
            : ['corporateCardId' => 'nullable'];

        $this->validate(array_merge([
            'claimType'    => 'required|in:reimbursement,card_receipt',
            'description'  => 'required|string|max:255',
            'purchasedAt'  => 'required|date|before_or_equal:today',
            'amount'       => 'required|numeric|min:0.01|max:999999.99',
            'costCenterId' => 'nullable|exists:cost_centers,id',
            'receipt'      => 'required|file|mimes:pdf,png,jpg,jpeg|max:20480',
        ], $cardRules), [
            'description.required'        => 'Beskriv vad inköpet avsåg.',
            'purchasedAt.required'        => 'Ange inköpsdatum.',
            'purchasedAt.before_or_equal' => 'Inköpsdatumet kan inte vara i framtiden.',
            'amount.required'             => 'Ange belopp.',
            'amount.min'                  => 'Beloppet måste vara större än noll.',
            'receipt.required'            => 'Bifoga ett kvitto.',
            'receipt.mimes'               => 'Kvitto måste vara PDF, PNG eller JPEG.',
            'receipt.max'                 => 'Kvittot får inte vara större än 20 MB.',
            'corporateCardId.required'    => 'Välj vilket kort köpet gjordes med.',
        ]);

        // Verify card belongs to this member
        if ($this->corporateCardId) {
            $card = CorporateCard::find($this->corporateCardId);
            abort_unless($card && $card->member_id === $member->id, 403);
        }

        $path   = ExpenseClaim::buildReceiptPath($this->receipt->getClientOriginalExtension());
        $upload = StoredUpload::store($this->receipt, 'local', dirname($path), basename($path));

        $claim = $upload->persist(fn (StoredUpload $upload) => DB::transaction(function () use ($member, $upload) {
            $claim = ExpenseClaim::create([
                'member_id'             => $member->id,
                'claim_type'            => $this->claimType,
                'corporate_card_id'     => $this->corporateCardId ?: null,
                'bank_transaction_id'   => $this->linkedTxId,
                'description'           => trim($this->description),
                'purchased_at'          => $this->purchasedAt,
                'amount_ore'            => (int) round((float) $this->amount * 100),
                'cost_center_id'        => $this->costCenterId ?: null,
                'receipt_disk'          => $upload->disk,
                'receipt_path'          => $upload->path,
                'receipt_original_name' => $upload->originalName,
                'receipt_mime_type'     => $upload->mimeType,
                'receipt_size_bytes'    => $upload->sizeBytes,
                'status'                => ExpenseClaim::STATUS_SUBMITTED,
                'submitted_at'          => now(),
            ]);

            // Try immediate matching if this is a card receipt without a pre-linked tx
            if ($this->claimType === ExpenseClaim::CLAIM_TYPE_CARD_RECEIPT && ! $this->linkedTxId) {
                app(CardReceiptMatcher::class)->processNewClaim($claim);
            }

            // If there was a pre-linked transaction, mark it as matched now
            if ($this->linkedTxId && $claim->status === ExpenseClaim::STATUS_SUBMITTED) {
                $claim->update([
                    'status'      => ExpenseClaim::STATUS_APPROVED,
                    'reviewed_at' => now(),
                ]);
            }

            return $claim;
        }));

        AuditLog::record('expense_claim_submitted', 'accounting', $claim->description . ' (' . number_format($claim->amount_ore / 100, 2, ',', ' ') . ' kr)', $claim);

        session()->flash('success',
            $this->claimType === ExpenseClaim::CLAIM_TYPE_CARD_RECEIPT
                ? 'Kvittot är inlämnat och kopplat till korttransaktionen.'
                : 'Ditt utlägg har skickats in och väntar på granskning.'
        );

        $this->redirect(route('expense-claims.index'), navigate: true);
    }
};
?>

<div>
    <x-slot:header>
        <h1 class="text-2xl font-bold">Skicka in utlägg</h1>
    </x-slot:header>

    @if (session('error'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">
            {{ session('error') }}
        </div>
    @endif

    <div class="max-w-xl">

        {{-- Pre-linked bank transaction info banner --}}
        @if ($this->linkedTransaction)
            <div class="mb-5 flex items-start gap-3 rounded-lg border border-complement/30 bg-violet-50 dark:bg-violet-950/20 px-4 py-3 text-sm">
                <x-bootstrap-icon name="credit-card" class="size-4 text-complement-light mt-0.5 shrink-0" />
                <div>
                    <p class="font-medium text-zinc-800 dark:text-zinc-100">Kvitto till kortköp</p>
                    <p class="text-zinc-500 dark:text-zinc-400 text-xs mt-0.5">
                        {{ $this->linkedTransaction->description }}
                        @if ($this->linkedTransaction->reference)
                            &nbsp;·&nbsp;
                            <span class="font-mono">{{ $this->linkedTransaction->reference }}</span>
                        @endif
                        &nbsp;·&nbsp;
                        {{ format_ore(abs($this->linkedTransaction->amount)) }}
                        &nbsp;·&nbsp;
                        {{ $this->linkedTransaction->transaction_date?->format('d/m Y') }}
                    </p>
                </div>
            </div>
        @endif

        <p class="mb-6 text-sm text-zinc-500 dark:text-zinc-400">
            @if ($claimType === 'card_receipt')
                Bifoga kvittot för köpet med föreningskortet. Kassören behöver det som underlag för bokföringen.
            @else
                Fyll i uppgifterna och bifoga ditt kvitto. Utlägget granskas av kassören innan det betalas ut.
            @endif
        </p>

        <form wire:submit="save" class="space-y-5">

            {{-- Claim type selector (only shown if member has a card, active or not) --}}
            @if ($this->hasAnyCard)
                <div>
                    <label class="lbl">Typ</label>
                    <div class="mt-1 flex rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                        <button type="button"
                                wire:click="$set('claimType', 'card_receipt')"
                                class="flex-1 px-4 py-2 text-sm font-medium transition-colors
                                       {{ $claimType === 'card_receipt'
                                            ? 'bg-complement text-white'
                                            : 'bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700' }}">
                            <x-bootstrap-icon name="credit-card" class="size-4 inline mr-1.5" />
                            Kortkvitto
                        </button>
                        <button type="button"
                                wire:click="$set('claimType', 'reimbursement')"
                                class="flex-1 px-4 py-2 text-sm font-medium border-l border-zinc-200 dark:border-zinc-700 transition-colors
                                       {{ $claimType === 'reimbursement'
                                            ? 'bg-complement text-white'
                                            : 'bg-white dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700' }}">
                            <x-bootstrap-icon name="wallet2" class="size-4 inline mr-1.5" />
                            Utlägg (egna pengar)
                        </button>
                    </div>
                </div>
            @endif

            {{-- Description --}}
            <div>
                <label class="lbl" for="description">Vad köptes?</label>
                <flux:input
                    id="description"
                    wire:model="description"
                    placeholder="T.ex. Skyddshylsor till turneringen"
                    maxlength="255"
                />
                @error('description')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            {{-- Date + Amount --}}
            <div class="flex gap-4">
                <div class="flex-1">
                    <label class="lbl" for="purchasedAt">Inköpsdatum</label>
                    <flux:input type="date" id="purchasedAt" wire:model="purchasedAt" />
                    @error('purchasedAt')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <div class="w-40">
                    <label class="lbl" for="amount">Belopp (kr)</label>
                    <flux:input
                        type="number"
                        id="amount"
                        wire:model="amount"
                        placeholder="0.00"
                        step="0.01"
                        min="0.01"
                        :readonly="(bool) $linkedTxId"
                    />
                    @error('amount')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Cost center --}}
            @if ($this->costCenters->isNotEmpty())
                <div>
                    <label class="lbl" for="costCenterId">Kostnadsställe <span class="font-normal text-zinc-400">(valfritt)</span></label>
                    <flux:select id="costCenterId" wire:model="costCenterId" class="w-full">
                        <option value="">– Inget kostnadsställe –</option>
                        @foreach ($this->costCenters as $cc)
                            <option value="{{ $cc->id }}">{{ $cc->name }}</option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            {{-- Receipt upload --}}
            <div>
                <label class="lbl" for="receipt">Kvitto</label>
                <input
                    type="file"
                    id="receipt"
                    wire:model="receipt"
                    accept=".pdf,.png,.jpg,.jpeg"
                    class="block w-full text-sm text-zinc-700 dark:text-zinc-300
                           file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-xs file:font-medium
                           file:text-zinc-700 hover:file:bg-zinc-200
                           dark:file:bg-zinc-700 dark:file:text-zinc-300 dark:hover:file:bg-zinc-600"
                />
                <p class="mt-1 text-xs text-zinc-400">PDF, PNG eller JPEG · max 20 MB</p>
                @error('receipt')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                @if ($receipt)
                    <div wire:loading.remove wire:target="receipt"
                         class="mt-2 flex items-center gap-2 text-xs text-zinc-500">
                        <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-500" />
                        {{ $receipt->getClientOriginalName() }}
                        ({{ round($receipt->getSize() / 1024, 1) }} KB)
                    </div>
                @endif
                <div wire:loading wire:target="receipt" class="mt-2 text-xs text-zinc-400">
                    Laddar upp…
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-3 pt-2">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">
                        {{ $claimType === 'card_receipt' ? 'Skicka in kvitto' : 'Skicka in utlägg' }}
                    </span>
                    <span wire:loading wire:target="save">Skickar in…</span>
                </flux:button>
                <a href="{{ route('expense-claims.index') }}" wire:navigate class="btn btn-ghost text-sm">
                    Avbryt
                </a>
            </div>
        </form>
    </div>
</div>
