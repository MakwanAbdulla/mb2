<?php

use App\Models\Membership\FamilyGroup;
use App\Models\Membership\Member;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    public string $flashMessage = '';

    #[Computed]
    public function member(): ?Member
    {
        return Member::where('user_id', auth()->id())->first();
    }

    #[Computed]
    public function family(): ?FamilyGroup
    {
        $member = $this->member;
        if (! $member?->family_group_id) {
            return null;
        }

        $isActive = DB::table('family_members')
            ->where('family_group_id', $member->family_group_id)
            ->where('member_id', $member->id)
            ->where('status', FamilyGroup::STATUS_ACTIVE)
            ->exists();

        if (! $isActive) {
            return null;
        }

        return FamilyGroup::with(['membersWithPivot', 'administrator'])->find($member->family_group_id);
    }

    #[Computed]
    public function pendingInvitation(): ?FamilyGroup
    {
        $member = $this->member;
        if (! $member?->family_group_id) {
            return null;
        }

        $isPending = DB::table('family_members')
            ->where('family_group_id', $member->family_group_id)
            ->where('member_id', $member->id)
            ->where('status', FamilyGroup::STATUS_PENDING)
            ->exists();

        return $isPending
            ? FamilyGroup::with('administrator')->find($member->family_group_id)
            : null;
    }

    public function acceptInvitation(): void
    {
        $member     = $this->member;
        $invitation = $this->pendingInvitation;

        if (! $member || ! $invitation) {
            return;
        }

        $invitation->acceptInvitation($member);

        unset($this->member, $this->family, $this->pendingInvitation);
        $this->flashMessage = 'Du är nu med i familjegruppen!';
    }

    public function declineInvitation(): void
    {
        $member     = $this->member;
        $invitation = $this->pendingInvitation;

        if (! $member || ! $invitation) {
            return;
        }

        $invitation->cancelInvitation($member, auth()->user());

        unset($this->member, $this->family, $this->pendingInvitation);
        $this->flashMessage = 'Du avböjde inbjudan.';
    }

    public function createGroup(): void
    {
        $member = $this->member;

        if (! $member || $member->family_group_id) {
            return;
        }

        if (! SystemSetting::get('modules.family_groups', true)) {
            return;
        }

        $group = FamilyGroup::create(['administrator_member_id' => $member->id]);
        $group->addMember($member, FamilyGroup::ROLE_ADULT, true, auth()->user());

        $this->redirectRoute('family.manage', navigate: true);
    }

    public function leaveFamily(): void
    {
        $member = $this->member;
        $family = $this->family;

        if (! $member || ! $family) {
            return;
        }

        // Billing contact must transfer responsibility before leaving
        if ($family->isBillingContact($member)) {
            $this->addError('leave', 'Du är betalningsansvarig och kan inte lämna familjen utan att först överföra rollen till en annan vuxen. Gå till "Hantera familj" för att ändra betalningsansvarig.');
            return;
        }

        $family->leave($member, auth()->user());

        // Invalidate computed caches
        unset($this->member, $this->family);
    }
}; ?>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
    @php $family = $this->family; $invitation = $this->pendingInvitation; @endphp

    @if($flashMessage)
        <div x-data x-init="setTimeout(() => $el.remove(), 5000)"
             class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
            {{ $flashMessage }}
        </div>
    @endif

    @if(! $this->member)
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="house-heart" class="mx-auto mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Du behöver vara registrerad som medlem för att se familjeinfo.</p>
        </div>
    @elseif($invitation)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-800 dark:bg-amber-900/10">
            <div class="flex items-start gap-4">
                <x-bootstrap-icon name="house-heart" class="mt-0.5 size-8 shrink-0 text-amber-500 dark:text-amber-400" />
                <div class="flex-1">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                        Du har blivit inbjuden till en familjegrupp
                    </p>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        <span class="font-medium">{{ $invitation->administrator?->full_name ?? 'En familjeansvarig' }}</span>
                        vill att du går med i deras familjegrupp.
                    </p>
                    <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">
                        Om du accepterar knyts ditt konto till familjegruppen. Din avgift hanteras av den betalningsansvarige.
                    </p>
                    <div class="mt-4 flex gap-3">
                        <button wire:click="acceptInvitation"
                                wire:loading.attr="disabled"
                                class="btn btn-primary">
                            <x-bootstrap-icon name="check-lg" class="size-4" />
                            Acceptera
                        </button>
                        <button wire:click="declineInvitation"
                                wire:confirm="Avböja inbjudan från {{ $invitation->administrator?->full_name ?? 'familjeansvarig' }}?"
                                class="btn btn-ghost text-red-600 dark:text-red-400">
                            Avböj
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @elseif(! $family)
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-6 text-center dark:border-zinc-700 dark:bg-zinc-800/50">
            <x-bootstrap-icon name="house-heart" class="mx-auto mb-3 size-8 text-zinc-400" />
            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Du tillhör ingen familjegrupp</p>
            <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">
                Skapa en grupp och bjud in familjemedlemmar. Du blir automatiskt betalningsansvarig.
            </p>
            @if(SystemSetting::get('modules.family_groups', true))
                <button wire:click="createGroup"
                        wire:loading.attr="disabled"
                        class="btn btn-primary mt-4">
                    <x-bootstrap-icon name="house-heart" class="size-4" />
                    Skapa familjegrupp
                </button>
            @endif
        </div>
    @else
        <section>
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                Familjemedlemmar ({{ $family->membersWithPivot->count() }})
            </h2>

            <ul class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                @foreach($family->membersWithPivot as $fm)
                    <li class="flex items-center gap-3 px-5 py-3.5">
                        <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-xs font-bold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                            {{ mb_strtoupper(mb_substr($fm->first_name, 0, 1)) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $fm->full_name }}</span>

                                @if($fm->pivot->role === FamilyGroup::ROLE_CHILD)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                                        Barn
                                    </span>
                                @endif

                                @if($family->administrator_member_id === $fm->id)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-complement/10 px-2 py-0.5 text-xs font-medium text-complement dark:text-complement-light">
                                        <x-bootstrap-icon name="receipt" class="size-3" />
                                        Betalningsansvarig
                                    </span>
                                @endif

                                @if(! $fm->pivot->has_own_account)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                        Ingen inloggning
                                    </span>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- Leave family error --}}
        @error('leave')
            <div class="mt-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/40 dark:bg-amber-900/10 dark:text-amber-300">
                <x-bootstrap-icon name="exclamation-triangle" class="mt-0.5 size-4 shrink-0" />
                <span>{{ $message }}</span>
            </div>
        @enderror

        <div class="mt-4 flex items-center justify-between gap-3">
            {{-- Leave family --}}
            @php $isBillingContact = $family->isBillingContact($this->member); @endphp
            <div>
                @if($isBillingContact)
                    <p class="text-xs text-zinc-400 dark:text-zinc-500">
                        Som betalningsansvarig kan du inte lämna familjen. Överför rollen först under "Hantera familj".
                    </p>
                @else
                    <button
                        wire:click="leaveFamily"
                        wire:confirm="Är du säker på att du vill lämna familjegruppen? Din årsavgift är betald och ditt medlemskap är aktivt till årets slut."
                        class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20">
                        <x-bootstrap-icon name="box-arrow-right" class="size-4" />
                        Lämna familjegruppen
                    </button>
                @endif
            </div>

            @if($family->isManagedBy($this->member))
                <a href="{{ route('family.manage') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    <x-bootstrap-icon name="gear" class="size-4" />
                    Hantera familj
                </a>
            @endif
        </div>
    @endif
</div>
