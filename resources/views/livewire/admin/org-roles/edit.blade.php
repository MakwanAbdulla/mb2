<?php

use App\Models\AuditLog;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\Section;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    public OrganizationalRole $role;

    public string $name                        = '';
    public string $slug                        = '';
    public string $description                 = '';
    public bool   $cross_period_access          = false;
    public bool   $provision_google_workspace   = false;
    public string $discord_role_id = '';
    public ?int   $section_id     = null;

    #[Computed]
    public function sections()
    {
        return Section::orderBy('name')->get(['id', 'name']);
    }

    public function mount(OrganizationalRole $role): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('org-roles.manage'), 403);

        $this->role                       = $role;
        $this->name                       = $role->name;
        $this->slug                       = $role->slug;
        $this->description                = $role->description ?? '';
        $this->cross_period_access        = $role->cross_period_access;
        $this->provision_google_workspace = $role->provision_google_workspace;
        $this->discord_role_id            = $role->discord_role_id ?? '';
        $this->section_id                 = $role->section_id;
    }

    public function save(): void
    {
        $this->validate([
            'name'            => 'required|string|max:120',
            'slug'            => "required|string|max:120|unique:organizational_roles,slug,{$this->role->id}",
            'description'     => 'nullable|string|max:500',
            'discord_role_id' => 'nullable|string|max:30',
            'section_id'      => 'nullable|integer|exists:sections,id',
        ]);

        $this->role->update([
            'name'                       => $this->name,
            'slug'                       => $this->slug,
            'description'                => $this->description ?: null,
            'cross_period_access'        => $this->cross_period_access,
            'provision_google_workspace' => $this->provision_google_workspace,
            'discord_role_id'            => $this->discord_role_id ?: null,
            'section_id'                 => $this->section_id,
        ]);

        AuditLog::record('org_role_updated', 'org-roles', $this->role->name, $this->role);
        $this->redirect(route('admin.org-roles.show', $this->role), navigate: true);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="org-roles.manage" :title="'Redigera: ' . $role->name" subtitle="Organisationsroll">
        <a href="{{ route('admin.org-roles.show', $role) }}" wire:navigate
           class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
            ← Tillbaka
        </a>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
    <form wire:submit="save" class="space-y-6">

        <div>
            <label class="lbl" for="name">Namn <span class="text-red-500">*</span></label>
            <input id="name" type="text" wire:model="name" class="inp mt-1" />
            @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="lbl" for="slug">Slug <span class="text-red-500">*</span></label>
            <input id="slug" type="text" wire:model="slug" class="inp mt-1 font-mono text-sm" />
            @error('slug') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="lbl" for="description">Beskrivning</label>
            <textarea id="description" wire:model="description" rows="3" class="inp mt-1"></textarea>
        </div>

        <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Beteende</p>

            <div class="flex items-center gap-3">
                <input id="cross_period_access" type="checkbox" wire:model="cross_period_access" class="rounded border-zinc-300 text-complement" />
                <label for="cross_period_access" class="text-sm text-zinc-700 dark:text-zinc-300">
                    Korsperiodstillgång
                    <span class="ml-1 text-xs text-zinc-400">(kan se alla perioder, t.ex. Styrelsen)</span>
                </label>
            </div>

            <div class="flex items-center gap-3">
                <input id="provision_google_workspace" type="checkbox" wire:model="provision_google_workspace" class="rounded border-zinc-300 text-complement" />
                <label for="provision_google_workspace" class="text-sm text-zinc-700 dark:text-zinc-300">
                    Provisionera Google Workspace-konto
                    <span class="ml-1 text-xs text-zinc-400">(skapar/återaktiverar konto när en ledamot väljs in, avaktiverar när de lämnar)</span>
                </label>
            </div>
        </div>

        <div>
            <label class="lbl" for="discord_role_id">Discord Role ID</label>
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Högerklicka på rollen i Discord → Copy Role ID. Lämna tomt för ingen Discord-koppling.</p>
            <input id="discord_role_id" type="text" wire:model="discord_role_id"
                   class="inp mt-1 font-mono text-sm" placeholder="t.ex. 1234567890123456789" />
            @error('discord_role_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="lbl" for="section_id">Kopplad grupp</label>
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                Aktiva ledamöter synkas automatiskt som medlemmar i vald grupp. Lämna tomt för ingen koppling.
            </p>
            <select id="section_id" wire:model="section_id" class="inp mt-1">
                <option value="">— Ingen kopplad grupp —</option>
                @foreach($this->sections as $section)
                    <option value="{{ $section->id }}">{{ $section->name }}</option>
                @endforeach
            </select>
            @error('section_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit" class="btn btn-primary">Spara</button>
            <a href="{{ route('admin.org-roles.show', $role) }}" wire:navigate class="btn btn-ghost">Avbryt</a>
        </div>

    </form>
</div>
