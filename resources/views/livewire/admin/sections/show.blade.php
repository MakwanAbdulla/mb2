<?php

use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use App\Models\Organization\SectionMembership;
use Illuminate\Support\Facades\Auth;
use App\Models\Organization\SectionRole;
use Livewire\Volt\Component;

new class extends Component {
    public Section $section;

    public string $memberSearch = '';

    // Role form
    public ?int   $editRoleId    = null;
    public int    $roleMemberId  = 0;
    public string $roleTitle     = '';
    public bool   $roleIsAdmin   = false;
    public string $roleValidFrom  = '';
    public string $roleValidUntil = '';

    public function mount(Section $section): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('sections.manage'), 403);

        $this->section = $section->load([
            'parent',
            'children',
            'activity',
            'tags',
            'activeMembers',
            'pendingMemberships.member',
            'sectionRoles' => fn ($q) => $q->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))->with('member'),
        ]);
        $this->roleValidFrom = now()->format('Y-m-d');
    }

    public function removeMember(int $memberId): void
    {
        SectionMembership::removeActive($this->section->id, $memberId);
        AuditLog::record('section_member_removed', 'sections', $this->section->name, $this->section);

        $this->section->load(['activeMembers', 'pendingMemberships.member']);
    }

    public function addMember(int $memberId): void
    {
        $existing = SectionMembership::where('section_id', $this->section->id)
            ->where('member_id', $memberId)
            ->whereNull('left_at')
            ->exists();

        if (! $existing) {
            SectionMembership::updateOrCreate(
                ['section_id' => $this->section->id, 'member_id' => $memberId],
                ['status' => SectionMembership::STATUS_ACTIVE, 'joined_at' => now(), 'left_at' => null]
            );
        }

        AuditLog::record('section_member_added', 'sections', $this->section->name, $this->section);

        $this->memberSearch = '';
        $this->section->load(['activeMembers', 'pendingMemberships.member']);
    }

    public function approveMember(int $membershipId): void
    {
        SectionMembership::findOrFail($membershipId)->approve();
        AuditLog::record('section_member_approved', 'sections', $this->section->name, $this->section);
        $this->section->load(['activeMembers', 'pendingMemberships.member']);
    }

    public function denyMember(int $membershipId): void
    {
        SectionMembership::findOrFail($membershipId)->deny();
        AuditLog::record('section_member_denied', 'sections', $this->section->name, $this->section);
        $this->section->load(['activeMembers', 'pendingMemberships.member']);
    }

    public function startNewRole(int $memberId): void
    {
        $this->editRoleId     = null;
        $this->roleMemberId   = $memberId;
        $this->roleTitle      = '';
        $this->roleIsAdmin    = false;
        $this->roleValidFrom  = now()->format('Y-m-d');
        $this->roleValidUntil = '';
    }

    public function editRole(int $roleId): void
    {
        $role = $this->section->sectionRoles->find($roleId);
        if (! $role) {
            return;
        }
        $this->editRoleId     = $role->id;
        $this->roleMemberId   = $role->member_id;
        $this->roleTitle      = $role->title;
        $this->roleIsAdmin    = $role->is_admin;
        $this->roleValidFrom  = $role->valid_from->format('Y-m-d');
        $this->roleValidUntil = $role->valid_until?->format('Y-m-d') ?? '';
    }

    public function saveRole(): void
    {
        $this->validate([
            'roleMemberId'   => 'required|exists:members,id',
            'roleTitle'      => 'required|string|max:120',
            'roleValidFrom'  => 'required|date',
            'roleValidUntil' => 'nullable|date|after_or_equal:roleValidFrom',
        ]);

        $data = [
            'section_id'  => $this->section->id,
            'member_id'   => $this->roleMemberId,
            'title'       => $this->roleTitle,
            'is_admin'    => $this->roleIsAdmin,
            'valid_from'  => $this->roleValidFrom,
            'valid_until' => $this->roleValidUntil ?: null,
        ];

        if ($this->editRoleId) {
            SectionRole::findOrFail($this->editRoleId)->update($data);
        } else {
            SectionRole::create($data);
        }

        AuditLog::record('section_role_saved', 'sections', $this->section->name, $this->section);

        $this->resetRoleForm();
        $this->reloadRoles();
    }

    public function removeRole(int $roleId): void
    {
        $role = SectionRole::findOrFail($roleId);

        if ($role->is_admin) {
            $adminCount = SectionRole::where('section_id', $this->section->id)
                ->where('is_admin', true)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
                ->count();

            if ($adminCount <= 1) {
                $this->addError('removeRole', 'Det måste finnas minst en admin i gruppen.');
                return;
            }
        }

        $role->delete();
        AuditLog::record('section_role_removed', 'sections', $this->section->name, $this->section);
        $this->reloadRoles();
    }

    public function cancelRoleEdit(): void
    {
        $this->resetRoleForm();
    }

    private function resetRoleForm(): void
    {
        $this->editRoleId     = null;
        $this->roleMemberId   = 0;
        $this->roleTitle      = '';
        $this->roleIsAdmin    = false;
        $this->roleValidFrom  = now()->format('Y-m-d');
        $this->roleValidUntil = '';
        $this->resetValidation();
    }

    private function reloadRoles(): void
    {
        $this->section->load([
            'sectionRoles' => fn ($q) => $q->where(
                fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now())
            )->with('member'),
        ]);
    }

    public function searchResults()
    {
        if (strlen($this->memberSearch) < 2) {
            return collect();
        }

        $currentIds = $this->section->activeMembers->pluck('id');

        $lower = mb_strtolower($this->memberSearch);

        return Member::where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(first_name) LIKE ?', ["%{$lower}%"])
                  ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$lower}%"])
                  ->orWhereRaw('LOWER(email) LIKE ?', ["%{$lower}%"]);
            })
            ->whereNotIn('id', $currentIds)
            ->where('status', Member::STATUS_ACTIVE)
            ->limit(8)
            ->get();
    }
}; ?>

<x-slot:header>
    <x-page-header permission="sections.manage" :title="$section->name" subtitle="Grupp">
        <div class="flex gap-2">
            <a href="{{ route('admin.sections.edit', $section) }}" wire:navigate
               class="inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                Redigera
            </a>
            <a href="{{ route('admin.sections.index') }}" wire:navigate
               class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                ← Tillbaka
            </a>
        </div>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="grid gap-12 lg:grid-cols-3">

        {{-- Left: members --}}
        <div class="lg:col-span-2 space-y-10">

            {{-- Add member --}}
            <section x-data="{ focused: false }">
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Lägg till medlem</h2>
                <div class="relative">
                    <input type="text"
                           wire:model.live.debounce.200ms="memberSearch"
                           @focus="focused = true" @blur="setTimeout(() => focused = false, 150)"
                           class="inp w-full"
                           placeholder="Sök namn eller e-post…" />

                    @php $results = $this->searchResults(); @endphp
                    @if($results->isNotEmpty() && strlen($memberSearch) >= 2)
                        <ul class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                            @foreach($results as $m)
                                <li>
                                    <button type="button" wire:click="addMember({{ $m->id }})"
                                            class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700">
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $m->full_name }}</span>
                                        <span class="text-zinc-400 dark:text-zinc-500">{{ $m->email }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            {{-- Pending membership requests --}}
            @if($section->pendingMemberships->isNotEmpty())
                <section>
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-amber-500 dark:text-amber-400">
                        Väntande ansökningar ({{ $section->pendingMemberships->count() }})
                    </h2>
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach($section->pendingMemberships as $pending)
                            <li class="flex items-center gap-3 py-2.5">
                                <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                    {{ mb_strtoupper(mb_substr($pending->member->first_name, 0, 1)) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $pending->member->full_name }}</span>
                                    <span class="ml-1.5 text-xs text-zinc-400 dark:text-zinc-500">
                                        ansökte {{ $pending->requested_at?->diffForHumans() ?? '—' }}
                                    </span>
                                </div>
                                <div class="flex shrink-0 items-center gap-1.5">
                                    <button wire:click="approveMember({{ $pending->id }})"
                                            class="rounded-lg bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400 dark:hover:bg-green-900/50">
                                        Godkänn
                                    </button>
                                    <button wire:click="denyMember({{ $pending->id }})"
                                            wire:confirm="Avvisa {{ $pending->member->full_name }}s ansökan?"
                                            class="rounded-lg bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-600 hover:bg-red-100 hover:text-red-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-red-900/30 dark:hover:text-red-400">
                                        Avvisa
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Member list --}}
            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                    Medlemmar ({{ $section->activeMembers->count() }})
                </h2>

                @if($section->activeMembers->isEmpty())
                    <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga aktiva medlemmar i denna grupp.</p>
                @else
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach($section->activeMembers as $member)
                            <li class="flex items-center gap-3 py-2.5">
                                <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-xs font-bold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                    {{ mb_strtoupper(mb_substr($member->first_name, 0, 1)) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('admin.members.show', $member) }}" wire:navigate
                                       class="text-sm font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                                        {{ $member->full_name }}
                                    </a>
                                    @php $memberRoles = $section->sectionRoles->where('member_id', $member->id); @endphp
                                    @foreach($memberRoles as $role)
                                        <span class="ml-1.5 inline-flex items-center gap-0.5 rounded text-xs {{ $role->is_admin ? 'text-complement dark:text-complement-light' : 'text-zinc-400 dark:text-zinc-500' }}">
                                            @if($role->is_admin)<x-bootstrap-icon name="shield-fill" class="size-3" />@endif
                                            {{ $role->title }}
                                        </span>
                                    @endforeach
                                </div>
                                <button wire:click="removeMember({{ $member->id }})"
                                        wire:confirm="Ta bort {{ $member->full_name }} från gruppen?"
                                        class="shrink-0 rounded p-1 text-zinc-300 hover:text-red-500 dark:text-zinc-600 dark:hover:text-red-400">
                                    <x-bootstrap-icon name="x-lg" class="size-3.5" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Roles --}}
            <section>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Roller ({{ $section->sectionRoles->count() }})</h2>
                </div>

                @error('removeRole')
                    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                        {{ $message }}
                    </div>
                @enderror

                @if($section->activeMembers->isNotEmpty())
                    @if($section->sectionRoles->isEmpty())
                        <p class="mb-3 text-sm text-zinc-400 dark:text-zinc-500">Inga roller tilldelade.</p>
                    @else
                        <ul class="mb-3 divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($section->sectionRoles as $role)
                                <li class="flex items-start gap-2 py-2">
                                    @if($role->is_admin)
                                        <x-bootstrap-icon name="shield-fill" class="mt-0.5 size-3.5 shrink-0 text-complement dark:text-complement-light" />
                                    @else
                                        <x-bootstrap-icon name="person-badge" class="mt-0.5 size-3.5 shrink-0 text-zinc-400" />
                                    @endif
                                    <div class="min-w-0 flex-1 text-xs">
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $role->member->full_name }}</span>
                                        <span class="text-zinc-500 dark:text-zinc-400"> — {{ $role->title }}</span>
                                        <span class="ml-1 text-zinc-400 dark:text-zinc-500">
                                            {{ $role->valid_from->format('Y-m-d') }}@if($role->valid_until) – {{ $role->valid_until->format('Y-m-d') }}@endif
                                        </span>
                                    </div>
                                    <div class="flex shrink-0 gap-1">
                                        <button wire:click="editRole({{ $role->id }})" class="text-xs text-complement hover:underline dark:text-complement-light">Redigera</button>
                                        <button wire:click="removeRole({{ $role->id }})"
                                                wire:confirm="Ta bort rollen '{{ $role->title }}'?"
                                                class="text-xs text-zinc-400 hover:text-red-500 dark:hover:text-red-400">Ta bort</button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- Role form --}}
                    @if($roleMemberId || $editRoleId)
                        <form wire:submit="saveRole" class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50 space-y-3">
                            <p class="text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                {{ $editRoleId ? 'Redigera roll' : 'Ny roll' }}
                                @if($roleMemberId)
                                    — {{ $section->activeMembers->find($roleMemberId)?->full_name }}
                                @endif
                            </p>
                            <div>
                                <input type="text" wire:model="roleTitle" class="inp" placeholder="Titel t.ex. Ordförande" />
                                @error('roleTitle') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <input type="date" wire:model="roleValidFrom" class="inp" />
                                    @error('roleValidFrom') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <input type="date" wire:model="roleValidUntil" class="inp" />
                                    @error('roleValidUntil') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <input id="adm_roleIsAdmin" type="checkbox" wire:model="roleIsAdmin" class="rounded border-zinc-300 text-complement" />
                                <label for="adm_roleIsAdmin" class="text-xs text-zinc-700 dark:text-zinc-300">Gruppadmin</label>
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" class="btn btn-primary text-xs px-3 py-1.5">Spara</button>
                                <button type="button" wire:click="cancelRoleEdit" class="btn btn-ghost text-xs px-3 py-1.5">Avbryt</button>
                            </div>
                        </form>
                    @else
                        <div x-data="{ open: false }">
                            <button @click="open = !open"
                                    class="inline-flex items-center gap-1 text-xs text-complement hover:underline dark:text-complement-light">
                                <x-bootstrap-icon name="plus-lg" class="size-3" />
                                Lägg till roll
                            </button>
                            <div x-show="open" x-transition class="mt-2">
                                <select wire:change="startNewRole($event.target.value)"
                                        class="inp text-sm">
                                    <option value="">— Välj medlem —</option>
                                    @foreach($section->activeMembers as $m)
                                        <option value="{{ $m->id }}">{{ $m->full_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endif
                @else
                    <p class="text-xs text-zinc-400">Lägg till medlemmar innan du tilldelar roller.</p>
                @endif
            </section>

            {{-- Subgroups --}}
            @if($section->children->isNotEmpty())
                <section>
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Undergrupper</h2>
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach($section->children as $child)
                            <li class="flex items-center justify-between py-2.5">
                                <a href="{{ route('admin.sections.show', $child) }}" wire:navigate
                                   class="text-sm text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                                    {{ $child->name }}
                                </a>
                                @if(! $child->is_active)
                                    <span class="text-xs text-zinc-400">Inaktiv</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ route('admin.sections.create', ['parent' => $section->id]) }}" wire:navigate
                       class="mt-3 inline-flex items-center gap-1 text-xs text-complement hover:underline dark:text-complement-light">
                        <x-bootstrap-icon name="plus-lg" class="size-3" />
                        Lägg till undergrupp
                    </a>
                </section>
            @endif

        </div>

        {{-- Right sidebar --}}
        <div class="space-y-8">

            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Info</h2>
                <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @if($section->parent)
                        <div class="flex justify-between py-2.5">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Övergrupp</dt>
                            <dd class="text-sm">
                                <a href="{{ route('admin.sections.show', $section->parent) }}" wire:navigate
                                   class="text-complement hover:underline dark:text-complement-light">
                                    {{ $section->parent->name }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    <div class="flex justify-between py-2.5">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Status</dt>
                        <dd class="text-sm {{ $section->is_active ? 'text-green-600 dark:text-green-400' : 'text-zinc-400' }}">
                            {{ $section->is_active ? 'Aktiv' : 'Inaktiv' }}
                        </dd>
                    </div>
                    @if($section->activity)
                        <div class="flex justify-between py-2.5">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Aktivitet</dt>
                            <dd class="text-sm text-zinc-900 dark:text-white">{{ $section->activity->name }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between py-2.5">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Synlighet</dt>
                        <dd class="text-sm">
                            @php
                                $visibilityLabel = match($section->visibility) {
                                    'public'  => ['text' => 'Publik',      'class' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'],
                                    'members' => ['text' => 'Medlemmar',   'class' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'],
                                    'private' => ['text' => 'Privat',      'class' => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'],
                                    'secret'  => ['text' => 'Hemlig',      'class' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'],
                                    default   => ['text' => $section->visibility, 'class' => 'bg-zinc-100 text-zinc-500'],
                                };
                            @endphp
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $visibilityLabel['class'] }}">
                                {{ $visibilityLabel['text'] }}
                            </span>
                        </dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Medlemskapstyp</dt>
                        <dd class="text-sm">
                            @php
                                $membershipLabel = match($section->membership_type) {
                                    'mandatory'   => 'Obligatorisk',
                                    'open'        => 'Öppen',
                                    'application' => 'Ansökan',
                                    'closed'      => 'Stängd',
                                    'admin_only'  => 'Endast admin',
                                    default       => $section->membership_type,
                                };
                            @endphp
                            <span class="text-zinc-900 dark:text-white">{{ $membershipLabel }}</span>
                        </dd>
                    </div>
                    @if($section->membership_requires_active)
                        <div class="flex justify-between py-2.5">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Kräver aktivt med.</dt>
                            <dd class="text-sm text-amber-600 dark:text-amber-400">Ja</dd>
                        </div>
                    @endif
                </dl>
            </section>

            @if($section->description)
                <section>
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Beskrivning</h2>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $section->description }}</p>
                </section>
            @endif

            @if($section->tags->isNotEmpty())
                <section>
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Taggar</h2>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($section->tags as $tag)
                            <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                {{ $tag->name }}
                            </span>
                        @endforeach
                    </div>
                </section>
            @endif

        </div>
    </div>

    @php $sectionMailbox = \App\Models\InboxMailbox::forOwner($section); @endphp
    @if($sectionMailbox?->is_enabled)
        <div class="mt-12">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                <x-bootstrap-icon name="inbox" class="mr-1 inline size-3.5" />
                Inkorg · {{ $sectionMailbox->address() }}
            </h2>
            <livewire:admin.shared.inbox-panel :mailbox-id="$sectionMailbox->id" lazy />
        </div>
    @endif
</div>
