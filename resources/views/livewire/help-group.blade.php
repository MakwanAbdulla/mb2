<?php

use App\Models\Content\HelpSection;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title(fn () => 'Hjälp — ' . ($this->group['title'] ?? 'Okänd grupp'));

new class extends Component {
    public array $group = [];

    public function mount(string $groupSlug): void
    {
        $group = HelpSection::groupBySlug($groupSlug);
        abort_if(! $group, 404);

        // Check permission for admin groups
        if ($group['permission']) {
            $user = Auth::user();
            abort_unless(
                $user?->hasPermissionTo('help.manage') || $user?->hasPermissionTo($group['permission']),
                403
            );
        }

        $this->group = $group;
    }

    public function with(): array
    {
        $user    = Auth::user();
        $isAuth  = Auth::check();
        $canEdit = $user?->hasPermissionTo('help.manage') ?? false;
        $userPerms = $isAuth ? $user->getAllPermissions()->pluck('name')->all() : [];

        $groupLabel = $this->group['label'];

        $sections = HelpSection::where('group_label', $groupLabel)
            ->where(function ($q) use ($isAuth, $userPerms, $canEdit) {
                $q->where('show_public', true);
                if ($isAuth) {
                    $q->orWhere('show_auth', true);
                }
                if ($userPerms) {
                    $q->orWhereIn('required_permission', $userPerms);
                }
                if ($canEdit) {
                    $q->orWhereNotNull('required_permission');
                }
            })
            ->orderBy('sort_order')
            ->get();

        return compact('sections', 'canEdit');
    }
};
?>

<div class="py-8">
<div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">

    {{-- Brödsmula --}}
    <nav class="mb-4 flex items-center gap-1.5 text-xs text-zinc-400 dark:text-zinc-500">
        <a href="{{ route('help') }}" wire:navigate class="hover:text-zinc-600 dark:hover:text-zinc-300">Hjälp</a>
        <x-bootstrap-icon name="chevron-right" class="size-3" />
        <span class="text-zinc-600 dark:text-zinc-300">{{ $group['title'] }}</span>
    </nav>

    {{-- Rubrik --}}
    <div class="mb-6 flex items-center gap-4">
        <div class="flex size-12 shrink-0 items-center justify-center rounded-xl
                    {{ str_starts_with($group['slug'], 'admin-')
                        ? 'bg-violet-100 dark:bg-violet-900/30'
                        : 'bg-brand/20 dark:bg-brand/10' }}">
            <x-bootstrap-icon
                name="{{ $group['icon'] }}"
                class="size-6 {{ str_starts_with($group['slug'], 'admin-') ? 'text-violet-600 dark:text-violet-400' : 'text-brand-dark dark:text-brand' }}"
            />
        </div>
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $group['title'] }}</h1>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $group['description'] }}</p>
        </div>
    </div>

    <div
        x-data="{
            search: '',
            open: {},
            toggle(id) { this.open[id] = !this.open[id]; },
            isOpen(id) { return this.open[id] || (this.search.trim().length >= 2); },
            visible(el) {
                const q = this.search.trim().toLowerCase();
                if (q.length < 2) return true;
                return el.textContent.toLowerCase().includes(q);
            },
            init() {
                const hash = window.location.hash.slice(1);
                if (hash) {
                    this.open[hash] = true;
                    this.$nextTick(() => {
                        const el = document.getElementById(hash);
                        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    });
                }
            }
        }"
    >
        {{-- Sökfält --}}
        <div class="relative mb-4">
            <input
                type="search"
                x-model.debounce.200ms="search"
                placeholder="Sök i {{ $group['title'] }}…"
                class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-2.5 pl-10 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:ring-brand-saffron dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500"
            />
            <x-bootstrap-icon name="search" class="absolute left-3 top-3 h-4 w-4 text-zinc-400 dark:text-zinc-500" />
        </div>

        <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white shadow-sm dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">

            @forelse ($sections as $section)
                <div id="{{ $section->slug }}" class="accordion-item" x-show="visible($el)" x-transition>
                    <button
                        @click="toggle('{{ $section->slug }}')"
                        class="flex w-full items-center justify-between px-5 py-4 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
                    >
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $section->title }}</span>
                        <x-bootstrap-icon
                            name="chevron-down"
                            class="h-4 w-4 shrink-0 text-zinc-400 transition-transform duration-200"
                            x-bind:class="{'rotate-180': isOpen('{{ $section->slug }}')}"
                        />
                    </button>
                    <div
                        x-show="isOpen('{{ $section->slug }}')"
                        x-collapse
                        class="prose prose-sm max-w-none space-y-3 px-5 pb-5 text-sm text-zinc-600 dark:prose-invert dark:text-zinc-300"
                    >
                        {!! $section->content !!}
                    </div>
                </div>
            @empty
                <div class="p-10 text-center text-zinc-500 dark:text-zinc-400">Inga avsnitt i den här gruppen.</div>
            @endforelse

        </div>

        {{-- "Inga träffar" meddelande vid sökning --}}
        <div
            x-show="search.trim().length >= 2"
            x-cloak
            class="mt-2 hidden text-center text-xs text-zinc-400 dark:text-zinc-500"
            :class="{ 'hidden': false }"
        ></div>

    </div>

    <div class="mt-6 flex items-center justify-between text-xs text-zinc-400 dark:text-zinc-500">
        <a href="{{ route('help') }}" wire:navigate class="flex items-center gap-1 hover:text-zinc-600 dark:hover:text-zinc-300">
            <x-bootstrap-icon name="arrow-left" class="size-3.5" />
            Tillbaka till alla ämnen
        </a>
        @if ($canEdit)
            <a href="{{ route('admin.help-sections.index') }}" wire:navigate
               class="transition-colors hover:text-brand dark:hover:text-brand">
                Redigera hjälpsektioner →
            </a>
        @endif
    </div>

</div>
</div>
