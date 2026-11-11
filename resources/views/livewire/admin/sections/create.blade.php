<?php

use App\Models\AuditLog;
use App\Models\Booking\BookingActivity;
use App\Models\Organization\Section;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Illuminate\Support\Str;

new class extends Component {
    #[Validate('nullable|exists:sections,id')]
    public ?int $parent_id = null;

    #[Validate('required|string|max:120')]
    public string $name = '';

    #[Validate('nullable|string|max:500')]
    public ?string $description = null;

    #[Validate('nullable|exists:booking_activities,id')]
    public ?int $activity_id = null;

    public bool $is_active = true;

    public string $visibility                 = Section::VISIBILITY_PRIVATE;
    public string $membership_type            = Section::MEMBERSHIP_CLOSED;
    public bool   $membership_requires_active = true;

    /** @var array<string> */
    public array $tags = [];

    public string $tagInput = '';

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('sections.manage'), 403);

        $this->parent_id = (int) request('parent') ?: null;
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
        $this->validate();

        if ($this->parent_id) {
            $parent = Section::findOrFail($this->parent_id);
            if ($parent->parent_id !== null && ! \App\Models\SystemSetting::get('sections.groups_can_have_subgroups', true)) {
                $this->addError('parent_id', 'Grupper kan inte ha undergrupper enligt nuvarande inställningar.');
                return;
            }
        }

        $section = Section::create([
            'parent_id'                  => $this->parent_id,
            'activity_id'                => $this->activity_id,
            'name'                       => $this->name,
            'slug'                       => 'tmp',
            'description'                => $this->description ?: null,
            'is_active'                  => $this->is_active,
            'sort_order'                 => Section::whereParentId($this->parent_id)->max('sort_order') + 1,
            'visibility'                 => $this->visibility,
            'membership_type'            => $this->membership_type,
            'membership_requires_active' => $this->membership_requires_active,
        ]);

        $section->update(['slug' => Str::slug($this->name) . '-' . $section->id]);

        $tagIds = collect($this->tags)
            ->map(fn (string $name) => Tag::findOrCreateByName($name)->id)
            ->toArray();

        $section->tags()->sync($tagIds);

        AuditLog::record('section_created', 'sections', $section->name, $section);
        $this->redirect(route('admin.sections.show', $section), navigate: true);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="sections.manage" title="{{ $parent_id ? 'Ny grupp' : 'Ny sektion' }}" subtitle="{{ $parent_id ? 'Skapa en ny grupp under en sektion' : 'Skapa en ny sektion' }}">
        <a href="{{ route('admin.sections.index') }}" wire:navigate
           class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
            ← Tillbaka
        </a>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
    <form wire:submit="save" class="space-y-6">

        {{-- Parent --}}
        @php
            $allSections = Section::orderBy('name')->get();
        @endphp
        <div>
            <label class="lbl" for="parent_id">Övergrupp</label>
            <select id="parent_id" wire:model="parent_id" class="inp mt-1">
                <option value="">— Toppnivå —</option>
                @foreach($allSections as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
            </select>
        </div>

        {{-- Name --}}
        <div>
            <label class="lbl" for="name">Namn <span class="text-red-500">*</span></label>
            <input id="name" type="text" wire:model.live="name" class="inp mt-1" placeholder="t.ex. Brädspelsgruppen" />
            @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        {{-- Description --}}
        <div>
            <label class="lbl" for="description">Beskrivning</label>
            <textarea id="description" wire:model="description" rows="3" class="inp mt-1"></textarea>
        </div>

        {{-- Activity category --}}
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
                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Kopplar gruppen till en aktivitetstyp från bokningssystemet.</p>
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

        <div class="flex gap-3 pt-2">
            <button type="submit" class="btn btn-primary">{{ $parent_id ? 'Spara grupp' : 'Spara sektion' }}</button>
            <a href="{{ route('admin.sections.index') }}" wire:navigate class="btn btn-ghost">Avbryt</a>
        </div>

    </form>
</div>
