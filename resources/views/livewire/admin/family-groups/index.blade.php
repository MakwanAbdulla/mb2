<?php

use App\Mail\FamilyInvitationMail;
use App\Models\AuditLog;
use App\Models\Membership\FamilyGroup;
use App\Models\Membership\Member;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Familjegrupper');

new class extends Component {

    public string $search          = '';
    public ?int   $selectedGroupId = null;
    public string $flashMessage    = '';

    // ── Add member to existing group ──────────────────────────────────────────
    public ?int   $addToGroupId   = null;
    public string $addMemberSearch = '';
    public ?int   $addMemberId    = null;
    public string $addRole        = FamilyGroup::ROLE_ADULT;

    // ── Create new group ──────────────────────────────────────────────────────
    public bool   $showCreateForm    = false;
    public string $createAdminSearch = '';
    public ?int   $createAdminId     = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('members.manage'), 403);
    }

    #[Computed]
    public function groups()
    {
        $query = FamilyGroup::query()
            ->with(['administrator'])
            ->withCount([
                'membersWithPivot as active_count',
                'pendingInvitations as pending_count',
            ]);

        if ($this->search) {
            $term = mb_strtolower($this->search);
            $query->where(function ($q) use ($term) {
                $q->whereHas('membersWithPivot', fn ($q) =>
                    $q->where(DB::raw("LOWER(CONCAT(first_name, ' ', last_name))"), 'like', "%{$term}%")
                      ->orWhere('membership_number', 'like', "%{$term}%")
                )->orWhereHas('administrator', fn ($q) =>
                    $q->where(DB::raw("LOWER(CONCAT(first_name, ' ', last_name))"), 'like', "%{$term}%")
                );
            });
        }

        return $query->orderBy('id')->get();
    }

    #[Computed]
    public function selectedGroup(): ?FamilyGroup
    {
        if (! $this->selectedGroupId) {
            return null;
        }

        return FamilyGroup::with([
            'administrator',
            'membersWithPivot',
            'pendingInvitations',
        ])->find($this->selectedGroupId);
    }

    #[Computed]
    public function addMemberResults()
    {
        if (strlen($this->addMemberSearch) < 2) {
            return collect();
        }

        $term = mb_strtolower($this->addMemberSearch);

        return Member::query()
            ->whereNull('family_group_id')
            ->where(function ($q) use ($term) {
                $q->where(DB::raw("LOWER(CONCAT(first_name, ' ', last_name))"), 'like', "%{$term}%")
                  ->orWhere('membership_number', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%");
            })
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function createAdminResults()
    {
        if (strlen($this->createAdminSearch) < 2) {
            return collect();
        }

        $term = mb_strtolower($this->createAdminSearch);

        return Member::query()
            ->whereNull('family_group_id')
            ->where(function ($q) use ($term) {
                $q->where(DB::raw("LOWER(CONCAT(first_name, ' ', last_name))"), 'like', "%{$term}%")
                  ->orWhere('membership_number', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%");
            })
            ->orderBy('last_name')->orderBy('first_name')
            ->limit(8)
            ->get();
    }

    public function selectGroup(int $id): void
    {
        $this->selectedGroupId   = ($this->selectedGroupId === $id) ? null : $id;
        $this->addToGroupId      = null;
        $this->addMemberSearch   = '';
        $this->addMemberId       = null;
        $this->flashMessage      = '';
        $this->resetErrorBag();
    }

    public function openAddMember(int $groupId): void
    {
        $this->addToGroupId    = $groupId;
        $this->addMemberSearch = '';
        $this->addMemberId     = null;
        $this->addRole         = FamilyGroup::ROLE_ADULT;
        $this->resetErrorBag();
    }

    public function selectAddMember(int $memberId, string $name): void
    {
        $this->addMemberId     = $memberId;
        $this->addMemberSearch = $name;
        unset($this->addMemberResults);
    }

    public function confirmAddMember(): void
    {
        $this->validate([
            'addMemberId' => 'required|integer',
            'addRole'     => 'required|in:adult,child',
        ], [
            'addMemberId.required' => 'Välj en medlem att lägga till.',
        ]);

        $group  = FamilyGroup::with(['membersWithPivot', 'pendingInvitations'])->find($this->addToGroupId);
        $member = Member::find($this->addMemberId);

        if (! $group || ! $member) {
            return;
        }

        if ($member->family_group_id) {
            $this->addError('addMemberId', 'Medlemmen tillhör redan en familjegrupp.');
            return;
        }

        if ($member->user_id) {
            // Has own account — send invitation
            $group->inviteMember($member, $this->addRole, Auth::user());
            // The mail says "X har bjudit in dig till sin familjegrupp", so name
            // the group's billing contact rather than the admin doing the click.
            Mail::to($member->email)->send(new FamilyInvitationMail($member, $group->administrator ?? Auth::user()->member));
            AuditLog::record('family_member_invited', 'members', $member->full_name, $member);
            $this->flashMessage = "{$member->full_name} bjöds in till familjegruppen. En inbjudan har skickats.";
        } else {
            $group->addMember($member, $this->addRole, false, Auth::user());
            AuditLog::record('family_member_added', 'members', $member->full_name, $member);
            $this->flashMessage = "{$member->full_name} lades till i familjegruppen.";
        }

        $this->addToGroupId    = null;
        $this->addMemberSearch = '';
        $this->addMemberId     = null;
        unset($this->groups, $this->selectedGroup, $this->addMemberResults);
    }

    public function cancelAddMember(): void
    {
        $this->addToGroupId    = null;
        $this->addMemberSearch = '';
        $this->addMemberId     = null;
        $this->resetErrorBag(['addMemberId', 'addRole']);
    }

    public function selectCreateAdmin(int $memberId, string $name): void
    {
        $this->createAdminId     = $memberId;
        $this->createAdminSearch = $name;
        unset($this->createAdminResults);
    }

    public function createGroup(): void
    {
        $this->validate([
            'createAdminId' => 'required|integer',
        ], [
            'createAdminId.required' => 'Välj en betalningsansvarig.',
        ]);

        $admin = Member::find($this->createAdminId);
        if (! $admin) {
            return;
        }

        if ($admin->family_group_id) {
            $this->addError('createAdminId', 'Medlemmen tillhör redan en familjegrupp.');
            return;
        }

        $group = FamilyGroup::create(['administrator_member_id' => $admin->id]);
        $group->addMember($admin, FamilyGroup::ROLE_ADULT, (bool) $admin->user_id, Auth::user());
        AuditLog::record('family_group_created', 'members', $admin->full_name, $group);

        $this->showCreateForm    = false;
        $this->createAdminSearch = '';
        $this->createAdminId     = null;
        $this->selectedGroupId   = $group->id;
        unset($this->groups, $this->selectedGroup, $this->createAdminResults);
        $this->flashMessage = "Ny familjegrupp skapad med {$admin->full_name} som betalningsansvarig.";
    }

    public function cancelCreate(): void
    {
        $this->showCreateForm    = false;
        $this->createAdminSearch = '';
        $this->createAdminId     = null;
        $this->resetErrorBag(['createAdminId']);
    }

    public function transferBilling(int $memberId): void
    {
        $group = $this->selectedGroup;
        if (! $group) {
            return;
        }

        $member = $group->membersWithPivot->find($memberId);
        if (! $member || $member->pivot->role !== FamilyGroup::ROLE_ADULT) {
            $this->addError('action', 'Bara vuxna kan vara betalningsansvarig.');
            return;
        }

        $group->update(['administrator_member_id' => $memberId]);
        AuditLog::record('family_billing_transferred', 'members', $member->full_name, $member);
        unset($this->groups, $this->selectedGroup);
        $this->flashMessage = "{$member->full_name} är nu betalningsansvarig.";
    }

    public function removeMember(int $memberId): void
    {
        $group = $this->selectedGroup;
        if (! $group) {
            return;
        }

        if ($group->administrator_member_id === $memberId) {
            $this->addError('action', 'Betalningsansvarig kan inte tas bort. Byt betalningsansvarig först.');
            return;
        }

        $member = $group->membersWithPivot->find($memberId);
        if (! $member) {
            return;
        }

        $group->removeMember($member, Auth::user());
        AuditLog::record('family_member_removed', 'members', $member->full_name, $member);
        unset($this->groups, $this->selectedGroup);
        $this->flashMessage = "{$member->full_name} togs bort från familjegruppen.";
    }

    public function cancelInvitation(int $memberId): void
    {
        $group = $this->selectedGroup;
        if (! $group) {
            return;
        }

        $member = $group->pendingInvitations->find($memberId);
        if (! $member) {
            return;
        }

        $group->cancelInvitation($member, Auth::user());
        AuditLog::record('family_invitation_cancelled', 'members', $member->full_name, $member);
        unset($this->groups, $this->selectedGroup);
        $this->flashMessage = "Inbjudan till {$member->full_name} återkallades.";
    }

    public function dissolveGroup(): void
    {
        $group = $this->selectedGroup;
        if (! $group) {
            return;
        }

        foreach ($group->membersWithPivot as $member) {
            $group->removeMember($member, Auth::user(), 'Familjegruppen upplöstes av administratör.');
        }

        foreach ($group->pendingInvitations as $member) {
            $group->cancelInvitation($member, Auth::user());
        }

        $groupId = $group->id;
        $group->delete();
        AuditLog::record('family_group_dissolved', 'members', "Familjegrupp #{$groupId}", null);

        $this->selectedGroupId = null;
        unset($this->groups, $this->selectedGroup);
        $this->flashMessage = 'Familjegruppen upplöstes.';
    }
}; ?>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">

    @if($flashMessage)
        <div x-data x-init="setTimeout(() => $el.remove(), 5000)"
             class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
            {{ $flashMessage }}
        </div>
    @endif

    @error('action')
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
            {{ $message }}
        </div>
    @enderror

    {{-- Toolbar --}}
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center">
        <input wire:model.live.debounce.300ms="search"
               type="text"
               class="inp flex-1 max-w-sm"
               placeholder="Sök på namn eller medlemsnummer…" />

        <button wire:click="$set('showCreateForm', true)"
                @class(['ml-auto inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron', 'hidden' => $showCreateForm])>
            + Ny familjegrupp
        </button>
    </div>

    {{-- Create group form --}}
    @if($showCreateForm)
        <div class="mb-6 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="bg-zinc-50 px-5 py-3 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Skapa ny familjegrupp</h2>
            </div>
            <div class="space-y-4 p-5">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Betalningsansvarig <span class="text-red-500">*</span>
                    </label>
                    <p class="mb-1 text-xs text-zinc-400 dark:text-zinc-500">Välj en befintlig medlem utan familjegrupp.</p>
                    <div class="relative max-w-sm">
                        <input wire:model.live.debounce.200ms="createAdminSearch"
                               type="text"
                               placeholder="Sök namn, nummer eller e-post…"
                               class="inp w-full" />
                        @if($this->createAdminResults->isNotEmpty() && ! $createAdminId)
                            <div class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                                @foreach($this->createAdminResults as $m)
                                    <button type="button"
                                            wire:click="selectCreateAdmin({{ $m->id }}, '{{ addslashes($m->full_name) }}')"
                                            class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700">
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $m->full_name }}</span>
                                        @if($m->membership_number)
                                            <span class="text-xs text-zinc-400">#{{ $m->membership_number }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @if($createAdminId)
                        <p class="mt-1 text-xs text-green-600 dark:text-green-400">
                            <x-bootstrap-icon name="check-circle" class="inline size-3.5" />
                            {{ $createAdminSearch }} vald
                        </p>
                        <button type="button" wire:click="$set('createAdminId', null)" class="mt-0.5 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">Ändra</button>
                    @endif
                    @error('createAdminId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <button wire:click="createGroup"
                            class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                        Skapa grupp
                    </button>
                    <button wire:click="cancelCreate"
                            class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                        Avbryt
                    </button>
                </div>
            </div>
        </div>
    @endif

    @php $groups = $this->groups; @endphp

    @if($groups->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="house-heart" class="mx-auto mb-3 size-8 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm text-zinc-500">{{ $search ? 'Inga träffar.' : 'Inga familjegrupper registrerade.' }}</p>
        </div>
    @else
        <div class="space-y-2">
            @foreach($groups as $group)
                @php $isSelected = $this->selectedGroupId === $group->id; @endphp

                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 {{ $isSelected ? 'ring-2 ring-complement/40' : '' }}">

                    {{-- Group row --}}
                    <button wire:click="selectGroup({{ $group->id }})"
                            class="flex w-full items-center gap-4 px-5 py-4 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $isSelected ? 'bg-zinc-50 dark:bg-zinc-800/50' : 'bg-white dark:bg-zinc-900' }}">

                        <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-complement/10 dark:bg-complement/20">
                            <x-bootstrap-icon name="house-heart" class="size-4 text-complement dark:text-complement-light" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                {{ $group->administrator?->full_name ?? '—' }}
                                <span class="ml-1 font-normal text-zinc-400 dark:text-zinc-500">(betalningsansvarig)</span>
                            </p>
                            <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                <span>{{ $group->active_count }} {{ $group->active_count === 1 ? 'aktiv medlem' : 'aktiva medlemmar' }}</span>
                                @if($group->pending_count > 0)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                        <x-bootstrap-icon name="clock" class="size-3" />
                                        {{ $group->pending_count }} väntande
                                    </span>
                                @endif
                            </div>
                        </div>

                        <x-bootstrap-icon name="{{ $isSelected ? 'chevron-up' : 'chevron-down' }}" class="size-4 text-zinc-400" />
                    </button>

                    {{-- Expanded detail --}}
                    @if($isSelected)
                        @php $detail = $this->selectedGroup; @endphp
                        <div class="border-t border-zinc-200 px-5 pb-5 pt-4 dark:border-zinc-700">

                            {{-- Active members --}}
                            @if($detail->membersWithPivot->isNotEmpty())
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                                    Aktiva medlemmar
                                </h3>
                                <ul class="mb-4 divide-y divide-zinc-100 overflow-hidden rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                                    @foreach($detail->membersWithPivot as $member)
                                        <li class="flex items-center gap-3 px-4 py-3">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <a href="{{ route('admin.members.show', $member) }}" wire:navigate
                                                       class="text-sm font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                                                        {{ $member->full_name }}
                                                    </a>
                                                    @if($member->pivot->role === FamilyGroup::ROLE_CHILD)
                                                        <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">Barn</span>
                                                    @else
                                                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">Vuxen</span>
                                                    @endif
                                                    @if($detail->administrator_member_id === $member->id)
                                                        <span class="inline-flex items-center gap-1 rounded-full bg-complement/10 px-2 py-0.5 text-xs font-medium text-complement dark:text-complement-light">
                                                            <x-bootstrap-icon name="receipt" class="size-3" />
                                                            Betalningsansvarig
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex shrink-0 gap-2">
                                                @if($member->pivot->role === FamilyGroup::ROLE_ADULT && $detail->administrator_member_id !== $member->id)
                                                    <button wire:click="transferBilling({{ $member->id }})"
                                                            class="rounded border border-zinc-300 px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-800">
                                                        Sätt betalningsansvarig
                                                    </button>
                                                @endif
                                                @if($detail->administrator_member_id !== $member->id)
                                                    <button wire:click="removeMember({{ $member->id }})"
                                                            wire:confirm="Ta bort {{ $member->full_name }} från familjegruppen?"
                                                            class="rounded border border-zinc-300 px-2 py-1 text-xs text-zinc-500 hover:border-red-300 hover:text-red-500 dark:border-zinc-600 dark:hover:text-red-400">
                                                        Ta bort
                                                    </button>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- Pending invitations --}}
                            @if($detail->pendingInvitations->isNotEmpty())
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                                    Väntande inbjudningar
                                </h3>
                                <ul class="mb-4 divide-y divide-zinc-100 overflow-hidden rounded-lg border border-amber-200 dark:divide-zinc-800 dark:border-amber-800/50">
                                    @foreach($detail->pendingInvitations as $invited)
                                        <li class="flex items-center gap-3 bg-amber-50 px-4 py-3 dark:bg-amber-900/5">
                                            <div class="min-w-0 flex-1">
                                                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $invited->full_name }}</span>
                                                @if($invited->pivot->invited_at)
                                                    <span class="ml-2 text-xs text-zinc-400">{{ $invited->pivot->invited_at->translatedFormat('j M Y') }}</span>
                                                @endif
                                            </div>
                                            <button wire:click="cancelInvitation({{ $invited->id }})"
                                                    wire:confirm="Återkalla inbjudan till {{ $invited->full_name }}?"
                                                    class="rounded border border-zinc-300 px-2 py-1 text-xs text-zinc-500 hover:border-red-300 hover:text-red-500 dark:border-zinc-600 dark:hover:text-red-400">
                                                Återkalla
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- Add member --}}
                            @if($addToGroupId === $detail->id)
                                <div class="mb-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                                        Lägg till medlem
                                    </h3>
                                    <div class="space-y-3">
                                        <div class="relative">
                                            <input wire:model.live.debounce.200ms="addMemberSearch"
                                                   type="text"
                                                   placeholder="Sök namn, nummer eller e-post…"
                                                   class="inp w-full max-w-sm" />
                                            @if($this->addMemberResults->isNotEmpty() && ! $addMemberId)
                                                <div class="absolute z-20 mt-1 w-full max-w-sm overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                                                    @foreach($this->addMemberResults as $m)
                                                        <button type="button"
                                                                wire:click="selectAddMember({{ $m->id }}, '{{ addslashes($m->full_name) }}')"
                                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700">
                                                            <span class="font-medium text-zinc-900 dark:text-white">{{ $m->full_name }}</span>
                                                            @if($m->membership_number)
                                                                <span class="text-xs text-zinc-400">#{{ $m->membership_number }}</span>
                                                            @endif
                                                            @if($m->user_id)
                                                                <span class="ml-auto text-xs text-complement dark:text-complement-light">Har konto</span>
                                                            @endif
                                                        </button>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @if($addMemberId)
                                                <p class="mt-1 text-xs text-green-600 dark:text-green-400">
                                                    <x-bootstrap-icon name="check-circle" class="inline size-3.5" />
                                                    {{ $addMemberSearch }} vald
                                                </p>
                                                <button type="button" wire:click="$set('addMemberId', null)" class="mt-0.5 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">Ändra</button>
                                            @endif
                                            @error('addMemberId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                        </div>
                                        <div class="flex items-center gap-4">
                                            <label class="text-sm text-zinc-700 dark:text-zinc-300">Roll:</label>
                                            <label class="flex items-center gap-1.5 text-sm">
                                                <input wire:model="addRole" type="radio" value="adult" class="accent-complement" />
                                                Vuxen
                                            </label>
                                            <label class="flex items-center gap-1.5 text-sm">
                                                <input wire:model="addRole" type="radio" value="child" class="accent-complement" />
                                                Barn
                                            </label>
                                        </div>
                                        <div class="flex gap-2">
                                            <button wire:click="confirmAddMember"
                                                    class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-brand-dark hover:bg-brand-saffron">
                                                Lägg till
                                            </button>
                                            <button wire:click="cancelAddMember"
                                                    class="rounded-lg px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                                Avbryt
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @else
                                <div class="mb-4">
                                    <button wire:click="openAddMember({{ $detail->id }})"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                        <x-bootstrap-icon name="person-plus" class="size-3.5" />
                                        Lägg till medlem
                                    </button>
                                </div>
                            @endif

                            {{-- Dissolve --}}
                            <div class="border-t border-zinc-100 pt-4 dark:border-zinc-800">
                                <button wire:click="dissolveGroup"
                                        wire:confirm="Lösa upp familjegruppen för {{ $detail->administrator?->full_name }}? Alla medlemmar tas bort från gruppen."
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20">
                                    <x-bootstrap-icon name="trash" class="size-3.5" />
                                    Lös upp familjegruppen
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="mt-4 text-xs text-zinc-400 dark:text-zinc-500">
            {{ $groups->count() }} {{ $groups->count() === 1 ? 'familjegrupp' : 'familjegrupper' }} totalt
        </p>
    @endif
</div>
