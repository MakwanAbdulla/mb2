<?php

use App\Models\AuditLog;
use App\Models\Membership\Member;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Redigera uppgifter');

new class extends Component {

    public string $first_name    = '';
    public string $last_name     = '';
    public string $email         = '';
    public string $phone         = '';
    public string $address       = '';
    public string $postal_code   = '';
    public string $city          = '';
    public string $date_of_birth = '';

    private ?Member $member = null;

    public function mount(): void
    {
        $member = Member::where('user_id', auth()->id())->first();

        if (! $member) {
            $this->redirect(route('membership.apply'), navigate: true);
            return;
        }

        $this->member        = $member;
        $this->first_name    = $member->first_name;
        $this->last_name     = $member->last_name;
        $this->email         = $member->email ?? '';
        $this->phone         = $member->phone ?? '';
        $this->address       = $member->address ?? '';
        $this->postal_code   = $member->postal_code ?? '';
        $this->city          = $member->city ?? '';
        $this->date_of_birth = $member->date_of_birth?->format('Y-m-d') ?? '';
    }

    public function save(): void
    {
        $member = Member::where('user_id', auth()->id())->first();

        if (! $member) {
            $this->redirect(route('membership.apply'), navigate: true);
            return;
        }

        $this->validate([
            'first_name'    => 'required|string|max:100',
            'last_name'     => 'required|string|max:100',
            'email'         => 'required|email|max:255',
            'phone'         => 'nullable|string|max:30',
            'address'       => 'nullable|string|max:255',
            'postal_code'   => 'nullable|string|max:10',
            'city'          => 'nullable|string|max:100',
            'date_of_birth' => 'nullable|date|before:today',
        ], [
            'first_name.required' => 'Förnamn är obligatoriskt.',
            'last_name.required'  => 'Efternamn är obligatoriskt.',
            'email.required'      => 'E-post är obligatorisk.',
            'email.email'         => 'Ange en giltig e-postadress.',
            'date_of_birth.before' => 'Ange ett giltigt födelsedatum.',
        ]);

        $member->update([
            'first_name'    => $this->first_name,
            'last_name'     => $this->last_name,
            'email'         => $this->email,
            'phone'         => $this->phone ?: null,
            'address'       => $this->address ?: null,
            'postal_code'   => $this->postal_code ?: null,
            'city'          => $this->city ?: null,
            'date_of_birth' => $this->date_of_birth ?: null,
        ]);

        AuditLog::record('member_profile_updated', 'members', $member->full_name, $member);
        session()->flash('success', 'Dina uppgifter har sparats.');
        $this->redirect(route('membership.show'), navigate: true);
    }
}; ?>

<div class="container mx-auto max-w-2xl px-4 py-12 sm:px-6">

    <div class="mb-8 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Redigera uppgifter</h1>
        <a href="{{ route('membership.show') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
            ← Tillbaka
        </a>
    </div>

    <form wire:submit="save" class="space-y-6">

        {{-- Personal details --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Personuppgifter</h2>
            </div>
            <div class="space-y-5 p-5">

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Förnamn <span class="text-red-500">*</span>
                        </label>
                        <input wire:model="first_name" type="text" autocomplete="given-name"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('first_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Efternamn <span class="text-red-500">*</span>
                        </label>
                        <input wire:model="last_name" type="text" autocomplete="family-name"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('last_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        E-post <span class="text-red-500">*</span>
                    </label>
                    <input wire:model="email" type="email" autocomplete="email"
                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Telefon</label>
                        <input wire:model="phone" type="tel" autocomplete="tel"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Födelsedag</label>
                        <input wire:model="date_of_birth" type="date" autocomplete="bday"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('date_of_birth') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

            </div>
        </div>

        {{-- Address --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Adress</h2>
            </div>
            <div class="space-y-4 p-5">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Gatuadress</label>
                    <input wire:model="address" type="text" autocomplete="street-address"
                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                    @error('address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_2fr]">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Postnummer</label>
                        <input wire:model="postal_code" type="text" autocomplete="postal-code"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('postal_code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Ort</label>
                        <input wire:model="city" type="text" autocomplete="address-level2"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('city') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit"
                    class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                Spara uppgifter
            </button>
            <a href="{{ route('membership.show') }}" wire:navigate
               class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                Avbryt
            </a>
        </div>

    </form>
</div>
