<?php

use App\Models\AuditLog;
use App\Models\Events\Event;
use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Models\Shop\Product;
use App\Services\ScannerService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Rabatter');

new class extends Component {

    // List state
    public ?int  $selectedId = null;
    public bool  $isCreating = false;

    // Form fields
    public string  $form_name              = '';
    public string  $form_type              = Discount::TYPE_PERCENTAGE;
    public string  $form_value             = '';
    public string  $form_applies_to        = Discount::APPLIES_ALL;
    public ?int    $form_applicable_id     = null;
    public string  $form_membership_type   = '';
    public string  $form_requires_membership = '';  // '' | 'true' | 'false'
    public string  $form_condition         = '';
    public string  $form_valid_from        = '';
    public string  $form_valid_until       = '';
    public string  $form_min_subtotal      = '';
    public string  $form_max_discount      = '';
    public string  $form_max_pct_of_total  = '';
    public string  $form_max_uses          = '';
    public string  $form_max_uses_per_user = '';
    public bool    $form_is_active         = true;
    public string  $form_logo              = Discount::LOGO_ARMAGEDON;

    // Code generation
    public int    $generateCount    = 10;
    public int    $generateLength   = 8;    // chars per code
    public string $generateCodeType = 'single';  // 'single' | 'limited' | 'unlimited'
    public int    $generateMaxUses  = 10;   // used when generateCodeType = 'limited'
    public string $batchLabel       = '';   // optional distribution label
    public string $manualCode        = '';
    public string $filterBatch       = '';  // '' = all batches

    // Unambiguous uppercase alphabet: no 0/O, 1/I/L
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    // 'all' requires all three domain permissions
    private const APPLIES_TO_PERMISSIONS = [
        Discount::APPLIES_MEMBERSHIP => 'membership-periods.manage',
        Discount::APPLIES_EVENT      => 'events.manage',
        Discount::APPLIES_SHOP       => 'shop.manage',
    ];

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('payments.view'), 403);
    }

    #[Computed]
    public function eventOptions(): \Illuminate\Support\Collection
    {
        return Event::whereIn('status', [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED])
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', today()))
            ->orderBy('start_date')
            ->get(['id', 'title', 'start_date'])
            ->map(fn ($e) => (object)[
                'id'    => $e->id,
                'title' => $e->title . ($e->start_date ? '  (' . $e->start_date->format('d/m Y') . ')' : ''),
            ]);
    }

    #[Computed]
    public function productOptions(): \Illuminate\Support\Collection
    {
        return Product::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function allowedAppliesTo(): array
    {
        $user = Auth::user();
        $allowed = [];
        foreach (self::APPLIES_TO_PERMISSIONS as $type => $perm) {
            if ($user->hasPermissionTo($perm)) {
                $allowed[] = $type;
            }
        }
        if (count($allowed) === count(self::APPLIES_TO_PERMISSIONS)) {
            $allowed[] = Discount::APPLIES_ALL;
        }
        return $allowed;
    }

    #[Computed]
    public function discounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Discount::withCount('uses')->orderBy('is_active', 'desc')->orderBy('name')->get();
    }

    #[Computed]
    public function selected(): ?Discount
    {
        return $this->selectedId ? Discount::with('codes.redeemedBy')->withCount('uses')->find($this->selectedId) : null;
    }

    #[Computed]
    public function codes(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->selectedId) {
            return collect();
        }
        $q = DiscountCode::where('discount_id', $this->selectedId)
            ->with('redeemedBy')
            ->withCount('uses');
        if ($this->filterBatch !== '') {
            $q->where('batch_label', $this->filterBatch === '__none__' ? null : $this->filterBatch);
        }
        return $q->orderBy('batch_label')->orderBy('code')->get();
    }

    #[Computed]
    public function batches(): array
    {
        if (! $this->selectedId) {
            return [];
        }
        return DiscountCode::where('discount_id', $this->selectedId)
            ->selectRaw('batch_label, COUNT(*) as total, SUM(redeemed_at IS NOT NULL) as redeemed')
            ->groupBy('batch_label')
            ->orderBy('batch_label')
            ->get()
            ->toArray();
    }

    public function select(int $id): void
    {
        $discount = Discount::findOrFail($id);
        $this->selectedId  = $id;
        $this->isCreating  = false;
        $this->fillForm($discount);
        unset($this->selected, $this->codes);
    }

    public function createNew(): void
    {
        abort_unless(count($this->allowedAppliesTo) > 0, 403);
        $this->selectedId = null;
        $this->isCreating = true;
        $this->resetForm();
    }

    public function cancelEdit(): void
    {
        if ($this->isCreating) {
            $this->isCreating = false;
            $this->selectedId = null;
        }
        $this->resetErrorBag();
    }

    private function fillForm(Discount $d): void
    {
        $this->form_name               = $d->name;
        $this->form_type               = $d->type;
        $this->form_value              = $d->type === Discount::TYPE_FIXED
            ? number_format($d->value / 100, 2, '.', '')
            : (string) $d->value;
        $this->form_applies_to         = $d->applies_to;
        $this->form_applicable_id      = $d->applicable_id;
        $this->form_membership_type    = $d->membership_type ?? '';
        $this->form_requires_membership = $d->requires_membership === null ? '' : ($d->requires_membership ? 'true' : 'false');
        $this->form_condition          = $d->condition ?? '';
        $this->form_valid_from         = $d->valid_from?->format('Y-m-d') ?? '';
        $this->form_valid_until        = $d->valid_until?->format('Y-m-d') ?? '';
        $this->form_min_subtotal       = $d->min_subtotal ? number_format($d->min_subtotal / 100, 2, '.', '') : '';
        $this->form_max_discount       = $d->max_discount ? number_format($d->max_discount / 100, 2, '.', '') : '';
        $this->form_max_pct_of_total   = $d->max_pct_of_total !== null ? (string) $d->max_pct_of_total : '';
        $this->form_max_uses           = $d->max_uses !== null ? (string) $d->max_uses : '';
        $this->form_max_uses_per_user  = $d->max_uses_per_user !== null ? (string) $d->max_uses_per_user : '';
        $this->form_is_active          = $d->is_active;
        $this->form_logo               = $d->logo ?? Discount::LOGO_ARMAGEDON;
    }

    private function resetForm(): void
    {
        $this->form_name               = '';
        $this->form_type               = Discount::TYPE_PERCENTAGE;
        $this->form_value              = '';
        $this->form_applies_to         = $this->allowedAppliesTo[0] ?? Discount::APPLIES_ALL;
        $this->form_applicable_id      = null;
        $this->form_membership_type    = '';
        $this->form_requires_membership = '';
        $this->form_condition          = '';
        $this->form_valid_from         = '';
        $this->form_valid_until        = '';
        $this->form_min_subtotal       = '';
        $this->form_max_discount       = '';
        $this->form_max_pct_of_total   = '';
        $this->form_max_uses           = '';
        $this->form_max_uses_per_user  = '';
        $this->form_is_active          = true;
        $this->form_logo               = Discount::LOGO_ARMAGEDON;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.view'), 403);
        abort_unless(in_array($this->form_applies_to, $this->allowedAppliesTo), 403);

        $valueMax = $this->form_type === Discount::TYPE_PERCENTAGE ? 'max:100' : '';

        $this->validate([
            'form_name'               => ['required', 'string', 'max:255'],
            'form_type'               => ['required', 'in:percentage,fixed'],
            'form_value'              => ['required', 'numeric', 'min:0', ...($valueMax ? [$valueMax] : [])],
            'form_applies_to'         => ['required', 'in:membership,event,shop,all'],
            'form_membership_type'    => ['nullable', 'string'],
            'form_requires_membership'=> ['nullable', 'in:,true,false'],
            'form_condition'          => ['nullable', 'string'],
            'form_valid_from'         => ['nullable', 'date'],
            'form_valid_until'        => ['nullable', 'date', 'after_or_equal:form_valid_from'],
            'form_min_subtotal'       => ['nullable', 'numeric', 'min:0'],
            'form_max_discount'       => ['nullable', 'numeric', 'min:0'],
            'form_max_pct_of_total'   => ['nullable', 'integer', 'min:1', 'max:100'],
            'form_max_uses'           => ['nullable', 'integer', 'min:1'],
            'form_max_uses_per_user'  => ['nullable', 'integer', 'min:1'],
        ], [
            'form_name.required'        => 'Ange ett namn.',
            'form_value.required'       => 'Ange ett värde.',
            'form_value.numeric'        => 'Värdet måste vara ett tal.',
            'form_value.max'            => 'Procentsatsen kan inte överstiga 100.',
            'form_valid_until.after_or_equal' => 'Slutdatum måste vara samma som eller efter startdatum.',
        ]);

        $data = [
            'name'               => $this->form_name,
            'type'               => $this->form_type,
            'value'              => $this->form_type === Discount::TYPE_FIXED
                ? (int) round((float) $this->form_value * 100)
                : (int) $this->form_value,
            'applies_to'         => $this->form_applies_to,
            'applicable_type'    => match ($this->form_applies_to) {
                Discount::APPLIES_EVENT => Event::class,
                Discount::APPLIES_SHOP  => Product::class,
                default                 => null,
            },
            'applicable_id'      => in_array($this->form_applies_to, [Discount::APPLIES_EVENT, Discount::APPLIES_SHOP])
                ? ($this->form_applicable_id ?: null)
                : null,
            'membership_type'    => $this->form_membership_type ?: null,
            'requires_membership'=> $this->form_requires_membership === '' ? null : ($this->form_requires_membership === 'true'),
            'condition'          => $this->form_condition ?: null,
            'valid_from'         => $this->form_valid_from ?: null,
            'valid_until'        => $this->form_valid_until ?: null,
            'min_subtotal'       => $this->form_min_subtotal ? (int) round((float) $this->form_min_subtotal * 100) : null,
            'max_discount'       => $this->form_max_discount ? (int) round((float) $this->form_max_discount * 100) : null,
            'max_pct_of_total'   => $this->form_max_pct_of_total ? (int) $this->form_max_pct_of_total : null,
            'max_uses'           => $this->form_max_uses ? (int) $this->form_max_uses : null,
            'max_uses_per_user'  => $this->form_max_uses_per_user ? (int) $this->form_max_uses_per_user : null,
            'is_active'          => $this->form_is_active,
            'logo'               => $this->form_logo,
        ];

        if ($this->isCreating) {
            $discount = Discount::create($data);
            $this->selectedId = $discount->id;
            $this->isCreating = false;
            AuditLog::record('discount_created', 'discount', "Rabatt skapad: {$discount->name}", $discount);
        } else {
            $discount = Discount::findOrFail($this->selectedId);
            $discount->update($data);
            AuditLog::record('discount_updated', 'discount', "Rabatt uppdaterad: {$this->form_name}", $discount);
        }

        unset($this->discounts, $this->selected, $this->codes);
    }

    public function toggleActive(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.view'), 403);
        $d = Discount::findOrFail($id);
        $d->update(['is_active' => ! $d->is_active]);
        if ($this->selectedId === $id) {
            $this->form_is_active = ! $this->form_is_active;
        }
        unset($this->discounts, $this->selected);
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.view'), 403);
        $d = Discount::withCount('uses')->findOrFail($id);
        if ($d->uses_count > 0) {
            $this->addError('delete', "Kan inte ta bort en rabatt som använts ({$d->uses_count} gånger).");
            return;
        }
        $name = $d->name;
        $d->codes()->delete();
        $d->delete();
        if ($this->selectedId === $id) {
            $this->selectedId = null;
            $this->isCreating = false;
        }
        AuditLog::record('discount_deleted', 'discount', "Rabatt borttagen: {$name}");
        unset($this->discounts, $this->selected, $this->codes);
    }

    public function generateCodes(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.view'), 403);
        if (! $this->selectedId) return;

        $this->validate([
            'generateCount'   => ['required', 'integer', 'min:1', 'max:500'],
            'generateLength'  => ['required', 'integer', 'min:4', 'max:16'],
            'generateCodeType'=> ['required', 'in:single,limited,unlimited'],
            'generateMaxUses' => ['required_if:generateCodeType,limited', 'integer', 'min:2', 'max:10000'],
        ], [
            'generateCount.min'    => 'Ange minst 1.',
            'generateCount.max'    => 'Max 500 koder åt gången.',
            'generateLength.min'   => 'Minst 4 tecken.',
            'generateLength.max'   => 'Max 16 tecken.',
            'generateMaxUses.min'  => 'Minst 2 användningar.',
            'generateMaxUses.required_if' => 'Ange max antal användningar.',
        ]);

        $alphabet = self::CODE_ALPHABET;
        $len      = strlen($alphabet);

        $created = 0;
        $attempts = 0;
        while ($created < $this->generateCount && $attempts < $this->generateCount * 10) {
            // Cryptographically random code from unambiguous alphabet
            $code = '';
            for ($i = 0; $i < $this->generateLength; $i++) {
                $code .= $alphabet[random_int(0, $len - 1)];
            }
            if (! DiscountCode::where('code', $code)->exists()) {
                DiscountCode::create([
                    'discount_id'  => $this->selectedId,
                    'code'         => $code,
                    'is_single_use'=> $this->generateCodeType === 'single',
                    'max_uses'     => $this->generateCodeType === 'limited' ? $this->generateMaxUses : null,
                    'batch_label'  => $this->batchLabel ?: null,
                ]);
                $created++;
            }
            $attempts++;
        }

        AuditLog::record('discount_codes_generated', 'discount', "{$created} koder genererade för rabatt #{$this->selectedId}");
        unset($this->codes);
    }

    public function addManualCode(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.view'), 403);
        if (! $this->selectedId) return;

        $this->validate([
            'manualCode' => ['required', 'string', 'min:3', 'max:50', 'unique:discount_codes,code'],
        ], [
            'manualCode.required' => 'Ange en kod.',
            'manualCode.unique'   => 'Den koden finns redan.',
            'manualCode.min'      => 'Koden måste vara minst 3 tecken.',
        ]);

        $upper = strtoupper(trim($this->manualCode));
        foreach (ScannerService::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                $this->addError('manualCode', "Prefix \"{$prefix}\" är reserverat för andra kodtyper (t.ex. evenemangsincheckning).");
                return;
            }
        }

        DiscountCode::create([
            'discount_id'  => $this->selectedId,
            'code'         => strtoupper(trim($this->manualCode)),
            'is_single_use'=> $this->generateCodeType === 'single',
            'max_uses'     => $this->generateCodeType === 'limited' ? $this->generateMaxUses : null,
            'batch_label'  => $this->batchLabel ?: null,
        ]);

        $this->manualCode = '';
        unset($this->codes);
    }

    public function deleteCode(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.view'), 403);
        $code = DiscountCode::withCount('uses')->findOrFail($id);
        if ($code->uses_count > 0) {
            return; // Don't delete codes that have been used
        }
        $code->delete();
        unset($this->codes);
    }

    public function deleteUnusedCodes(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.view'), 403);
        if (! $this->selectedId) return;

        $q = DiscountCode::where('discount_id', $this->selectedId)
            ->whereNull('redeemed_at')
            ->whereDoesntHave('uses');

        if ($this->filterBatch !== '') {
            $q->where('batch_label', $this->filterBatch === '__none__' ? null : $this->filterBatch);
        }

        $count = $q->count();
        $q->delete();

        AuditLog::record('discount_codes_deleted', 'discount', "{$count} oanvända koder raderade för rabatt #{$this->selectedId}");
        unset($this->codes, $this->batches);
    }

    /**
     * Stream a CSV download of all codes for the selected discount.
     * filter: 'all' | 'available' | 'redeemed'
     */
    public function exportCsv(string $filter = 'all'): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(Auth::user()->hasPermissionTo('payments.view'), 403);

        $discount = Discount::findOrFail($this->selectedId);
        $query    = DiscountCode::where('discount_id', $this->selectedId)->with('redeemedBy');

        if ($filter === 'available') {
            $query->available();
        } elseif ($filter === 'redeemed') {
            $query->where(fn ($q) => $q->whereNotNull('redeemed_at')
                ->orWhereRaw('max_uses IS NOT NULL AND (SELECT COUNT(*) FROM discount_uses WHERE discount_code_id = discount_codes.id) >= discount_codes.max_uses'));
        }

        $codes    = $query->withCount('uses')->orderBy('code')->get();
        $filename = 'koder-' . \Illuminate\Support\Str::slug($discount->name) . '-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($codes) {
            $out = fopen('php://output', 'w');
            // BOM for Excel UTF-8 detection
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Kod', 'Batch', 'Typ', 'Status', 'Inlöst av', 'Inlöst datum'], ';');

            foreach ($codes as $code) {
                $type = $code->is_single_use
                    ? 'Engångskod'
                    : ($code->max_uses ? "Max {$code->max_uses}×" : 'Delad');
                $usedUp = $code->isUsedUp();
                $status = $usedUp
                    ? ($code->is_single_use ? 'Inlöst' : "Fullt ({$code->uses_count}/{$code->max_uses})")
                    : ($code->uses_count > 0
                        ? ('Använd ' . $code->uses_count . ($code->max_uses ? "/{$code->max_uses}" : '×'))
                        : 'Tillgänglig');

                fputcsv($out, [
                    $code->code,
                    $code->batch_label ?? '',
                    $type,
                    $status,
                    $code->redeemedBy?->full_name ?? '',
                    $code->redeemed_at?->format('Y-m-d') ?? '',
                ], ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function valueLabel(): string
    {
        return $this->form_type === Discount::TYPE_PERCENTAGE ? '%' : 'kr';
    }

    public function formattedValue(Discount $d): string
    {
        return $d->type === Discount::TYPE_PERCENTAGE
            ? "{$d->value} %"
            : number_format($d->value / 100, 2, ',', ' ') . ' kr';
    }

    public function appliesToLabel(string $v): string
    {
        return match($v) {
            'membership' => 'Medlemskap',
            'event'      => 'Evenemang',
            'shop'       => 'Webshop',
            default      => 'Alla',
        };
    }
}; ?>

<div>
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6">

        <div class="mb-4 flex items-center justify-end gap-2">
            <a href="{{ route('admin.discounts.scan') }}" wire:navigate class="btn">
                <x-bootstrap-icon name="upc-scan" class="size-4" />
                Skanningsstation
            </a>
            @if(count($this->allowedAppliesTo) > 0)
            <button wire:click="createNew" class="btn btn-primary">
                <x-bootstrap-icon name="plus" class="size-4" />
                Ny rabatt
            </button>
            @endif
        </div>

        @error('delete')
            <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                {{ $message }}
            </div>
        @enderror

        <div class="grid gap-6 lg:grid-cols-[320px_1fr]">

            {{-- Discount list --}}
            <div class="space-y-1 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="border-b border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                        Rabatter
                        <span class="ml-1 font-normal text-zinc-400">({{ $this->discounts->count() }})</span>
                    </p>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($this->discounts as $d)
                        <button wire:click="select({{ $d->id }})"
                                class="w-full px-4 py-3 text-left transition-colors
                                    {{ $selectedId === $d->id && !$isCreating
                                        ? 'bg-brand/10 dark:bg-brand/10'
                                        : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-zinc-900 dark:text-white {{ $d->is_active ? '' : 'opacity-50' }}">
                                        {{ $d->name }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $this->formattedValue($d) }}
                                        · {{ $this->appliesToLabel($d->applies_to) }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-1">
                                    <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium
                                        {{ $d->is_active
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                        {{ $d->is_active ? 'Aktiv' : 'Inaktiv' }}
                                    </span>
                                    @if($d->uses_count > 0)
                                        <span class="text-xs text-zinc-400">{{ $d->uses_count }}×</span>
                                    @endif
                                </div>
                            </div>
                        </button>
                    @empty
                        <div class="px-4 py-8 text-center text-sm text-zinc-400 dark:text-zinc-500">
                            Inga rabatter ännu.
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Detail panel --}}
            @if($isCreating || $selectedId)
                <div class="space-y-6">

                    {{-- Form --}}
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ $isCreating ? 'Ny rabatt' : 'Redigera' }}
                            </h2>
                            @if(! $isCreating)
                                <button wire:click="toggleActive({{ $selectedId }})"
                                        class="text-xs {{ $form_is_active ? 'text-zinc-400 hover:text-red-500' : 'text-green-600 hover:text-green-700' }}">
                                    {{ $form_is_active ? 'Inaktivera' : 'Aktivera' }}
                                </button>
                            @endif
                        </div>
                        <div class="space-y-5 p-5">

                            {{-- Name --}}
                            <div>
                                <label class="lbl">Admin-etikett</label>
                                <input wire:model="form_name" type="text" placeholder="t.ex. Välkomstrabatt 2026"
                                       class="inp mt-1 @error('form_name') border-red-300 @enderror" />
                                @error('form_name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            {{-- Type + Value --}}
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="lbl">Typ</label>
                                    <div class="mt-2 flex flex-col gap-1.5">
                                        <label class="flex cursor-pointer items-center gap-2">
                                            <input type="radio" wire:model.live="form_type" value="percentage"
                                                   class="border-zinc-300 text-brand focus:ring-brand-saffron">
                                            <span class="text-sm text-zinc-700 dark:text-zinc-300">Procent (%)</span>
                                        </label>
                                        <label class="flex cursor-pointer items-center gap-2">
                                            <input type="radio" wire:model.live="form_type" value="fixed"
                                                   class="border-zinc-300 text-brand focus:ring-brand-saffron">
                                            <span class="text-sm text-zinc-700 dark:text-zinc-300">Fast belopp (kr)</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <label class="lbl">
                                        Värde
                                        <span class="font-normal text-zinc-400">({{ $this->valueLabel() }})</span>
                                    </label>
                                    <input wire:model="form_value" type="number" step="{{ $form_type === 'fixed' ? '0.01' : '1' }}"
                                           min="0" max="{{ $form_type === 'percentage' ? '100' : '' }}"
                                           placeholder="{{ $form_type === 'percentage' ? '10' : '50.00' }}"
                                           class="inp mt-1 font-mono @error('form_value') border-red-300 @enderror" />
                                    @error('form_value') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            {{-- Applies to --}}
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="lbl">Gäller för</label>
                                    <select wire:model.live="form_applies_to"
                                            class="inp mt-1">
                                        @if(in_array('all', $this->allowedAppliesTo))
                                        <option value="all">Alla kategorier</option>
                                        @endif
                                        @if(in_array('membership', $this->allowedAppliesTo))
                                        <option value="membership">Enbart medlemskap</option>
                                        @endif
                                        @if(in_array('event', $this->allowedAppliesTo))
                                        <option value="event">Enbart evenemang</option>
                                        @endif
                                        @if(in_array('shop', $this->allowedAppliesTo))
                                        <option value="shop">Enbart webshop</option>
                                        @endif
                                    </select>
                                </div>
                                @if($form_applies_to === 'event')
                                    <div>
                                        <label class="lbl">Specifikt evenemang</label>
                                        <select wire:model="form_applicable_id" class="inp mt-1">
                                            <option value="">— Gäller alla evenemang —</option>
                                            @foreach($this->eventOptions as $e)
                                                <option value="{{ $e->id }}">{{ $e->title }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @elseif($form_applies_to === 'shop')
                                    <div>
                                        <label class="lbl">Specifik produkt</label>
                                        <select wire:model="form_applicable_id" class="inp mt-1">
                                            <option value="">— Gäller alla produkter —</option>
                                            @foreach($this->productOptions as $p)
                                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                                @if($form_applies_to === 'membership')
                                    <div>
                                        <label class="lbl">Begränsa till typ</label>
                                        <select wire:model="form_membership_type" class="inp mt-1">
                                            <option value="">Alla typer</option>
                                            @foreach(['single' => 'Vuxen', 'family' => 'Familj', 'youth' => 'Ungdom', 'child' => 'Barn', 'student' => 'Student', 'senior' => 'Senior'] as $v => $l)
                                                <option value="{{ $v }}">{{ $l }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            </div>

                            {{-- Condition & membership requirement --}}
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="lbl">Automatiskt villkor</label>
                                    <select wire:model="form_condition" class="inp mt-1">
                                        <option value="">Ingen (kräver kod)</option>
                                        <option value="first_membership">Första medlemskap</option>
                                    </select>
                                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                                        Automatiska rabatter tillämpas utan kod om villkoret stämmer.
                                    </p>
                                </div>
                                <div>
                                    <label class="lbl">Kräver</label>
                                    <select wire:model="form_requires_membership" class="inp mt-1">
                                        <option value="">Vem som helst</option>
                                        <option value="true">Aktiva/karansensmedlemmar</option>
                                        <option value="false">Icke-medlemmar</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Validity --}}
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="lbl">Giltig från</label>
                                    <input wire:model="form_valid_from" type="date"
                                           class="inp mt-1 @error('form_valid_from') border-red-300 @enderror" />
                                    @error('form_valid_from') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="lbl">Giltig till</label>
                                    <input wire:model="form_valid_until" type="date"
                                           class="inp mt-1 @error('form_valid_until') border-red-300 @enderror" />
                                    @error('form_valid_until') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            {{-- Usage limits --}}
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="lbl">Max antal totalt</label>
                                    <input wire:model="form_max_uses" type="number" min="1" placeholder="Obegränsat"
                                           class="inp mt-1 @error('form_max_uses') border-red-300 @enderror" />
                                    @error('form_max_uses') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="lbl">Max per person</label>
                                    <input wire:model="form_max_uses_per_user" type="number" min="1" placeholder="Obegränsat"
                                           class="inp mt-1 @error('form_max_uses_per_user') border-red-300 @enderror" />
                                    @error('form_max_uses_per_user') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            {{-- Caps --}}
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="lbl">Minimibelopp <span class="font-normal text-zinc-400">(kr)</span></label>
                                    <input wire:model="form_min_subtotal" type="number" step="0.01" min="0" placeholder="—"
                                           class="inp mt-1 font-mono @error('form_min_subtotal') border-red-300 @enderror" />
                                    @error('form_min_subtotal') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="lbl">Max rabatt <span class="font-normal text-zinc-400">(kr)</span></label>
                                    <input wire:model="form_max_discount" type="number" step="0.01" min="0" placeholder="—"
                                           class="inp mt-1 font-mono @error('form_max_discount') border-red-300 @enderror" />
                                    @error('form_max_discount') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="lbl">Max % av total</label>
                                    <input wire:model="form_max_pct_of_total" type="number" min="1" max="100" placeholder="—"
                                           class="inp mt-1 font-mono @error('form_max_pct_of_total') border-red-300 @enderror" />
                                    @error('form_max_pct_of_total') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            {{-- Logo --}}
                            <div>
                                <label class="lbl">Logotyp på bild/utskrift</label>
                                <div class="mt-2 flex flex-wrap gap-4">
                                    @foreach([Discount::LOGO_ARMAGEDON => 'Armagedon', Discount::LOGO_BSK => 'Borås Spelkonvent', Discount::LOGO_NONE => 'Ingen'] as $val => $lbl)
                                        <label class="flex cursor-pointer items-center gap-2">
                                            <input type="radio" wire:model="form_logo" value="{{ $val }}"
                                                   class="border-zinc-300 text-brand focus:ring-brand-saffron">
                                            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $lbl }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Active --}}
                            <div>
                                <label class="flex cursor-pointer items-center gap-2.5">
                                    <input type="checkbox" wire:model="form_is_active"
                                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Aktiv</span>
                                </label>
                            </div>

                        </div>
                        <div class="flex items-center justify-between border-t border-zinc-100 px-5 py-4 dark:border-zinc-700">
                            <div>
                                @if(! $isCreating)
                                    <button wire:click="delete({{ $selectedId }})"
                                            wire:confirm="Ta bort rabatt '{{ $form_name }}'? Går inte att ångra."
                                            class="text-sm text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                        Ta bort
                                    </button>
                                @endif
                            </div>
                            <div class="flex items-center gap-3">
                                @if($isCreating)
                                    <button wire:click="cancelEdit"
                                            class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">
                                        Avbryt
                                    </button>
                                @endif
                                <button wire:click="save" class="btn btn-primary">
                                    {{ $isCreating ? 'Skapa rabatt' : 'Spara' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Codes section — only for existing discounts --}}
                    @if(! $isCreating && $selectedId)
                        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                            <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Koder
                                    <span class="ml-1 font-normal">
                                        ({{ $this->codes->count() }})
                                    </span>
                                </h2>
                                <a href="{{ route('admin.discounts.print', ['discount' => $selectedId, 'available_only' => 1, 'batch' => $filterBatch]) }}"
                                   target="_blank"
                                   class="inline-flex items-center gap-1.5 text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                                    <x-bootstrap-icon name="printer" class="size-3.5" />
                                    Skriv ut streckkoder
                                </a>
                            </div>

                            {{-- Generate / add --}}
                            <div class="space-y-4 border-b border-zinc-100 p-5 dark:border-zinc-700">
                                {{-- Batch label (shared by both generate and manual add) --}}
                                <div>
                                    <label class="lbl">
                                        Batch-etikett
                                        <span class="font-normal text-zinc-400">(valfritt — t.ex. "Roddföreningen mars 2026")</span>
                                    </label>
                                    <input wire:model="batchLabel" type="text" placeholder="Lämna tomt för generell distribution"
                                           class="inp mt-1" />
                                </div>
                                <div class="flex flex-wrap items-end gap-3">
                                    <div>
                                        <label class="lbl">Antal</label>
                                        <input wire:model="generateCount" type="number" min="1" max="500"
                                               class="inp mt-1 w-24 font-mono" />
                                        @error('generateCount') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="lbl">Längd <span class="font-normal text-zinc-400">(tecken)</span></label>
                                        <input wire:model="generateLength" type="number" min="4" max="16"
                                               class="inp mt-1 w-20 font-mono" />
                                        @error('generateLength') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="lbl">Typ</label>
                                        <div class="mt-2 flex flex-wrap gap-3">
                                            <label class="flex cursor-pointer items-center gap-1.5">
                                                <input type="radio" wire:model.live="generateCodeType" value="single"
                                                       class="border-zinc-300 text-brand focus:ring-brand-saffron">
                                                <span class="text-sm text-zinc-700 dark:text-zinc-300">Engångskod</span>
                                            </label>
                                            <label class="flex cursor-pointer items-center gap-1.5">
                                                <input type="radio" wire:model.live="generateCodeType" value="limited"
                                                       class="border-zinc-300 text-brand focus:ring-brand-saffron">
                                                <span class="text-sm text-zinc-700 dark:text-zinc-300">Begränsad</span>
                                            </label>
                                            <label class="flex cursor-pointer items-center gap-1.5">
                                                <input type="radio" wire:model.live="generateCodeType" value="unlimited"
                                                       class="border-zinc-300 text-brand focus:ring-brand-saffron">
                                                <span class="text-sm text-zinc-700 dark:text-zinc-300">Obegränsad</span>
                                            </label>
                                        </div>
                                        @error('generateCodeType') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                    </div>
                                    @if($generateCodeType === 'limited')
                                        <div>
                                            <label class="lbl">Max användningar</label>
                                            <input wire:model="generateMaxUses" type="number" min="2"
                                                   class="inp mt-1 w-24 font-mono" />
                                            @error('generateMaxUses') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                        </div>
                                    @endif
                                    <button wire:click="generateCodes" class="btn btn-primary">
                                        <x-bootstrap-icon name="key" class="size-4" />
                                        Generera
                                    </button>
                                </div>
                                <p class="text-xs text-zinc-400 dark:text-zinc-500">
                                    Koder genereras med ett tydligt alfabet (utan O/0, I/1, L) för enkel manuell inmatning.
                                </p>
                                <div class="flex items-center gap-3">
                                    <input wire:model="manualCode" type="text" placeholder="MINKKOD2026"
                                           class="inp flex-1 font-mono uppercase"
                                           wire:keydown.enter="addManualCode" />
                                    <button wire:click="addManualCode" class="btn">
                                        Lägg till
                                    </button>
                                    @error('manualCode') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            {{-- Batch summary --}}
                            @if(count($this->batches) > 1 || (count($this->batches) === 1 && $this->batches[0]['batch_label'] !== null))
                                <div class="border-b border-zinc-100 px-5 py-3 dark:border-zinc-700">
                                    <p class="mb-2 text-xs font-semibold text-zinc-500 dark:text-zinc-400">Batchar</p>
                                    <div class="flex flex-wrap gap-2">
                                        <button wire:click="$set('filterBatch', '')"
                                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors
                                                    {{ $filterBatch === '' ? 'bg-brand/20 text-brand-dark dark:text-zinc-200' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700' }}">
                                            Alla
                                        </button>
                                        @foreach($this->batches as $batch)
                                            @php $bl = $batch['batch_label'] ?? null; @endphp
                                            <button wire:click="$set('filterBatch', '{{ $bl ?? '__none__' }}')"
                                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors
                                                        {{ $filterBatch === ($bl ?? '__none__') ? 'bg-brand/20 text-brand-dark dark:text-zinc-200' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700' }}">
                                                {{ $bl ?? '(ingen etikett)' }}
                                                <span class="text-zinc-400">
                                                    {{ $batch['redeemed'] }}/{{ $batch['total'] }}
                                                </span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Code list --}}
                            @if($this->codes->isNotEmpty())
                                {{-- Export toolbar --}}
                                @php
                                    $allCodes       = $this->codes->pluck('code')->join("\n");
                                    $availableCodes = $this->codes->filter(fn ($c) => ! $c->isUsedUp())->pluck('code')->join("\n");
                                @endphp
                                <div x-data="{ copied: false }"
                                     class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-5 py-3 dark:border-zinc-700">
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $this->codes->count() }} koder
                                        ({{ $this->codes->filter(fn ($c) => ! $c->isUsedUp())->count() }} tillgängliga)
                                        @if($filterBatch !== '') — filtrerat @endif
                                    </span>
                                    <div class="flex flex-wrap items-center gap-2">
                                        {{-- Copy available codes --}}
                                        <button type="button"
                                                x-on:click="navigator.clipboard.writeText({{ Js::from($availableCodes) }}).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                            <template x-if="!copied">
                                                <x-bootstrap-icon name="clipboard" class="size-3.5" />
                                            </template>
                                            <template x-if="copied">
                                                <x-bootstrap-icon name="clipboard-check" class="size-3.5 text-green-600" />
                                            </template>
                                            <span x-text="copied ? 'Kopierat!' : 'Kopiera tillgängliga'"></span>
                                        </button>
                                        {{-- CSV: available only --}}
                                        <button wire:click="exportCsv('available')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                            <x-bootstrap-icon name="download" class="size-3.5" />
                                            CSV (tillgängliga)
                                        </button>
                                        {{-- CSV: all --}}
                                        <button wire:click="exportCsv('all')"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                            <x-bootstrap-icon name="download" class="size-3.5" />
                                            CSV (alla)
                                        </button>
                                        {{-- ZIP download --}}
                                        <a href="{{ route('admin.discounts.codes.zip', array_filter(['discount' => $selectedId, 'batch' => $filterBatch ?: null])) }}"
                                           class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                            <x-bootstrap-icon name="file-zip" class="size-3.5" />
                                            Ladda ner ZIP
                                        </a>
                                        {{-- Delete unused --}}
                                        @php $unusedCount = $this->codes->filter(fn($c) => $c->uses_count === 0 && !$c->redeemed_at)->count(); @endphp
                                        @if($unusedCount > 0)
                                        <button wire:click="deleteUnusedCodes"
                                                wire:confirm="Radera {{ $unusedCount }} oanvända koder{{ $filterBatch !== '' ? ' i denna batch' : '' }}? Går inte att ångra."
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20">
                                            <x-bootstrap-icon name="trash" class="size-3.5" />
                                            Radera oanvända ({{ $unusedCount }})
                                        </button>
                                        @endif
                                    </div>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="border-b border-zinc-100 bg-zinc-50/50 dark:border-zinc-700 dark:bg-zinc-800/50">
                                                <th class="px-5 py-2.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400">Kod</th>
                                                <th class="px-5 py-2.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 hidden md:table-cell">Batch</th>
                                                <th class="px-5 py-2.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400">Typ</th>
                                                <th class="px-5 py-2.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400">Status</th>
                                                <th class="px-5 py-2.5 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 hidden sm:table-cell">Inlöst av</th>
                                                <th class="px-2 py-2.5"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                            @foreach($this->codes as $code)
                                                <tr wire:key="code-{{ $code->id }}" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                                    <td class="px-5 py-2.5 font-mono font-semibold text-zinc-900 dark:text-white">
                                                        {{ $code->code }}
                                                    </td>
                                                    <td class="hidden px-5 py-2.5 text-xs text-zinc-500 dark:text-zinc-400 md:table-cell">
                                                        {{ $code->batch_label ?? '—' }}
                                                    </td>
                                                    <td class="px-5 py-2.5 text-zinc-500 dark:text-zinc-400">
                                                        {{ $code->is_single_use ? 'Engång' : ($code->max_uses ? "Max {$code->max_uses}×" : 'Delad') }}
                                                    </td>
                                                    <td class="px-5 py-2.5">
                                                        @if($code->isUsedUp())
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                                                <x-bootstrap-icon name="check2" class="size-3" />
                                                                {{ $code->is_single_use ? 'Inlöst' : "Fullt ({$code->uses_count}/{$code->max_uses})" }}
                                                            </span>
                                                        @elseif($code->uses_count > 0)
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                                {{ $code->uses_count }}{{ $code->max_uses ? "/{$code->max_uses}" : '×' }} använd
                                                            </span>
                                                        @else
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                                <x-bootstrap-icon name="circle" class="size-3" />
                                                                Tillgänglig
                                                            </span>
                                                        @endif
                                                    </td>
                                                    <td class="hidden px-5 py-2.5 text-zinc-500 dark:text-zinc-400 sm:table-cell">
                                                        {{ $code->redeemedBy?->full_name ?? '—' }}
                                                        @if($code->redeemed_at)
                                                            <span class="text-xs text-zinc-400">{{ $code->redeemed_at->format('d/m Y') }}</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-2 py-2.5">
                                                        <div class="flex items-center gap-2">
                                                            <a href="{{ route('admin.discounts.code.png', ['discount' => $selectedId, 'code' => $code->id]) }}"
                                                               title="Ladda ner PNG"
                                                               class="text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                                                                <x-bootstrap-icon name="image" class="size-4" />
                                                            </a>
                                                            @if(! $code->redeemed_at && $code->uses_count === 0)
                                                                <button wire:click="deleteCode({{ $code->id }})"
                                                                        wire:confirm="Ta bort koden {{ $code->code }}?"
                                                                        class="text-zinc-400 hover:text-red-500 dark:text-zinc-500 dark:hover:text-red-400">
                                                                    <x-bootstrap-icon name="trash" class="size-4" />
                                                                </button>
                                                            @endif
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="px-5 py-6 text-center text-sm text-zinc-400 dark:text-zinc-500">
                                    Inga koder genererade ännu.
                                </p>
                            @endif
                        </div>
                    @endif

                </div>
            @else
                <div class="flex items-center justify-center rounded-xl border border-dashed border-zinc-200 py-16 dark:border-zinc-700">
                    <div class="text-center">
                        <x-bootstrap-icon name="tag" class="mx-auto mb-3 size-8 text-zinc-300 dark:text-zinc-600" />
                        <p class="text-sm text-zinc-400 dark:text-zinc-500">Välj en rabatt eller skapa en ny.</p>
                    </div>
                </div>
            @endif

        </div>
    </div>
</div>
