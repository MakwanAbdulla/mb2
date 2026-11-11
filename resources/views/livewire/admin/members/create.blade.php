<?php

use App\Models\AuditLog;
use App\Models\Membership\Member;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {

    public string $first_name = '';
    public string $last_name = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public string $postal_code = '';
    public string $city = '';
    public string $date_of_birth = '';
    public string $membership_type = Member::TYPE_SINGLE;
    public string $status = Member::STATUS_PENDING;
    public string $notes = '';
    public bool $create_account = false;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('members.manage'), 403);
    }

    public function save(): void
    {
        $this->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'email'           => 'required|email|max:255|unique:members,email',
            'phone'           => 'nullable|string|max:30',
            'address'         => 'nullable|string|max:255',
            'postal_code'     => 'nullable|string|max:10',
            'city'            => 'nullable|string|max:100',
            'date_of_birth'   => 'nullable|date|before:today',
            'membership_type' => 'required|in:single,family,student,honorary',
            'status'          => 'required|in:pending,active,inactive,expired,deceased',
            'notes'           => 'nullable|string|max:2000',
        ], [
            'first_name.required' => 'Förnamn är obligatoriskt.',
            'last_name.required'  => 'Efternamn är obligatoriskt.',
            'email.required'      => 'E-post är obligatorisk.',
            'email.email'         => 'Ange en giltig e-postadress.',
            'email.unique'        => 'Denna e-postadress är redan registrerad.',
            'date_of_birth.before' => 'Födelsedag måste vara i det förflutna.',
        ]);

        $member = Member::create([
            'first_name'      => $this->first_name,
            'last_name'       => $this->last_name,
            'email'           => $this->email,
            'phone'           => $this->phone ?: null,
            'address'         => $this->address ?: null,
            'postal_code'     => $this->postal_code ?: null,
            'city'            => $this->city ?: null,
            'date_of_birth'   => $this->date_of_birth ?: null,
            'membership_type' => $this->membership_type,
            'status'          => $this->status,
            'notes'           => $this->notes ?: null,
        ]);

        AuditLog::record('member_created', 'members', $member->full_name . ' (' . $member->email . ')', $member);

        session()->flash('success', "Medlemmen '{$member->full_name}' har registrerats.");
        $this->redirect(route('admin.members.show', $member), navigate: true);
    }
}; ?>

<div>

    <div class="border-b border-zinc-200 bg-gradient-to-r from-brand/10 via-brand-orange/5 to-complement/10 py-5 dark:border-zinc-800 dark:from-brand/5 dark:via-brand-orange/5 dark:to-complement/5">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Registrera ny medlem</h1>
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Administrativ registrering, utan krav på betalning.</p>
                </div>
                <a href="{{ route('admin.members.index') }}" wire:navigate
                   class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                    ← Tillbaka
                </a>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
        <form wire:submit="save" class="space-y-6">

            {{-- Personal details --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="bg-zinc-50 px-5 py-3 dark:bg-zinc-800">
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Personuppgifter</h2>
                </div>
                <div class="space-y-5 p-5">

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                Förnamn <span class="text-red-500">*</span>
                            </label>
                            <input wire:model="first_name" type="text" placeholder="Anna"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                            @error('first_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                Efternamn <span class="text-red-500">*</span>
                            </label>
                            <input wire:model="last_name" type="text" placeholder="Andersson"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                            @error('last_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            E-post <span class="text-red-500">*</span>
                        </label>
                        <input wire:model="email" type="email" placeholder="anna@exempel.se"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                        @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Telefon</label>
                            <input wire:model="phone" type="tel" placeholder="070-123 45 67"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                            @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Födelsedag</label>
                            <input wire:model="date_of_birth" type="date"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                            @error('date_of_birth') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                </div>
            </div>

            {{-- Address --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="bg-zinc-50 px-5 py-3 dark:bg-zinc-800">
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Adress</h2>
                </div>
                <div class="space-y-4 p-5">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Gatuadress</label>
                        <input wire:model="address" type="text" placeholder="Storgatan 1"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                        @error('address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-[1fr_2fr]">
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Postnummer</label>
                            <input wire:model="postal_code" type="text" placeholder="503 38"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                            @error('postal_code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Ort</label>
                            <input wire:model="city" type="text" placeholder="Borås"
                                   class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                            @error('city') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- Membership settings --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="bg-zinc-50 px-5 py-3 dark:bg-zinc-800">
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Medlemskap</h2>
                </div>
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Typ <span class="text-red-500">*</span>
                        </label>
                        <select wire:model="membership_type"
                                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                            <option value="{{ Member::TYPE_SINGLE }}">Vuxen</option>
                            <option value="{{ Member::TYPE_FAMILY }}">Familj</option>
                            <option value="{{ Member::TYPE_YOUTH }}">Ungdom</option>
                            <option value="{{ Member::TYPE_STUDENT }}">Student</option>
                            <option value="{{ Member::TYPE_SENIOR }}">Senior</option>
                            <option value="{{ Member::TYPE_HONORARY }}">Hedersmedlem</option>
                        </select>
                        @error('membership_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Status <span class="text-red-500">*</span>
                        </label>
                        <select wire:model="status"
                                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                            <option value="{{ Member::STATUS_PENDING }}">Väntar på betalning</option>
                            <option value="{{ Member::STATUS_ACTIVE }}">Aktiv (betalning bortbokad)</option>
                            <option value="{{ Member::STATUS_INACTIVE }}">Inaktiv</option>
                        </select>
                        @error('status') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- Notes --}}
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Anteckningar</label>
                <textarea wire:model="notes" rows="3" placeholder="Intern anteckning (visas ej för medlemmen)…"
                          class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500"></textarea>
                @error('notes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Actions --}}
            <div class="flex gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <button type="submit"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                    Registrera medlem
                </button>
                <a href="{{ route('admin.members.index') }}" wire:navigate
                   class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                    Avbryt
                </a>
            </div>

        </form>
    </div>
</div>
