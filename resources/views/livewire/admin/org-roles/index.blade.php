<?php

use App\Models\AuditLog;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\OrganizationalRolePeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('org-roles.manage'), 403);
    }

    public bool $showCreateForm = false;

    public string $name = '';
    public string $slug = '';
    public string $description = '';
    public bool $cross_period_access = false;

    public function updatedName(): void
    {
        if (! $this->slug || $this->slug === Str::slug($this->name)) {
            $this->slug = Str::slug($this->name);
        }
    }

    public function save(): void
    {
        $this->validate([
            'name'        => 'required|string|max:120',
            'slug'        => 'required|string|max:120|unique:organizational_roles,slug',
            'description' => 'nullable|string|max:500',
        ]);

        $orgRole = OrganizationalRole::create([
            'name'                => $this->name,
            'slug'                => $this->slug,
            'description'         => $this->description ?: null,
            'cross_period_access' => $this->cross_period_access,
        ]);

        AuditLog::record('org_role_created', 'org-roles', $orgRole->name, $orgRole);
        $this->reset('name', 'slug', 'description', 'cross_period_access');
        $this->showCreateForm = false;
    }

    public function deleteRole(int $id): void
    {
        $role = OrganizationalRole::findOrFail($id);

        if ($role->periods()->exists()) {
            $this->addError('delete', 'Rollen har perioder och kan inte tas bort.');
            return;
        }

        AuditLog::record('org_role_deleted', 'org-roles', $role->name, null);
        $role->delete();
    }
}; ?>

<x-slot:header>
    <x-page-header permission="org-roles.manage" title="Organisationsroller" subtitle="Administration" />
</x-slot:header>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">

    @if(! $showCreateForm)
        <div class="mb-6 flex justify-end">
            <button wire:click="$set('showCreateForm', true)"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                <x-bootstrap-icon name="plus-lg" class="size-4" />
                Ny roll
            </button>
        </div>
    @endif

    @if($showCreateForm)
        <div class="mb-8 rounded-xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-700 dark:bg-zinc-800/50">
            <h2 class="mb-4 text-sm font-semibold text-zinc-900 dark:text-white">Ny organisationsroll</h2>
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="lbl" for="name">Namn <span class="text-red-500">*</span></label>
                        <input id="name" type="text" wire:model.live="name" class="inp mt-1" placeholder="t.ex. Styrelsen" />
                        @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl" for="slug">Slug <span class="text-red-500">*</span></label>
                        <input id="slug" type="text" wire:model="slug" class="inp mt-1 font-mono text-sm" />
                        @error('slug') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="lbl" for="description">Beskrivning</label>
                    <input id="description" type="text" wire:model="description" class="inp mt-1" placeholder="Valfri beskrivning" />
                </div>
                <div class="flex items-center gap-3">
                    <input id="cross_period_access" type="checkbox" wire:model="cross_period_access" class="rounded border-zinc-300 text-complement" />
                    <label for="cross_period_access" class="text-sm text-zinc-700 dark:text-zinc-300">
                        Korsperiodstillgång
                        <span class="ml-1 text-xs text-zinc-400">(kan se alla perioder, t.ex. Styrelsen)</span>
                    </label>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="submit" class="btn btn-primary">Spara roll</button>
                    <button type="button" wire:click="$set('showCreateForm', false)" class="btn btn-ghost">Avbryt</button>
                </div>
            </form>
        </div>
    @endif

    @php
        $roles = OrganizationalRole::withCount('periods')
            ->with(['periods' => fn ($q) => $q->where('status', '!=', OrganizationalRolePeriod::STATUS_CLOSED)->orderByDesc('active_start')])
            ->orderBy('name')
            ->get();
    @endphp

    @if($roles->isEmpty())
        <div class="py-16 text-center">
            <x-bootstrap-icon name="person-badge" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga organisationsroller skapade ännu.</p>
        </div>
    @else
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
            @foreach($roles as $role)
                <div class="group flex items-start gap-4 py-4">
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('admin.org-roles.show', $role) }}" wire:navigate
                               class="text-sm font-semibold text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                                {{ $role->name }}
                            </a>
                            @if($role->cross_period_access)
                                <span class="rounded-full bg-complement/10 px-2 py-0.5 text-xs text-complement dark:bg-complement/20 dark:text-complement-light">
                                    Korsperiod
                                </span>
                            @endif
                        </div>
                        @if($role->description)
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $role->description }}</p>
                        @endif
                        @if($role->periods->isNotEmpty())
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                @foreach($role->periods as $period)
                                    @php
                                        $statusColor = match($period->status) {
                                            'active'    => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                                            'forming'   => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                            'reporting' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                            default     => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
                                        };
                                        $statusLabel = match($period->status) {
                                            'active'    => 'Aktiv',
                                            'forming'   => 'Bildas',
                                            'reporting' => 'Rapportering',
                                            default     => 'Stängd',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs {{ $statusColor }}">
                                        {{ $period->name }}
                                        <span class="opacity-70">· {{ $statusLabel }}</span>
                                    </span>
                                @endforeach
                            </div>
                        @else
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">{{ $role->periods_count }} {{ $role->periods_count === 1 ? 'period' : 'perioder' }} (alla stängda)</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                        <a href="{{ route('admin.org-roles.show', $role) }}" wire:navigate
                           title="Visa"
                           class="rounded p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
                            <x-bootstrap-icon name="arrow-right" class="size-3.5" />
                        </a>
                        <a href="{{ route('admin.org-roles.edit', $role) }}" wire:navigate
                           title="Redigera"
                           class="rounded p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
                            <x-bootstrap-icon name="pencil" class="size-3.5" />
                        </a>
                        <button wire:click="deleteRole({{ $role->id }})"
                                wire:confirm="Ta bort rollen '{{ $role->name }}'? Detta går inte att ångra."
                                title="Ta bort"
                                class="rounded p-1.5 text-zinc-400 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400">
                            <x-bootstrap-icon name="trash" class="size-3.5" />
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</div>
