<?php

use App\Mail\OrderReadyForPickupMail;
use App\Mail\OrderShippedMail;
use App\Models\Accounting\Customer;
use App\Models\Accounting\Invoice;
use App\Models\AuditLog;
use App\Models\Shop\Order;
use App\Models\Shop\OrderFulfillment;
use App\Models\Shop\OrderLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => 'Order ' . $this->order->payment_reference);

new class extends Component {

    public Order $order;

    // Fulfillment form per line
    public array $trackingInputs = [];
    public ?int  $editFulfillId  = null;

    // Customer linking
    public bool   $showLinkModal        = false;
    public string $linkSearch           = '';
    public ?int   $selectedCustomerId   = null;

    // Create customer inline
    public bool   $showCreateForm  = false;
    public string $newName         = '';
    public string $newEmail        = '';
    public string $newOrgNumber    = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('shop.manage'), 403);
        $this->order->load(['user', 'customer', 'lines.fulfillment', 'lines.orderable.product', 'lines.discountUse.discountCode']);

        // Pre-fill create form from guest info
        $this->newName  = $this->order->guest_name ?? '';
        $this->newEmail = $this->order->guest_email ?? '';
    }

    #[Computed]
    public function shopLines()
    {
        return $this->order->lines->where('category', OrderLine::CATEGORY_SHOP);
    }

    #[Computed]
    public function customerResults()
    {
        if (strlen($this->linkSearch) < 2) {
            return collect();
        }
        return Customer::where('is_active', true)
            ->where(fn ($q) =>
                $q->where('name', 'like', "%{$this->linkSearch}%")
                  ->orWhere('email', 'like', "%{$this->linkSearch}%")
            )
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    public function linkCustomer(): void
    {
        abort_unless(Auth::user()->can('shop.manage'), 403);

        if (! $this->selectedCustomerId) {
            return;
        }

        $this->order->update(['customer_id' => $this->selectedCustomerId]);
        $this->order->refresh()->load('customer');
        AuditLog::record('order_customer_linked', 'shop', $this->order->payment_reference . ' → ' . $this->order->customer?->name, $this->order);
        $this->showLinkModal      = false;
        $this->linkSearch         = '';
        $this->selectedCustomerId = null;
        unset($this->customerResults);
    }

    public function unlinkCustomer(): void
    {
        abort_unless(Auth::user()->can('shop.manage'), 403);
        $this->order->update(['customer_id' => null]);
        $this->order->refresh()->load('customer');
        AuditLog::record('order_customer_unlinked', 'shop', $this->order->payment_reference, $this->order);
    }

    public function createAndLink(): void
    {
        abort_unless(Auth::user()->can('shop.manage'), 403);

        $this->validate([
            'newName'  => 'required|string|max:255',
            'newEmail' => 'nullable|email|max:255',
        ]);

        $customer = Customer::create([
            'name'       => $this->newName,
            'email'      => $this->newEmail ?: null,
            'org_number' => $this->newOrgNumber ?: null,
            'is_active'  => true,
        ]);

        $this->order->update(['customer_id' => $customer->id]);
        $this->order->refresh()->load('customer');
        AuditLog::record('order_customer_created_and_linked', 'shop', $this->order->payment_reference . ' → ' . $customer->name, $this->order);
        $this->showCreateForm = false;
    }

    public function createInvoice(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        if (! $this->order->customer_id) {
            return;
        }

        $invoice = Invoice::create([
            'type'          => 'receivable',
            'customer_id'   => $this->order->customer_id,
            'invoice_date'  => today(),
            'due_date'      => today()->addDays(30),
            'amount_ex_vat' => $this->order->total_ore,
            'vat_amount'    => 0,
            'total'         => $this->order->total_ore,
            'status'        => Invoice::STATUS_DRAFT,
            'notes'         => "Order {$this->order->payment_reference}",
            'created_by'    => Auth::id(),
        ]);

        AuditLog::record('order_invoice_created', 'shop', $this->order->payment_reference . ' — faktura ' . $invoice->id, $this->order);
        $this->redirect(route('admin.accounting.invoices.receivable.show', $invoice), navigate: true);
    }

    public function markShipped(int $fulfillmentId): void
    {
        $fulfillment = OrderFulfillment::findOrFail($fulfillmentId);
        $tracking    = trim($this->trackingInputs[$fulfillmentId] ?? '');

        $fulfillment->update([
            'status'          => OrderFulfillment::STATUS_SHIPPED,
            'shipped_at'      => now(),
            'tracking_number' => $tracking ?: null,
        ]);

        AuditLog::record(
            action: 'order_shipped',
            area: 'shop',
            subject: $this->order->payment_reference,
            auditable: $this->order,
        );

        $recipientEmail = $this->order->user?->email ?? $this->order->guest_email;
        if ($recipientEmail) {
            Mail::to($recipientEmail)->queue(new OrderShippedMail($this->order, $fulfillment->fresh()));
        }

        $this->editFulfillId = null;
        $this->order->refresh();
        $this->order->load(['lines.fulfillment', 'lines.orderable.product', 'lines.discountUse.discountCode']);
        unset($this->shopLines);
    }

    public function markDelivered(int $fulfillmentId): void
    {
        OrderFulfillment::findOrFail($fulfillmentId)->update([
            'status'       => OrderFulfillment::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);

        AuditLog::record(action: 'order_delivered', area: 'shop', subject: $this->order->payment_reference, auditable: $this->order);

        $this->order->refresh();
        $this->order->load(['lines.fulfillment', 'lines.orderable.product', 'lines.discountUse.discountCode']);
        unset($this->shopLines);
    }

    public function markReadyForPickup(int $fulfillmentId): void
    {
        $fulfillment = OrderFulfillment::findOrFail($fulfillmentId);

        $fulfillment->update([
            'status'   => OrderFulfillment::STATUS_READY_FOR_PICKUP,
            'ready_at' => now(),
        ]);

        AuditLog::record(action: 'order_ready_for_pickup', area: 'shop', subject: $this->order->payment_reference, auditable: $this->order);

        $recipientEmail = $this->order->user?->email ?? $this->order->guest_email;
        if ($recipientEmail) {
            Mail::to($recipientEmail)->queue(new OrderReadyForPickupMail($this->order));
        }

        $this->order->refresh();
        $this->order->load(['lines.fulfillment', 'lines.orderable.product', 'lines.discountUse.discountCode']);
        unset($this->shopLines);
    }

    public function markPickedUp(int $fulfillmentId): void
    {
        OrderFulfillment::findOrFail($fulfillmentId)->update([
            'status'       => OrderFulfillment::STATUS_PICKED_UP,
            'picked_up_at' => now(),
        ]);

        AuditLog::record(action: 'order_picked_up', area: 'shop', subject: $this->order->payment_reference, auditable: $this->order);

        $this->order->refresh();
        $this->order->load(['lines.fulfillment', 'lines.orderable.product', 'lines.discountUse.discountCode']);
        unset($this->shopLines);
    }

}; ?>

<div>
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.shop.orders.index') }}" wire:navigate class="text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
            <x-bootstrap-icon name="arrow-left" class="size-5" />
        </a>
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $order->payment_reference }}</h1>
            <p class="text-sm text-zinc-500">{{ $order->created_at->format('d/m Y H:i') }}</p>
        </div>
    </div>

    {{-- Order info --}}
    <div class="mb-6 grid grid-cols-2 gap-4 rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900 sm:grid-cols-4">
        <div>
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-400">Kund</p>
            <p class="mt-1 text-sm font-medium text-zinc-900 dark:text-white">
                {{ $order->user?->name ?? $order->guest_name ?? '–' }}
            </p>
            @if ($order->user?->email)
                <p class="text-xs text-zinc-500">{{ $order->user->email }}</p>
            @elseif ($order->guest_email)
                <p class="text-xs text-zinc-500">{{ $order->guest_email }}</p>
            @endif
        </div>
        <div>
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-400">Betalmetod</p>
            <p class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $order->payment_method ?? '–' }}</p>
        </div>
        <div>
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-400">Status</p>
            <p class="mt-1">
                @if ($order->isPaid())
                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                        <x-bootstrap-icon name="check-circle" class="size-3" /> Betald
                    </span>
                @else
                    <span class="text-xs text-zinc-500">{{ $order->status }}</span>
                @endif
            </p>
        </div>
        <div>
            <p class="text-xs font-medium uppercase tracking-wider text-zinc-400">Totalt</p>
            <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                {{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr
            </p>
        </div>
    </div>

    {{-- Customer linking --}}
    <div class="mb-6 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="px-5 py-3 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Kundkoppling</h2>
            @if ($order->customer)
                <div class="flex items-center gap-2">
                    @can('accounting.manage')
                    <flux:button wire:click="createInvoice" size="sm" variant="primary"
                                 wire:confirm="Skapa en kundfaktura (utkast) för denna order?">
                        <x-bootstrap-icon name="receipt" class="size-3.5" />
                        Skapa faktura
                    </flux:button>
                    @endcan
                    <button wire:click="unlinkCustomer"
                            class="text-xs text-zinc-400 hover:text-red-500 dark:hover:text-red-400">
                        Koppla bort
                    </button>
                </div>
            @endif
        </div>

        <div class="px-5 py-4">
            @if ($order->customer)
                {{-- Linked customer --}}
                <div class="flex items-center gap-3">
                    <x-bootstrap-icon name="person-fill" class="size-5 text-zinc-400 shrink-0" />
                    <div>
                        <a href="{{ route('admin.accounting.customers.show', $order->customer) }}"
                           wire:navigate
                           class="font-medium text-zinc-900 dark:text-white hover:text-brand-dark dark:hover:text-brand">
                            {{ $order->customer->name }}
                        </a>
                        @if ($order->customer->email)
                            <p class="text-xs text-zinc-500">{{ $order->customer->email }}</p>
                        @endif
                    </div>
                </div>

            @elseif ($showCreateForm)
                {{-- Create new customer form --}}
                <div class="space-y-3">
                    <p class="text-sm text-zinc-500">Skapa ny kund och koppla till ordern:</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="lbl">Namn</label>
                            <flux:input wire:model="newName" placeholder="Företag eller person" />
                            @error('newName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="lbl">E-post</label>
                            <flux:input wire:model="newEmail" type="email" />
                        </div>
                    </div>
                    <div>
                        <label class="lbl">Org.nr <span class="text-zinc-400 font-normal">(valfritt)</span></label>
                        <flux:input wire:model="newOrgNumber" placeholder="556000-0000" class="w-48" />
                    </div>
                    <div class="flex gap-2 pt-1">
                        <flux:button wire:click="createAndLink" variant="primary" size="sm">Skapa och koppla</flux:button>
                        <flux:button wire:click="$set('showCreateForm', false)" variant="ghost" size="sm">Avbryt</flux:button>
                    </div>
                </div>

            @elseif ($showLinkModal)
                {{-- Link existing customer --}}
                <div class="space-y-3">
                    <p class="text-sm text-zinc-500">Sök bland befintliga kunder:</p>
                    <flux:input wire:model.live.debounce.300ms="linkSearch" placeholder="Namn eller e-post…" autofocus />
                    @if ($this->customerResults->isNotEmpty())
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($this->customerResults as $c)
                                <label class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                    <input type="radio" wire:model="selectedCustomerId" value="{{ $c->id }}"
                                           class="text-brand focus:ring-brand" />
                                    <div>
                                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $c->name }}</p>
                                        @if ($c->email)
                                            <p class="text-xs text-zinc-400">{{ $c->email }}</p>
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @elseif (strlen($linkSearch) >= 2)
                        <p class="text-sm text-zinc-400 italic">Inga kunder hittades.</p>
                    @endif
                    <div class="flex gap-2 pt-1">
                        <flux:button wire:click="linkCustomer" variant="primary" size="sm"
                                     :disabled="!$selectedCustomerId">
                            Koppla
                        </flux:button>
                        <flux:button wire:click="$set('showLinkModal', false)" variant="ghost" size="sm">Avbryt</flux:button>
                    </div>
                </div>

            @else
                {{-- No customer linked --}}
                <div class="flex items-center gap-3">
                    <x-bootstrap-icon name="person-dash" class="size-5 text-zinc-300 dark:text-zinc-600 shrink-0" />
                    <p class="text-sm text-zinc-500">Ingen kund kopplad till denna order.</p>
                    <div class="flex gap-2 ml-auto">
                        <flux:button wire:click="$set('showLinkModal', true)" size="sm" variant="ghost">
                            Koppla befintlig
                        </flux:button>
                        <flux:button wire:click="$set('showCreateForm', true)" size="sm" variant="ghost">
                            Skapa ny kund
                        </flux:button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Order lines + fulfillment --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
            <h2 class="font-semibold text-zinc-900 dark:text-white">Orderrader</h2>
        </div>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach ($this->shopLines as $line)
            @php
                $fulfillment = $line->fulfillment;
                $isPickupOnly = $line->orderable instanceof \App\Models\Shop\ProductVariant
                    && $line->orderable->product?->pickup_only;
            @endphp
            <div class="px-6 py-4">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <p class="font-medium text-zinc-900 dark:text-white">{{ $line->description }}</p>
                            @if ($isPickupOnly)
                                <span class="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-700 dark:bg-violet-900/30 dark:text-violet-400">
                                    <x-bootstrap-icon name="bag-check" class="size-3" /> Endast upphämtning
                                </span>
                            @endif
                        </div>
                        <p class="text-sm text-zinc-500">
                            {{ $line->quantity }} × {{ number_format($line->unit_price_ore / 100, 2, ',', ' ') }} kr
                        </p>
                    </div>
                    <p class="font-medium text-zinc-900 dark:text-white">
                        {{ number_format($line->unit_price_ore * $line->quantity / 100, 2, ',', ' ') }} kr
                    </p>
                </div>
                @if($line->discount_ore > 0)
                @php $discountCode = $line->discountUse?->discountCode?->code; @endphp
                <div class="mt-1 flex justify-between text-sm">
                    <span class="text-green-700 dark:text-green-400">Rabatt{{ $discountCode ? ' ' . $discountCode : '' }}</span>
                    <span class="font-medium text-green-700 dark:text-green-400">−{{ number_format($line->discount_ore / 100, 2, ',', ' ') }} kr</span>
                </div>
                @endif

                @if ($fulfillment)
                <div class="mt-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            @if ($fulfillment->status === 'delivered')
                                <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-500" />
                                <span class="text-sm font-medium text-green-700 dark:text-green-400">Levererad {{ $fulfillment->delivered_at?->format('d/m Y') }}</span>
                            @elseif ($fulfillment->status === 'picked_up')
                                <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-500" />
                                <span class="text-sm font-medium text-green-700 dark:text-green-400">Upphämtad {{ $fulfillment->picked_up_at?->format('d/m Y') }}</span>
                            @elseif ($fulfillment->status === 'shipped')
                                <x-bootstrap-icon name="truck" class="size-4 text-blue-500" />
                                <span class="text-sm font-medium text-blue-700 dark:text-blue-400">
                                    Skickad {{ $fulfillment->shipped_at?->format('d/m Y') }}
                                    @if ($fulfillment->tracking_number)
                                    — Spårning: <span class="font-mono">{{ $fulfillment->tracking_number }}</span>
                                    @endif
                                </span>
                            @elseif ($fulfillment->status === 'ready_for_pickup')
                                <x-bootstrap-icon name="bag-check" class="size-4 text-violet-500" />
                                <span class="text-sm font-medium text-violet-700 dark:text-violet-400">Redo för upphämtning {{ $fulfillment->ready_at?->format('d/m Y') }}</span>
                            @else
                                <x-bootstrap-icon name="box-seam" class="size-4 text-amber-500" />
                                <span class="text-sm font-medium text-amber-700 dark:text-amber-400">Att förbereda</span>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($fulfillment->status === 'pending' || $fulfillment->status === 'processing')
                                @if ($isPickupOnly)
                                    <button wire:click="markReadyForPickup({{ $fulfillment->id }})"
                                            wire:confirm="Markera som redo för upphämtning? Kunden meddelas via e-post."
                                            class="btn btn-ghost text-xs">
                                        <x-bootstrap-icon name="bag-check" class="size-3.5" /> Redo för upphämtning
                                    </button>
                                @elseif ($editFulfillId === $fulfillment->id)
                                    <div class="flex items-center gap-2">
                                        <input wire:model="trackingInputs.{{ $fulfillment->id }}"
                                               type="text" class="inp text-sm w-40" placeholder="Spårningsnummer (valfritt)" />
                                        <button wire:click="markShipped({{ $fulfillment->id }})" class="btn btn-primary text-xs">
                                            Markera skickad
                                        </button>
                                        <button wire:click="$set('editFulfillId', null)" class="btn btn-ghost text-xs">Avbryt</button>
                                    </div>
                                @else
                                    <button wire:click="$set('editFulfillId', {{ $fulfillment->id }})" class="btn btn-ghost text-xs">
                                        <x-bootstrap-icon name="truck" class="size-3.5" /> Markera skickad
                                    </button>
                                    <button wire:click="markReadyForPickup({{ $fulfillment->id }})"
                                            wire:confirm="Markera som redo för upphämtning? Kunden meddelas via e-post."
                                            class="btn btn-ghost text-xs">
                                        <x-bootstrap-icon name="bag-check" class="size-3.5" /> Redo för upphämtning
                                    </button>
                                @endif
                            @elseif ($fulfillment->status === 'shipped')
                                <button wire:click="markDelivered({{ $fulfillment->id }})" class="btn btn-ghost text-xs">
                                    <x-bootstrap-icon name="check-circle" class="size-3.5" /> Markera levererad
                                </button>
                            @elseif ($fulfillment->status === 'ready_for_pickup')
                                <button wire:click="markPickedUp({{ $fulfillment->id }})" class="btn btn-ghost text-xs">
                                    <x-bootstrap-icon name="check-circle" class="size-3.5" /> Markera upphämtad
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
                @endif
            </div>
            @endforeach
        </div>
    </div>

    @if ($order->notes)
    <div class="mt-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <p class="text-xs font-medium uppercase tracking-wider text-zinc-400">Notering</p>
        <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $order->notes }}</p>
    </div>
    @endif
</div>
