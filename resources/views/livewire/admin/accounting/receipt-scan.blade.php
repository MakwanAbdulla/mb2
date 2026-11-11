<?php

use App\Models\Accounting\Account;
use App\Models\Accounting\PostingRule;
use App\Models\Accounting\Vendor;
use App\Services\Accounting\ReceiptScanner;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('AI-kvittoskanning');

new class extends Component {
    use WithFileUploads;

    public $file = null;

    // 'upload' → 'scanning' → 'result'
    public string $state = 'upload';

    // Raw scanner output
    public ?array $scanResult = null;

    // Matched vendor (id only to avoid Livewire serialization issues)
    public ?int $matchedVendorId = null;

    // Source of the account suggestion: 'posting_rule' | 'vendor_default' | 'ai'
    public string $accountSource = 'ai';

    // Editable fields after scan
    public string $editDate        = '';
    public string $editDescription = '';
    public string $editAmountKr    = '';
    public ?int   $editAccountId   = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    public function scan(): void
    {
        $this->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf'],
        ], [
            'file.required' => 'Välj en fil att skanna.',
            'file.max'      => 'Filen får max vara 10 MB.',
            'file.mimes'    => 'Tillåtna format: JPEG, PNG, GIF, WEBP, PDF.',
        ]);

        $path    = $this->file->getRealPath();
        $mime    = $this->file->getMimeType();
        $scanner = new ReceiptScanner();
        $result  = $scanner->scan($path, $mime);

        $this->scanResult = $result;

        if ($result['error']) {
            $this->addError('file', $result['error']);
            return;
        }

        // Populate base fields from AI
        $this->editDate        = $result['date'] ?? '';
        $this->editDescription = $result['description'] ?? '';
        $this->editAccountId   = $result['suggested_account_id'];
        $this->accountSource   = 'ai';

        if ($result['amount_ore'] !== null) {
            $this->editAmountKr = number_format($result['amount_ore'] / 100, 2, ',', ' ');
        }

        // Try to match vendor and override account suggestion
        if (! empty($result['vendor'])) {
            $vendor = $this->findVendor($result['vendor']);

            if ($vendor) {
                $this->matchedVendorId = $vendor->id;

                // 1. Check vendor's AP invoice posting rules
                $rule = PostingRule::active()
                    ->forTrigger(PostingRule::TRIGGER_AP_INVOICE)
                    ->where('vendor_id', $vendor->id)
                    ->whereNotNull('debit_account_id')
                    ->orderBy('sort_order')
                    ->first();

                if ($rule) {
                    $this->editAccountId = $rule->debit_account_id;
                    $this->accountSource = 'posting_rule';
                } elseif ($vendor->default_account_id) {
                    // 2. Vendor default account
                    $this->editAccountId = $vendor->default_account_id;
                    $this->accountSource = 'vendor_default';
                }
            }
        }

        $this->state = 'result';
    }

    public function startOver(): void
    {
        $this->state           = 'upload';
        $this->file            = null;
        $this->scanResult      = null;
        $this->matchedVendorId = null;
        $this->accountSource   = 'ai';
        $this->editDate        = '';
        $this->editDescription = '';
        $this->editAmountKr    = '';
        $this->editAccountId   = null;
        $this->resetErrorBag();
    }

    public function createEntry(): void
    {
        $this->validate([
            'editDate'        => ['required', 'date'],
            'editDescription' => ['required', 'string', 'max:255'],
            'editAmountKr'    => ['required'],
            'editAccountId'   => ['required', 'integer', 'exists:accounts,id'],
        ], [
            'editDate.required'        => 'Datum krävs.',
            'editDate.date'            => 'Ogiltigt datum.',
            'editDescription.required' => 'Beskrivning krävs.',
            'editAmountKr.required'    => 'Belopp krävs.',
            'editAccountId.required'   => 'Välj ett konto.',
            'editAccountId.exists'     => 'Ogiltigt konto.',
        ]);

        $amountOre = (int) round(
            (float) str_replace([' ', ','], ['', '.'], $this->editAmountKr) * 100
        );

        // Credit account: from matching posting rule, or 2440 as default
        $creditAccountId = null;
        if ($this->matchedVendorId && $this->accountSource === 'posting_rule') {
            $rule = PostingRule::active()
                ->forTrigger(PostingRule::TRIGGER_AP_INVOICE)
                ->where('vendor_id', $this->matchedVendorId)
                ->whereNotNull('debit_account_id')
                ->orderBy('sort_order')
                ->first();
            $creditAccountId = $rule?->credit_account_id ?? $rule?->counter_account_id;
        }

        $creditAccountId ??= Account::where('code', '2440')->value('id');

        $formatted = number_format($amountOre / 100, 2, ',', ' ');

        $lines = [
            [
                'account_id'     => $this->editAccountId,
                'cost_center_id' => '',
                'debit'          => $formatted,
                'credit'         => '',
                'description'    => '',
            ],
            [
                'account_id'     => $creditAccountId ?? '',
                'cost_center_id' => '',
                'debit'          => '',
                'credit'         => $formatted,
                'description'    => '',
            ],
        ];

        session()->flash('receipt_prefill', [
            'date'        => $this->editDate,
            'description' => $this->editDescription,
            'lines'       => $lines,
        ]);

        $this->redirectRoute('admin.accounting.journal-entries.create', navigate: true);
    }

    #[Computed]
    public function matchedVendor(): ?Vendor
    {
        return $this->matchedVendorId
            ? Vendor::with('defaultAccount')->find($this->matchedVendorId)
            : null;
    }

    #[Computed]
    public function expenseAccounts()
    {
        return Account::active()->leaf()
            ->whereIn('type', [Account::TYPE_EXPENSE, Account::TYPE_ASSET, Account::TYPE_LIABILITY])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    private function findVendor(string $vendorName): ?Vendor
    {
        $norm     = mb_strtolower(trim($vendorName));
        $vendors  = Vendor::active()->get(['id', 'name', 'default_account_id']);

        // 1. Exact match
        foreach ($vendors as $v) {
            if (mb_strtolower($v->name) === $norm) {
                return $v;
            }
        }

        // 2. One contains the other
        foreach ($vendors as $v) {
            $vn = mb_strtolower($v->name);
            if (str_contains($norm, $vn) || str_contains($vn, $norm)) {
                return $v;
            }
        }

        // 3. Best word overlap (at least 2 words in common)
        $normWords = array_filter(explode(' ', $norm));
        $best      = null;
        $bestScore = 1; // require at least 2 matching words

        foreach ($vendors as $v) {
            $vWords = array_filter(explode(' ', mb_strtolower($v->name)));
            $common = \count(array_intersect($normWords, $vWords));
            if ($common > $bestScore) {
                $bestScore = $common;
                $best      = $v;
            }
        }

        return $best;
    }
};
?>

<div class="max-w-2xl space-y-6">

    <div>
        <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">AI-kvittoskanning</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Ladda upp ett kvitto — Claude analyserar det och föreslår konto utifrån leverantörsinställningar.
        </p>
    </div>

    {{-- Upload state --}}
    @if ($state === 'upload')
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 space-y-4">

            <div x-data="{ isDragging: false }"
                 @dragover.prevent="isDragging = true"
                 @dragleave.prevent="isDragging = false"
                 @drop.prevent="isDragging = false; $refs.fileInput.files = $event.dataTransfer.files; $refs.fileInput.dispatchEvent(new Event('change'))"
                 :class="isDragging ? 'border-brand bg-brand/5' : 'border-zinc-200 dark:border-zinc-700'"
                 class="border-2 border-dashed rounded-lg p-8 text-center transition-colors">

                <input type="file"
                       x-ref="fileInput"
                       wire:model="file"
                       accept=".jpg,.jpeg,.png,.gif,.webp,.pdf"
                       class="hidden"
                       id="receipt-file-input" />

                <label for="receipt-file-input" class="cursor-pointer space-y-2 block">
                    <x-bootstrap-icon name="cloud-upload" class="size-10 text-zinc-300 dark:text-zinc-600 mx-auto" />
                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Klicka för att välja eller dra hit en fil
                    </p>
                    <p class="text-xs text-zinc-400">JPEG, PNG, GIF, WEBP eller PDF · max 10 MB</p>
                </label>

                @if ($file)
                    <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400 font-medium">
                        <x-bootstrap-icon name="file-earmark-check" class="size-4 inline mr-1" />
                        {{ $file->getClientOriginalName() }}
                    </p>
                @endif
            </div>

            @error('file')
                <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-3 text-sm text-red-600 dark:text-red-400">
                    {{ $message }}
                </div>
            @enderror

            <div class="flex justify-end">
                <flux:button wire:click="scan" :disabled="!$file" variant="primary"
                             wire:loading.attr="disabled" wire:target="scan">
                    <span wire:loading.remove wire:target="scan">
                        <x-bootstrap-icon name="robot" class="size-4 mr-1.5 inline" />
                        Skanna kvitto
                    </span>
                    <span wire:loading wire:target="scan" class="flex items-center gap-2">
                        <span class="animate-spin size-4 border-2 border-white border-t-transparent rounded-full inline-block"></span>
                        Claude analyserar…
                    </span>
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Result state --}}
    @if ($state === 'result' && $scanResult)
        @php
            $confidence = $scanResult['confidence'] ?? null;
            $confidenceClass = match($confidence) {
                'high'   => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                'medium' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                'low'    => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                default  => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
            };
            $confidenceLabel = match($confidence) {
                'high'   => 'Hög säkerhet',
                'medium' => 'Medel säkerhet',
                'low'    => 'Låg säkerhet',
                default  => '–',
            };
        @endphp

        {{-- Vendor match status --}}
        @if ($scanResult['vendor'])
            @if ($this->matchedVendor)
                <div class="rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 p-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-bootstrap-icon name="check-circle" class="size-4 text-green-600 dark:text-green-400 shrink-0" />
                        <div class="text-sm">
                            <span class="text-zinc-500 dark:text-zinc-400">Identifierad leverantör:</span>
                            <a href="{{ route('admin.accounting.vendors.show', $this->matchedVendor) }}"
                               wire:navigate
                               class="ml-1 font-medium text-green-700 dark:text-green-300 hover:underline">
                                {{ $this->matchedVendor->name }}
                            </a>
                            <span class="ml-2 text-xs text-zinc-400">
                                (AI läste: "{{ $scanResult['vendor'] }}")
                            </span>
                        </div>
                    </div>
                    @if ($accountSource !== 'ai')
                        <span class="text-xs rounded-full px-2.5 py-0.5 font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 shrink-0">
                            {{ $accountSource === 'posting_rule' ? 'Bokföringsregel' : 'Leverantörsinställning' }}
                        </span>
                    @endif
                </div>
            @else
                <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 p-3 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-bootstrap-icon name="question-circle" class="size-4 text-amber-600 dark:text-amber-400 shrink-0" />
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">
                            Ingen matchad leverantör för
                            <strong class="text-zinc-800 dark:text-zinc-200">"{{ $scanResult['vendor'] }}"</strong>
                        </span>
                    </div>
                    <a href="{{ route('admin.accounting.vendors.index') }}"
                       wire:navigate
                       class="text-xs font-medium text-amber-700 dark:text-amber-300 hover:underline shrink-0 ml-3">
                        Skapa leverantör
                    </a>
                </div>
            @endif
        @endif

        {{-- AI reasoning note --}}
        @if ($scanResult['reasoning'] && $accountSource === 'ai')
            <div class="rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 p-3 flex items-start gap-2">
                <x-bootstrap-icon name="robot" class="size-4 text-blue-500 mt-0.5 shrink-0" />
                <div>
                    <span class="inline-flex items-center rounded-full {{ $confidenceClass }} px-2 py-0.5 text-xs font-medium mr-2">
                        {{ $confidenceLabel }}
                    </span>
                    <span class="text-sm text-blue-700 dark:text-blue-300">{{ $scanResult['reasoning'] }}</span>
                </div>
            </div>
        @endif

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 space-y-5">
            <h2 class="font-medium text-zinc-800 dark:text-zinc-200 text-sm">Granska och justera</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <div>
                    <flux:label for="editDate">Datum</flux:label>
                    <flux:input id="editDate" type="date" wire:model="editDate" />
                    @error('editDate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <flux:label for="editAmountKr">Belopp inkl. moms (kr)</flux:label>
                    <flux:input id="editAmountKr" wire:model="editAmountKr" inputmode="decimal" placeholder="0,00" />
                    @error('editAmountKr') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <flux:label for="editDescription">Beskrivning</flux:label>
                    <flux:input id="editDescription" wire:model="editDescription" placeholder="t.ex. Kontorsmaterial, ICA" maxlength="255" />
                    @error('editDescription') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <flux:label for="editAccountId">
                        Debetkonto
                        @if ($accountSource !== 'ai' && $this->matchedVendor)
                            <span class="ml-2 text-xs text-green-600 dark:text-green-400">
                                från {{ $accountSource === 'posting_rule' ? 'bokföringsregel' : 'leverantörsinställning' }}
                            </span>
                        @elseif ($scanResult['suggested_account_code'])
                            <span class="ml-2 text-xs text-zinc-400">
                                AI föreslog: {{ $scanResult['suggested_account_code'] }} {{ $scanResult['suggested_account_name'] }}
                            </span>
                        @endif
                    </flux:label>
                    <select id="editAccountId"
                            wire:model="editAccountId"
                            class="w-full rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-brand">
                        <option value="">– Välj konto –</option>
                        @foreach ($this->expenseAccounts as $acc)
                            <option value="{{ $acc->id }}" @selected($editAccountId === $acc->id)>
                                {{ $acc->code }} {{ $acc->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('editAccountId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-zinc-400 mt-1">
                        Kreditkonto sätts automatiskt — 2440 (leverantörsskulder) som standard.
                    </p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <flux:button wire:click="startOver" variant="ghost">
                <x-bootstrap-icon name="arrow-counterclockwise" class="size-4 mr-1.5" />
                Skanna nytt kvitto
            </flux:button>
            <flux:button wire:click="createEntry" variant="primary">
                <x-bootstrap-icon name="journal-plus" class="size-4 mr-1.5" />
                Skapa verifikat
            </flux:button>
        </div>
    @endif

</div>
