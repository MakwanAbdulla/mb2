<?php

use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Min sida');

new class extends Component {
    public bool $fullAccess = false;
    public bool $hasMember = false;

    public function mount(): void
    {
        $user = auth()->user();
        $this->hasMember = $user->member !== null;
        $this->fullAccess = $user->isAdmin() || ($user->member?->hasFullAccess() ?? false);
    }
}; ?>

<x-slot:header>
    <x-page-header
        :title="'Välkommen, ' . auth()->user()->name"
        :subtitle="now()->locale('sv')->isoFormat('dddd D MMMM YYYY')"
    />
</x-slot:header>

<div x-data="{
    tab: window.location.hash.slice(1) || 'oversikt',
    setTab(t) { this.tab = t; history.replaceState(null, '', '#' + t); }
}"
x-init="
    window.addEventListener('hashchange', () => { tab = window.location.hash.slice(1) || 'oversikt'; });
    window.addEventListener('set-tab', e => setTab(e.detail));
">

    @livewire('signing.pending-for-me')

    {{-- Sticky pill nav --}}
    <div class="sticky z-10 border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
         style="top: var(--header-height, 3.5rem)">
        <div class="mx-auto max-w-4xl px-4 py-2.5 sm:px-6">
            <nav class="flex flex-wrap gap-1.5">

                <button @click="setTab('oversikt')"
                        :class="tab === 'oversikt'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="house" class="size-4 shrink-0" />
                    Översikt
                </button>

                <button @click="setTab('medlemskap')"
                        :class="tab === 'medlemskap'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="person-badge" class="size-4 shrink-0" />
                    Medlemskap
                </button>

                @if($this->fullAccess)
                <button @click="setTab('grupper')"
                        :class="tab === 'grupper'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="people" class="size-4 shrink-0" />
                    Grupper
                </button>

                <button @click="setTab('familj')"
                        :class="tab === 'familj'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="house-heart" class="size-4 shrink-0" />
                    Familj
                </button>

                <button @click="setTab('bokningar')"
                        :class="tab === 'bokningar'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="calendar-check" class="size-4 shrink-0" />
                    Bokningar
                </button>

                <button @click="setTab('ansokningar')"
                        :class="tab === 'ansokningar'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="building" class="size-4 shrink-0" />
                    Lokalansökningar
                </button>

                <button @click="setTab('hyror')"
                        :class="tab === 'hyror'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="key" class="size-4 shrink-0" />
                    Hyror
                </button>
                @endif

                <button @click="setTab('tagg')"
                        :class="tab === 'tagg'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="broadcast" class="size-4 shrink-0" />
                    RFID-tagg
                </button>

                <button @click="setTab('fakturor')"
                        :class="tab === 'fakturor'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="receipt" class="size-4 shrink-0" />
                    Fakturor
                </button>

                <button @click="setTab('meddelanden')"
                        :class="tab === 'meddelanden'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="envelope" class="size-4 shrink-0" />
                    Meddelanden
                </button>

                @if($this->hasMember)
                <button @click="setTab('utlagg')"
                        :class="tab === 'utlagg'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="receipt" class="size-4 shrink-0" />
                    Utlägg
                </button>

                <button @click="setTab('arenden')"
                        :class="tab === 'arenden'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="inbox" class="size-4 shrink-0" />
                    Ärenden
                </button>
                @endif

                <button @click="setTab('installningar')"
                        :class="tab === 'installningar'
                            ? 'bg-zinc-100 font-semibold text-zinc-900 dark:bg-zinc-700 dark:text-white'
                            : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm transition-colors">
                    <x-bootstrap-icon name="gear" class="size-4 shrink-0" />
                    Inställningar
                </button>

            </nav>
        </div>
    </div>

    {{-- Content --}}
    <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6">

        <div x-show="tab === 'oversikt'">
            @livewire('dashboard.oversikt')
        </div>

        <div x-show="tab === 'medlemskap'">
            @livewire('dashboard.membership')
        </div>

        <div x-show="tab === 'grupper'">
            @livewire('dashboard.groups')
        </div>

        <div x-show="tab === 'familj'">
            @livewire('family.overview')
        </div>

        @if($this->fullAccess)
        <div x-show="tab === 'bokningar'">
            @livewire('booking.my-bookings')
        </div>
        @endif

        <div x-show="tab === 'ansokningar'">
            @livewire('booking.my-applications')
        </div>

        <div x-show="tab === 'hyror'">
            @livewire('dashboard.rentals')
        </div>

        <div x-show="tab === 'tagg'">
            @livewire('dashboard.tags')
        </div>

        <div x-show="tab === 'fakturor'">
            @livewire('dashboard.invoices')
        </div>

        <div x-show="tab === 'meddelanden'">
            @livewire('dashboard.messages')
        </div>

        @if($this->hasMember)
        <div x-show="tab === 'utlagg'">
            @livewire('member.expense-claims.index', ['embedded' => true])
        </div>

        <div x-show="tab === 'arenden'">
            @livewire(\App\Livewire\Member\SubmissionIndex::class)
        </div>
        @endif

        <div x-show="tab === 'installningar'">
            @livewire('dashboard.settings')
        </div>

    </div>

</div>
