<?php

use App\Models\AuditLog;
use App\Models\Content\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('cms.edit'), 403);
    }

    #[Computed]
    public function pages()
    {
        return Page::query()->orderBy('sort_order')->orderBy('title')->get();
    }

    public function deletePage(Page $page): void
    {
        if ($page->isProtected()) {
            session()->flash('error', "Sidan '{$page->title}' är skyddad och kan inte raderas.");
            return;
        }

        $title = $page->title;
        $page->delete();
        AuditLog::record('page_deleted', 'cms', $title, null);
        unset($this->pages);
        session()->flash('success', "Sidan '{$title}' har raderats.");
    }

    public function togglePublished(Page $page): void
    {
        $page->update(['is_published' => ! $page->is_published]);
        AuditLog::record('page_publish_toggled', 'cms', $page->title, null);
        unset($this->pages);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="cms.edit" title="Sidor" subtitle="Hantera CMS-sidor som visas på webbplatsen.">
        <a href="{{ route('admin.pages.create') }}" wire:navigate
           class="inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
            + Skapa ny sida
        </a>
    </x-page-header>
</x-slot:header>

<div>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

        @if(session('error'))
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                {{ session('error') }}
            </div>
        @endif

        @if(session('success'))
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        @if($this->pages->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-8 text-center text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                Inga sidor finns ännu. Skapa din första sida!
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Titel</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Slug</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Meny</th>
                            <th class="relative px-6 py-3"><span class="sr-only">Åtgärder</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                        @foreach($this->pages as $page)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-zinc-900 dark:text-white">
                                        {{ $page->title }}
                                        @if($page->has_draft)
                                            <span class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                                Utkast
                                            </span>
                                        @endif
                                        @if($page->isProtected())
                                            <span class="ml-1 inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                                Skyddad
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                    /{{ $page->slug }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <button wire:click="togglePublished({{ $page->id }})"
                                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors
                                                {{ $page->is_published ? 'bg-green-100 text-green-800 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                        {{ $page->is_published ? 'Publicerad' : 'Opublicerad' }}
                                    </button>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-zinc-500 dark:text-zinc-400">
                                    @if($page->show_in_nav)
                                        <span class="text-green-600 dark:text-green-400">✓</span>
                                        {{ $page->nav_title ?? $page->title }}
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-4">
                                        <a href="{{ route('page.show', $page->slug) }}" target="_blank"
                                           class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">Visa</a>
                                        <a href="{{ route('admin.pages.edit', $page) }}" wire:navigate
                                           class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Redigera</a>
                                        @if($page->isProtected())
                                            <span class="cursor-not-allowed text-zinc-300 dark:text-zinc-600" title="Skyddad sida">Radera</span>
                                        @else
                                            <button wire:click="deletePage({{ $page->id }})"
                                                    wire:confirm="Är du säker på att du vill radera '{{ $page->title }}'?"
                                                    class="text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300">
                                                Radera
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
