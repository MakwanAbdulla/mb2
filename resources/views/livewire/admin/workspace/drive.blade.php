<?php

use App\Models\AuditLog;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\Section;
use App\Models\WorkspaceSharedDrive;
use App\Services\GoogleWorkspaceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Google Workspace – Drive');

new class extends Component {

    // Import form
    public bool   $showImport   = false;
    public string $importDriveId = '';

    // Create form
    public bool   $showCreate    = false;
    public string $createName    = '';
    public bool   $createPublic  = false;
    public string $createRole    = 'writer';
    public ?int   $createOrgRoleId = null;
    public ?int   $createSectionId = null;

    // Edit
    public ?int   $editingId     = null;
    public string $editName      = '';
    public bool   $editPublic    = false;
    public string $editRole      = 'writer';
    public ?int   $editOrgRoleId = null;
    public ?int   $editSectionId = null;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('workspace.manage'), 403);
    }

    #[Computed]
    public function drives(): \Illuminate\Support\Collection
    {
        return WorkspaceSharedDrive::with(['section', 'organizationalRole'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function apiDrives(): array
    {
        return app(GoogleWorkspaceService::class)->listSharedDrives() ?? [];
    }

    #[Computed]
    public function unregisteredDrives(): array
    {
        $registered = $this->drives->pluck('google_drive_id')->filter()->all();
        return array_filter($this->apiDrives, fn ($d) => ! in_array($d['id'], $registered, true));
    }

    #[Computed]
    public function sections(): \Illuminate\Support\Collection
    {
        return Section::orderBy('name')->get();
    }

    #[Computed]
    public function orgRoles(): \Illuminate\Support\Collection
    {
        return OrganizationalRole::orderBy('name')->get();
    }

    public function importDrive(): void
    {
        $this->validate(['importDriveId' => 'required|string']);

        $api = collect($this->apiDrives)->firstWhere('id', $this->importDriveId);
        if (! $api) {
            $this->addError('importDriveId', 'Drive-ID hittades inte i Workspace.');
            return;
        }

        WorkspaceSharedDrive::create([
            'google_drive_id' => $api['id'],
            'name'            => $api['name'],
            'member_role'     => 'writer',
            'public_access'   => false,
        ]);

        AuditLog::record('drive_imported', 'workspace', $api['name'], null);
        $this->importDriveId = '';
        $this->showImport    = false;
        unset($this->drives, $this->unregisteredDrives);
    }

    public function createDrive(): void
    {
        $this->validate([
            'createName'       => 'required|string|max:100',
            'createRole'       => 'required|in:reader,writer',
            'createOrgRoleId'  => 'nullable|integer|exists:organizational_roles,id',
            'createSectionId'  => 'nullable|integer|exists:sections,id',
        ]);

        $record = WorkspaceSharedDrive::create([
            'name'                   => $this->createName,
            'member_role'            => $this->createRole,
            'public_access'          => $this->createPublic,
            'organizational_role_id' => $this->createOrgRoleId,
            'section_id'             => $this->createSectionId,
        ]);

        $ws = app(GoogleWorkspaceService::class);
        if (! $ws->createSharedDrive($record)) {
            $this->addError('createName', 'Kunde inte skapa enheten i Google Workspace.');
            $record->delete();
            return;
        }

        $ws->syncDriveMembers();

        AuditLog::record('drive_created', 'workspace', $this->createName, $record);
        $this->createName       = '';
        $this->createPublic     = false;
        $this->createRole       = 'writer';
        $this->createOrgRoleId  = null;
        $this->createSectionId  = null;
        $this->showCreate       = false;
        unset($this->drives, $this->unregisteredDrives);
    }

    public function openEdit(int $id): void
    {
        $drive = WorkspaceSharedDrive::findOrFail($id);
        $this->editingId     = $id;
        $this->editName      = $drive->name;
        $this->editPublic    = $drive->public_access;
        $this->editRole      = $drive->member_role;
        $this->editOrgRoleId = $drive->organizational_role_id;
        $this->editSectionId = $drive->section_id;
    }

    public function saveDrive(): void
    {
        $this->validate([
            'editName'      => 'required|string|max:100',
            'editRole'      => 'required|in:reader,writer',
            'editOrgRoleId' => 'nullable|integer|exists:organizational_roles,id',
            'editSectionId' => 'nullable|integer|exists:sections,id',
        ]);

        $drive = WorkspaceSharedDrive::findOrFail($this->editingId);

        $publicChanged = $drive->public_access !== $this->editPublic;
        $roleChanged   = $drive->organizational_role_id !== $this->editOrgRoleId
                      || $drive->section_id !== $this->editSectionId
                      || $drive->member_role !== $this->editRole;

        $drive->update([
            'name'                   => $this->editName,
            'member_role'            => $this->editRole,
            'public_access'          => $this->editPublic,
            'organizational_role_id' => $this->editOrgRoleId,
            'section_id'             => $this->editSectionId,
        ]);

        $ws = app(GoogleWorkspaceService::class);

        if ($publicChanged && $drive->google_drive_id) {
            $ws->setPublicAccess($drive->google_drive_id, $this->editPublic);
        }
        if ($roleChanged && $drive->google_drive_id) {
            $ws->syncDriveMembers();
        }

        AuditLog::record('drive_updated', 'workspace', $this->editName, $drive);
        $this->editingId = null;
        unset($this->drives);
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    public function syncAll(): void
    {
        app(GoogleWorkspaceService::class)->syncDriveMembers();
        unset($this->drives);
        session()->flash('synced', 'Synkronisering klar.');
    }
}; ?>

<div>
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 space-y-6">

        {{-- Flash --}}
        @if(session('synced'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800/40 dark:bg-green-900/20 dark:text-green-400">
                {{ session('synced') }}
            </div>
        @endif

        {{-- Actions --}}
        <div class="flex items-center justify-between gap-3">
            <div class="flex gap-2">
                <button wire:click="$set('showImport', true)" class="btn btn-ghost btn-sm">
                    <x-bootstrap-icon name="box-arrow-in-down" class="size-4" /> Importera befintlig
                </button>
                <button wire:click="$set('showCreate', true)" class="btn btn-primary btn-sm">
                    <x-bootstrap-icon name="plus-lg" class="size-4" /> Ny enhet
                </button>
            </div>
            <button wire:click="syncAll" wire:loading.attr="disabled" class="btn btn-ghost btn-sm">
                <span wire:loading.remove wire:target="syncAll"><x-bootstrap-icon name="arrow-repeat" class="size-4" /> Synka nu</span>
                <span wire:loading wire:target="syncAll">Synkar…</span>
            </button>
        </div>

        {{-- Import form --}}
        @if($showImport)
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="mb-3 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Importera befintlig Shared Drive</h2>
                <div class="mb-3 overflow-hidden rounded-lg border border-zinc-100 dark:border-zinc-800">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400">Namn</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400">Drive-ID</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse($this->unregisteredDrives as $d)
                                <tr class="bg-white dark:bg-zinc-900">
                                    <td class="px-4 py-2 font-medium text-zinc-900 dark:text-white">{{ $d['name'] }}</td>
                                    <td class="px-4 py-2 font-mono text-xs text-zinc-400">{{ $d['id'] }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <button wire:click="$set('importDriveId', '{{ $d['id'] }}')"
                                                @class(['text-xs text-complement hover:underline dark:text-complement-light', 'font-bold' => $importDriveId === $d['id']])>
                                            Välj
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-3 text-center text-sm text-zinc-400">Alla drives är redan registrerade.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @error('importDriveId') <p class="mb-2 text-xs text-red-500">{{ $message }}</p> @enderror
                <div class="flex gap-2">
                    <button wire:click="importDrive" :disabled="!$wire.importDriveId" class="btn btn-primary btn-sm">Importera vald</button>
                    <button wire:click="$set('showImport', false)" class="btn btn-ghost btn-sm">Avbryt</button>
                </div>
            </div>
        @endif

        {{-- Create form --}}
        @if($showCreate)
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="mb-4 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Skapa ny Shared Drive</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="lbl">Namn</label>
                        <input wire:model="createName" type="text" class="inp mt-1 w-full" placeholder="t.ex. Styrelsehandlingar" />
                        @error('createName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">Åtkomstnivå för kopplade</label>
                        <select wire:model="createRole" class="inp mt-1 w-full">
                            <option value="writer">Redigera (writer)</option>
                            <option value="reader">Läsa (reader)</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Organisationsroll</label>
                        <select wire:model.number="createOrgRoleId" class="inp mt-1 w-full">
                            <option value="">— Ingen —</option>
                            @foreach($this->orgRoles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Sektion</label>
                        <select wire:model.number="createSectionId" class="inp mt-1 w-full">
                            <option value="">— Ingen —</option>
                            @foreach($this->sections as $section)
                                <option value="{{ $section->id }}">{{ $section->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" wire:model="createPublic" class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                            <span class="text-sm text-zinc-700 dark:text-zinc-300">Publik läsåtkomst (alla med länken kan läsa)</span>
                        </label>
                    </div>
                </div>
                <div class="mt-4 flex gap-2">
                    <button wire:click="createDrive" wire:loading.attr="disabled" class="btn btn-primary btn-sm">
                        <span wire:loading.remove wire:target="createDrive">Skapa</span>
                        <span wire:loading wire:target="createDrive">Skapar…</span>
                    </button>
                    <button wire:click="$set('showCreate', false)" class="btn btn-ghost btn-sm">Avbryt</button>
                </div>
            </div>
        @endif

        {{-- Drive list --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Enhet</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Koppling</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Åtkomst</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($this->drives as $drive)
                        <tr class="bg-white dark:bg-zinc-900">
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $drive->name }}</div>
                                @if($drive->google_drive_id)
                                    <a href="https://drive.google.com/drive/folders/{{ $drive->google_drive_id }}"
                                       target="_blank" rel="noopener"
                                       class="font-mono text-xs text-complement hover:underline dark:text-complement-light">
                                        Öppna i Drive →
                                    </a>
                                @else
                                    <span class="text-xs text-amber-500">Ej skapad i Google ännu</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($drive->organizationalRole)
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $drive->organizationalRole->name }}</span>
                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">Organisationsroll</div>
                                @elseif($drive->section)
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $drive->section->name }}</span>
                                    <div class="text-xs text-zinc-400 dark:text-zinc-500">Sektion</div>
                                @else
                                    <span class="text-xs text-zinc-400 dark:text-zinc-500">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        {{ $drive->member_role === 'writer' ? 'Redigera' : 'Läsa' }}
                                    </span>
                                    @if($drive->public_access)
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                            Publik länk
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button wire:click="openEdit({{ $drive->id }})"
                                        class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                                    Redigera
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-zinc-400 dark:text-zinc-500">
                                Inga Shared Drives registrerade ännu.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    </div>

    {{-- Edit slide-in --}}
    @if($editingId)
        <div class="fixed inset-0 z-40 flex justify-end">
            <div class="absolute inset-0 bg-black/30 dark:bg-black/50" wire:click="cancelEdit"></div>
            <div class="relative z-50 w-full max-w-sm overflow-y-auto bg-white shadow-xl dark:bg-zinc-900 sm:max-w-md">
                <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <h2 class="font-semibold text-zinc-900 dark:text-white">Redigera Shared Drive</h2>
                </div>
                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label class="lbl">Namn</label>
                        <input wire:model="editName" type="text" class="inp mt-1 w-full" />
                        @error('editName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">Åtkomstnivå för kopplade</label>
                        <select wire:model="editRole" class="inp mt-1 w-full">
                            <option value="writer">Redigera (writer)</option>
                            <option value="reader">Läsa (reader)</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Organisationsroll</label>
                        <select wire:model.number="editOrgRoleId" class="inp mt-1 w-full">
                            <option value="">— Ingen —</option>
                            @foreach($this->orgRoles as $role)
                                <option value="{{ $role->id }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Sektion</label>
                        <select wire:model.number="editSectionId" class="inp mt-1 w-full">
                            <option value="">— Ingen —</option>
                            @foreach($this->sections as $section)
                                <option value="{{ $section->id }}">{{ $section->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="checkbox" wire:model="editPublic" class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                            <span class="text-sm text-zinc-700 dark:text-zinc-300">Publik läsåtkomst</span>
                        </label>
                    </div>
                </div>
                <div class="flex gap-3 border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <button wire:click="saveDrive" wire:loading.attr="disabled" class="btn btn-primary">Spara</button>
                    <button wire:click="cancelEdit" class="btn btn-ghost">Avbryt</button>
                </div>
            </div>
        </div>
    @endif
</div>
