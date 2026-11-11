<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Livewire\Volt\layout;
use function Livewire\Volt\title;

layout('components.layouts.public');
title('Användare utan medlemskap');

new class extends Component
{
    public ?int $selectedId = null;

    public array $assignedRoles = [];

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('users.manage-roles'), 403);
    }

    #[Computed]
    public function users()
    {
        return User::whereDoesntHave('member')->with('roles')->orderBy('name')->get();
    }

    #[Computed]
    public function allRoles()
    {
        return Role::orderBy('name')->get();
    }

    #[Computed]
    public function selectedUser(): ?User
    {
        return $this->selectedId ? User::find($this->selectedId) : null;
    }

    public function selectUser(int $id): void
    {
        $this->selectedId = $id;
        $user = User::findOrFail($id);
        $this->assignedRoles = $user->roles->pluck('name')->toArray();
    }

    public function saveRoles(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('users.manage-roles'), 403);

        $user = User::findOrFail($this->selectedId);

        // Guard: never let an admin remove their own admin role here either
        if ($user->id === Auth::id() && ! in_array('admin', $this->assignedRoles, true) && $user->hasRole('admin')) {
            $this->addError('assignedRoles', 'Du kan inte ta bort admin-rollen från dig själv.');

            return;
        }

        $user->syncRoles($this->assignedRoles);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        AuditLog::record('user_roles_synced', 'users', $user->name.' — '.implode(', ', $this->assignedRoles ?: ['(inga)']), $user);

        session()->flash('success', 'Roller sparade.');
        unset($this->users);
    }
}; ?>

<div class="container mx-auto max-w-5xl px-4 py-8 sm:px-6">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Användare utan medlemskap</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Konton (t.ex. inloggade via Google) som inte är kopplade till någon medlemspost —
            de syns inte i medlemslistan och kan bara hanteras här.
        </p>
    </div>

    @if (session('success'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800/40 dark:bg-green-900/20 dark:text-green-400">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[320px_1fr]">

        {{-- ── User list ──────────────────────────────────────────────────── --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                    Konton ({{ $this->users->count() }})
                </span>
            </div>
            @if ($this->users->isEmpty())
                <p class="px-4 py-6 text-sm text-zinc-400 dark:text-zinc-500">Inga konton utan medlemskap just nu.</p>
            @else
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($this->users as $user)
                        <li>
                            <button wire:click="selectUser({{ $user->id }})"
                                    @class([
                                        'group w-full px-4 py-3 text-left transition-colors',
                                        'bg-brand/10 dark:bg-brand/5' => $selectedId === $user->id,
                                        'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' => $selectedId !== $user->id,
                                    ])>
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $user->name }}</span>
                                <span class="block truncate text-xs text-zinc-400 dark:text-zinc-500">{{ $user->email }}</span>
                                @if ($user->roles->isNotEmpty())
                                    <span class="mt-1 block truncate text-xs text-complement dark:text-complement-light">
                                        {{ $user->roles->pluck('name')->implode(', ') }}
                                    </span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- ── Role editor ─────────────────────────────────────────────────── --}}
        @if ($this->selectedUser)
            @php $user = $this->selectedUser; @endphp
            <div class="space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $user->name }}</h2>
                    <p class="text-xs text-zinc-400 dark:text-zinc-500">{{ $user->email }}</p>
                </div>

                @error('assignedRoles')
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-400">
                        {{ $message }}
                    </div>
                @enderror

                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Roller</span>
                    </div>
                    <div class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                        @foreach ($this->allRoles as $role)
                            <label class="flex cursor-pointer items-center gap-4 px-5 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <input type="checkbox"
                                       wire:model="assignedRoles"
                                       value="{{ $role->name }}"
                                       class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                                <span class="text-sm text-zinc-800 dark:text-zinc-200">
                                    {{ $role->label ?: $role->name }}
                                    <span class="ml-1 font-mono text-xs text-zinc-400 dark:text-zinc-500">({{ $role->name }})</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end">
                    <button wire:click="saveRoles" wire:loading.attr="disabled" class="btn btn-primary">
                        <span wire:loading.remove wire:target="saveRoles">Spara roller</span>
                        <span wire:loading wire:target="saveRoles">Sparar…</span>
                    </button>
                </div>
            </div>
        @else
            <div class="flex items-center justify-center rounded-xl border border-dashed border-zinc-200 bg-white py-20 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Välj ett konto till vänster för att hantera dess roller.</p>
            </div>
        @endif

    </div>
</div>
