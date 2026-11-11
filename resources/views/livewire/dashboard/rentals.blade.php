<?php

use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Rental\RentalItemType;
use App\Models\Rental\RentalWaitlist;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    public ?string $flash = null;

    #[Computed]
    public function member(): ?Member
    {
        return Auth::user()->member;
    }

    #[Computed]
    public function activeRentals()
    {
        return $this->member?->activeRentals()->with('item.type')->orderBy('started_at')->get()
            ?? collect();
    }

    #[Computed]
    public function waitlistEntries()
    {
        return $this->member?->rentalWaitlist()->with('itemType')->orderBy('created_at')->get()
            ?? collect();
    }

    #[Computed]
    public function availableTypes()
    {
        $waitlistTypeIds = $this->waitlistEntries->pluck('rental_item_type_id');
        $activeTypeIds   = $this->activeRentals->pluck('item.rental_item_type_id');

        return RentalItemType::where('is_active', true)
            ->where('is_admin_controlled', false)
            ->whereNotIn('id', $activeTypeIds)
            ->whereNotIn('id', $waitlistTypeIds)
            ->orderBy('sort_order')
            ->get();
    }

    public function joinWaitlist(int $typeId): void
    {
        $member = $this->member;
        abort_unless($member !== null, 403);

        $type = RentalItemType::where('is_active', true)->findOrFail($typeId);

        // Already on waitlist or has active rental of this type
        $alreadyQueued = RentalWaitlist::where('rental_item_type_id', $typeId)
            ->where('member_id', $member->id)
            ->exists();

        $alreadyRenting = $member->activeRentals()
            ->whereHas('item', fn ($q) => $q->where('rental_item_type_id', $typeId))
            ->exists();

        if ($alreadyQueued || $alreadyRenting) {
            return;
        }

        RentalWaitlist::create([
            'rental_item_type_id' => $typeId,
            'member_id'           => $member->id,
        ]);

        AuditLog::record('rental_waitlist_joined', 'rentals', $type->name, null);
        $this->flash = "Du är nu i kö för {$type->name}.";
        unset($this->waitlistEntries, $this->availableTypes);
    }

    public function leaveWaitlist(int $entryId): void
    {
        $member = $this->member;
        abort_unless($member !== null, 403);

        $entry = RentalWaitlist::where('id', $entryId)->where('member_id', $member->id)->first();
        $entryTypeName = $entry?->itemType?->name ?? (string) $entryId;

        RentalWaitlist::where('id', $entryId)
            ->where('member_id', $member->id)
            ->delete();

        AuditLog::record('rental_waitlist_left', 'rentals', $entryTypeName, null);
        $this->flash = 'Du har lämnat kön.';
        unset($this->waitlistEntries, $this->availableTypes);
    }
}; ?>

<div class="space-y-6">

    {{-- Flash --}}
    @if($flash)
        <div class="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 dark:border-green-900/40 dark:bg-green-900/20"
             x-data x-init="setTimeout(() => $wire.set('flash', null), 4000)">
            <x-bootstrap-icon name="check-circle-fill" class="size-4 shrink-0 text-green-600 dark:text-green-400" />
            <span class="text-sm text-green-800 dark:text-green-300">{{ $flash }}</span>
        </div>
    @endif

    {{-- Active rentals --}}
    @if($this->activeRentals->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Dina hyror</span>
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($this->activeRentals as $rental)
                    <li class="flex items-center justify-between px-5 py-4">
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">
                                {{ $rental->item->type->name }} #{{ $rental->item->label }}
                            </p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Sedan {{ $rental->started_at->isoFormat('D MMMM YYYY') }}
                                · {{ $rental->formattedPrice() }}
                            </p>
                        </div>
                        <x-bootstrap-icon name="key-fill" class="size-5 text-brand-orange" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Waitlist entries --}}
    @if($this->waitlistEntries->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Köplatser</span>
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($this->waitlistEntries as $entry)
                    <li class="flex items-center justify-between px-5 py-3">
                        <div>
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                {{ $entry->itemType->name }}
                            </p>
                            <p class="text-xs text-zinc-400">
                                I kö sedan {{ $entry->created_at->isoFormat('D MMMM YYYY') }}
                            </p>
                        </div>
                        <button wire:click="leaveWaitlist({{ $entry->id }})"
                                wire:confirm="Lämna kön för {{ $entry->itemType->name }}?"
                                class="text-xs text-zinc-400 hover:text-red-500 dark:hover:text-red-400">
                            Lämna kön
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Join waitlist --}}
    @if($this->availableTypes->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Ställ dig i kö</span>
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($this->availableTypes as $type)
                    <li class="flex items-center justify-between px-5 py-4">
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">{{ $type->name }}</p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $type->formattedPrice() }}
                                @if($type->description)
                                    · {{ $type->description }}
                                @endif
                            </p>
                        </div>
                        <button wire:click="joinWaitlist({{ $type->id }})" class="btn btn-sm btn-secondary">
                            Ställ mig i kö
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($this->activeRentals->isEmpty() && $this->waitlistEntries->isEmpty() && $this->availableTypes->isEmpty())
        <p class="text-sm text-zinc-400 dark:text-zinc-500">
            Inga hyror tillgängliga just nu.
        </p>
    @endif

</div>
