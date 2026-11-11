<?php

use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Organization\OrganizationalRole;
use App\Models\Organization\OrganizationalRoleMember;
use Illuminate\Support\Facades\Auth;
use App\Models\Organization\OrganizationalRolePeriod;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    public OrganizationalRole $role;

    // Period create form
    public bool $showPeriodForm = false;
    public string $periodName        = '';
    public string $periodType        = 'calendar_year';
    public string $periodYear        = '';
    public string $formingStart      = '';
    public string $activeStart       = '';
    public string $activeEnd         = '';
    public string $reportingEnd      = '';
    public string $customStart       = '';
    public string $customEnd         = '';

    // Member add form (per period)
    public ?int $addingMemberToPeriod = null;
    public string $memberSearch      = '';
    public string $memberTitle       = '';
    public string $memberTransition  = 'current';

    public function mount(OrganizationalRole $role): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('org-roles.manage'), 403);

        $this->role = $role->load([
            'periods' => fn ($q) => $q->orderByDesc('active_start'),
            'periods.members.member',
            'section',
        ]);

        // Sensible defaults for period create
        $this->periodYear  = (string) now()->year;
        $this->activeStart = now()->startOfYear()->format('Y-m-d');
        $this->activeEnd   = now()->endOfYear()->format('Y-m-d');
    }

    public function updatedPeriodType(): void
    {
        if ($this->periodType === 'calendar_year') {
            $year              = (int) ($this->periodYear ?: now()->year);
            $this->activeStart = Carbon::create($year, 1, 1)->format('Y-m-d');
            $this->activeEnd   = Carbon::create($year, 12, 31)->format('Y-m-d');
            $this->periodName  = $this->role->name . ' ' . $year;
        }
    }

    public function updatedPeriodYear(): void
    {
        if ($this->periodType === 'calendar_year' && $this->periodYear) {
            $year              = (int) $this->periodYear;
            $this->activeStart = Carbon::create($year, 1, 1)->format('Y-m-d');
            $this->activeEnd   = Carbon::create($year, 12, 31)->format('Y-m-d');
            $this->periodName  = $this->role->name . ' ' . $year;
        }
    }

    public function openPeriodForm(): void
    {
        $year              = (int) now()->year;
        $this->periodType  = 'calendar_year';
        $this->periodYear  = (string) $year;
        $this->activeStart = Carbon::create($year, 1, 1)->format('Y-m-d');
        $this->activeEnd   = Carbon::create($year, 12, 31)->format('Y-m-d');
        $this->periodName  = $this->role->name . ' ' . $year;
        $this->formingStart = '';
        $this->reportingEnd = '';
        $this->showPeriodForm = true;
    }

    public function savePeriod(): void
    {
        $this->validate([
            'periodName'    => 'required|string|max:120',
            'periodType'    => 'required|in:calendar_year,custom',
            'periodYear'    => 'nullable|integer|min:2000|max:2099',
            'activeStart'   => 'required|date',
            'activeEnd'     => 'required|date|after:activeStart',
            'formingStart'  => 'nullable|date|before:activeStart',
            'reportingEnd'  => 'nullable|date|after:activeEnd',
            'customStart'   => 'nullable|date',
            'customEnd'     => 'nullable|date|after_or_equal:customStart',
        ]);

        $period = OrganizationalRolePeriod::create([
            'organizational_role_id' => $this->role->id,
            'name'                   => $this->periodName,
            'period_type'            => $this->periodType,
            'year'                   => $this->periodType === 'calendar_year' ? (int) $this->periodYear : null,
            'custom_start'           => $this->periodType === 'custom' ? $this->customStart ?: null : null,
            'custom_end'             => $this->periodType === 'custom' ? $this->customEnd ?: null : null,
            'forming_start'          => $this->formingStart ?: null,
            'active_start'           => $this->activeStart,
            'active_end'             => $this->activeEnd,
            'reporting_end'          => $this->reportingEnd ?: null,
            'status'                 => $this->formingStart && now()->lt(Carbon::parse($this->activeStart))
                                            ? OrganizationalRolePeriod::STATUS_FORMING
                                            : OrganizationalRolePeriod::STATUS_ACTIVE,
        ]);

        AuditLog::record('org_role_period_saved', 'org-roles', $this->role->name . ': ' . $period->name, $period);

        $this->showPeriodForm = false;
        $this->role->load(['periods' => fn ($q) => $q->orderByDesc('active_start'), 'periods.members.member']);
    }

    public function updatePeriodStatus(int $periodId, string $status): void
    {
        $allowed = [
            OrganizationalRolePeriod::STATUS_FORMING,
            OrganizationalRolePeriod::STATUS_ACTIVE,
            OrganizationalRolePeriod::STATUS_REPORTING,
            OrganizationalRolePeriod::STATUS_CLOSED,
        ];

        if (! in_array($status, $allowed)) {
            return;
        }

        $statusPeriod = OrganizationalRolePeriod::findOrFail($periodId);
        $statusPeriod->transitionTo($status);
        AuditLog::record('org_role_period_status_updated', 'org-roles', $this->role->name . ': ' . $statusPeriod->name . ' → ' . $status, $statusPeriod);
        $this->role->load(['periods' => fn ($q) => $q->orderByDesc('active_start'), 'periods.members.member']);
    }

    public function deletePeriod(int $periodId): void
    {
        $period = OrganizationalRolePeriod::findOrFail($periodId);
        $periodName = $period->name;
        $period->members()->delete();
        $period->delete();
        AuditLog::record('org_role_period_deleted', 'org-roles', $this->role->name . ': ' . $periodName, null);
        $this->role->load(['periods' => fn ($q) => $q->orderByDesc('active_start'), 'periods.members.member']);
    }

    public function startAddMember(int $periodId): void
    {
        $this->addingMemberToPeriod = $periodId;
        $this->memberSearch         = '';
        $this->memberTitle          = '';
        $this->memberTransition     = 'current';
    }

    public function cancelAddMember(): void
    {
        $this->addingMemberToPeriod = null;
        $this->memberSearch         = '';
    }

    public function searchResults()
    {
        if (! $this->addingMemberToPeriod || strlen($this->memberSearch) < 2) {
            return collect();
        }

        $currentIds = OrganizationalRoleMember::where('organizational_role_period_id', $this->addingMemberToPeriod)
            ->whereNotNull('member_id')
            ->pluck('member_id');

        $term = mb_strtolower($this->memberSearch);
        return Member::whereNotIn('id', $currentIds)
            ->get()
            ->filter(fn ($m) =>
                str_contains(mb_strtolower($m->first_name . ' ' . $m->last_name), $term)
                || str_contains(mb_strtolower($m->email ?? ''), $term)
            )
            ->take(8)
            ->values();
    }

    public function addMember(int $memberId): void
    {
        if (! $this->addingMemberToPeriod) {
            return;
        }

        $newMember = Member::find($memberId);
        $roleMember = OrganizationalRoleMember::create([
            'organizational_role_period_id' => $this->addingMemberToPeriod,
            'member_id'                     => $memberId,
            'title'                         => $this->memberTitle ?: null,
            'transition_role'               => $this->memberTransition,
        ]);

        AuditLog::record('org_role_member_added', 'org-roles', $this->role->name . ': ' . ($newMember?->full_name ?? $memberId), $roleMember);

        $this->memberSearch = '';
        $this->memberTitle  = '';
        $this->role->load(['periods' => fn ($q) => $q->orderByDesc('active_start'), 'periods.members.member']);
    }

    public function addExternal(): void
    {
        if (! $this->addingMemberToPeriod || ! trim($this->memberSearch)) {
            return;
        }

        $externalName = trim($this->memberSearch);
        $externalRoleMember = OrganizationalRoleMember::create([
            'organizational_role_period_id' => $this->addingMemberToPeriod,
            'member_id'                     => null,
            'name'                          => $externalName,
            'title'                         => $this->memberTitle ?: null,
            'transition_role'               => $this->memberTransition,
        ]);

        AuditLog::record('org_role_external_added', 'org-roles', $this->role->name . ': ' . $externalName . ' (extern)', $externalRoleMember);

        $this->memberSearch = '';
        $this->memberTitle  = '';
        $this->role->load(['periods' => fn ($q) => $q->orderByDesc('active_start'), 'periods.members.member']);
    }

    public function removeMember(int $roleMemberId): void
    {
        $removedRoleMember = OrganizationalRoleMember::with('member')->findOrFail($roleMemberId);
        $removedName = $removedRoleMember->member?->full_name ?? $removedRoleMember->name ?? '–';
        $removedRoleMember->delete();
        AuditLog::record('org_role_member_removed', 'org-roles', $this->role->name . ': ' . $removedName, null);
        $this->role->load(['periods' => fn ($q) => $q->orderByDesc('active_start'), 'periods.members.member']);
    }

    public function updateAccessUntil(int $roleMemberId, string $date): void
    {
        abort_unless(Auth::user()->hasPermissionTo('org-roles.manage'), 403);
        $rm = OrganizationalRoleMember::findOrFail($roleMemberId);
        $parsed = $date ? \Illuminate\Support\Carbon::parse($date) : null;
        $rm->update(['access_until' => $parsed]);
        AuditLog::record('org_role_access_until_updated', 'org-roles',
            $this->role->name . ': ' . ($rm->member?->full_name ?? '–') . ' → ' . ($parsed?->format('d/m Y') ?? 'borttaget'),
            $rm
        );
        $this->role->load(['periods' => fn ($q) => $q->orderByDesc('active_start'), 'periods.members.member', 'section']);
    }

    public function updateTitle(int $roleMemberId, string $title): void
    {
        $titleRoleMember = OrganizationalRoleMember::with('member')->findOrFail($roleMemberId);
        $titleRoleMember->update(['title' => $title ?: null]);
        AuditLog::record('org_role_title_updated', 'org-roles', $this->role->name . ': ' . ($titleRoleMember->member?->full_name ?? $titleRoleMember->name ?? '–') . ' → ' . ($title ?: 'ingen titel'), $titleRoleMember);
        $this->role->load(['periods' => fn ($q) => $q->orderByDesc('active_start'), 'periods.members.member']);
    }

    public function updateTransition(int $roleMemberId, string $transition): void
    {
        $allowed = ['incoming', 'current', 'outgoing'];
        if (in_array($transition, $allowed)) {
            $transitionRoleMember = OrganizationalRoleMember::with('member')->findOrFail($roleMemberId);
            $transitionRoleMember->update(['transition_role' => $transition]);
            AuditLog::record('org_role_transition_updated', 'org-roles', $this->role->name . ': ' . ($transitionRoleMember->member?->full_name ?? $transitionRoleMember->name ?? '–') . ' → ' . $transition, $transitionRoleMember);
            $this->role->load(['periods' => fn ($q) => $q->orderByDesc('active_start'), 'periods.members.member']);
        }
    }

    public function copyFromPreviousPeriod(int $periodId): void
    {
        $period = OrganizationalRolePeriod::findOrFail($periodId);

        $source = OrganizationalRolePeriod::where('organizational_role_id', $period->organizational_role_id)
            ->where('active_start', '<', $period->active_start)
            ->orderByDesc('active_start')
            ->first();

        if (! $source) {
            return;
        }

        $existingMemberIds = $period->members()->whereNotNull('member_id')->pluck('member_id');

        foreach ($source->members as $rm) {
            if ($rm->member_id && $existingMemberIds->contains($rm->member_id)) {
                continue;
            }

            OrganizationalRoleMember::create([
                'organizational_role_period_id' => $period->id,
                'member_id'                     => $rm->member_id,
                'name'                          => $rm->name,
                'title'                         => $rm->title,
                'transition_role'               => 'current',
            ]);
        }

        AuditLog::record('org_role_period_copied', 'org-roles', $this->role->name . ': kopierade från ' . $source->name . ' till ' . $period->name, $period);

        $this->role->load(['periods' => fn ($q) => $q->orderByDesc('active_start'), 'periods.members.member']);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="org-roles.manage" :title="$role->name" subtitle="Organisationsroll">
        <div class="flex gap-2">
            <a href="{{ route('admin.org-roles.edit', $role) }}" wire:navigate
               class="inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                Redigera
            </a>
            <a href="{{ route('admin.org-roles.index') }}" wire:navigate
               class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                ← Tillbaka
            </a>
        </div>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="grid gap-12 lg:grid-cols-3">

        {{-- Left: periods --}}
        <div class="lg:col-span-2 space-y-8">

            {{-- Period create form --}}
            @if($showPeriodForm)
                <section class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <h3 class="mb-4 text-sm font-semibold text-zinc-900 dark:text-white">Ny period</h3>
                    <form wire:submit="savePeriod" class="space-y-4">

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="lbl">Periodtyp</label>
                                <select wire:model.live="periodType" class="inp mt-1">
                                    <option value="calendar_year">Kalenderår</option>
                                    <option value="custom">Anpassad period</option>
                                </select>
                            </div>
                            @if($periodType === 'calendar_year')
                                <div>
                                    <label class="lbl">År</label>
                                    <input type="number" wire:model.live="periodYear" class="inp mt-1" min="2000" max="2099" />
                                    @error('periodYear') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                                </div>
                            @else
                                <div>
                                    <label class="lbl">Anpassad start</label>
                                    <input type="date" wire:model="customStart" class="inp mt-1" />
                                </div>
                            @endif
                        </div>

                        <div>
                            <label class="lbl">Periodnamn <span class="text-red-500">*</span></label>
                            <input type="text" wire:model="periodName" class="inp mt-1" placeholder="t.ex. Styrelsen 2025" />
                            @error('periodName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>

                        @if($periodType === 'custom')
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="lbl">Anpassad slut</label>
                                    <input type="date" wire:model="customEnd" class="inp mt-1" />
                                </div>
                            </div>
                        @endif

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="lbl">Aktiv från <span class="text-red-500">*</span></label>
                                <input type="date" wire:model="activeStart" class="inp mt-1" />
                                @error('activeStart') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Aktiv till <span class="text-red-500">*</span></label>
                                <input type="date" wire:model="activeEnd" class="inp mt-1" />
                                @error('activeEnd') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="lbl">Bildningsstart <span class="text-xs text-zinc-400">(valfri)</span></label>
                                <input type="date" wire:model="formingStart" class="inp mt-1" />
                                @error('formingStart') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Rapportering till <span class="text-xs text-zinc-400">(valfri)</span></label>
                                <input type="date" wire:model="reportingEnd" class="inp mt-1" />
                                @error('reportingEnd') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="flex gap-3 pt-1">
                            <button type="submit" class="btn btn-primary">Spara period</button>
                            <button type="button" wire:click="$set('showPeriodForm', false)" class="btn btn-ghost">Avbryt</button>
                        </div>
                    </form>
                </section>
            @endif

            {{-- Periods list --}}
            <section>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                        Perioder ({{ $role->periods->count() }})
                    </h2>
                    @if(! $showPeriodForm)
                        <button wire:click="openPeriodForm"
                                class="inline-flex items-center gap-1 text-xs text-complement hover:underline dark:text-complement-light">
                            <x-bootstrap-icon name="plus-lg" class="size-3" />
                            Lägg till period
                        </button>
                    @endif
                </div>

                @if($role->periods->isEmpty())
                    <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga perioder skapade ännu.</p>
                @else
                    <div class="space-y-4">
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
                                $currentMembers  = $period->members->where('transition_role', 'current');
                                $incomingMembers = $period->members->where('transition_role', 'incoming');
                                $outgoingMembers = $period->members->where('transition_role', 'outgoing');
                                $sourcePeriod    = $role->periods->filter(fn($p) => $p->active_start < $period->active_start)->first();
                            @endphp
                            <div x-data="{ open: {{ $period->status !== 'closed' ? 'true' : 'false' }}, hovered: false }"
                                 @mouseenter="hovered = true" @mouseleave="hovered = false"
                                 class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">

                                {{-- Period header --}}
                                <button @click="open = !open"
                                        class="flex w-full items-center justify-between gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <x-bootstrap-icon name="chevron-right" class="size-3.5 shrink-0 text-zinc-400 transition-transform duration-150" x-bind:class="{ 'rotate-90': open }" />
                                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $period->name }}</span>
                                        <span class="rounded-full px-2 py-0.5 text-xs {{ $statusColor }}">{{ $statusLabel }}</span>
                                        <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $period->members->count() }} {{ $period->members->count() === 1 ? 'person' : 'personer' }}</span>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1 transition-opacity" :class="hovered ? 'opacity-100' : 'opacity-0'" @click.stop>
                                        <select wire:change="updatePeriodStatus({{ $period->id }}, $event.target.value)"
                                                class="rounded border-0 bg-transparent py-0.5 text-xs text-zinc-500 dark:text-zinc-400 focus:ring-0">
                                            @foreach(['forming' => 'Bildas', 'active' => 'Aktiv', 'reporting' => 'Rapportering', 'closed' => 'Stängd'] as $val => $label)
                                                <option value="{{ $val }}" @selected($period->status === $val)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <button wire:click="deletePeriod({{ $period->id }})"
                                                wire:confirm="Ta bort perioden '{{ $period->name }}' och alla dess medlemmar?"
                                                class="rounded p-1 text-zinc-300 hover:text-red-500 dark:text-zinc-600 dark:hover:text-red-400">
                                            <x-bootstrap-icon name="trash" class="size-3.5" />
                                        </button>
                                    </div>
                                </button>

                                {{-- Period dates --}}
                                <div x-show="open" x-cloak class="border-t border-zinc-100 dark:border-zinc-700">
                                    <div class="flex flex-wrap gap-x-6 gap-y-1 px-4 py-2.5 text-xs text-zinc-400 dark:text-zinc-500">
                                        <span>Aktiv: {{ $period->active_start->format('d/m Y') }} – {{ $period->active_end->format('d/m Y') }}</span>
                                        @if($period->forming_start)
                                            <span>Bildning från: {{ $period->forming_start->format('d/m Y') }}</span>
                                        @endif
                                        @if($period->reporting_end)
                                            <span>Rapportering till: {{ $period->reporting_end->format('d/m Y') }}</span>
                                        @endif
                                    </div>

                                    {{-- Members by transition role --}}
                                    <div class="space-y-4 px-4 pb-4">

                                        @foreach([
                                            ['incoming', 'Tillträdande', $incomingMembers],
                                            ['current',  'Sittande',     $currentMembers],
                                            ['outgoing', 'Avgående',     $outgoingMembers],
                                        ] as [$transitionKey, $transitionLabel, $transitionGroup])
                                            @if($transitionGroup->isNotEmpty())
                                                <div>
                                                    <p class="mb-1.5 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $transitionLabel }}</p>
                                                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                                        @foreach($transitionGroup as $rm)
                                                            <li class="group flex items-center gap-3 py-2">
                                                                @php $displayName = $rm->member?->full_name ?? $rm->name ?? '–'; @endphp
                                                                <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-xs font-bold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                                                    {{ mb_strtoupper(mb_substr($displayName, 0, 1)) }}
                                                                </div>
                                                                <div class="min-w-0 flex-1"
                                                                     x-data="{ editing: false, val: @js($rm->title ?? '') }"
                                                                     @click.away="editing = false">
                                                                    @if($rm->member)
                                                                        <a href="{{ route('admin.members.show', $rm->member) }}" wire:navigate
                                                                           class="text-sm font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                                                                            {{ $displayName }}
                                                                        </a>
                                                                    @else
                                                                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $displayName }}</span>
                                                                        <span class="ml-1.5 rounded-full bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">Extern</span>
                                                                    @endif
                                                                    <span x-show="!editing"
                                                                          @click.prevent="editing = true; $nextTick(() => $refs.titleInput{{ $rm->id }}.focus())"
                                                                          class="ml-1.5 cursor-pointer text-xs text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300"
                                                                          :class="val ? '' : 'italic'">
                                                                        <span x-text="val || 'Lägg till titel'"></span>
                                                                    </span>
                                                                    <input x-show="editing"
                                                                           x-ref="titleInput{{ $rm->id }}"
                                                                           x-model="val"
                                                                           @keydown.enter="editing = false; $wire.updateTitle({{ $rm->id }}, val)"
                                                                           @keydown.escape="editing = false"
                                                                           @blur="editing = false; $wire.updateTitle({{ $rm->id }}, val)"
                                                                           type="text"
                                                                           placeholder="Titel…"
                                                                           class="ml-1.5 rounded border border-zinc-300 px-1.5 py-0.5 text-xs text-zinc-700 focus:border-complement focus:outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" />
                                                                </div>
                                                                <div class="flex shrink-0 items-center gap-1.5 opacity-0 transition-opacity group-hover:opacity-100">
                                                                    <select wire:change="updateTransition({{ $rm->id }}, $event.target.value)"
                                                                            class="rounded border-zinc-200 bg-transparent py-0.5 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                                                                        <option value="incoming" @selected($rm->transition_role === 'incoming')>Tillträdande</option>
                                                                        <option value="current"  @selected($rm->transition_role === 'current')>Sittande</option>
                                                                        <option value="outgoing" @selected($rm->transition_role === 'outgoing')>Avgående</option>
                                                                    </select>
                                                                    <input type="date"
                                                                           title="Förlängd access till (access_until) — åsidosätter karenstiden"
                                                                           value="{{ $rm->access_until?->format('Y-m-d') }}"
                                                                           wire:change="updateAccessUntil({{ $rm->id }}, $event.target.value)"
                                                                           class="rounded border border-zinc-200 bg-transparent py-0.5 px-1 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400 dark:bg-transparent" />
                                                                    <button wire:click="removeMember({{ $rm->id }})"
                                                                            wire:confirm="Ta bort {{ $rm->member?->full_name ?? $rm->name ?? 'personen' }} från perioden?"
                                                                            class="rounded p-1 text-zinc-300 hover:text-red-500 dark:text-zinc-600 dark:hover:text-red-400">
                                                                        <x-bootstrap-icon name="x-lg" class="size-3.5" />
                                                                    </button>
                                                                </div>
                                                                @if($rm->access_until)
                                                                    <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-400"
                                                                          title="Förlängd access">
                                                                        till {{ $rm->access_until->isoFormat('D MMM YYYY') }}
                                                                    </span>
                                                                @endif
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif
                                        @endforeach

                                        {{-- Add member to this period --}}
                                        @if($addingMemberToPeriod === $period->id)
                                            <div class="mt-2 space-y-2">
                                                <div class="grid gap-2 sm:grid-cols-2">
                                                    <div class="relative">
                                                        <input type="text"
                                                               wire:model.live.debounce.200ms="memberSearch"
                                                               class="inp w-full text-sm"
                                                               placeholder="Sök namn eller e-post…"
                                                               autofocus />
                                                        @php
                                                            $results = $this->searchResults();
                                                            $statusLabels = [
                                                                'active'   => null,
                                                                'inactive' => 'Inaktiv',
                                                                'expired'  => 'Utgången',
                                                                'pending'  => 'Väntande',
                                                            ];
                                                        @endphp
                                                        @if($results->isNotEmpty() || strlen($memberSearch) >= 2)
                                                            <ul class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                                                                @foreach($results as $m)
                                                                    @php $statusLabel = $statusLabels[$m->status] ?? $m->status; @endphp
                                                                    <li>
                                                                        <button type="button" wire:click="addMember({{ $m->id }})"
                                                                                class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700">
                                                                            <span class="font-medium text-zinc-900 dark:text-white">{{ $m->full_name }}</span>
                                                                            <span class="text-xs text-zinc-400">{{ $m->email }}</span>
                                                                            @if($statusLabel)
                                                                                <span class="ml-auto rounded-full bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">{{ $statusLabel }}</span>
                                                                            @endif
                                                                        </button>
                                                                    </li>
                                                                @endforeach
                                                                @if(strlen($memberSearch) >= 2)
                                                                    <li class="border-t border-zinc-100 dark:border-zinc-700">
                                                                        <button type="button" wire:click="addExternal"
                                                                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-500 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:bg-zinc-700">
                                                                            <x-bootstrap-icon name="person-plus" class="size-3.5 shrink-0" />
                                                                            Lägg till "{{ $memberSearch }}" som extern
                                                                        </button>
                                                                    </li>
                                                                @endif
                                                            </ul>
                                                        @endif
                                                    </div>
                                                    <input type="text" wire:model="memberTitle"
                                                           class="inp text-sm"
                                                           placeholder="Titel (t.ex. Ordförande)" />
                                                </div>
                                                <div class="flex items-center gap-3">
                                                    <select wire:model="memberTransition" class="inp text-sm">
                                                        <option value="incoming">Tillträdande</option>
                                                        <option value="current" selected>Sittande</option>
                                                        <option value="outgoing">Avgående</option>
                                                    </select>
                                                    <button type="button" wire:click="cancelAddMember"
                                                            class="text-sm text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                                        Avbryt
                                                    </button>
                                                </div>
                                            </div>
                                        @else
                                            <div class="flex flex-wrap items-center gap-4">
                                                <button wire:click="startAddMember({{ $period->id }})"
                                                        class="inline-flex items-center gap-1 text-xs text-complement hover:underline dark:text-complement-light">
                                                    <x-bootstrap-icon name="plus-lg" class="size-3" />
                                                    Lägg till person
                                                </button>
                                                @if($sourcePeriod && $sourcePeriod->members->isNotEmpty())
                                                    <button wire:click="copyFromPreviousPeriod({{ $period->id }})"
                                                            wire:confirm="Kopiera {{ $sourcePeriod->members->count() }} {{ $sourcePeriod->members->count() === 1 ? 'person' : 'personer' }} från {{ $sourcePeriod->name }}?"
                                                            class="inline-flex items-center gap-1 text-xs text-zinc-400 hover:text-zinc-600 hover:underline dark:text-zinc-500 dark:hover:text-zinc-300">
                                                        <x-bootstrap-icon name="copy" class="size-3" />
                                                        Kopiera från {{ $sourcePeriod->name }}
                                                    </button>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>

        {{-- Right sidebar: role info --}}
        <div class="space-y-8">
            <section>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Info</h2>
                <dl class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <div class="flex justify-between py-2.5">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Slug</dt>
                        <dd class="font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $role->slug }}</dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Korsperiodstillgång</dt>
                        <dd class="text-sm {{ $role->cross_period_access ? 'text-green-600 dark:text-green-400' : 'text-zinc-400' }}">
                            {{ $role->cross_period_access ? 'Ja' : 'Nej' }}
                        </dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">Perioder</dt>
                        <dd class="text-sm text-zinc-900 dark:text-white">{{ $role->periods->count() }}</dd>
                    </div>
                    @if($role->section_id)
                        <div class="flex justify-between py-2.5">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Kopplad grupp</dt>
                            <dd class="text-sm">
                                <a href="{{ route('groups.show', $role->section) }}" wire:navigate
                                   class="text-complement hover:underline dark:text-complement-light">
                                    {{ $role->section->name }}
                                </a>
                            </dd>
                        </div>
                    @endif
                </dl>
            </section>

            @if($role->description)
                <section>
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">Beskrivning</h2>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $role->description }}</p>
                </section>
            @endif

            {{-- Active period members summary --}}
            @php $activePeriod = $role->periods->firstWhere('status', 'active'); @endphp
            @if($activePeriod && $activePeriod->members->isNotEmpty())
                <section>
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                        Aktiv period · {{ $activePeriod->name }}
                    </h2>
                    <ul class="space-y-1.5">
                        @foreach($activePeriod->members->where('transition_role', 'current') as $rm)
                            @php $sidebarName = $rm->member?->full_name ?? $rm->name ?? '–'; @endphp
                            <li class="flex items-center gap-2">
                                <div class="flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-xs font-bold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                    {{ mb_strtoupper(mb_substr($sidebarName, 0, 1)) }}
                                </div>
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $sidebarName }}</span>
                                @if($rm->title)
                                    <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $rm->title }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

    </div>

    @php $roleMailbox = \App\Models\InboxMailbox::forOwner($role); @endphp
    @if($roleMailbox?->is_enabled)
        <div class="mt-12">
            <h2 class="mb-4 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                <x-bootstrap-icon name="inbox" class="mr-1 inline size-3.5" />
                Inkorg · {{ $roleMailbox->address() }}
            </h2>
            <livewire:admin.shared.inbox-panel :mailbox-id="$roleMailbox->id" lazy />
        </div>
    @endif
</div>
