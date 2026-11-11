<?php

use App\Models\AuditLog;
use App\Models\Booking\BookingActivity;
use App\Models\Organization\Section;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new class extends Component {
    public Section $section;

    #[Validate('nullable|exists:sections,id')]
    public ?int $parent_id = null;

    #[Validate('required|string|max:120')]
    public string $name = '';

    #[Validate('nullable|string|max:500')]
    public ?string $description = null;

    #[Validate('nullable|exists:booking_activities,id')]
    public ?int $activity_id = null;

    public bool $is_active      = true;
    public bool $accepts_issues = false;
    public bool $can_book_rooms  = false;
    public ?int $bookingMaxOverride = null;

    public string $visibility                 = Section::VISIBILITY_PRIVATE;
    public string $membership_type            = Section::MEMBERSHIP_CLOSED;
    public bool   $membership_requires_active = true;

    /** @var array<string> */
    public array $tags = [];

    public string $tagInput = '';

    public function mount(Section $section): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('sections.manage'), 403);

        $this->section     = $section;
        $this->parent_id   = $section->parent_id;
        $this->name        = $section->name;
        $this->description = $section->description;
        $this->activity_id = $section->activity_id;
        $this->is_active                  = $section->is_active;
        $this->accepts_issues             = $section->accepts_issues;
        $this->can_book_rooms             = $section->can_book_rooms;
        $this->bookingMaxOverride         = $section->booking_max_override;
        $this->visibility                 = $section->visibility ?? Section::VISIBILITY_PRIVATE;
        $this->membership_type            = $section->membership_type ?? Section::MEMBERSHIP_CLOSED;
        $this->membership_requires_active = $section->membership_requires_active ?? true;
        $this->tags                       = $section->tags->pluck('name')->toArray();
    }

    public function addTag(): void
    {
        $name = mb_strtolower(trim($this->tagInput));
        if ($name && ! in_array($name, $this->tags, true)) {
            $this->tags[] = $name;
        }
        $this->tagInput = '';
    }

    public function removeTag(string $tag): void
    {
        $this->tags = array_values(array_filter($this->tags, fn ($t) => $t !== $tag));
    }

    public function save(): void
    {
        $this->validate([
            'parent_id'   => 'nullable|exists:sections,id',
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'activity_id' => 'nullable|exists:booking_activities,id',
        ]);

        // Prevent setting self or own descendant as parent
        if ($this->parent_id === $this->section->id) {
            $this->addError('parent_id', 'En grupp kan inte vara sin egen övergrupp.');
            return;
        }

        $this->section->update([
            'parent_id'                  => $this->parent_id,
            'activity_id'                => $this->activity_id,
            'name'                       => $this->name,
            'description'                => $this->description ?: null,
            'is_active'                  => $this->is_active,
            'accepts_issues'             => $this->accepts_issues,
            'can_book_rooms'             => $this->can_book_rooms,
            'booking_max_override'       => $this->bookingMaxOverride ?: null,
            'visibility'                 => $this->visibility,
            'membership_type'            => $this->membership_type,
            'membership_requires_active' => $this->membership_requires_active,
        ]);

        AuditLog::record('section_updated', 'sections', $this->section->name, $this->section);

        $tagIds = collect($this->tags)
            ->map(fn (string $name) => Tag::findOrCreateByName($name)->id)
            ->toArray();

        $this->section->tags()->sync($tagIds);

        $this->redirect(route('admin.sections.show', $this->section), navigate: true);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="sections.manage" :title="'Redigera: ' . $section->name" subtitle="Grupp">
        <div class="flex gap-2">
            <a href="{{ route('admin.sections.show', $section) }}" wire:navigate
               class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                ← Tillbaka
            </a>
        </div>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
    <form wire:submit="save" class="space-y-6">

        {{-- Parent --}}
        @php
            $allSections = Section::where('id', '!=', $section->id)->orderBy('name')->get();
        @endphp
        <div>
            <label class="lbl" for="parent_id">Övergrupp</label>
            <select id="parent_id" wire:model="parent_id" class="inp mt-1">
                <option value="">— Toppnivå —</option>
                @foreach($allSections as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
            </select>
            @error('parent_id') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        {{-- Name --}}
        <div>
            <label class="lbl" for="name">Namn <span class="text-red-500">*</span></label>
            <input id="name" type="text" wire:model="name" class="inp mt-1" />
            @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        {{-- Description --}}
        <div>
            <label class="lbl" for="description">Beskrivning</label>
            <textarea id="description" wire:model="description" rows="3" class="inp mt-1"></textarea>
        </div>

        {{-- Activity --}}
        @php $activities = BookingActivity::where('active', true)->orderBy('name')->get(); @endphp
        @if($activities->isNotEmpty())
            <div>
                <label class="lbl" for="activity_id">Aktivitetskategori</label>
                <select id="activity_id" wire:model="activity_id" class="inp mt-1">
                    <option value="">— Ingen —</option>
                    @foreach($activities as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        {{-- Tags --}}
        <div>
            <label class="lbl">Taggar</label>
            <div class="mt-1 flex gap-2">
                <input type="text" wire:model="tagInput" wire:keydown.enter.prevent="addTag"
                       class="inp flex-1" placeholder="Skriv tagg och tryck Enter" />
                <button type="button" wire:click="addTag"
                        class="rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-800">
                    Lägg till
                </button>
            </div>
            @if($tags)
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach($tags as $tag)
                        <span class="flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            {{ $tag }}
                            <button type="button" wire:click="removeTag('{{ $tag }}')" class="ml-0.5 text-zinc-400 hover:text-red-500">×</button>
                        </span>
                    @endforeach
                </div>
            @endif
            <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Befintliga taggar: {{ implode(', ', Tag::orderBy('name')->pluck('name')->toArray()) ?: '—' }}</p>
        </div>

        {{-- Visibility & membership type --}}
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="lbl" for="visibility">Synlighet</label>
                <select id="visibility" wire:model="visibility" class="inp mt-1">
                    <option value="{{ Section::VISIBILITY_PUBLIC }}">Publik — alla med konto kan se</option>
                    <option value="{{ Section::VISIBILITY_MEMBERS }}">Medlemmar — kräver aktivt medlemskap</option>
                    <option value="{{ Section::VISIBILITY_PRIVATE }}">Privat — visas men ej insyn</option>
                    <option value="{{ Section::VISIBILITY_SECRET }}">Hemlig — osynlig för utomstående</option>
                </select>
            </div>
            <div>
                <label class="lbl" for="membership_type">Medlemskapstyp</label>
                <select id="membership_type" wire:model="membership_type" class="inp mt-1">
                    <option value="{{ Section::MEMBERSHIP_MANDATORY }}">Obligatorisk — alla aktiva auto-tillagda</option>
                    <option value="{{ Section::MEMBERSHIP_OPEN }}">Öppen — alla kan gå med direkt</option>
                    <option value="{{ Section::MEMBERSHIP_APPLICATION }}">Ansökan — kräver godkännande</option>
                    <option value="{{ Section::MEMBERSHIP_CLOSED }}">Sluten — admin lägger till</option>
                    <option value="{{ Section::MEMBERSHIP_ADMIN_ONLY }}">Endast admin — bara site-admin</option>
                </select>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <input id="membership_requires_active" type="checkbox" wire:model="membership_requires_active" class="rounded border-zinc-300 text-complement" />
            <label for="membership_requires_active" class="text-sm text-zinc-700 dark:text-zinc-300">
                Kräver aktivt föreningsmedlemskap för åtkomst
                <span class="ml-1 text-xs text-zinc-400">(avaktivera för t.ex. alumnigrupper)</span>
            </label>
        </div>

        {{-- Active --}}
        <div class="flex items-center gap-3">
            <input id="is_active" type="checkbox" wire:model="is_active" class="rounded border-zinc-300 text-complement" />
            <label for="is_active" class="text-sm text-zinc-700 dark:text-zinc-300">Aktiv</label>
        </div>

        {{-- Accepts issues --}}
        <div class="flex items-center gap-3">
            <input id="accepts_issues" type="checkbox" wire:model="accepts_issues" class="rounded border-zinc-300 text-complement" />
            <label for="accepts_issues" class="text-sm text-zinc-700 dark:text-zinc-300">
                Ta emot ärenden från medlemmar
            </label>
        </div>

        {{-- Room booking --}}
        <div class="flex items-center gap-3">
            <input id="can_book_rooms" type="checkbox" wire:model.live="can_book_rooms" class="rounded border-zinc-300 text-complement" />
            <label for="can_book_rooms" class="text-sm text-zinc-700 dark:text-zinc-300">
                Kan boka rum
                <span class="ml-1 text-xs text-zinc-400">(sektionen/gruppen kan göra rumsbokningar)</span>
            </label>
        </div>

        @if($can_book_rooms)
        <div>
            <label class="lbl" for="bookingMaxOverride">Max aktiva bokningar</label>
            <input id="bookingMaxOverride" type="number" min="1" wire:model="bookingMaxOverride"
                   class="inp mt-1 w-32" placeholder="Systemstandard" />
            <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Lämna tomt för att använda systemstandarden ({{ $section->parent_id ? 'grupp' : 'sektion' }}).</p>
        </div>
        @endif

        <div class="flex gap-3 pt-2">
            <button type="submit" class="btn btn-primary">Spara</button>
            <a href="{{ route('admin.sections.show', $section) }}" wire:navigate class="btn btn-ghost">Avbryt</a>
        </div>

    </form>
</div>
