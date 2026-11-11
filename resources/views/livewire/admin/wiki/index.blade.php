<?php

use App\Enums\WikiReadAccess;
use App\Models\AuditLog;
use App\Models\Wiki\WikiSpace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

// Groups get their space automatically; here admins create association-wide
// spaces, adjust any space and archive the ones no longer in use.
new class extends Component {
    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $description = '';
    public string $readAccess = 'members';

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('wiki.manage'), 403);
    }

    #[Computed]
    public function spaces()
    {
        return WikiSpace::with('group:id,name')
            ->withCount('pages')
            ->orderByRaw('archived_at is not null')
            ->orderByRaw('group_id is not null')
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'description', 'readAccess');
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $space = WikiSpace::findOrFail($id);

        $this->editingId = $space->id;
        $this->name = $space->name;
        $this->description = $space->description ?? '';
        $this->readAccess = $space->read_access->value;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->editingId = null;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('wiki.manage'), 403);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'readAccess' => ['required', Rule::enum(WikiReadAccess::class)],
        ], attributes: ['name' => 'namn', 'description' => 'beskrivning', 'readAccess' => 'läsnivå']);

        $values = [
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
            'read_access' => $data['readAccess'],
        ];

        if ($this->editingId) {
            // The slug stays: links to the space must keep working after a rename.
            $space = tap(WikiSpace::findOrFail($this->editingId))->update($values);
            AuditLog::record('wiki_space_updated', 'wiki', $space->name, $space);
        } else {
            $space = WikiSpace::create([...$values, 'slug' => WikiSpace::uniqueSlug($data['name'])]);
            AuditLog::record('wiki_space_created', 'wiki', $space->name, $space);
        }

        $this->showForm = false;
        $this->editingId = null;
        unset($this->spaces);
    }

    public function toggleArchived(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('wiki.manage'), 403);

        $space = WikiSpace::findOrFail($id);
        $space->update(['archived_at' => $space->isArchived() ? null : now()]);

        AuditLog::record($space->isArchived() ? 'wiki_space_archived' : 'wiki_space_restored', 'wiki', $space->name, $space);
        unset($this->spaces);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="wiki.manage" title="Wiki" subtitle="Utrymmen för grupperna och för hela föreningen">
        <div class="flex flex-wrap gap-2">
            <button wire:click="create"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                <x-bootstrap-icon name="plus-lg" class="size-4" />
                Nytt gemensamt utrymme
            </button>
            <a href="{{ route('wiki.index') }}" wire:navigate
               class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800">
                Till wikin
            </a>
        </div>
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">

    <p class="text-sm text-zinc-500 dark:text-zinc-400">
        Varje grupp får ett utrymme automatiskt. Gruppens aktiva medlemmar skriver i det. Gemensamma utrymmen redigeras av den som har behörigheten att redigera föreningsgemensamma wikiutrymmen.
    </p>

    @if($showForm)
        <form wire:submit="save" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $editingId ? 'Redigera utrymme' : 'Nytt gemensamt utrymme' }}</h2>

            <div>
                <label for="space-name" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Namn</label>
                <input id="space-name" wire:model="name" type="text"
                       class="mt-1 w-full rounded-lg border border-zinc-300 bg-white text-sm text-zinc-900 shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                @error('name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="space-description" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Beskrivning</label>
                <textarea id="space-description" wire:model="description" rows="2"
                          class="mt-1 w-full rounded-lg border border-zinc-300 bg-white text-sm text-zinc-900 shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"></textarea>
                @error('description') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="space-read" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Vem kan läsa?</label>
                <select id="space-read" wire:model="readAccess"
                        class="mt-1 w-full rounded-lg border border-zinc-300 bg-white text-sm text-zinc-900 shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white">
                    @foreach(\App\Enums\WikiReadAccess::cases() as $access)
                        <option value="{{ $access->value }}">{{ $access->label() }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Ett utrymme för en privat eller hemlig grupp syns aldrig utanför gruppen, oavsett vad som väljs här.</p>
                @error('readAccess') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-2">
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">Spara</button>
                <button type="button" wire:click="cancel" class="rounded-lg px-4 py-2 text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">Avbryt</button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto rounded-xl border border-zinc-200 shadow-sm dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Utrymme</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Ägare</th>
                    <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Läsnivå</th>
                    <th class="px-4 py-3 text-right font-semibold text-zinc-600 dark:text-zinc-300">Sidor</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @forelse($this->spaces as $space)
                    <tr wire:key="space-{{ $space->id }}" @class(['hover:bg-zinc-50 dark:hover:bg-zinc-800/40', 'opacity-60' => $space->isArchived()])>
                        <td class="px-4 py-3">
                            <a href="{{ route('wiki.space', $space) }}" wire:navigate class="font-medium text-zinc-900 hover:underline dark:text-white">{{ $space->name }}</a>
                            @if($space->isArchived())
                                <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">Arkiverat {{ $space->archived_at->format('d/m Y') }}</span>
                            @endif
                            <p class="text-xs text-zinc-400 dark:text-zinc-500">/wiki/{{ $space->slug }}</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $space->group?->name ?? 'Hela föreningen' }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $space->read_access->label() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-zinc-500 dark:text-zinc-400">{{ $space->pages_count }}</td>
                        <td class="space-x-3 whitespace-nowrap px-4 py-3 text-right">
                            <button wire:click="edit({{ $space->id }})" class="text-xs text-complement hover:underline dark:text-complement-light">Redigera</button>
                            <button wire:click="toggleArchived({{ $space->id }})"
                                    @unless($space->isArchived()) wire:confirm="Arkivera {{ $space->name }}? Utrymmet försvinner från listorna och går inte att ändra, men sidorna går att läsa." @endunless
                                    class="text-xs text-zinc-500 hover:underline dark:text-zinc-400">
                                {{ $space->isArchived() ? 'Återställ' : 'Arkivera' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Inga utrymmen ännu.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
