<?php

use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use App\Models\Organization\SectionMembership;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    #[Computed]
    public function member(): ?Member
    {
        return Member::where('user_id', auth()->id())
            ->with([
                'sections'     => fn ($q) => $q->wherePivotNull('left_at')->withPivot(['status', 'joined_at', 'left_at', 'requested_at']),
                'sectionRoles' => fn ($q) => $q->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))->with('section'),
            ])
            ->first();
    }

    #[Computed]
    public function joinableSections()
    {
        if (! $this->member) {
            return collect();
        }

        $memberSectionIds = $this->member->sections->pluck('id');

        return Section::whereIn('membership_type', [Section::MEMBERSHIP_OPEN, Section::MEMBERSHIP_APPLICATION])
            ->where('is_active', true)
            ->whereNotIn('id', $memberSectionIds)
            ->orderBy('name')
            ->get()
            ->filter(fn (Section $s) => $s->canView($this->member));
    }

    public function joinSection(int $sectionId): void
    {
        $section = Section::findOrFail($sectionId);
        $member  = $this->member;

        if (! $member || $section->membership_type !== Section::MEMBERSHIP_OPEN) {
            return;
        }

        SectionMembership::updateOrCreate(
            ['section_id' => $section->id, 'member_id' => $member->id],
            ['status' => SectionMembership::STATUS_ACTIVE, 'joined_at' => now(), 'left_at' => null, 'requested_at' => null]
        );

        AuditLog::record('section_joined', 'sections', $section->name, $section);
        unset($this->member, $this->joinableSections);
    }

    public function applyToSection(int $sectionId): void
    {
        $section = Section::findOrFail($sectionId);
        $member  = $this->member;

        if (! $member || $section->membership_type !== Section::MEMBERSHIP_APPLICATION) {
            return;
        }

        $existing = SectionMembership::where('section_id', $section->id)
            ->where('member_id', $member->id)
            ->whereNull('left_at')
            ->first();

        if ($existing) {
            return;
        }

        SectionMembership::create([
            'section_id'   => $section->id,
            'member_id'    => $member->id,
            'status'       => SectionMembership::STATUS_PENDING,
            'requested_at' => now(),
        ]);

        AuditLog::record('section_applied', 'sections', $section->name, $section);
        unset($this->member, $this->joinableSections);
    }

    public function leaveSection(int $sectionId): void
    {
        $member = $this->member;
        if (! $member) {
            return;
        }

        $section = Section::find($sectionId);
        SectionMembership::where('section_id', $sectionId)
            ->where('member_id', $member->id)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);

        AuditLog::record('section_left', 'sections', $section?->name ?? (string) $sectionId, $section);
        unset($this->member, $this->joinableSections);
    }
}; ?>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
    @if(! $this->member)
        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <x-bootstrap-icon name="people" class="mx-auto mb-4 size-12 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Du behöver vara registrerad som medlem för att se grupper.</p>
        </div>
    @else
        @php
            $myMemberships = $this->member->sections
                ->map(function ($s) {
                    $role = $this->member->sectionRoles->first(fn ($r) => $r->section_id === $s->id);
                    return ['section' => $s, 'pivot' => $s->pivot, 'role' => $role];
                });
            $adminGroupIds = $this->member->sectionRoles
                ->where('is_admin', true)
                ->filter(fn ($r) => $r->valid_until === null || $r->valid_until->isFuture())
                ->pluck('section_id')
                ->toArray();
        @endphp

        <div class="mb-8">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                Mina grupper ({{ $myMemberships->count() }})
            </h2>

            @if($myMemberships->isEmpty())
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-8 text-center dark:border-zinc-700 dark:bg-zinc-800/50">
                    <x-bootstrap-icon name="people" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Du är inte med i några grupper än.</p>
                    <a href="{{ route('groups.index') }}" wire:navigate
                       class="mt-3 inline-flex items-center gap-1 text-sm text-brand-orange hover:underline">
                        <x-bootstrap-icon name="diagram-3" class="size-3.5" /> Bläddra bland grupper
                    </a>
                </div>
            @else
                <ul class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                    @foreach($myMemberships as $item)
                        @php
                            $s       = $item['section'];
                            $role    = $item['role'];
                            $status  = $item['pivot']->status ?? null;
                            $isAdmin = in_array($s->id, $adminGroupIds);
                        @endphp
                        <li class="flex items-center gap-3 px-5 py-3.5">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-complement/10 dark:bg-complement/20">
                                <x-bootstrap-icon name="people-fill" class="size-4 text-complement dark:text-complement-light" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('groups.show', $s) }}" wire:navigate
                                   class="text-sm font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">{{ $s->name }}</a>
                                <p class="text-xs text-zinc-400 dark:text-zinc-500">
                                    @if($status === 'pending')
                                        <span class="text-amber-600 dark:text-amber-400">Väntar på godkännande</span>
                                    @elseif($role)
                                        {{ $role->title }}
                                        @if($isAdmin)
                                            <span class="ml-1 inline-flex items-center gap-0.5 rounded bg-complement/10 px-1.5 py-0.5 text-xs font-medium text-complement dark:bg-complement/20 dark:text-complement-light">
                                                <x-bootstrap-icon name="shield-fill" class="size-3" />
                                                Admin
                                            </span>
                                        @endif
                                    @else
                                        Medlem
                                        @if($isAdmin)
                                            <span class="ml-1 inline-flex items-center gap-0.5 rounded bg-complement/10 px-1.5 py-0.5 text-xs font-medium text-complement dark:bg-complement/20 dark:text-complement-light">
                                                <x-bootstrap-icon name="shield-fill" class="size-3" />
                                                Admin
                                            </span>
                                        @endif
                                    @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if($isAdmin)
                                    <a href="{{ route('groups.show', $s) }}?tab=members" wire:navigate
                                       class="inline-flex items-center gap-1 rounded-lg bg-complement/10 px-2.5 py-1 text-xs font-medium text-complement hover:bg-complement/20 dark:bg-complement/20 dark:text-complement-light dark:hover:bg-complement/30">
                                        <x-bootstrap-icon name="people" class="size-3" />
                                        Medlemmar
                                    </a>
                                @endif
                                @if($status !== 'pending' && $s->membership_type !== \App\Models\Organization\Section::MEMBERSHIP_MANDATORY)
                                    <button wire:click="leaveSection({{ $s->id }})"
                                            wire:confirm="Lämna gruppen {{ $s->name }}?"
                                            class="rounded-lg px-2.5 py-1 text-xs font-medium text-zinc-400 hover:bg-red-50 hover:text-red-500 dark:text-zinc-500 dark:hover:bg-red-900/20 dark:hover:text-red-400">
                                        Lämna
                                    </button>
                                @elseif($status === 'pending')
                                    <button wire:click="leaveSection({{ $s->id }})"
                                            wire:confirm="Dra tillbaka ansökan?"
                                            class="rounded-lg px-2.5 py-1 text-xs font-medium text-zinc-400 hover:bg-red-50 hover:text-red-500 dark:text-zinc-500 dark:hover:bg-red-900/20 dark:hover:text-red-400">
                                        Dra tillbaka
                                    </button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @php $joinable = $this->joinableSections; @endphp
        @if($joinable->isNotEmpty())
            <div>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                    Grupper du kan gå med i
                </h2>
                <ul class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                    @foreach($joinable as $s)
                        <li class="flex items-center gap-3 px-5 py-3.5">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <x-bootstrap-icon name="people" class="size-4 text-zinc-400 dark:text-zinc-500" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('groups.show', $s) }}" wire:navigate
                                   class="text-sm font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">{{ $s->name }}</a>
                                @if($s->description)
                                    <p class="mt-0.5 truncate text-xs text-zinc-400 dark:text-zinc-500">{{ $s->description }}</p>
                                @endif
                            </div>
                            <div class="shrink-0">
                                @if($s->membership_type === \App\Models\Organization\Section::MEMBERSHIP_OPEN)
                                    <button wire:click="joinSection({{ $s->id }})"
                                            class="inline-flex items-center gap-1 rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-brand-dark hover:bg-brand-saffron">
                                        <x-bootstrap-icon name="plus" class="size-3" />
                                        Gå med
                                    </button>
                                @else
                                    <button wire:click="applyToSection({{ $s->id }})"
                                            class="inline-flex items-center gap-1 rounded-lg border border-complement px-3 py-1.5 text-xs font-semibold text-complement hover:bg-complement/5 dark:border-complement-light dark:text-complement-light">
                                        <x-bootstrap-icon name="send" class="size-3" />
                                        Ansök
                                    </button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-4 text-center">
            <a href="{{ route('groups.index') }}" wire:navigate
               class="inline-flex items-center gap-1.5 text-sm text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300 transition-colors">
                <x-bootstrap-icon name="diagram-3" class="size-3.5" /> Se alla grupper
            </a>
        </div>
    @endif
</div>
