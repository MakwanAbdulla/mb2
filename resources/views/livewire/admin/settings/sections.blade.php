<?php

use App\Models\AuditLog;
use App\Models\Reaction;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    public bool $saved    = false;
    public int  $maxDepth = 1;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);
        $this->maxDepth = (int) SystemSetting::get('sections.max_depth', 1);
    }

    public function saveMaxDepth(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);
        $this->validate(['maxDepth' => 'required|integer|min:0|max:10']);
        SystemSetting::set('sections.max_depth', $this->maxDepth, SystemSetting::TYPE_INTEGER);
        $this->saved = true;
        AuditLog::record('settings_saved', 'settings', 'Max gruppdjup → ' . $this->maxDepth);
    }

    #[Computed]
    public function activeEmojis(): array
    {
        return Reaction::allowed();
    }

    #[Computed]
    public function usageCounts(): array
    {
        return Reaction::usageCounts();
    }

    public function addEmoji(string $emoji): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        if (! in_array($emoji, Reaction::allCurated(), true)) {
            return;
        }

        $current = Reaction::allowed();
        if (in_array($emoji, $current, true)) {
            return;
        }

        $current[] = $emoji;
        SystemSetting::set('sections.reaction_emojis', $current, SystemSetting::TYPE_JSON);
        $this->saved = true;
        unset($this->activeEmojis, $this->usageCounts);
        AuditLog::record('settings_saved', 'settings', 'Reaktions-emoji tillagd: ' . $emoji);
    }

    public function removeEmoji(string $emoji): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $current = Reaction::allowed();
        $updated = array_values(array_filter($current, fn ($e) => $e !== $emoji));
        SystemSetting::set('sections.reaction_emojis', $updated, SystemSetting::TYPE_JSON);
        $this->saved = true;
        unset($this->activeEmojis, $this->usageCounts);
        AuditLog::record('settings_saved', 'settings', 'Reaktions-emoji borttagen: ' . $emoji);
    }
}; ?>

<div class="space-y-8">

    @if($saved)
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
            Inställningar sparade.
        </div>
    @endif

    {{-- Max gruppdjup --}}
    <div>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-2">
            Max gruppdjup
        </h2>
        <p class="text-xs text-zinc-400 dark:text-zinc-500 mb-3">
            Hur många nivåer grupper får nästlas. <strong>0</strong> = inga undergrupper, <strong>1</strong> = en nivå undergrupper, osv.
        </p>
        <div class="flex items-center gap-3">
            <input wire:model="maxDepth" type="number" min="0" max="10"
                   class="w-24 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
            <button wire:click="saveMaxDepth" class="btn btn-primary btn-sm">Spara</button>
        </div>
        @error('maxDepth') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
    </div>

    <hr class="border-zinc-200 dark:border-zinc-700" />

    {{-- Aktiva reaktions-emojis --}}
    <div>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-4">
            Aktiva reaktions-emojis
        </h2>

        @if(empty($this->activeEmojis))
            <p class="text-sm text-zinc-400 dark:text-zinc-500">Inga aktiva emojis.</p>
        @else
            <div class="flex flex-wrap gap-2">
                @foreach($this->activeEmojis as $emoji)
                    @php $count = $this->usageCounts[$emoji] ?? 0; @endphp
                    <div class="group relative flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-900">
                        <span class="text-xl leading-none">{{ $emoji }}</span>
                        @if($count > 0)
                            <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $count }}</span>
                        @endif
                        <button
                            @if($count > 0)
                                wire:click="removeEmoji('{{ $emoji }}')"
                                wire:confirm="{{ $count }} reaktion{{ $count === 1 ? '' : 'er' }} använder {{ $emoji }} — ta ändå bort från pickern? Befintliga reaktioner bevaras."
                            @else
                                wire:click="removeEmoji('{{ $emoji }}')"
                            @endif
                            class="ml-1 rounded-full p-0.5 text-zinc-300 opacity-0 transition-opacity group-hover:opacity-100 hover:text-red-500 dark:text-zinc-600 dark:hover:text-red-400">
                            <x-bootstrap-icon name="x-lg" class="size-3" />
                        </button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Kuraterat urval att lägga till --}}
    <div>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-4">
            Lägg till emoji
        </h2>
        <div class="space-y-5">
            @foreach(Reaction::CURATED as $category => $emojis)
                <div>
                    <p class="mb-2 text-xs font-medium text-zinc-400 dark:text-zinc-500">{{ $category }}</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($emojis as $emoji)
                            @php $active = in_array($emoji, $this->activeEmojis, true); @endphp
                            <button
                                wire:click="{{ $active ? '' : 'addEmoji(\'' . $emoji . '\')' }}"
                                @disabled($active)
                                title="{{ $active ? 'Redan aktiv' : 'Lägg till' }}"
                                class="relative rounded-lg p-1.5 text-2xl leading-none transition-all
                                       {{ $active
                                           ? 'opacity-30 cursor-default'
                                           : 'hover:bg-zinc-100 dark:hover:bg-zinc-800 hover:scale-110' }}">
                                {{ $emoji }}
                                @if($active)
                                    <span class="absolute -right-0.5 -top-0.5 flex size-3.5 items-center justify-center rounded-full bg-green-500">
                                        <x-bootstrap-icon name="check" class="size-2.5 text-white" />
                                    </span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

</div>
