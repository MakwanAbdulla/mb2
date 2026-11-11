<?php

use App\Models\AuditLog;
use App\Models\Events\Event;
use App\Models\Events\EventRegistration;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Mina anmälningar');

new class extends Component {

    public ?int $cancelConfirmId = null;

    #[Computed]
    public function registrations()
    {
        return EventRegistration::where('user_id', Auth::id())
            ->with(['event.occurrences', 'priceTier', 'order'])
            ->whereHas('event', fn ($q) => $q->whereIn('status', [
                Event::STATUS_PUBLISHED,
                Event::STATUS_COMPLETED,
            ]))
            ->orderByDesc('created_at')
            ->get();
    }

    public function cancelRegistration(int $id): void
    {
        $reg = EventRegistration::where('user_id', Auth::id())->findOrFail($id);

        abort_unless(
            $reg->event->allow_ticket_transfer || $reg->status === EventRegistration::STATUS_PENDING_PAYMENT,
            403
        );

        $reg->cancel();
        AuditLog::record('event_registration_cancelled', 'events', $reg->event->title, $reg);
        $this->cancelConfirmId = null;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            EventRegistration::STATUS_PENDING_PAYMENT => 'Väntar på betalning',
            EventRegistration::STATUS_CONFIRMED       => 'Bekräftad',
            EventRegistration::STATUS_WAITLISTED      => 'Väntelista',
            EventRegistration::STATUS_CANCELLED       => 'Avbokad',
            default                                   => $status,
        };
    }

    public function statusClasses(string $status): string
    {
        return match ($status) {
            EventRegistration::STATUS_PENDING_PAYMENT => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
            EventRegistration::STATUS_CONFIRMED       => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            EventRegistration::STATUS_WAITLISTED      => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
            EventRegistration::STATUS_CANCELLED       => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
            default                                   => 'bg-zinc-100 text-zinc-500',
        };
    }
}; ?>

<x-slot:header>
    <x-page-header title="Mina anmälningar" subtitle="Dina evenemangsanmälningar." />
</x-slot:header>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">

    @if($this->registrations->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-12 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="ticket" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
            <p class="text-zinc-500 dark:text-zinc-400">Du har inga anmälningar ännu.</p>
            <a href="{{ route('events.index') }}" wire:navigate
               class="mt-4 inline-block text-sm font-medium text-complement hover:text-complement-light dark:text-complement-light">
                Se kommande evenemang →
            </a>
        </div>
    @else
        <div class="space-y-4">
            @foreach($this->registrations as $reg)
                @php $firstOcc = $reg->event->occurrences->first(); @endphp
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between gap-4 p-5">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="truncate text-base font-semibold text-zinc-900 dark:text-white">
                                    <a href="{{ route('events.show', $reg->event->slug) }}" wire:navigate
                                       class="hover:text-complement dark:hover:text-complement-light">
                                        {{ $reg->event->title }}
                                    </a>
                                </h2>
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $this->statusClasses($reg->status) }}">
                                    {{ $this->statusLabel($reg->status) }}
                                </span>
                            </div>

                            @if($firstOcc)
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    <x-bootstrap-icon name="calendar3" class="mr-1 inline size-3.5" />
                                    {{ $firstOcc->starts_at->isoFormat('D MMMM YYYY, HH:mm') }}
                                </p>
                            @endif

                            @if($reg->priceTier)
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    <x-bootstrap-icon name="ticket" class="mr-1 inline size-3.5" />
                                    {{ $reg->priceTier->label }}
                                    @if($reg->order)
                                        · {{ $reg->order->total_ore === 0 ? 'Gratis' : number_format($reg->order->total_ore / 100, 2, ',', ' ') . ' kr' }}
                                    @endif
                                </p>
                            @endif

                            {{-- Pending payment CTA --}}
                            @if($reg->status === EventRegistration::STATUS_PENDING_PAYMENT && $reg->order)
                                <div class="mt-3">
                                    <a href="{{ route('events.payment', $reg->event->slug) }}" wire:navigate
                                       class="btn btn-primary btn-sm">
                                        Slutför betalning
                                    </a>
                                </div>
                            @endif

                            {{-- QR code for confirmed registrations --}}
                            @if($reg->status === EventRegistration::STATUS_CONFIRMED && $reg->check_in_token)
                                <div class="mt-3" x-data="{ open: false }">
                                    <button @click="open = !open"
                                            class="inline-flex items-center gap-1.5 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                        <x-bootstrap-icon name="qr-code" class="size-3.5" />
                                        <span x-text="open ? 'Dölj QR-kod' : 'Visa QR-kod'">Visa QR-kod</span>
                                    </button>
                                    <div x-show="open" x-cloak class="mt-2 w-fit">
                                        <img src="{{ route('ticket.qr', $reg->check_in_token) }}"
                                             alt="QR-kod för incheckning"
                                             width="150" height="150"
                                             class="rounded border border-zinc-200 dark:border-zinc-700" />
                                        <p class="mt-1 text-xs text-zinc-400">
                                            Visa för personalen vid incheckning.
                                        </p>
                                    </div>
                                </div>
                            @endif
                        </div>

                        {{-- Cancel --}}
                        @if(in_array($reg->status, [EventRegistration::STATUS_CONFIRMED, EventRegistration::STATUS_PENDING_PAYMENT])
                            && ($reg->event->allow_ticket_transfer || $reg->status === EventRegistration::STATUS_PENDING_PAYMENT))
                            <div x-data="{ confirming: false }" class="shrink-0">
                                <button @click="confirming = true" x-show="!confirming"
                                        class="text-xs text-zinc-400 hover:text-red-500 dark:hover:text-red-400">
                                    Avboka
                                </button>
                                <div x-show="confirming" x-cloak class="flex items-center gap-2">
                                    <span class="text-xs text-red-600 dark:text-red-400">Säker?</span>
                                    <button wire:click="cancelRegistration({{ $reg->id }})"
                                            class="text-xs font-medium text-red-600 hover:text-red-800 dark:hover:text-red-400">
                                        Ja
                                    </button>
                                    <button @click="confirming = false"
                                            class="text-xs text-zinc-400 hover:text-zinc-600">
                                        Nej
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</div>
