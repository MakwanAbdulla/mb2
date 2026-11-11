<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Roller & behörigheter');

new class extends Component {

    public ?int    $selectedId    = null;
    public string  $newRoleName   = '';
    public string  $editingLabel  = '';
    public array   $rolePerms     = [];
    public bool    $showCreate    = false;
    public ?int    $confirmDelete = null;

    // Grouping for all permissions — names only, labels come from the DB
    private const GROUPS = [
        'Medlemmar'    => ['members.view', 'members.edit', 'members.manage', 'membership-periods.manage'],
        'Betalningar'  => ['payments.view', 'payments.confirm', 'payments.import'],
        'Bokning'      => ['bookings.manage', 'bookings.categories', 'bookings.applications', 'bookings.statistics', 'bookings.super'],
        'Evenemang'    => ['events.view', 'events.create', 'events.manage-own', 'events.manage-all', 'events.check-in', 'events.economy'],
        'Butik'        => ['shop.manage'],
        'Bokföring'    => ['accounting.view', 'accounting.manage', 'accounting.admin', 'accounting.approve'],
        'Formulär'     => ['forms.manage'],
        'Utskick'      => ['mailings.manage', 'surveys.manage'],
        'Möten'        => ['meetings.view', 'meetings.manage', 'tasks.view', 'tasks.manage', 'submissions.view', 'submissions.manage'],
        'E-signering'  => ['signing.create', 'signing.view', 'signing.manage'],
        'Wiki'         => ['wiki.view', 'wiki.edit', 'wiki.manage'],
        'Uthyrning'    => ['rentals.manage'],
        'RFID'         => ['tags.manage'],
        'Webbplats'    => ['cms.edit', 'cms.publish', 'notices.manage', 'help.manage'],
        'Organisation' => ['sections.manage', 'org-roles.manage'],
        'Workspace'    => ['workspace.manage'],
        'Statistik'    => ['statistics.view'],
        'Gamification'  => ['gamification.manage'],
        'Turneringar'   => ['tournaments.view', 'tournaments.manage'],
        'System'       => ['settings.manage', 'users.manage-roles', 'audit.view', 'api.access'],
    ];

    // Roles created by the seeder — cannot be deleted
    private const SYSTEM_ROLES = ['admin', 'treasurer', 'member-manager', 'booking-admin', 'website-editor', 'event-organizer', 'shop-manager', 'styrelse', 'revisor', 'rental-manager'];

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('users.manage-roles'), 403);
    }

    #[\Livewire\Attributes\Computed]
    public function roles()
    {
        return Role::withCount('users')->orderBy('name')->get();
    }

    #[\Livewire\Attributes\Computed]
    public function groups(): array
    {
        $labels = Permission::whereIn('name', collect(self::GROUPS)->flatten()->all())
            ->pluck('label', 'name');

        return collect(self::GROUPS)->map(
            fn (array $names) => collect($names)->mapWithKeys(
                fn (string $name) => [$name => $labels[$name] ?? $name]
            )->all()
        )->all();
    }

    #[\Livewire\Attributes\Computed]
    public function selectedRole(): ?Role
    {
        return $this->selectedId ? Role::find($this->selectedId) : null;
    }

    public function selectRole(int $id): void
    {
        $this->selectedId    = $id;
        $this->confirmDelete = null;
        $this->showCreate    = false;

        $role                = Role::findOrFail($id);
        $this->editingLabel  = $role->label ?? '';
        $this->rolePerms     = $role->permissions->pluck('name')->toArray();
    }

    public function savePermissions(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('users.manage-roles'), 403);

        $role = Role::findOrFail($this->selectedId);

        // Guard: never strip all permissions from admin
        if ($role->name === 'admin' && empty($this->rolePerms)) {
            $this->addError('rolePerms', 'Admin-rollen kan inte tömmas på behörigheter.');
            return;
        }

        $label = trim($this->editingLabel);
        $role->label = $label ?: null;
        $role->save();

        $permissions = Permission::whereIn('name', $this->rolePerms)->get();
        $role->syncPermissions($permissions);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        AuditLog::record('role_permissions_synced', 'system', $role->name . ' (' . count($this->rolePerms) . ' behörigheter)', $role);
    }

    public function createRole(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('users.manage-roles'), 403);

        $this->validate(['newRoleName' => ['required', 'string', 'max:50', 'unique:roles,name']],
            ['newRoleName.required' => 'Ange ett namn.', 'newRoleName.unique' => 'Det finns redan en roll med det namnet.']);

        $role = Role::create(['name' => $this->newRoleName]);
        AuditLog::record('role_created', 'system', $role->name, $role);

        $this->newRoleName = '';
        $this->showCreate  = false;
        $this->selectRole($role->id);
    }

    public function deleteRole(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('users.manage-roles'), 403);

        $role = Role::findOrFail($id);

        if (in_array($role->name, self::SYSTEM_ROLES, true)) {
            return; // Silently ignore — button should be hidden anyway
        }

        if ($this->selectedId === $id) {
            $this->selectedId = null;
            $this->rolePerms  = [];
        }

        $this->confirmDelete = null;
        AuditLog::record('role_deleted', 'system', $role->name, null);
        $role->delete();
    }

    public function isSystemRole(string $name): bool
    {
        return in_array($name, self::SYSTEM_ROLES, true);
    }
}; ?>

<div class="container mx-auto max-w-6xl px-4 py-8 sm:px-6">

    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Roller & behörigheter</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Skapa roller och styr vilka behörigheter de ger.
            </p>
        </div>
        <button wire:click="$set('showCreate', true)" x-on:click="$nextTick(() => $refs.newRoleName?.focus())"
                class="btn btn-primary btn-sm">
            <x-bootstrap-icon name="plus-lg" class="size-4" /> Ny roll
        </button>
    </div>

    {{-- Create form --}}
    @if($showCreate)
        <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Skapa ny roll</h2>
            <div class="flex items-end gap-3">
                <div class="w-64">
                    <label class="lbl">Rollnamn</label>
                    <input wire:model="newRoleName" x-ref="newRoleName" type="text"
                           placeholder="t.ex. event-manager"
                           class="inp mt-1 w-full"
                           wire:keydown.enter="createRole" />
                    @error('newRoleName') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <button wire:click="createRole" class="btn btn-primary btn-sm">Skapa</button>
                <button wire:click="$set('showCreate', false)" class="btn btn-ghost btn-sm">Avbryt</button>
            </div>
            <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">
                Använd engelska, gemener, bindestreck. Efter skapandet kan du tilldela behörigheter.
            </p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[280px_1fr]">

        {{-- ── Role list ──────────────────────────────────────────────────── --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Roller</span>
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($this->roles as $role)
                    <li>
                        <button wire:click="selectRole({{ $role->id }})"
                                @class([
                                    'group w-full px-4 py-3 text-left transition-colors',
                                    'bg-brand/10 dark:bg-brand/5' => $selectedId === $role->id,
                                    'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' => $selectedId !== $role->id,
                                ])>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if($this->isSystemRole($role->name))
                                        <x-bootstrap-icon name="shield-fill" class="size-3.5 shrink-0 text-zinc-400 dark:text-zinc-500" />
                                    @else
                                        <x-bootstrap-icon name="person-fill-gear" class="size-3.5 shrink-0 text-complement-light dark:text-complement-light" />
                                    @endif
                                    <div class="min-w-0">
                                        <span @class([
                                            'block truncate text-sm font-medium',
                                            'text-zinc-900 dark:text-white' => $selectedId === $role->id,
                                            'text-zinc-700 dark:text-zinc-300' => $selectedId !== $role->id,
                                        ])>{{ $role->label ?: $role->name }}</span>
                                        @if($role->label)
                                            <span class="block truncate font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $role->name }}</span>
                                        @endif
                                    </div>
                                </div>
                                <span class="ml-2 shrink-0 text-xs text-zinc-400 dark:text-zinc-500">
                                    {{ $role->users_count }}
                                    <span class="sr-only">användare</span>
                                </span>
                            </div>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- ── Permission editor ───────────────────────────────────────────── --}}
        @if($this->selectedRole)
            @php $role = $this->selectedRole; @endphp
            <div class="space-y-4">

                {{-- Header --}}
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1 space-y-3">
                        <div class="flex items-center gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                                    {{ $role->label ?: $role->name }}
                                </h2>
                                <p class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $role->name }} &middot; {{ $role->users_count }} användare</p>
                            </div>
                            @if($this->isSystemRole($role->name))
                                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                    <x-bootstrap-icon name="shield-fill" class="size-3" /> Systemroll
                                </span>
                            @endif
                        </div>
                        <div>
                            <label class="lbl" for="editingLabel">Visningsnamn</label>
                            <input id="editingLabel" type="text" wire:model="editingLabel"
                                   class="inp mt-1 w-72"
                                   placeholder="{{ $role->name }}" />
                            <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Visas för administratörer. Lämna tomt för att använda systemkoden.</p>
                        </div>
                    </div>
                    @if(! $this->isSystemRole($role->name))
                        <div x-data="{ confirming: false }">
                            <button type="button" @click="confirming = true" x-show="!confirming"
                                    class="btn btn-danger btn-sm">
                                <x-bootstrap-icon name="trash3" class="size-3.5" /> Ta bort roll
                            </button>
                            <div x-show="confirming" x-cloak class="flex items-center gap-2">
                                <span class="text-sm text-red-600 dark:text-red-400">Säker?</span>
                                <button type="button" wire:click="deleteRole({{ $role->id }})"
                                        class="btn btn-danger btn-sm">Ja, ta bort</button>
                                <button type="button" @click="confirming = false" class="btn btn-ghost btn-sm">Avbryt</button>
                            </div>
                        </div>
                    @endif
                </div>

                @error('rolePerms')
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-400">
                        {{ $message }}
                    </div>
                @enderror

                {{-- Permission groups --}}
                @foreach($this->groups as $groupName => $permissions)
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $groupName }}</span>
                        </div>
                        <div class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                            @foreach($permissions as $permName => $label)
                                <label class="flex cursor-pointer items-center gap-4 px-5 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <input type="checkbox"
                                           wire:model="rolePerms"
                                           value="{{ $permName }}"
                                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                                    <div class="min-w-0 flex-1">
                                        <span class="text-sm text-zinc-800 dark:text-zinc-200">{{ $label }}</span>
                                        <span class="ml-2 font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $permName }}</span>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                {{-- Save button --}}
                <div class="flex justify-end">
                    <button wire:click="savePermissions" wire:loading.attr="disabled"
                            class="btn btn-primary">
                        <span wire:loading.remove wire:target="savePermissions">
                            <x-bootstrap-icon name="check2" class="size-4" /> Spara behörigheter
                        </span>
                        <span wire:loading wire:target="savePermissions">Sparar…</span>
                    </button>
                </div>

            </div>
        @else
            {{-- Empty state --}}
            <div class="flex items-center justify-center rounded-xl border border-dashed border-zinc-200 bg-white py-20 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-center">
                    <x-bootstrap-icon name="person-fill-gear" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Välj en roll till vänster för att redigera dess behörigheter.</p>
                </div>
            </div>
        @endif

    </div>
</div>
