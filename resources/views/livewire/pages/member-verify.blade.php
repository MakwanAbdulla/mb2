<?php

use App\Services\MemberCardService;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Verifiera medlemskort');

new class extends Component {
    public int    $memberId;
    public string $token;
    public bool   $valid   = false;
    public bool   $expired = false;
    public string $verifiedAt = '';

    public function mount(int $member, string $token): void
    {
        $this->memberId = $member;
        $this->token    = $token;

        $this->valid = MemberCardService::verifyToken($member, $token, tolerance: 5);

        if ($this->valid) {
            $this->verifiedAt = now()->locale('sv')->format('H:i:s');
        } else {
            // Wider tolerance to distinguish expired vs outright invalid
            $this->expired = MemberCardService::verifyToken($member, $token, tolerance: 60);
        }
    }

    #[\Livewire\Attributes\Computed]
    public function member()
    {
        if (! $this->valid && ! $this->expired) {
            return null;
        }

        return \App\Models\Membership\Member::with('user', 'sections', 'membershipPeriods')
            ->find($this->memberId);
    }

    #[\Livewire\Attributes\Computed]
    public function currentPeriod()
    {
        return $this->member?->membershipPeriods
            ->where('year', now()->year)
            ->where('status', \App\Models\Membership\MembershipPeriod::STATUS_ACTIVE)
            ->first();
    }
}; ?>

<x-slot:header>
    <x-page-header
        title="Verifiera medlemskort"
        subtitle="Kontroll av Armagedon-medlemskap"
    />
</x-slot:header>

<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6">

    @if(($this->valid || $this->expired) && $this->member)

        {{-- ── VALID ─────────────────────────────────────────────────────────── --}}

        {{-- Status banner --}}
        @if($this->valid)
        <div class="mb-4 flex items-center gap-3 rounded-xl bg-green-50 px-5 py-4 ring-1 ring-green-200 dark:bg-green-900/20 dark:ring-green-800">
            <x-bootstrap-icon name="patch-check-fill" class="size-7 shrink-0 text-green-600 dark:text-green-400" />
            <div>
                <div class="font-semibold text-green-800 dark:text-green-300">Verifierad</div>
                <div class="text-sm text-green-700 dark:text-green-400">Verifierad kl. {{ $verifiedAt }}</div>
            </div>
        </div>
        @else
        <div class="mb-4 flex items-center gap-3 rounded-xl bg-amber-50 px-5 py-4 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:ring-amber-800">
            <x-bootstrap-icon name="clock-history" class="size-7 shrink-0 text-amber-600 dark:text-amber-400" />
            <div>
                <div class="font-semibold text-amber-800 dark:text-amber-300">QR-koden har gått ut</div>
                <div class="text-sm text-amber-700 dark:text-amber-400">Be medlemmen visa en ny kod — informationen nedan är fortfarande korrekt.</div>
            </div>
        </div>
        @endif

        {{-- Member card (read-only, no QR) --}}
        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-dark via-zinc-900 to-complement p-6 shadow-2xl ring-1 ring-white/10">

            <div class="pointer-events-none absolute inset-0 bg-gradient-to-tr from-white/5 via-transparent to-transparent"></div>

            {{-- Logo + name --}}
            <div class="mb-5 flex items-center justify-between">
                <img src="{{ asset('armagedon-logo-dark.svg') }}" alt="Armagedon" class="h-8">
                <span class="text-xs font-semibold uppercase tracking-widest text-zinc-400">Armagedon</span>
            </div>

            <div class="mb-5 h-0.5 rounded-full bg-gradient-to-r from-brand via-brand-orange to-complement opacity-70"></div>

            <div class="flex flex-col gap-5 sm:flex-row sm:items-start">

                {{-- Avatar + name --}}
                <div class="flex flex-col items-center gap-3 sm:items-start">
                    @if($this->member->user)
                    <img
                        src="{{ $this->member->user->avatarUrl(120) }}"
                        alt="{{ $this->member->full_name }}"
                        class="size-20 rounded-full object-cover ring-2 ring-brand/40 sm:size-24"
                    >
                    @else
                    <div class="flex size-20 items-center justify-center rounded-full bg-zinc-700 ring-2 ring-brand/40 sm:size-24">
                        <x-bootstrap-icon name="person-fill" class="size-10 text-zinc-400" />
                    </div>
                    @endif
                    <div class="text-center sm:text-left">
                        <div class="text-xl font-bold text-white">{{ $this->member->full_name }}</div>
                        <div class="mt-0.5 text-sm text-zinc-400">
                            Medlem sedan {{ $this->member->created_at->year }}
                        </div>
                        @if($this->member->membership_number)
                        <div class="mt-1 font-mono text-xs text-zinc-500">
                            #{{ $this->member->membership_number }}
                        </div>
                        @endif
                    </div>
                </div>

                <div class="h-px bg-white/10 sm:hidden"></div>

                {{-- Membership info --}}
                <div class="flex flex-1 flex-col gap-3">

                    @if($this->currentPeriod)
                        <div>
                            <div class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">Medlemskap</div>
                            <div class="text-base font-semibold text-white">
                                {{ match($this->currentPeriod->membership_type) {
                                    'single'   => 'Enskild',
                                    'family'   => 'Familj',
                                    'youth'    => 'Ungdom',
                                    'child'    => 'Barn',
                                    'student'  => 'Student',
                                    'senior'   => 'Senior',
                                    'honorary' => 'Hedersmedlem',
                                    default    => ucfirst($this->currentPeriod->membership_type),
                                } }}
                            </div>
                        </div>
                        <div>
                            <div class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">Status</div>
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-500/20 px-2.5 py-1 text-sm font-semibold text-green-400 ring-1 ring-green-500/30">
                                <x-bootstrap-icon name="check-circle-fill" class="size-3.5" />
                                Aktiv {{ $this->currentPeriod->year }}
                            </span>
                        </div>
                    @else
                        <div>
                            <div class="mb-1 text-xs font-medium uppercase tracking-wide text-zinc-500">Status</div>
                            <span class="inline-flex items-center gap-1 rounded-full bg-red-500/20 px-2.5 py-1 text-sm font-semibold text-red-400 ring-1 ring-red-500/30">
                                <x-bootstrap-icon name="x-circle-fill" class="size-3.5" />
                                Ej betald {{ now()->year }}
                            </span>
                        </div>
                    @endif

                    @if($this->member->sections->isNotEmpty())
                    <div>
                        <div class="mb-1.5 text-xs font-medium uppercase tracking-wide text-zinc-500">Grupper</div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($this->member->sections as $section)
                            <span class="inline-flex items-center rounded-full bg-complement/30 px-2.5 py-0.5 text-xs font-medium text-violet-300 ring-1 ring-complement/40">
                                {{ $section->name }}
                            </span>
                            @endforeach
                        </div>
                    </div>
                    @endif

                </div>
            </div>
        </div>

    @else

        {{-- ── INVALID ──────────────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-red-200 bg-red-50 p-8 text-center dark:border-red-800/50 dark:bg-red-900/20">
            <div class="mx-auto mb-4 flex size-16 items-center justify-center rounded-2xl bg-red-100 dark:bg-red-900/40">
                <x-bootstrap-icon name="shield-x" class="size-8 text-red-600 dark:text-red-400" />
            </div>
            <h2 class="mb-2 text-xl font-semibold text-red-800 dark:text-red-300">Ogiltig kod</h2>
            <p class="text-red-700 dark:text-red-400">
                Denna QR-kod är inte utfärdad av Armagedon.
            </p>
        </div>

    @endif

</div>
