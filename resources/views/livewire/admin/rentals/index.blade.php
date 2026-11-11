<?php

use App\Mail\RentalAssignedMail;
use App\Mail\RentalTerminatedMail;
use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Shop\Order;
use App\Models\Rental\Rental;
use App\Models\Rental\RentalItem;
use App\Models\Rental\RentalItemType;
use App\Models\Rental\RentalWaitlist;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Hyror');

new class extends Component {

    // Assign form
    public ?int    $assignTypeId   = null;
    public ?int    $assignItemId   = null;
    public ?int    $assignMemberId = null;
    public string  $assignSearch   = '';
    public bool    $showAssignForm = false;

    // Create type form
    public bool   $showCreateTypeForm       = false;
    public string $createTypeName           = '';
    public string $createTypePrice          = '0';
    public string $createTypeSetupFee       = '0';
    public bool   $createTypeAdminControlled = false;

    // Edit type form
    public ?int    $editTypeId              = null;
    public string  $editTypeName            = '';
    public string  $editTypePrice           = '';
    public string  $editTypeSetupFee        = '0';
    public bool    $editTypeActive          = true;
    public bool    $editTypeAdminControlled = false;

    // Add item form
    public ?int   $addItemTypeId    = null;
    public string $addItemLabel     = '';

    // Edit item form
    public ?int   $editItemId        = null;
    public string $editItemLabel     = '';
    public bool   $editItemAvailable = true;

    // Terminate confirmation
    public ?int $terminateRentalId = null;

    // Flash
    public ?string $flash     = null;
    public bool    $flashError = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);
    }

    // ── Computed ───────────────────────────────────────────────────────────────

    #[Computed]
    public function types()
    {
        return RentalItemType::orderBy('sort_order')->with([
            'items.activeRental.member',
            'items.activeRental.orderLines.order',
            'waitlist.member',
        ])->get();
    }

    #[Computed]
    public function availableItems()
    {
        if (! $this->assignTypeId) {
            return collect();
        }
        return RentalItem::where('rental_item_type_id', $this->assignTypeId)
            ->where('is_available', true)
            ->whereDoesntHave('activeRental')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function memberSearch()
    {
        if (strlen($this->assignSearch) < 2) {
            return collect();
        }
        $term = mb_strtolower($this->assignSearch);
        return Member::where('status', Member::STATUS_ACTIVE)
            ->with('user')
            ->orderBy('last_name')
            ->get()
            ->filter(fn ($m) =>
                str_contains(mb_strtolower($m->first_name . ' ' . $m->last_name), $term)
                || str_contains(mb_strtolower($m->membership_number ?? ''), $term)
            )
            ->take(10)
            ->values();
    }

    // ── Assign ─────────────────────────────────────────────────────────────────

    public function selectType(int $typeId): void
    {
        $this->assignTypeId   = $typeId;
        $this->assignItemId   = null;
        $this->assignMemberId = null;
        $this->assignSearch   = '';
        $this->showAssignForm = true;
        unset($this->availableItems);
    }

    public function selectTypeAndMember(int $typeId, int $memberId): void
    {
        $this->selectType($typeId);
        $this->selectMember($memberId);
    }

    public function selectMember(int $memberId): void
    {
        $member = Member::find($memberId);
        $this->assignMemberId = $memberId;
        $this->assignSearch   = $member?->full_name ?? '';
        unset($this->memberSearch);
    }

    public function assign(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);

        $type   = RentalItemType::findOrFail($this->assignTypeId);
        $item   = RentalItem::where('rental_item_type_id', $type->id)
            ->where('id', $this->assignItemId)
            ->where('is_available', true)
            ->whereDoesntHave('activeRental')
            ->firstOrFail();
        $member = Member::where('status', Member::STATUS_ACTIVE)->findOrFail($this->assignMemberId);

        $rental = Rental::create([
            'rental_item_id' => $item->id,
            'member_id'      => $member->id,
            'price_per_year' => $type->price_per_year,
            'started_at'     => today(),
        ]);

        // Setup fee: always invoiced immediately regardless of time of year.
        if ($type->setup_fee_ore > 0) {
            $setupOrder = $rental->createSetupFeeOrder();
        }

        // Annual fee: bundle onto today's pending rental order, or create new. Skipped at year-start.
        $isYearStart = today()->month === 1 && today()->day <= 31;
        if (! $isYearStart && $type->price_per_year > 0) {
            $existing = Order::where('user_id', $member->user?->id)
                ->where('status', Order::STATUS_PENDING)
                ->whereDate('created_at', today())
                ->whereDoesntHave('lines', fn ($q) => $q->where('description', 'like', '%anskaffningsavgift%'))
                ->first();

            if ($existing) {
                $rental->addLineToOrder($existing);
                $order = $existing;
            } else {
                $order = $rental->createOrder();
            }

        }

        $flashParts = ["{$member->full_name} tilldelad {$type->name} #{$item->label}."];
        if (isset($setupOrder)) {
            $flashParts[] = 'Anskaffningsavgift fakturerad (order #' . $setupOrder->id . ').';
        } elseif (isset($order)) {
            $flashParts[] = 'Årsavgift fakturerad (order #' . $order->id . ').';
        } elseif ($isYearStart) {
            $flashParts[] = 'Årsavgift läggs till vid nästa förnyelse.';
        }
        $this->flash = implode(' ', $flashParts);

        RentalWaitlist::where('rental_item_type_id', $type->id)
            ->where('member_id', $member->id)
            ->delete();

        AuditLog::record(
            'rental_assigned', 'rentals',
            "{$member->full_name} – {$type->name} #{$item->label}",
            $rental,
        );

        if ($member->email) {
            Mail::to($member->email)->queue(new RentalAssignedMail($rental));
        }

        $this->resetAssignForm();
        unset($this->types);
    }

    // ── Terminate ──────────────────────────────────────────────────────────────

    public function terminate(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);

        $rental = Rental::with(['item.type', 'member'])->findOrFail($this->terminateRentalId);
        $rental->update(['ended_at' => today()]);

        AuditLog::record(
            'rental_terminated', 'rentals',
            "{$rental->member->full_name} – {$rental->item->type->name} #{$rental->item->label}",
            $rental,
        );

        if ($rental->member->email) {
            Mail::to($rental->member->email)->queue(new RentalTerminatedMail($rental));
        }

        $this->flash = "Hyra avslutad: {$rental->member->full_name} – {$rental->item->type->name} #{$rental->item->label}.";
        $this->terminateRentalId = null;
        unset($this->types);
    }

    // ── Edit type ──────────────────────────────────────────────────────────────

    public function startEditType(int $id): void
    {
        $type = RentalItemType::findOrFail($id);
        $this->editTypeId              = $id;
        $this->editTypeName            = $type->name;
        $this->editTypePrice           = number_format($type->price_per_year / 100, 2, '.', '');
        $this->editTypeSetupFee        = number_format($type->setup_fee_ore / 100, 2, '.', '');
        $this->editTypeActive          = $type->is_active;
        $this->editTypeAdminControlled = $type->is_admin_controlled;
    }

    public function saveType(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);

        $priceOre    = (int) round((float) str_replace(',', '.', $this->editTypePrice) * 100);
        $setupFeeOre = (int) round((float) str_replace(',', '.', $this->editTypeSetupFee) * 100);

        $type = RentalItemType::findOrFail($this->editTypeId);
        $type->update([
            'name'                => trim($this->editTypeName),
            'price_per_year'      => $priceOre,
            'setup_fee_ore'       => $setupFeeOre,
            'is_active'           => $this->editTypeActive,
            'is_admin_controlled' => $this->editTypeAdminControlled,
        ]);

        AuditLog::record('rental_type_updated', 'rentals', $type->name, $type);

        $this->editTypeId = null;
        $this->flash = 'Hyrestyp uppdaterad.';
        unset($this->types);
    }

    public function cancelEditType(): void
    {
        $this->editTypeId = null;
    }

    public function createType(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);

        $this->validate([
            'createTypeName'  => 'required|string|max:100',
            'createTypePrice' => 'required|numeric|min:0',
        ]);

        $priceOre    = (int) round((float) str_replace(',', '.', $this->createTypePrice) * 100);
        $setupFeeOre = (int) round((float) str_replace(',', '.', $this->createTypeSetupFee) * 100);

        $maxSort = RentalItemType::max('sort_order') ?? 0;
        $newType = RentalItemType::create([
            'name'                => trim($this->createTypeName),
            'price_per_year'      => $priceOre,
            'setup_fee_ore'       => $setupFeeOre,
            'is_active'           => true,
            'is_admin_controlled' => $this->createTypeAdminControlled,
            'sort_order'          => $maxSort + 1,
        ]);

        AuditLog::record('rental_type_created', 'rentals', $newType->name, $newType);

        $this->showCreateTypeForm        = false;
        $this->createTypeName            = '';
        $this->createTypePrice           = '0';
        $this->createTypeSetupFee        = '0';
        $this->createTypeAdminControlled = false;
        $this->flash = 'Ny typ skapad.';
        unset($this->types);
    }

    // ── Items ─────────────────────────────────────────────────────────────────

    public function addItem(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);

        $this->validate(['addItemLabel' => 'required|string|max:50']);

        $type    = RentalItemType::findOrFail($this->addItemTypeId);
        $maxSort = $type->items()->max('sort_order') ?? 0;

        $newItem = RentalItem::create([
            'rental_item_type_id' => $type->id,
            'label'               => trim($this->addItemLabel),
            'is_available'        => true,
            'sort_order'          => $maxSort + 1,
        ]);

        AuditLog::record('rental_item_added', 'rentals', "{$type->name} #{$newItem->label}", $newItem);

        $this->addItemTypeId = null;
        $this->addItemLabel  = '';
        $this->flash = 'Objekt tillagt.';
        unset($this->types);
    }

    public function startEditItem(int $id): void
    {
        $item = RentalItem::findOrFail($id);
        $this->editItemId        = $id;
        $this->editItemLabel     = $item->label;
        $this->editItemAvailable = $item->is_available;
    }

    public function saveItem(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);

        $this->validate(['editItemLabel' => 'required|string|max:50']);

        $item = RentalItem::with('type')->findOrFail($this->editItemId);
        $item->update([
            'label'        => trim($this->editItemLabel),
            'is_available' => $this->editItemAvailable,
        ]);

        AuditLog::record('rental_item_updated', 'rentals', "{$item->type->name} #{$item->label}", $item);

        $this->editItemId = null;
        $this->flash = 'Objekt sparat.';
        unset($this->types);
    }

    public function cancelEditItem(): void
    {
        $this->editItemId = null;
    }

    public function deleteItem(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);

        $item = RentalItem::withCount(['rentals' => fn ($q) => $q->whereNull('ended_at')])->findOrFail($id);

        if ($item->rentals_count > 0) {
            $this->flash      = 'Kan inte ta bort ett objekt med aktiv hyra.';
            $this->flashError = true;
            return;
        }

        $label = $item->type->name . ' #' . $item->label;
        $item->delete();
        AuditLog::record('rental_item_deleted', 'rentals', $label);
        $this->flash = 'Objekt borttaget.';
        unset($this->types);
    }

    // ── Waitlist ───────────────────────────────────────────────────────────────

    public function removeFromWaitlist(int $waitlistId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('rentals.manage'), 403);

        RentalWaitlist::findOrFail($waitlistId)->delete();
        unset($this->types);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function resetAssignForm(): void
    {
        $this->showAssignForm = false;
        $this->assignTypeId   = null;
        $this->assignItemId   = null;
        $this->assignMemberId = null;
        $this->assignSearch   = '';
        unset($this->availableItems, $this->memberSearch);
    }
}; ?>

<div>
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">

        {{-- Flash --}}
        @if($flash)
            <div class="flex items-center gap-3 rounded-xl border px-5 py-3
                        {{ $flashError
                            ? 'border-red-200 bg-red-50 dark:border-red-900/40 dark:bg-red-900/20'
                            : 'border-green-200 bg-green-50 dark:border-green-900/40 dark:bg-green-900/20' }}"
                 x-data x-init="setTimeout(() => $wire.set('flash', null), 5000)">
                <x-bootstrap-icon
                    name="{{ $flashError ? 'x-circle-fill' : 'check-circle-fill' }}"
                    class="size-4 shrink-0 {{ $flashError ? 'text-red-500' : 'text-green-600 dark:text-green-400' }}" />
                <span class="text-sm {{ $flashError ? 'text-red-800 dark:text-red-300' : 'text-green-800 dark:text-green-300' }}">
                    {{ $flash }}
                </span>
            </div>
        @endif

        {{-- Toolbar --}}
        <div class="flex justify-end">
            <button wire:click="$toggle('showCreateTypeForm')" class="btn btn-sm btn-secondary">
                <x-bootstrap-icon name="plus" class="size-3.5" />
                Ny typ
            </button>
        </div>

        {{-- Create type form --}}
        @if($showCreateTypeForm)
            <div class="overflow-hidden rounded-xl border border-complement/30 bg-complement/5 dark:border-complement/20">
                <div class="border-b border-complement/20 px-5 py-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                    Ny hyrestyp
                </div>
                <div class="p-5">
                    <div class="flex flex-wrap items-end gap-4">
                        <div>
                            <label class="lbl">Namn</label>
                            <input wire:model="createTypeName" type="text" placeholder="t.ex. Entrényckel" class="inp mt-1 w-52" />
                            @error('createTypeName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="lbl">Pris per år (kr)</label>
                            <input wire:model="createTypePrice" type="number" step="0.01" min="0" class="inp mt-1 w-28" />
                        </div>
                        <div>
                            <label class="lbl">Engångsavgift (kr)</label>
                            <input wire:model="createTypeSetupFee" type="number" step="0.01" min="0" class="inp mt-1 w-28" />
                        </div>
                        <div class="pb-1">
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                <input wire:model="createTypeAdminControlled" type="checkbox" class="rounded border-zinc-300 dark:border-zinc-600" />
                                Adminstyrd (döljs för medlemmar)
                            </label>
                        </div>
                        <div class="flex gap-2 pb-0.5">
                            <button wire:click="createType" class="btn btn-sm btn-primary">Skapa</button>
                            <button wire:click="$set('showCreateTypeForm', false)" class="btn btn-sm">Avbryt</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Assign form --}}
        @if($showAssignForm)
            @php $type = $this->types->firstWhere('id', $assignTypeId); @endphp
            <div class="overflow-hidden rounded-xl border border-brand/40 bg-brand/5 dark:border-brand/20 dark:bg-brand/5">
                <div class="flex items-center justify-between border-b border-brand/20 px-5 py-3">
                    <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                        Tilldela {{ $type?->name ?? '' }}
                    </span>
                    <button wire:click="$set('showAssignForm', false)" class="text-zinc-400 hover:text-zinc-600">
                        <x-bootstrap-icon name="x" class="size-4" />
                    </button>
                </div>
                <div class="p-5 space-y-4">

                    {{-- Item picker --}}
                    <div>
                        <label class="lbl">Välj ledigt skåp</label>
                        <div class="mt-1 flex flex-wrap gap-2">
                            @forelse($this->availableItems as $item)
                                <button wire:click="$set('assignItemId', {{ $item->id }})"
                                        @class([
                                            'rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors',
                                            'border-brand bg-brand text-brand-dark' => $assignItemId === $item->id,
                                            'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => $assignItemId !== $item->id,
                                        ])>
                                    #{{ $item->label }}
                                </button>
                            @empty
                                <p class="text-sm text-zinc-400">Inga lediga skåp av denna typ.</p>
                            @endforelse
                        </div>
                    </div>

                    {{-- Member search --}}
                    <div>
                        <label class="lbl">Sök medlem</label>
                        <x-member-search wire:model.live.debounce.200ms="assignSearch"
                                         :results="$this->memberSearch"
                                         action="selectMember"
                                         :selected-name="$assignMemberId ? Member::find($assignMemberId)?->full_name : null" />
                    </div>


                    <div class="flex items-center gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-700">
                        <button wire:click="assign"
                                @disabled(!$assignItemId || !$assignMemberId)
                                class="btn btn-primary"
                                :class="{ 'opacity-50 cursor-not-allowed': !$wire.assignItemId || !$wire.assignMemberId }">
                            <x-bootstrap-icon name="person-check" class="size-4" />
                            Tilldela skåp
                        </button>
                        <button wire:click="$set('showAssignForm', false)" class="btn">Avbryt</button>
                    </div>
                </div>
            </div>
        @endif

        {{-- Types --}}
        @foreach($this->types as $type)
            @php
                $occupied  = $type->items->filter(fn ($i) => $i->activeRental !== null)->count();
                $total     = $type->items->where('is_available', true)->count();
                $free      = $total - $occupied;
                $waitCount = $type->waitlist->count();
            @endphp
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">

                {{-- Type header --}}
                <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $type->name }}</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $type->formattedPrice() }}</span>
                        @if($type->setup_fee_ore > 0)
                            <span class="text-xs text-zinc-400 dark:text-zinc-500">+ {{ number_format($type->setup_fee_ore / 100, 2, ',', ' ') }} kr engång</span>
                        @endif
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs
                                     {{ $free > 0
                                         ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                         : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }}">
                            {{ $free }} lediga / {{ $total }}
                        </span>
                        @if($waitCount > 0)
                            <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                {{ $waitCount }} i kö
                            </span>
                        @endif
                        @if($type->is_admin_controlled)
                            <span class="inline-flex items-center gap-1 rounded-full bg-complement/10 px-2 py-0.5 text-xs text-complement dark:bg-complement/20 dark:text-complement-light">
                                <x-bootstrap-icon name="key" class="size-3" />
                                Adminstyrd
                            </span>
                        @endif
                        @if(! $type->is_active)
                            <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">
                                Inaktiv
                            </span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="startEditType({{ $type->id }})"
                                title="Redigera typ"
                                class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                            <x-bootstrap-icon name="pencil" class="size-3.5" />
                        </button>
                        @if($free > 0 && $type->is_active)
                            <button wire:click="selectType({{ $type->id }})" class="btn btn-sm btn-primary">
                                <x-bootstrap-icon name="plus" class="size-3.5" />
                                Tilldela
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Edit type form --}}
                @if($editTypeId === $type->id)
                    <div class="border-b border-zinc-100 bg-zinc-50/50 px-5 py-4 dark:border-zinc-800 dark:bg-zinc-800/50">
                        <div class="flex flex-wrap items-end gap-4">
                            <div>
                                <label class="lbl">Namn</label>
                                <input wire:model="editTypeName" type="text" class="inp mt-1 w-48" />
                            </div>
                            <div>
                                <label class="lbl">Pris per år (kr)</label>
                                <input wire:model="editTypePrice" type="number" step="0.01" min="0" class="inp mt-1 w-28" />
                            </div>
                            <div>
                                <label class="lbl">Engångsavgift (kr)</label>
                                <input wire:model="editTypeSetupFee" type="number" step="0.01" min="0" class="inp mt-1 w-28" />
                            </div>
                            <div class="pb-1 space-y-2">
                                <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                    <input wire:model="editTypeActive" type="checkbox" class="rounded border-zinc-300 dark:border-zinc-600" />
                                    Aktiv
                                </label>
                                <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                    <input wire:model="editTypeAdminControlled" type="checkbox" class="rounded border-zinc-300 dark:border-zinc-600" />
                                    Adminstyrd (döljs för medlemmar, ingen kö)
                                </label>
                            </div>
                            <div class="flex gap-2 pb-0.5">
                                <button wire:click="saveType" class="btn btn-sm btn-primary">Spara</button>
                                <button wire:click="cancelEditType" class="btn btn-sm">Avbryt</button>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Items grid --}}
                <div class="grid grid-cols-2 gap-px bg-zinc-100 dark:bg-zinc-800 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                    @foreach($type->items->sortBy('sort_order') as $item)
                        @php $rental = $item->activeRental; @endphp
                        <div @class([
                                'bg-white px-3 py-3 dark:bg-zinc-900',
                                'opacity-50' => ! $item->is_available && ! $rental,
                             ])>
                            {{-- Edit item inline --}}
                            @if($editItemId === $item->id)
                                <div class="space-y-1.5">
                                    <input wire:model="editItemLabel" type="text"
                                           class="inp w-full py-0.5 text-xs" />
                                    <label class="flex cursor-pointer items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                        <input wire:model="editItemAvailable" type="checkbox" class="rounded border-zinc-300" />
                                        Tillgänglig
                                    </label>
                                    <div class="flex gap-1 pt-0.5">
                                        <button wire:click="saveItem" class="btn btn-sm py-0 text-xs btn-primary">OK</button>
                                        <button wire:click="cancelEditItem" class="btn btn-sm py-0 text-xs">✕</button>
                                    </div>
                                </div>
                            @else
                                <div class="flex items-start justify-between">
                                    <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                                        {{ $item->label }}
                                        @if(! $item->is_available && ! $rental)
                                            <span class="text-zinc-300 dark:text-zinc-600">(ej tillg.)</span>
                                        @endif
                                    </span>
                                    <div class="flex items-center gap-1">
                                        <button wire:click="startEditItem({{ $item->id }})"
                                                title="Redigera"
                                                class="text-zinc-300 hover:text-zinc-500 dark:text-zinc-600 dark:hover:text-zinc-300">
                                            <x-bootstrap-icon name="pencil" class="size-3" />
                                        </button>
                                        @if(! $rental)
                                            <button wire:click="deleteItem({{ $item->id }})"
                                                    wire:confirm="Ta bort {{ $item->label }}?"
                                                    title="Ta bort"
                                                    class="text-zinc-300 hover:text-red-500 dark:text-zinc-600 dark:hover:text-red-400">
                                                <x-bootstrap-icon name="trash" class="size-3" />
                                            </button>
                                        @else
                                            <button wire:click="$set('terminateRentalId', {{ $rental->id }})"
                                                    title="Avsluta hyra"
                                                    class="text-zinc-300 hover:text-red-500 dark:text-zinc-600 dark:hover:text-red-400">
                                                <x-bootstrap-icon name="x-circle" class="size-3.5" />
                                            </button>
                                        @endif
                                    </div>
                                </div>
                                @if($rental)
                                    <p class="mt-1 truncate text-xs font-medium text-zinc-900 dark:text-white">
                                        {{ $rental->member->full_name }}
                                    </p>
                                    <p class="text-xs text-zinc-400">{{ $rental->started_at->format('d/m Y') }}</p>
                                    @php $billedYear = $rental->lastBilledYear(); @endphp
                                    <p class="text-xs {{ $billedYear === now()->year ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400' }}">
                                        {{ $billedYear ? 'Betalt ' . $billedYear : 'Ej fakturerat' }}
                                    </p>
                                @else
                                    <p class="mt-1 text-xs text-zinc-300 dark:text-zinc-600">Ledig</p>
                                @endif
                            @endif
                        </div>
                    @endforeach

                    {{-- Add item cell --}}
                    <div class="bg-white px-3 py-3 dark:bg-zinc-900">
                        @if($addItemTypeId === $type->id)
                            <div class="space-y-1.5">
                                <input wire:model="addItemLabel" type="text"
                                       placeholder="Beteckning…"
                                       class="inp w-full py-0.5 text-xs"
                                       wire:keydown.enter="addItem"
                                       wire:keydown.escape="$set('addItemTypeId', null)"
                                       autofocus />
                                @error('addItemLabel') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                <div class="flex gap-1">
                                    <button wire:click="addItem" class="btn btn-sm py-0 text-xs btn-primary">Lägg till</button>
                                    <button wire:click="$set('addItemTypeId', null)" class="btn btn-sm py-0 text-xs">✕</button>
                                </div>
                            </div>
                        @else
                            <button wire:click="$set('addItemTypeId', {{ $type->id }})"
                                    class="flex h-full w-full items-center justify-center gap-1 text-xs text-zinc-300 hover:text-zinc-500 dark:text-zinc-600 dark:hover:text-zinc-300">
                                <x-bootstrap-icon name="plus" class="size-3.5" />
                                Lägg till
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Terminate confirm --}}
                @if($terminateRentalId && $type->items->pluck('activeRental')->filter()->pluck('id')->contains($terminateRentalId))
                    @php $r = Rental::with(['item', 'member'])->find($terminateRentalId); @endphp
                    @if($r)
                        <div class="flex items-center justify-between border-t border-zinc-100 bg-red-50 px-5 py-3 dark:border-zinc-800 dark:bg-red-900/10">
                            <p class="text-sm text-red-700 dark:text-red-400">
                                Avsluta hyra för <strong>{{ $r->member->full_name }}</strong> – {{ $r->item->type->name ?? '' }} #{{ $r->item->label }}?
                            </p>
                            <div class="flex gap-2">
                                <button wire:click="terminate" class="btn btn-danger btn-sm">Avsluta</button>
                                <button wire:click="$set('terminateRentalId', null)" class="btn btn-sm">Avbryt</button>
                            </div>
                        </div>
                    @endif
                @endif

                {{-- Waitlist (hidden for admin-controlled types) --}}
                @if($waitCount > 0 && ! $type->is_admin_controlled)
                    <div class="border-t border-zinc-100 dark:border-zinc-800">
                        <div class="px-5 py-2 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                            Väntelista
                        </div>
                        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($type->waitlist as $i => $entry)
                                <li class="flex items-center justify-between px-5 py-2.5 text-sm">
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs text-zinc-400">#{{ $i + 1 }}</span>
                                        <span class="text-zinc-900 dark:text-white">{{ $entry->member->full_name }}</span>
                                        <span class="text-xs text-zinc-400">{{ $entry->created_at->isoFormat('D MMM YYYY') }}</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        @if($free > 0 && $type->is_active)
                                            <button wire:click="selectTypeAndMember({{ $type->id }}, {{ $entry->member->id }})"
                                                    title="Tilldela detta skåp till {{ $entry->member->full_name }}"
                                                    class="text-xs text-complement hover:text-complement-light dark:text-complement-light">
                                                Tilldela →
                                            </button>
                                        @endif
                                        <button wire:click="removeFromWaitlist({{ $entry->id }})"
                                                title="Ta bort från kö"
                                                class="text-zinc-300 hover:text-red-500 dark:text-zinc-600">
                                            <x-bootstrap-icon name="x" class="size-4" />
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

            </div>
        @endforeach

    </div>
</div>
