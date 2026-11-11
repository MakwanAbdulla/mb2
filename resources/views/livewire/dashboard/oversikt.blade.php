<?php

use App\Models\Membership\Member;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    #[Computed]
    public function member(): ?Member
    {
        return Member::where('user_id', auth()->id())->first();
    }

    public function statusLabel(string $status): string
    {
        return match($status) {
            Member::STATUS_PENDING  => 'Väntar på betalning',
            Member::STATUS_ACTIVE   => 'Aktiv',
            Member::STATUS_INACTIVE => 'Inaktiv',
            Member::STATUS_EXPIRED  => 'Utgången',
            Member::STATUS_DECEASED => 'Avliden',
            default                 => $status,
        };
    }

    public function statusClasses(string $status): string
    {
        return match($status) {
            Member::STATUS_PENDING  => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
            Member::STATUS_ACTIVE   => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            Member::STATUS_INACTIVE,
            Member::STATUS_EXPIRED  => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            default                 => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
        };
    }

    public function typeLabel(string $type): string
    {
        return match($type) {
            Member::TYPE_SINGLE   => 'Vuxen',
            Member::TYPE_FAMILY   => 'Familj',
            Member::TYPE_YOUTH    => 'Ungdom',
            Member::TYPE_STUDENT  => 'Student',
            Member::TYPE_SENIOR   => 'Senior',
            Member::TYPE_HONORARY => 'Hedersmedlem',
            default               => $type,
        };
    }
}; ?>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">

    @if(! $this->member)
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex items-center gap-6 p-6">
                <div class="flex size-14 shrink-0 items-center justify-center rounded-full bg-brand/20">
                    <x-bootstrap-icon name="person-plus-fill" class="size-7 text-brand-dark dark:text-brand" />
                </div>
                <div class="flex-1">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">Du är inte registrerad som medlem</h2>
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                        Bli en del av föreningen och få tillgång till alla aktiviteter och grupper.
                    </p>
                </div>
                <a href="{{ route('membership.apply') }}" wire:navigate
                   class="shrink-0 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                    Bli medlem
                </a>
            </div>
        </div>
    @else
        @if($this->member->date_of_birth?->isBirthday())
            <div x-data="{ show: true }" x-show="show" x-transition.duration.500ms class="mb-6">
                <div class="rounded-xl bg-gradient-to-r from-brand via-brand-orange to-complement p-px">
                    <div class="rounded-[11px] bg-white px-5 pb-4 pt-3 dark:bg-zinc-900">
                        <div class="pointer-events-none mb-2 flex justify-between" aria-hidden="true">
                            @foreach(['🎉','🎊','🎈','⭐','✨','🎂','🎁','🎈','🎉','✨','🎊','⭐'] as $i => $emoji)
                                <span class="animate-bounce text-lg"
                                      style="animation-delay:{{ $i * 0.12 }}s; animation-duration:{{ 1.4 + ($i % 3) * 0.3 }}s">{{ $emoji }}</span>
                            @endforeach
                        </div>
                        @php
                            $age  = $this->member->date_of_birth->age;
                            $msgs = [
                                'Hoppas du har en riktigt bra dag — vi är glada att du är med i föreningen!',
                                'Må alla tärningar falla dig väl idag och resten av året!',
                                'Ett år äldre, ett år visare — och förhoppningsvis ett år bättre på spel.',
                                'Grattis! Du är nu officiellt ' . $age . ' år gammal och fortfarande med i världens bästa spelförening.',
                                'Idag är det du som bestämmer vilket spel vi ska spela. Grattis!',
                                'Ännu ett år av episka partier, oupphörliga regler-tvister och föreningsanda. Grattis!',
                                'Förhoppningsvis firar du med ett spel du faktiskt vinner idag. Du förtjänar det!',
                                'Happy birthday! Kom ihåg: ålder är bara ett tal — precis som poäng i Agricola.',
                            ];
                        @endphp
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-lg font-bold text-zinc-900 dark:text-white">
                                    Grattis på födelsedagen, {{ $this->member->first_name }}! 🎂
                                </p>
                                <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $msgs[$age % count($msgs)] }}</p>
                            </div>
                            <button @click="show = false"
                                    class="shrink-0 rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800">
                                <x-bootstrap-icon name="x-lg" class="size-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @php $isChristmasEve = now()->month === 12 && now()->day === 24; @endphp
        @if($isChristmasEve)
            <div x-data="{ show: true }" x-show="show" x-transition.duration.500ms class="mb-6">
                <div class="rounded-xl bg-gradient-to-r from-red-500 via-green-600 to-red-500 p-px">
                    <div class="rounded-[11px] bg-white px-5 pb-4 pt-3 dark:bg-zinc-900">
                        <div class="pointer-events-none mb-2 flex justify-between" aria-hidden="true">
                            @foreach(['🎄','⭐','🎁','❄️','🦌','🎅','🕯️','⛄','🎄','❄️','🎁','⭐'] as $i => $emoji)
                                <span class="animate-bounce text-lg"
                                      style="animation-delay:{{ $i * 0.12 }}s; animation-duration:{{ 1.4 + ($i % 3) * 0.3 }}s">{{ $emoji }}</span>
                            @endforeach
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-lg font-bold text-zinc-900 dark:text-white">God jul, {{ $this->member->first_name }}! 🎄</p>
                                <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                                    @php
                                        $julMsgs = [
                                            'Hoppas du får massor av bra spel under julgranen!',
                                            'Passa på — nu är det dags för riktigt långa spelkvällar.',
                                            'Kom ihåg: det bästa spelet är ett där alla trivs att spela tillsammans.',
                                            'God jul från hela föreningen — vi ses snart vid spelbordet!',
                                        ];
                                    @endphp
                                    {{ $julMsgs[now()->year % count($julMsgs)] }}
                                </p>
                            </div>
                            <button @click="show = false"
                                    class="shrink-0 rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800">
                                <x-bootstrap-icon name="x-lg" class="size-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Medlemsstatus --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex items-center gap-5 px-6 py-5">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-full
                    {{ $this->member->isActive() ? 'bg-green-100 dark:bg-green-900/30' : 'bg-amber-100 dark:bg-amber-900/30' }}">
                    <x-bootstrap-icon
                        name="{{ $this->member->isActive() ? 'person-check-fill' : 'person-fill-exclamation' }}"
                        class="size-6 {{ $this->member->isActive() ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400' }}" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-zinc-900 dark:text-white">{{ $this->member->full_name }}</p>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $this->typeLabel($this->member->membership_type) }} · Medlem sedan {{ $this->member->created_at->format('Y') }}
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium {{ $this->statusClasses($this->member->status) }}">
                        @if($this->member->isActive())
                            <x-bootstrap-icon name="check-circle-fill" class="size-3.5" />
                        @else
                            <x-bootstrap-icon name="clock" class="size-3.5" />
                        @endif
                        {{ $this->statusLabel($this->member->status) }}
                    </span>
                    <button @click="$dispatch('set-tab', 'medlemskap')"
                            class="text-sm text-complement hover:underline dark:text-complement-light">
                        Detaljer →
                    </button>
                </div>
            </div>
            @if($this->member->status === Member::STATUS_PENDING)
                <div class="border-t border-amber-100 bg-amber-50 px-6 py-3 dark:border-amber-800/30 dark:bg-amber-900/20">
                    <div class="flex items-center justify-between gap-4">
                        <p class="text-sm text-amber-800 dark:text-amber-300">Din anmälan väntar på betalning.</p>
                        <a href="{{ route('membership.payment') }}" wire:navigate
                           class="shrink-0 rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-brand-dark hover:bg-brand-saffron">
                            Slutför betalning
                        </a>
                    </div>
                </div>
            @elseif($this->member->status === Member::STATUS_EXPIRED)
                <div class="border-t border-red-100 bg-red-50 px-6 py-3 dark:border-red-800/30 dark:bg-red-900/20">
                    <div class="flex items-center justify-between gap-4">
                        <p class="text-sm text-red-800 dark:text-red-400">Ditt medlemskap har gått ut.</p>
                        <a href="{{ route('membership.payment') }}" wire:navigate
                           class="shrink-0 rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-brand-dark hover:bg-brand-saffron">
                            Förnya nu
                        </a>
                    </div>
                </div>
            @elseif($this->member->shouldShowRenewal())
                <div class="border-t border-brand/30 bg-brand/5 px-6 py-3 dark:border-brand/20">
                    <div class="flex items-center justify-between gap-4">
                        <p class="text-sm text-zinc-700 dark:text-zinc-300">
                            Förnya ditt medlemskap för {{ now()->year + 1 }}.
                        </p>
                        <a href="{{ route('membership.payment') }}" wire:navigate
                           class="shrink-0 rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-brand-dark hover:bg-brand-saffron">
                            Förnya
                        </a>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Digitalt medlemskort --}}
    @if($this->member)
    <div class="mt-6">
        <a href="{{ route('member.card') }}" wire:navigate
           class="group flex items-center gap-4 overflow-hidden rounded-xl bg-gradient-to-r from-brand-dark to-zinc-900 px-5 py-4 ring-1 ring-white/10 transition-opacity hover:opacity-90">
            <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/20">
                <x-bootstrap-icon name="qr-code" class="size-6 text-brand" />
            </div>
            <div class="flex-1">
                <p class="font-semibold text-white">Digitalt medlemskort</p>
                <p class="text-sm text-zinc-400">Visa upp din QR-kod vid incheckning och arrangemang</p>
            </div>
            <x-bootstrap-icon name="chevron-right" class="size-4 text-zinc-500 transition-transform group-hover:translate-x-0.5" />
        </a>
    </div>
    @endif

    {{-- Snabblänkar --}}
    @php $fullAccess = auth()->user()->isAdmin() || ($this->member?->hasFullAccess() ?? false); @endphp
    <div class="mt-8">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Snabblänkar</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <a href="{{ route('booking.calendar') }}" wire:navigate
               class="group flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 transition-colors hover:border-complement dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-complement-light">
                <x-bootstrap-icon name="calendar3" class="size-5 shrink-0 text-complement dark:text-complement-light" />
                <span class="text-sm font-medium text-zinc-700 group-hover:text-complement dark:text-zinc-300 dark:group-hover:text-complement-light">Kalender</span>
            </a>
            @if($fullAccess)
                <a href="{{ route('booking.create') }}" wire:navigate
                   class="group flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 transition-colors hover:border-complement dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-complement-light">
                    <x-bootstrap-icon name="plus-circle" class="size-5 shrink-0 text-complement dark:text-complement-light" />
                    <span class="text-sm font-medium text-zinc-700 group-hover:text-complement dark:text-zinc-300 dark:group-hover:text-complement-light">Boka lokal</span>
                </a>
                <button @click="$dispatch('set-tab', 'bokningar')"
                        class="group flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 text-left transition-colors hover:border-complement dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-complement-light">
                    <x-bootstrap-icon name="calendar-check" class="size-5 shrink-0 text-complement dark:text-complement-light" />
                    <span class="text-sm font-medium text-zinc-700 group-hover:text-complement dark:text-zinc-300 dark:group-hover:text-complement-light">Mina bokningar</span>
                </button>
                <a href="{{ route('booking.resources.index') }}" wire:navigate
                   class="group flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 transition-colors hover:border-complement dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-complement-light">
                    <x-bootstrap-icon name="door-open" class="size-5 shrink-0 text-complement dark:text-complement-light" />
                    <span class="text-sm font-medium text-zinc-700 group-hover:text-complement dark:text-zinc-300 dark:group-hover:text-complement-light">Rum &amp; lokaler</span>
                </a>
                <button @click="$dispatch('set-tab', 'ansokningar')"
                        class="group flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 text-left transition-colors hover:border-complement dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-complement-light">
                    <x-bootstrap-icon name="building" class="size-5 shrink-0 text-complement dark:text-complement-light" />
                    <span class="text-sm font-medium text-zinc-700 group-hover:text-complement dark:text-zinc-300 dark:group-hover:text-complement-light">Lokalansökningar</span>
                </button>
            @endif
            <button @click="$dispatch('set-tab', 'installningar')"
                    class="group flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 text-left transition-colors hover:border-complement dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-complement-light">
                <x-bootstrap-icon name="gear" class="size-5 shrink-0 text-complement dark:text-complement-light" />
                <span class="text-sm font-medium text-zinc-700 group-hover:text-complement dark:text-zinc-300 dark:group-hover:text-complement-light">Inställningar</span>
            </button>
        </div>
    </div>

    {{-- XP & Gamification --}}
    @if(Auth::user()?->member)
    <div class="mt-6">
        @livewire('shared.xp-widget')
    </div>
    @endif

</div>
