<?php

use App\Mail\FamilyInvitationMail;
use App\Mail\MemberInvitationMail;
use App\Models\AuditLog;
use App\Models\Membership\FamilyGroup;
use App\Models\Membership\FamilyMemberHistory;
use App\Models\Membership\Member;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\SystemSetting;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Min familj');

new class extends Component {

    public string $flashMessage = '';
    public string $search       = '';

    // Create new member form
    public string $newFirstName = '';
    public string $newLastName  = '';
    public string $newDob       = '';
    public string $newEmail     = '';

    // Edit member form
    public ?int   $editMemberId   = null;
    public string $editRole       = FamilyGroup::ROLE_ADULT;
    public bool   $editHasAccount = false;

    public function mount(): void
    {
        $member = $this->currentMember();
        abort_unless($member && $this->familyGroup($member), 403);
        abort_unless($this->familyGroup($member)->isManagedBy($member), 403);
    }

    private function currentMember(): ?Member
    {
        return Member::where('user_id', auth()->id())->first();
    }

    private function familyGroup(Member $member): ?FamilyGroup
    {
        if (! $member->family_group_id) {
            return null;
        }

        return FamilyGroup::with(['membersWithPivot', 'pendingInvitations', 'administrator'])->find($member->family_group_id);
    }

    #[Computed]
    public function group(): ?FamilyGroup
    {
        $member = $this->currentMember();
        if (! $member) {
            return null;
        }

        return $this->familyGroup($member);
    }

    #[Computed]
    public function currentMemberRecord(): ?Member
    {
        return $this->currentMember();
    }

    #[Computed]
    public function searchResults()
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return collect();
        }

        $existingIds = $this->group?->membersWithPivot->pluck('id') ?? collect();

        $term = mb_strtolower($this->search);
        return Member::whereNotIn('id', $existingIds)
            ->whereNull('family_group_id')
            ->get()
            ->filter(fn ($m) =>
                str_contains(mb_strtolower($m->first_name . ' ' . $m->last_name), $term)
                || str_contains(mb_strtolower($m->membership_number ?? ''), $term)
            )
            ->take(8)
            ->values();
    }

    #[Computed]
    public function historyEntries()
    {
        $group = $this->group;
        if (! $group) {
            return collect();
        }

        return FamilyMemberHistory::where('family_group_id', $group->id)
            ->with(['member', 'performedBy.member'])
            ->orderByDesc('created_at')
            ->get();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Open-slot helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Find the family's paid order for the current year, if one exists.
     * "Family order" = paid order owned by billing contact that has at least one
     * MembershipPeriod for the current year linked to a current family member.
     */
    private function openFamilyOrder(FamilyGroup $group): ?Order
    {
        $adminUserId = $group->administrator?->user_id;
        if (! $adminUserId) {
            return null;
        }

        $currentYear = now()->year;
        $memberIds   = $group->membersWithPivot->pluck('id');

        return Order::where('user_id', $adminUserId)
            ->where('status', Order::STATUS_PAID)
            ->whereHas('membershipPeriods', function ($q) use ($memberIds, $currentYear) {
                $q->whereIn('member_id', $memberIds)->where('year', $currentYear);
            })
            ->latest('paid_at')
            ->first();
    }

    /**
     * Returns how many unallocated membership slots of the given role remain.
     *
     * Paid slots = sum of quantities on lines with matching slot_role.
     * Used slots = periods on this order this year whose holder is/was of that role.
     *   Current family members  → their pivot role.
     *   Ex-members (left/removed) → inferred from date_of_birth.
     *
     * A departed adult's slot does NOT become available for a new adult:
     * their MembershipPeriod still exists and still "uses" their slot.
     */
    private function availableSlotsForRole(Order $order, FamilyGroup $group, string $role): int
    {
        $maxChildAge = (int) SystemSetting::get('family.max_child_age', 18);
        $year        = now()->year;

        $paidSlots = $order->lines()
            ->where('category', OrderLine::CATEGORY_MEMBERSHIP)
            ->where('slot_role', $role)
            ->sum('quantity');

        if ($paidSlots <= 0) {
            return 0;
        }

        $currentRoles = $group->membersWithPivot->pluck('pivot.role', 'id');

        $usedSlots = MembershipPeriod::where('order_id', $order->id)
            ->where('year', $year)
            ->with('member')
            ->get()
            ->filter(function ($period) use ($currentRoles, $role, $maxChildAge) {
                if ($currentRoles->has($period->member_id)) {
                    return $currentRoles->get($period->member_id) === $role;
                }
                // Ex-member: infer from DOB
                $dob     = $period->member?->date_of_birth;
                $isChild = $dob && $dob->gt(now()->subYears($maxChildAge));

                return $isChild
                    ? $role === FamilyGroup::ROLE_CHILD
                    : $role === FamilyGroup::ROLE_ADULT;
            })
            ->count();

        return max(0, (int) $paidSlots - $usedSlots);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Actions
    // ──────────────────────────────────────────────────────────────────────────

    public function startEdit(int $memberId): void
    {
        $member = $this->group?->membersWithPivot->find($memberId);
        if (! $member) {
            return;
        }

        $this->editMemberId   = $memberId;
        $this->editRole       = $member->pivot->role;
        $this->editHasAccount = (bool) $member->pivot->has_own_account;
    }

    public function saveEdit(): void
    {
        $group = $this->group;
        if (! $group || ! $this->editMemberId) {
            return;
        }

        $member = $group->membersWithPivot->find($this->editMemberId);
        if (! $member) {
            return;
        }

        // Guard: cannot remove last adult
        if ($this->editRole === FamilyGroup::ROLE_CHILD) {
            $adultCount = $group->membersWithPivot
                ->filter(fn ($m) => $m->pivot->role === FamilyGroup::ROLE_ADULT && $m->id !== $this->editMemberId)
                ->count();

            if ($adultCount < 1) {
                $this->addError('editRole', 'Det måste finnas minst en vuxen i familjen.');
                return;
            }
        }

        $group->membersWithPivot()->updateExistingPivot($this->editMemberId, [
            'role'            => $this->editRole,
            'has_own_account' => $this->editHasAccount,
        ]);

        AuditLog::record('family_member_updated', 'members', $member->full_name, $group);
        $this->cancelEdit();
        unset($this->group);
        $this->flashMessage = 'Ändringarna sparades.';
    }

    public function cancelEdit(): void
    {
        $this->editMemberId   = null;
        $this->editRole       = FamilyGroup::ROLE_ADULT;
        $this->editHasAccount = false;
        $this->resetValidation();
    }

    public function removeMember(int $memberId): void
    {
        $group = $this->group;
        if (! $group) {
            return;
        }

        // Cannot remove billing contact
        if ($group->administrator_member_id === $memberId) {
            $this->addError('remove', 'Betalningsansvarig kan inte tas bort. Byt betalningsansvarig först.');
            return;
        }

        // Cannot remove last adult
        $isAdult = $group->membersWithPivot->find($memberId)?->pivot->role === FamilyGroup::ROLE_ADULT;
        if ($isAdult) {
            $adultCount = $group->membersWithPivot
                ->filter(fn ($m) => $m->pivot->role === FamilyGroup::ROLE_ADULT)
                ->count();

            if ($adultCount <= 1) {
                $this->addError('remove', 'Det måste finnas minst en vuxen i familjen.');
                return;
            }
        }

        $removedMember = $group->membersWithPivot->find($memberId);
        $group->removeMember($removedMember, auth()->user());

        AuditLog::record('family_member_removed', 'members', $removedMember->full_name, $group);
        unset($this->group, $this->historyEntries);
        $this->flashMessage = 'Medlemmen togs bort från familjen. Eventuellt betalt medlemskap gäller ut året.';
    }

    public function createAndAddMember(): void
    {
        $this->validate([
            'newFirstName' => ['required', 'string', 'max:100'],
            'newLastName'  => ['required', 'string', 'max:100'],
            'newDob'       => ['required', 'date', 'before:today'],
            'newEmail'     => ['nullable', 'email', 'max:255', 'unique:users,email'],
        ], [
            'newFirstName.required' => 'Förnamn krävs.',
            'newLastName.required'  => 'Efternamn krävs.',
            'newDob.required'       => 'Födelsedatum krävs.',
            'newDob.date'           => 'Ogiltigt datum.',
            'newDob.before'         => 'Födelsedatum måste vara i det förflutna.',
            'newEmail.email'        => 'Ogiltig e-postadress.',
            'newEmail.unique'       => 'E-postadressen används redan av ett annat konto.',
        ]);

        $group = $this->group;
        if (! $group) {
            return;
        }

        $maxAdults   = (int) SystemSetting::get('family.max_adults', 2);
        $maxChildren = (int) SystemSetting::get('family.max_children', 5);
        $maxChildAge = (int) SystemSetting::get('family.max_child_age', 18);

        $dob     = \Illuminate\Support\Carbon::parse($this->newDob);
        $isChild = $dob->gt(now()->subYears($maxChildAge));
        $role    = $isChild ? FamilyGroup::ROLE_CHILD : FamilyGroup::ROLE_ADULT;

        $currentAdults   = $group->membersWithPivot->filter(fn ($m) => $m->pivot->role === FamilyGroup::ROLE_ADULT)->count();
        $currentChildren = $group->membersWithPivot->filter(fn ($m) => $m->pivot->role === FamilyGroup::ROLE_CHILD)->count();

        if ($role === FamilyGroup::ROLE_ADULT && $currentAdults >= $maxAdults) {
            $this->addError('newFirstName', "Familjen får max {$maxAdults} vuxna.");
            return;
        }
        if ($role === FamilyGroup::ROLE_CHILD && $currentChildren >= $maxChildren) {
            $this->addError('newFirstName', "Familjen får max {$maxChildren} barn.");
            return;
        }

        $memberType = $isChild ? Member::TYPE_CHILD : Member::TYPE_FAMILY;

        $newMember = Member::create([
            'first_name'      => trim($this->newFirstName),
            'last_name'       => trim($this->newLastName),
            'date_of_birth'   => $dob->toDateString(),
            'membership_type' => $memberType,
            'status'          => Member::STATUS_ACTIVE,
        ]);

        $hasAccount       = ! empty($this->newEmail);
        $invitationEmail  = $this->newEmail;
        $group->addMember($newMember, $role, $hasAccount, auth()->user());

        if ($hasAccount) {
            $user = User::create([
                'name'     => $newMember->full_name,
                'email'    => $invitationEmail,
                'password' => Hash::make(Str::random(32)),
            ]);
            $newMember->update(['user_id' => $user->id]);
            /** @var \Illuminate\Auth\Passwords\PasswordBroker $broker */
            $broker        = Password::broker();
            $token         = $broker->createToken($user);
            $activationUrl = route('password.reset', ['token' => $token, 'email' => $user->email]);
            Mail::to($user->email)->send(new MemberInvitationMail($newMember, $activationUrl));
        }

        AuditLog::record('family_member_created_and_added', 'members', $newMember->full_name, $group);
        $this->newFirstName = '';
        $this->newLastName  = '';
        $this->newDob       = '';
        $this->newEmail     = '';
        $this->resetValidation(['newFirstName', 'newLastName', 'newDob', 'newEmail']);
        unset($this->group, $this->searchResults, $this->historyEntries);

        $roleLabel = $isChild ? 'barn' : 'vuxen';
        if ($hasAccount) {
            $this->flashMessage = "{$newMember->full_name} skapades och lades till som {$roleLabel}. En inbjudan har skickats till {$invitationEmail}.";
        } else {
            $this->flashMessage = "{$newMember->full_name} skapades och lades till som {$roleLabel}. Inget eget konto — du hanterar deras medlemskap.";
        }
    }

    public function dissolveGroup(): void
    {
        $member = $this->currentMember();
        $group  = $this->group;

        if (! $group || ! $member) {
            return;
        }

        // Only the billing contact can dissolve the group
        if (! $group->isBillingContact($member)) {
            $this->addError('dissolve', 'Bara betalningsansvarig kan lösa upp familjegruppen.');
            return;
        }

        foreach ($group->membersWithPivot as $fm) {
            $group->removeMember($fm, auth()->user(), 'Familjegruppen upplöstes.');
        }

        AuditLog::record('family_group_dissolved', 'members', 'Familjegrupp #' . $group->id, null);
        $group->delete();

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function setBillingContact(int $memberId): void
    {
        $group  = $this->group;
        $member = $group?->membersWithPivot->find($memberId);

        if (! $group || ! $member) {
            return;
        }

        if ($member->pivot->role !== FamilyGroup::ROLE_ADULT) {
            $this->addError('billing', 'Bara vuxna kan vara betalningsansvarig.');
            return;
        }

        $group->update(['administrator_member_id' => $memberId]);
        AuditLog::record('family_billing_contact_changed', 'members', $member->full_name, $group);
        unset($this->group);
        $this->flashMessage = "{$member->full_name} är nu betalningsansvarig.";
    }

    public function addMember(int $memberId): void
    {
        $group = $this->group;
        if (! $group) {
            return;
        }

        $maxAdults   = (int) SystemSetting::get('family.max_adults', 2);
        $maxChildren = (int) SystemSetting::get('family.max_children', 5);
        $maxChildAge = (int) SystemSetting::get('family.max_child_age', 18);

        $newMember = Member::whereNull('family_group_id')->find($memberId);
        if (! $newMember) {
            return;
        }

        $isChild = $newMember->date_of_birth && $newMember->date_of_birth->gt(now()->subYears($maxChildAge));
        $role    = $isChild ? FamilyGroup::ROLE_CHILD : FamilyGroup::ROLE_ADULT;

        $currentAdults   = $group->membersWithPivot->filter(fn ($m) => $m->pivot->role === FamilyGroup::ROLE_ADULT)->count();
        $currentChildren = $group->membersWithPivot->filter(fn ($m) => $m->pivot->role === FamilyGroup::ROLE_CHILD)->count();

        if ($role === FamilyGroup::ROLE_ADULT && $currentAdults >= $maxAdults) {
            $this->addError('add', "Familjen får max {$maxAdults} vuxna.");
            return;
        }

        if ($role === FamilyGroup::ROLE_CHILD && $currentChildren >= $maxChildren) {
            $this->addError('add', "Familjen får max {$maxChildren} barn.");
            return;
        }

        // Check for an open paid slot of the correct role before mutating,
        // so the slot count is accurate (group hasn't changed yet).
        $period    = null;
        $note      = null;
        $openOrder = $this->openFamilyOrder($group);

        if ($openOrder && $this->availableSlotsForRole($openOrder, $group, $role) > 0) {
            $period = MembershipPeriod::create([
                'member_id'       => $newMember->id,
                'order_id'        => $openOrder->id,
                'year'            => now()->year,
                'membership_type' => $newMember->membership_type,
                'status'          => MembershipPeriod::STATUS_ACTIVE,
                'activated_at'    => now(),
                'expires_at'      => now()->endOfYear(),
            ]);
            $roleLabel = $role === FamilyGroup::ROLE_CHILD ? 'barn' : 'vuxen';
            $note      = "Tilldelad ledig {$roleLabel}-plats från order {$openOrder->payment_reference}.";
        }

        if ($newMember->user_id !== null) {
            // Member has their own account — send invitation instead of adding directly
            $adminMember = $this->currentMember();
            $group->inviteMember($newMember, $role, auth()->user());

            Mail::to($newMember->user->email)
                ->send(new FamilyInvitationMail($newMember, $adminMember));

            AuditLog::record('family_member_invited', 'members', $newMember->full_name, $group);
            $this->search = '';
            unset($this->group, $this->searchResults, $this->historyEntries);
            $this->flashMessage = "En inbjudan har skickats till {$newMember->full_name}. De måste acceptera för att bli en del av familjen.";
            return;
        }

        $group->addMember($newMember, $role, false, auth()->user(), $period, $note);

        AuditLog::record('family_member_added', 'members', $newMember->full_name, $group);
        $this->search = '';
        unset($this->group, $this->searchResults, $this->historyEntries);

        if ($period) {
            $this->flashMessage = "{$newMember->full_name} lades till och fick en ledig plats i årets betalning — medlemskapet är aktivt.";
        } else {
            $this->flashMessage = "{$newMember->full_name} lades till i familjen. Avgiften ingår inte i befintlig betalning — ny betalning krävs vid förnyelse.";
        }
    }

    public function cancelInvitation(int $memberId): void
    {
        $group = $this->group;
        if (! $group) {
            return;
        }

        $member = $group->pendingInvitations->find($memberId);
        if (! $member) {
            return;
        }

        $group->cancelInvitation($member, auth()->user());

        AuditLog::record('family_invitation_cancelled', 'members', $member->full_name, $group);
        unset($this->group, $this->historyEntries);
        $this->flashMessage = "Inbjudan till {$member->full_name} återkallades.";
    }
}; ?>

<x-slot:header>
    <x-page-header title="Min familj" subtitle="Familjehantering">
        <a href="{{ route('dashboard') }}#familj" wire:navigate
           class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
            ← Tillbaka
        </a>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">

    @if($flashMessage)
        <div x-data x-init="setTimeout(() => $el.remove(), 5000)"
             class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
            {{ $flashMessage }}
        </div>
    @endif

    @error('remove')
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
            {{ $message }}
        </div>
    @enderror
    @error('billing')
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
            {{ $message }}
        </div>
    @enderror

    @php $group = $this->group; @endphp

    {{-- Family members list --}}
    <section>
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
            Familjemedlemmar ({{ $group->membersWithPivot->count() }})
        </h2>

        <ul class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
            @foreach($group->membersWithPivot as $member)
                <li class="px-5 py-4">
                    <div class="flex items-center gap-3">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-xs font-bold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                            {{ mb_strtoupper(mb_substr($member->first_name, 0, 1)) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $member->full_name }}
                                </span>

                                {{-- Role badge --}}
                                @if($member->pivot->role === FamilyGroup::ROLE_CHILD)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                        <x-bootstrap-icon name="person-fill" class="size-3" />
                                        Barn
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        <x-bootstrap-icon name="person-fill" class="size-3" />
                                        Vuxen
                                    </span>
                                @endif

                                {{-- Billing contact badge --}}
                                @if($group->administrator_member_id === $member->id)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-complement/10 px-2 py-0.5 text-xs font-medium text-complement dark:text-complement-light">
                                        <x-bootstrap-icon name="receipt" class="size-3" />
                                        Betalningsansvarig
                                    </span>
                                @endif

                                {{-- Account badge --}}
                                @if($member->pivot->has_own_account)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-300">
                                        <x-bootstrap-icon name="person-check" class="size-3" />
                                        Har konto
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                        <x-bootstrap-icon name="person-dash" class="size-3" />
                                        Ingen inloggning
                                    </span>
                                @endif
                            </div>
                            @if($member->date_of_birth)
                                <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                                    Född {{ $member->date_of_birth->format('Y-m-d') }}
                                </p>
                            @endif
                        </div>

                        <div class="flex shrink-0 gap-2">
                            <button wire:click="startEdit({{ $member->id }})"
                                    class="rounded-lg border border-zinc-300 px-2.5 py-1 text-xs font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                Redigera
                            </button>
                            @if($group->administrator_member_id !== $member->id)
                                <button wire:click="removeMember({{ $member->id }})"
                                        wire:confirm="Ta bort {{ $member->full_name }} från familjen? Eventuellt betalt medlemskap gäller ut året."
                                        class="rounded-lg border border-zinc-300 px-2.5 py-1 text-xs font-medium text-zinc-500 hover:border-red-300 hover:text-red-500 dark:border-zinc-600 dark:text-zinc-500 dark:hover:text-red-400">
                                    Ta bort
                                </button>
                            @endif
                            @if($member->pivot->role === FamilyGroup::ROLE_ADULT && $group->administrator_member_id !== $member->id)
                                <button wire:click="setBillingContact({{ $member->id }})"
                                        class="rounded-lg border border-zinc-300 px-2.5 py-1 text-xs font-medium text-zinc-500 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-500 dark:hover:bg-zinc-800">
                                    Sätt betalningsansvarig
                                </button>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Edit member form --}}
    @if($editMemberId)
        @php $editMember = $group->membersWithPivot->find($editMemberId); @endphp
        <section class="mt-8">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                Redigera — {{ $editMember?->full_name }}
            </h2>
            <form wire:submit="saveEdit" class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">

                <div>
                    <label class="lbl">Roll</label>
                    <div class="mt-2 flex gap-4">
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input type="radio" wire:model="editRole" value="{{ FamilyGroup::ROLE_ADULT }}"
                                   class="text-complement" />
                            Vuxen
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm">
                            <input type="radio" wire:model="editRole" value="{{ FamilyGroup::ROLE_CHILD }}"
                                   class="text-complement" />
                            Barn
                        </label>
                    </div>
                    @error('editRole') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3">
                    <input id="editHasAccount" type="checkbox" wire:model="editHasAccount"
                           class="rounded border-zinc-300 text-complement" />
                    <label for="editHasAccount" class="text-sm text-zinc-700 dark:text-zinc-300">
                        Har egen inloggning
                        <span class="ml-1 text-xs text-zinc-400">(vi skapar kontot manuellt)</span>
                    </label>
                </div>

                <div class="flex gap-3 pt-1">
                    <button type="submit" class="btn btn-primary">Spara</button>
                    <button type="button" wire:click="cancelEdit" class="btn btn-ghost">Avbryt</button>
                </div>
            </form>
        </section>
    @endif

    {{-- Add member --}}
    <section class="mt-8">
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
            Lägg till familjemedlem
        </h2>

        @error('add')
            <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                {{ $message }}
            </div>
        @enderror

        {{-- Open slots notice (per role) --}}
        @php
            $openOrder     = $this->openFamilyOrder($group);
            $adultSlots    = $openOrder ? $this->availableSlotsForRole($openOrder, $group, FamilyGroup::ROLE_ADULT) : 0;
            $childSlots    = $openOrder ? $this->availableSlotsForRole($openOrder, $group, FamilyGroup::ROLE_CHILD) : 0;
        @endphp
        @if($adultSlots > 0 || $childSlots > 0)
            <div class="mb-3 flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                <x-bootstrap-icon name="ticket-perforated" class="mt-0.5 size-4 shrink-0" />
                <span>
                    Lediga platser i årets familjefaktura ({{ $openOrder->payment_reference }}):
                    @if($adultSlots > 0)
                        {{ $adultSlots }} vuxen{{ $adultSlots > 1 ? 'a' : '' }}
                    @endif
                    @if($adultSlots > 0 && $childSlots > 0)
                        +
                    @endif
                    @if($childSlots > 0)
                        {{ $childSlots }} barn
                    @endif
                    — rätt typ av person läggs till utan extra kostnad.
                </span>
            </div>
        @endif

        {{-- Search existing members --}}
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
            <input type="text" wire:model.live.debounce.300ms="search"
                   class="inp w-full"
                   placeholder="Sök på namn eller medlemsnummer…" />

            @php $results = $this->searchResults; @endphp
            @if($results->isNotEmpty())
                <ul class="mt-3 divide-y divide-zinc-100 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900">
                    @foreach($results as $result)
                        <li class="flex items-center justify-between px-4 py-3">
                            <div>
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $result->full_name }}</span>
                                <span class="ml-2 text-xs text-zinc-400">#{{ $result->membership_number }}</span>
                                @if($result->date_of_birth)
                                    <span class="ml-2 text-xs text-zinc-400">{{ $result->date_of_birth->format('Y') }}</span>
                                @endif
                            </div>
                            <button wire:click="addMember({{ $result->id }})"
                                    class="inline-flex items-center gap-1 rounded-lg border border-zinc-300 px-2.5 py-1 text-xs font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                <x-bootstrap-icon name="plus" class="size-3" />
                                Lägg till
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif(mb_strlen(trim($search)) >= 2)
                <p class="mt-3 text-sm text-zinc-400">Inga träffar — personen måste redan ha ett konto och inte tillhöra en annan familj.</p>
            @endif
        </div>

        {{-- Create new member without account --}}
        <div x-data="{ open: false }" class="mt-4">
            <button type="button" @click="open = !open"
                    class="flex items-center gap-2 text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                <x-bootstrap-icon name="person-plus" class="size-4" />
                <span x-text="open ? 'Avbryt' : 'Skapa ny familjemedlem (utan eget konto)'"></span>
            </button>

            <div x-show="open" x-cloak x-transition
                 class="mt-3 rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
                <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                    Skapar en ny medlemspost. Lägg till en e-postadress om personen ska ha ett eget konto — en inbjudan skickas automatiskt. Utan e-post hanterar du deras avgift.
                </p>

                <form wire:submit="createAndAddMember" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="lbl">Förnamn</label>
                            <input wire:model="newFirstName" type="text" autocomplete="off"
                                   class="inp mt-1 @error('newFirstName') border-red-300 @enderror" />
                            @error('newFirstName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="lbl">Efternamn</label>
                            <input wire:model="newLastName" type="text" autocomplete="off"
                                   class="inp mt-1 @error('newLastName') border-red-300 @enderror" />
                            @error('newLastName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="max-w-xs">
                        <label class="lbl">
                            Födelsedatum
                            <span class="font-normal text-zinc-400">(avgör om det är barn eller vuxen)</span>
                        </label>
                        <input wire:model="newDob" type="date"
                               class="inp mt-1 @error('newDob') border-red-300 @enderror" />
                        @error('newDob') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">
                            E-postadress
                            <span class="font-normal text-zinc-400">(valfritt — skapar ett konto och skickar inbjudan)</span>
                        </label>
                        <input wire:model="newEmail" type="email" autocomplete="off"
                               class="inp mt-1 @error('newEmail') border-red-300 @enderror"
                               placeholder="exempel@epost.se" />
                        @error('newEmail')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @else
                            <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                                Lämnas fältet tomt går all kommunikation till dig som familjeansvarig.
                            </p>
                        @enderror
                    </div>
                    <div class="flex items-center gap-3 pt-1">
                        <button type="submit" class="btn btn-primary"
                                wire:loading.attr="disabled">
                            Skapa och lägg till
                        </button>
                        <button type="button" @click="open = false" class="btn btn-ghost">Avbryt</button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    {{-- Pending invitations --}}
    @if($group->pendingInvitations->isNotEmpty())
        <section class="mt-8">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                Väntande inbjudningar ({{ $group->pendingInvitations->count() }})
            </h2>
            <ul class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                @foreach($group->pendingInvitations as $invited)
                    <li class="flex items-center gap-3 px-5 py-3.5">
                        <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-600 dark:bg-amber-900/30 dark:text-amber-400">
                            {{ mb_strtoupper(mb_substr($invited->first_name, 0, 1)) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $invited->full_name }}</span>
                            <span class="ml-2 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                <x-bootstrap-icon name="clock" class="size-3" />
                                Väntar på svar
                            </span>
                            @if($invited->pivot->invited_at)
                                <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                                    Inbjuden {{ $invited->pivot->invited_at->translatedFormat('j M Y') }}
                                </p>
                            @endif
                        </div>
                        <button wire:click="cancelInvitation({{ $invited->id }})"
                                wire:confirm="Återkalla inbjudan till {{ $invited->full_name }}?"
                                class="rounded-lg border border-zinc-300 px-2.5 py-1 text-xs font-medium text-zinc-500 hover:border-red-300 hover:text-red-500 dark:border-zinc-600 dark:text-zinc-500 dark:hover:text-red-400">
                            Återkalla
                        </button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Dissolve group (billing contact only) --}}
    @if($group->isBillingContact($this->currentMemberRecord))
        <section class="mt-10 border-t border-zinc-200 pt-8 dark:border-zinc-700">
            <h2 class="mb-1 text-xs font-semibold uppercase tracking-wider text-red-400 dark:text-red-500">Farlig zon</h2>
            @error('dissolve')
                <p class="mb-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
            <p class="mb-3 text-sm text-zinc-500 dark:text-zinc-400">
                Löser upp familjegruppen och tar bort alla medlemmar från den. Betalda medlemskap påverkas inte.
            </p>
            <button wire:click="dissolveGroup"
                    wire:confirm="Är du säker? Alla familjemedlemmar tas bort från gruppen. Åtgärden kan inte ångras."
                    class="btn btn-danger">
                <x-bootstrap-icon name="trash" class="size-4" />
                Lös upp familjegruppen
            </button>
        </section>
    @endif

    {{-- Family history --}}
    @php $history = $this->historyEntries; @endphp
    @if($history->isNotEmpty())
        <section class="mt-10">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                Historik — sammansättningsförändringar
            </h2>

            <ol class="relative border-s border-zinc-200 dark:border-zinc-700">
                @foreach($history as $entry)
                    @php
                        $dotColor = match($entry->action) {
                            'joined', 'added' => 'bg-green-500',
                            'left'            => 'bg-amber-500',
                            'removed'         => 'bg-red-500',
                            default           => 'bg-zinc-400',
                        };
                        $memberName      = $entry->member?->full_name ?? 'Raderad medlem';
                        $performedByName = $entry->performedBy?->member?->full_name
                            ?? $entry->performedBy?->name
                            ?? null;
                    @endphp
                    <li class="mb-6 ms-4">
                        <div class="absolute -start-1.5 mt-1 size-3 rounded-full border-2 border-white dark:border-zinc-900 {{ $dotColor }}"></div>

                        <time class="mb-1 text-xs font-normal leading-none text-zinc-400 dark:text-zinc-500">
                            {{ $entry->created_at->translatedFormat('j M Y, H:i') }}
                            @if($entry->year)
                                · {{ $entry->year }}
                            @endif
                        </time>

                        <p class="text-sm font-medium text-zinc-900 dark:text-white">
                            {{ $memberName }}
                            <span class="font-normal text-zinc-500 dark:text-zinc-400">— {{ $entry->actionLabel() }}</span>
                        </p>

                        @if($performedByName && ! in_array($entry->action, ['joined', 'left']))
                            <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">Utfört av {{ $performedByName }}</p>
                        @endif

                        @if($entry->notes)
                            <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">{{ $entry->notes }}</p>
                        @endif

                        @if($entry->membershipPeriod)
                            <p class="mt-0.5 text-xs text-green-600 dark:text-green-400">
                                <x-bootstrap-icon name="check-circle" class="inline size-3" />
                                Aktivt medlemskap {{ $entry->membershipPeriod->year }} tilldelat
                            </p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

</div>
