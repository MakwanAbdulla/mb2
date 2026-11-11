@php
    $label = $depth === 0 ? 'Sektion' : 'Grupp';
    $canAddChild = $depth === 0 || $groupsCanHaveSubgroups;
@endphp

<div class="group" style="margin-left: {{ $depth * 1.5 }}rem">
    <div class="flex items-center gap-3 rounded-lg px-3 py-2.5 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">

        {{-- Indent indicator --}}
        @if($depth > 0)
            <x-bootstrap-icon name="arrow-return-right" class="size-3.5 shrink-0 text-zinc-300 dark:text-zinc-600" />
        @else
            <x-bootstrap-icon name="diagram-3" class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
        @endif

        {{-- Name + badges --}}
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.sections.show', $section) }}" wire:navigate
                   class="text-sm font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                    {{ $section->name }}
                </a>
                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500">{{ $label }}</span>
                @if(! $section->is_active)
                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">Inaktiv</span>
                @endif
                @if($section->activity)
                    <span class="rounded-full bg-complement/10 px-2 py-0.5 text-xs text-complement dark:bg-complement/20 dark:text-complement-light">
                        {{ $section->activity->name }}
                    </span>
                @endif
                @foreach($section->tags as $tag)
                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        {{ $tag->name }}
                    </span>
                @endforeach
            </div>
            @if($section->description)
                <p class="mt-0.5 truncate text-xs text-zinc-400 dark:text-zinc-500">{{ $section->description }}</p>
            @endif
        </div>

        {{-- Member count --}}
        @php $memberCount = $section->activeMembers()->count(); @endphp
        <span class="shrink-0 text-xs text-zinc-400 dark:text-zinc-500">
            {{ $memberCount }} {{ $memberCount === 1 ? 'medlem' : 'medlemmar' }}
        </span>

        {{-- Actions --}}
        <div class="flex shrink-0 items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
            @if($canAddChild)
                <a href="{{ route('admin.sections.create', ['parent' => $section->id]) }}" wire:navigate
                   title="Lägg till {{ $depth === 0 ? 'grupp' : 'undergrupp' }}"
                   class="rounded p-1 text-zinc-400 hover:bg-zinc-200 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-200">
                    <x-bootstrap-icon name="plus-lg" class="size-3.5" />
                </a>
            @endif
            <a href="{{ route('admin.sections.edit', $section) }}" wire:navigate
               title="Redigera"
               class="rounded p-1 text-zinc-400 hover:bg-zinc-200 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-200">
                <x-bootstrap-icon name="pencil" class="size-3.5" />
            </a>
            <button wire:click="deleteSection({{ $section->id }})"
                    wire:confirm="Ta bort {{ strtolower($label) }} '{{ $section->name }}'?"
                    title="Ta bort"
                    class="rounded p-1 text-zinc-400 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400">
                <x-bootstrap-icon name="trash" class="size-3.5" />
            </button>
        </div>
    </div>
</div>

@foreach($section->children as $child)
    @include('livewire.admin.sections._row', ['section' => $child, 'depth' => $depth + 1, 'groupsCanHaveSubgroups' => $groupsCanHaveSubgroups])
@endforeach
