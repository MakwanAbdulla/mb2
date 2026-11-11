<?php

use App\Models\AuditLog;
use App\Models\Organization\Section;
use App\Models\Organization\SectionMembership;
use App\Models\Organization\SectionRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Grupper')]
class extends Component {

    public bool   $showCreateForm  = false;
    public string $newName         = '';
    public string $newDescription  = '';
    public string $newVisibility   = Section::VISIBILITY_PRIVATE;
    public string $newMembership   = Section::MEMBERSHIP_CLOSED;
    public ?int   $newParentId     = null;

    public function mount(): void
    {
        if ($parentId = request()->query('parent')) {
            $this->newParentId    = (int) $parentId;
            $this->showCreateForm = true;
        }
    }

    public function createGroup(): void
    {
        $user   = Auth::user();
        $member = $user?->member;

        abort_unless($member && $member->hasFullAccess(), 403);

        $this->validate([
            'newName'        => 'required|string|min:2|max:120',
            'newDescription' => 'nullable|string|max:500',
            'newVisibility'  => 'required|in:public,members,private,secret',
            'newMembership'  => 'required|in:open,application,closed',
        ], ['newName.required' => 'Ange ett namn.', 'newName.min' => 'Namnet måste vara minst 2 tecken.']);

        $slug = Str::slug($this->newName);
        $base = $slug;
        $i    = 2;
        while (Section::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        $section = Section::create([
            'name'            => $this->newName,
            'slug'            => $slug,
            'description'     => $this->newDescription ?: null,
            'visibility'      => $this->newVisibility,
            'membership_type' => $this->newMembership,
            'parent_id'       => $this->newParentId,
            'is_active'       => true,
        ]);

        SectionMembership::create([
            'section_id' => $section->id,
            'member_id'  => $member->id,
            'status'     => SectionMembership::STATUS_ACTIVE,
            'joined_at'  => now(),
        ]);

        SectionRole::create([
            'section_id' => $section->id,
            'member_id'  => $member->id,
            'title'      => 'Skapare',
            'is_admin'   => true,
            'valid_from' => today(),
        ]);

        AuditLog::record('section_created', 'sections', $section->name, $section);

        $this->redirect(route('groups.show', $section), navigate: true);
    }

    public function join(int $sectionId): void
    {
        $user = Auth::user();
        if (! $user || ! $user->member) return;

        $section = Section::findOrFail($sectionId);
        $member  = $user->member;

        if (! in_array($section->membership_type, [Section::MEMBERSHIP_OPEN, Section::MEMBERSHIP_APPLICATION])) {
            return;
        }
        if (! $section->canView($member)) return;

        $existing = SectionMembership::where('section_id', $sectionId)
            ->where('member_id', $member->id)
            ->whereNull('left_at')
            ->first();
        if ($existing) return;

        $isOpen = $section->membership_type === Section::MEMBERSHIP_OPEN;

        SectionMembership::create([
            'section_id'   => $sectionId,
            'member_id'    => $member->id,
            'status'       => $isOpen ? SectionMembership::STATUS_ACTIVE : SectionMembership::STATUS_PENDING,
            'requested_at' => now(),
            'joined_at'    => $isOpen ? today() : null,
        ]);

        AuditLog::record($isOpen ? 'section_joined' : 'section_applied', 'sections', $section->name, $section);
    }

    public function leave(int $sectionId): void
    {
        $user = Auth::user();
        if (! $user || ! $user->member) return;

        $member  = $user->member;
        $section = Section::find($sectionId);

        SectionMembership::where('section_id', $sectionId)
            ->where('member_id', $member->id)
            ->whereNull('left_at')
            ->update(['left_at' => today(), 'status' => SectionMembership::STATUS_ACTIVE]);

        AuditLog::record('section_left', 'sections', $section?->name ?? (string) $sectionId, $section);
    }

    public function withdraw(int $sectionId): void
    {
        $user = Auth::user();
        if (! $user || ! $user->member) return;

        $section = Section::find($sectionId);
        SectionMembership::where('section_id', $sectionId)
            ->where('member_id', $user->member->id)
            ->where('status', SectionMembership::STATUS_PENDING)
            ->whereNull('left_at')
            ->delete();

        AuditLog::record('section_application_withdrawn', 'sections', $section?->name ?? (string) $sectionId, $section);
    }

    public function with(): array
    {
        $member = Auth::check() ? Auth::user()->member : null;

        $sections = Section::where('is_active', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->with(['children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->get()
            ->filter(fn ($s) => $s->canView($member))
            ->values();

        $myMemberships = collect();
        if ($member) {
            $myMemberships = SectionMembership::where('member_id', $member->id)
                ->whereNull('left_at')
                ->get()
                ->keyBy('section_id');
        }

        return compact('sections', 'myMemberships', 'member');
    }
}
?>

@php
    /** @var \App\Models\Organization\Section[]|\Illuminate\Support\Collection $sections */
    /** @var \Illuminate\Support\Collection $myMemberships */
    /** @var \App\Models\Membership\Member|null $member */
@endphp

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Grupper</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Föreningens sektioner och arbetsgrupper.</p>
        </div>
        @if(Auth::user()?->member?->hasFullAccess())
            <button wire:click="$toggle('showCreateForm')"
                    class="btn btn-secondary btn-sm shrink-0">
                <x-bootstrap-icon name="{{ $showCreateForm ? 'x-lg' : 'plus-lg' }}" class="size-3.5" />
                {{ $showCreateForm ? 'Avbryt' : 'Skapa grupp' }}
            </button>
        @endif
    </div>

    @if($showCreateForm)
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900 space-y-4">
            <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Ny grupp</h2>
            @if($newParentId)
                @php $parentSection = \App\Models\Organization\Section::find($newParentId); @endphp
                @if($parentSection)
                    <span class="text-xs text-zinc-400 dark:text-zinc-500">
                        Undergrupp till
                        <a href="{{ route('groups.show', $parentSection) }}" wire:navigate class="text-complement dark:text-complement-light hover:underline">{{ $parentSection->name }}</a>
                    </span>
                @endif
            @endif
        </div>
            <div>
                <label class="lbl">Namn <span class="text-red-500">*</span></label>
                <input wire:model="newName" type="text" class="inp mt-1" placeholder="t.ex. Rollspel, Filmklubb…" />
                @error('newName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lbl">Beskrivning</label>
                <textarea wire:model="newDescription" rows="2" class="inp mt-1 resize-none" placeholder="Kort beskrivning av gruppen…"></textarea>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="lbl">Synlighet</label>
                    <select wire:model="newVisibility" class="inp mt-1">
                        <option value="public">Publik — alla inloggade</option>
                        <option value="members">Enbart föreningsmedlemmar</option>
                        <option value="private">Privat — syns men bara för medlemmar</option>
                        <option value="secret">Hemlig — osynlig för utomstående</option>
                    </select>
                </div>
                <div>
                    <label class="lbl">Hur går man med?</label>
                    <select wire:model="newMembership" class="inp mt-1">
                        <option value="open">Öppet — självgå</option>
                        <option value="application">Ansökan — kräver godkännande</option>
                        <option value="closed">Stängt — admin bjuder in</option>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 pt-1">
                <button wire:click="createGroup" class="btn btn-primary btn-sm">Skapa grupp</button>
                <button wire:click="$set('showCreateForm', false)" class="btn btn-ghost btn-sm">Avbryt</button>
            </div>
        </div>
    @endif

    @if ($sections->isEmpty())
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-10 text-center text-zinc-400 dark:text-zinc-500">
            Inga grupper att visa just nu.
        </div>
    @else
        <div class="space-y-3">
            @foreach ($sections as $section)
                @php
                    $ms       = $myMemberships->get($section->id);
                    $isActive  = $ms && $ms->status === 'active';
                    $isPending = $ms && $ms->status === 'pending';
                    $canJoin   = $member && ! $ms && in_array($section->membership_type, [
                        Section::MEMBERSHIP_OPEN,
                        Section::MEMBERSHIP_APPLICATION,
                    ]);
                    $memberCount = $section->activeMembers()->count();
                @endphp
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">
                    <div class="flex items-start justify-between gap-4 p-5">
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <a href="{{ route('groups.show', $section) }}" wire:navigate
                                   class="text-base font-semibold text-zinc-900 dark:text-white hover:text-complement dark:hover:text-complement-light">{{ $section->name }}</a>
                                @if ($isActive)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 border border-green-200 dark:border-green-700/50">
                                        <x-bootstrap-icon name="check-circle" class="size-3" /> Medlem
                                    </span>
                                @elseif ($isPending)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-700/50">
                                        <x-bootstrap-icon name="hourglass-split" class="size-3" /> Ansökan skickad
                                    </span>
                                @elseif ($section->membership_type === Section::MEMBERSHIP_OPEN)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-700/50">
                                        <x-bootstrap-icon name="door-open" class="size-3" /> Öppen
                                    </span>
                                @elseif ($section->membership_type === Section::MEMBERSHIP_APPLICATION)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 border border-zinc-200 dark:border-zinc-600">
                                        <x-bootstrap-icon name="envelope" class="size-3" /> Ansökan
                                    </span>
                                @endif
                                <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $memberCount }} {{ $memberCount === 1 ? 'medlem' : 'medlemmar' }}</span>
                            </div>
                            @if ($section->description)
                                <p class="text-sm text-zinc-600 dark:text-zinc-300 leading-relaxed">{{ $section->description }}</p>
                            @endif
                        </div>
                        <div class="shrink-0 flex flex-col items-end gap-2">
                            @auth
                                @if ($isActive)
                                    <button wire:click="leave({{ $section->id }})"
                                            wire:confirm="Lämna gruppen '{{ $section->name }}'?"
                                            class="text-xs text-zinc-400 hover:text-red-600 dark:hover:text-red-400 transition-colors">
                                        Lämna
                                    </button>
                                @elseif ($isPending)
                                    <button wire:click="withdraw({{ $section->id }})"
                                            wire:confirm="Dra tillbaka din ansökan till '{{ $section->name }}'?"
                                            class="text-xs text-zinc-400 hover:text-red-600 dark:hover:text-red-400 transition-colors">
                                        Dra tillbaka ansökan
                                    </button>
                                @elseif ($canJoin)
                                    @if ($section->membership_type === Section::MEMBERSHIP_OPEN)
                                        <button wire:click="join({{ $section->id }})"
                                                class="rounded-lg bg-brand px-4 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                                            Gå med
                                        </button>
                                    @else
                                        <button wire:click="join({{ $section->id }})"
                                                class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-4 py-1.5 text-sm font-medium text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                                            Ansök om medlemskap
                                        </button>
                                    @endif
                                @endif
                            @endauth
                        </div>
                    </div>

                    {{-- Undergrupper --}}
                    @if ($section->children->isNotEmpty())
                        @php
                            $visibleChildren = $section->children->filter(fn ($c) => $c->canView($member))->values();
                        @endphp
                        @if ($visibleChildren->isNotEmpty())
                            <div class="border-t border-zinc-100 dark:border-zinc-800 divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($visibleChildren as $child)
                                    @php
                                        $cms        = $myMemberships->get($child->id);
                                        $cIsActive  = $cms && $cms->status === 'active';
                                        $cIsPending = $cms && $cms->status === 'pending';
                                        $cCanJoin   = $member && ! $cms && in_array($child->membership_type, [
                                            Section::MEMBERSHIP_OPEN,
                                            Section::MEMBERSHIP_APPLICATION,
                                        ]);
                                        $cCount = $child->activeMembers()->count();
                                    @endphp
                                    <div class="flex items-center justify-between gap-4 px-5 py-3 pl-9">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $child->name }}</span>
                                                @if ($cIsActive)
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 border border-green-200 dark:border-green-700/50">
                                                        <x-bootstrap-icon name="check-circle" class="size-3" /> Medlem
                                                    </span>
                                                @elseif ($cIsPending)
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-700/50">
                                                        <x-bootstrap-icon name="hourglass-split" class="size-3" /> Ansökan skickad
                                                    </span>
                                                @elseif ($child->membership_type === Section::MEMBERSHIP_OPEN)
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-700/50">
                                                        <x-bootstrap-icon name="door-open" class="size-3" /> Öppen
                                                    </span>
                                                @elseif ($child->membership_type === Section::MEMBERSHIP_APPLICATION)
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 border border-zinc-200 dark:border-zinc-600">
                                                        <x-bootstrap-icon name="envelope" class="size-3" /> Ansökan
                                                    </span>
                                                @endif
                                                <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $cCount }} {{ $cCount === 1 ? 'medlem' : 'medlemmar' }}</span>
                                            </div>
                                            @if ($child->description)
                                                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 leading-relaxed">{{ $child->description }}</p>
                                            @endif
                                        </div>
                                        <div class="shrink-0">
                                            @auth
                                                @if ($cIsActive)
                                                    <button wire:click="leave({{ $child->id }})"
                                                            wire:confirm="Lämna gruppen '{{ $child->name }}'?"
                                                            class="text-xs text-zinc-400 hover:text-red-600 dark:hover:text-red-400 transition-colors">
                                                        Lämna
                                                    </button>
                                                @elseif ($cIsPending)
                                                    <button wire:click="withdraw({{ $child->id }})"
                                                            wire:confirm="Dra tillbaka din ansökan till '{{ $child->name }}'?"
                                                            class="text-xs text-zinc-400 hover:text-red-600 dark:hover:text-red-400 transition-colors">
                                                        Dra tillbaka
                                                    </button>
                                                @elseif ($cCanJoin)
                                                    @if ($child->membership_type === Section::MEMBERSHIP_OPEN)
                                                        <button wire:click="join({{ $child->id }})"
                                                                class="rounded-lg bg-brand px-3 py-1 text-xs font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                                                            Gå med
                                                        </button>
                                                    @else
                                                        <button wire:click="join({{ $child->id }})"
                                                                class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-1 text-xs font-medium text-zinc-700 dark:text-zinc-200 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                                                            Ansök
                                                        </button>
                                                    @endif
                                                @endif
                                            @endauth
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @guest
        <p class="text-center text-sm text-zinc-500 dark:text-zinc-400">
            <a href="{{ route('login') }}" class="text-brand-orange hover:underline">Logga in</a> för att gå med i grupper.
        </p>
    @endguest

</div>
