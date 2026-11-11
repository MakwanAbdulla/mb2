<?php

use App\Models\AuditLog;
use App\Models\Content\HelpText;
use App\Services\HelpTextService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component {

    #[Url]
    public string $search = '';

    public ?int $editingId = null;
    public string $customText = '';
    public string $customUrl = '';

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('cms.edit'), 403);
    }

    public function startEditing(int $id): void
    {
        $helpText = HelpText::findOrFail($id);
        $this->editingId = $id;
        $this->customText = $helpText->custom_text ?? '';
        $this->customUrl  = $helpText->custom_url ?? '';
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->customText = '';
        $this->customUrl = '';
    }

    public function save(): void
    {
        $helpText = HelpText::findOrFail($this->editingId);

        $data = ['custom_text' => trim($this->customText) ?: null];
        if ($helpText->url !== null) {
            $data['custom_url'] = trim($this->customUrl) ?: null;
        }

        $helpText->update($data);
        app(HelpTextService::class)->flush();
        AuditLog::record('help_text_updated', 'cms', $helpText->field_label, $helpText);

        session()->flash('success', "Hjälptext \"{$helpText->field_label}\" sparad.");
        $this->cancel();
    }

    public function resetToDefault(int $id): void
    {
        $helpText = HelpText::findOrFail($id);
        $helpText->update(['custom_text' => null, 'custom_url' => null]);
        app(HelpTextService::class)->flush();
        AuditLog::record('help_text_reset', 'cms', $helpText->field_label, $helpText);

        session()->flash('success', "Hjälptext \"{$helpText->field_label}\" återställd.");

        if ($this->editingId === $id) {
            $this->cancel();
        }
    }

    public function with(): array
    {
        $query = HelpText::query()->orderBy('group')->orderBy('field_label');

        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('field_label', 'like', "%{$s}%")
                  ->orWhere('key', 'like', "%{$s}%")
                  ->orWhere('default_text', 'like', "%{$s}%")
                  ->orWhere('custom_text', 'like', "%{$s}%");
            });
        }

        return ['grouped' => $query->get()->groupBy('group')];
    }
}; ?>

<x-slot:header>
    <x-page-header permission="cms.edit" title="Sidtexter" subtitle="Redigera texter som visas i formulär och gränssnitt." />
</x-slot:header>

<div>
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">

        @if(session('success'))
            <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        <input wire:model.live.debounce.300ms="search" type="text" placeholder="Sök sidtexter…"
               class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />

        @forelse($grouped as $group => $texts)
            <div>
                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 capitalize">
                    {{ $group ?: 'Övrigt' }}
                </h2>

                <div class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                    @foreach($texts as $ht)
                        <div class="bg-white p-4 dark:bg-zinc-900">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $ht->field_label }}</span>
                                        @if($ht->hasCustomText() || $ht->hasCustomUrl())
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">Anpassad</span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $ht->key }}</p>

                                    @if($editingId === $ht->id)
                                        {{-- Edit mode --}}
                                        <div class="mt-4 space-y-3">
                                            <div class="rounded-md bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                                                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Standardtext:</p>
                                                <p class="mt-1 text-zinc-700 dark:text-zinc-300">{{ $ht->default_text }}</p>
                                            </div>

                                            <div>
                                                <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Anpassad text</label>
                                                <textarea wire:model="customText" rows="3"
                                                          placeholder="Lämna tomt för att använda standardtexten…"
                                                          class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"></textarea>
                                            </div>

                                            @if($ht->url !== null)
                                                <div class="rounded-md bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                                                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Standard-URL:</p>
                                                    <p class="mt-1 font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $ht->url }}</p>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Anpassad URL</label>
                                                    <input wire:model="customUrl" type="text" placeholder="Lämna tomt för standard-URL…"
                                                           class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
                                                </div>
                                            @endif

                                            <div class="flex gap-2">
                                                <button type="button" wire:click="save"
                                                        class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-brand-dark hover:bg-brand-saffron">
                                                    Spara
                                                </button>
                                                <button type="button" wire:click="cancel"
                                                        class="rounded-lg px-3 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                                    Avbryt
                                                </button>
                                            </div>
                                        </div>
                                    @else
                                        {{-- View mode --}}
                                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                            {{ $ht->displayText() }}
                                        </p>
                                        @if($ht->url !== null)
                                            <p class="mt-1 font-mono text-xs text-zinc-400 dark:text-zinc-500">{{ $ht->displayUrl() }}</p>
                                        @endif
                                    @endif
                                </div>

                                @if($editingId !== $ht->id)
                                    <div class="flex shrink-0 items-center gap-2">
                                        @if($ht->hasCustomText() || $ht->hasCustomUrl())
                                            <button wire:click="resetToDefault({{ $ht->id }})"
                                                    wire:confirm="Återställ till standardtext?"
                                                    class="text-xs text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">
                                                Återställ
                                            </button>
                                        @endif
                                        <button wire:click="startEditing({{ $ht->id }})"
                                                class="flex items-center gap-1 text-xs text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                                            <x-bootstrap-icon name="pencil" class="size-3.5" />
                                            Redigera
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-8 text-center text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                Inga sidtexter hittades.
            </div>
        @endforelse
    </div>
</div>
