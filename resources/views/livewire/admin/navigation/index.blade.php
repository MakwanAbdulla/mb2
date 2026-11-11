<?php

use App\Models\AuditLog;
use App\Models\Content\NavigationItem;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('cms.edit'), 403);
    }

    public function with(): array
    {
        return [
            'items' => NavigationItem::with('children')
                ->rootItems()
                ->get()
                ->groupBy('location'),
        ];
    }

    public function delete(NavigationItem $item): void
    {
        $item->delete();
        AuditLog::record('nav_item_deleted', 'cms', $item->label, null);
        session()->flash('success', "Menyalternativet '{$item->label}' har tagits bort.");
    }

    public function toggleActive(NavigationItem $item): void
    {
        $item->update(['is_active' => ! $item->is_active]);
        AuditLog::record('nav_item_toggled', 'cms', $item->label, null);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="cms.edit" title="Navigation" subtitle="Hantera menyalternativ för toppmeny och sidfot.">
        <a href="{{ route('admin.navigation.create') }}" wire:navigate
           class="inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
            + Lägg till menyalternativ
        </a>
    </x-page-header>
</x-slot:header>

<div>
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">

        @if(session('success'))
            <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        @foreach($items as $location => $locationItems)
            <div>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                    {{ $location === 'topnav' ? 'Toppmeny' : 'Sidfot' }}
                </h2>

                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Label</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Ikon</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">URL / Route</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Ordning</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                                <th class="relative px-4 py-3"><span class="sr-only">Åtgärder</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                            @foreach($locationItems as $item)
                                {{-- Root item --}}
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                    <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">
                                        {{ $item->label }}
                                        @if($item->requires_auth)
                                            <span class="ml-1 rounded-full bg-blue-100 px-1.5 py-0.5 text-xs text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                                                {{ $item->permission ?? 'Inloggad' }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($item->icon)
                                            <x-bootstrap-icon :name="$item->icon" class="size-5 text-zinc-500" />
                                        @else
                                            <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $item->route_name ?? $item->url }}
                                    </td>
                                    <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ $item->order }}</td>
                                    <td class="px-4 py-3">
                                        <button wire:click="toggleActive({{ $item->id }})"
                                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors
                                                    {{ $item->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                            {{ $item->is_active ? 'Aktiv' : 'Inaktiv' }}
                                        </button>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-3">
                                            <a href="{{ route('admin.navigation.edit', $item) }}" wire:navigate
                                               class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Redigera</a>
                                            <button wire:click="delete({{ $item->id }})"
                                                    wire:confirm="Ta bort '{{ $item->label }}'?"
                                                    class="text-red-600 hover:text-red-800 dark:text-red-400">Ta bort</button>
                                        </div>
                                    </td>
                                </tr>

                                {{-- Children --}}
                                @foreach($item->children as $child)
                                    <tr class="bg-zinc-50/50 hover:bg-zinc-50 dark:bg-zinc-800/30 dark:hover:bg-zinc-800/50">
                                        <td class="px-4 py-2.5 text-zinc-700 dark:text-zinc-300">
                                            <span class="ml-5 text-zinc-400">↳</span>
                                            {{ $child->label }}
                                        </td>
                                        <td class="px-4 py-2.5">
                                            @if($child->icon)
                                                <x-bootstrap-icon :name="$child->icon" class="size-4 text-zinc-400" />
                                            @else
                                                <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 font-mono text-xs text-zinc-400 dark:text-zinc-500">
                                            {{ $child->route_name ?? $child->url }}
                                        </td>
                                        <td class="px-4 py-2.5 text-zinc-400">{{ $child->order }}</td>
                                        <td class="px-4 py-2.5">
                                            <button wire:click="toggleActive({{ $child->id }})"
                                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                                        {{ $child->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                                {{ $child->is_active ? 'Aktiv' : 'Inaktiv' }}
                                            </button>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                            <div class="flex items-center justify-end gap-3">
                                                <a href="{{ route('admin.navigation.edit', $child) }}" wire:navigate
                                                   class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Redigera</a>
                                                <button wire:click="delete({{ $child->id }})"
                                                        wire:confirm="Ta bort '{{ $child->label }}'?"
                                                        class="text-red-600 hover:text-red-800 dark:text-red-400">Ta bort</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        @if($items->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-8 text-center text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                Inga menyalternativ finns ännu.
            </div>
        @endif
    </div>
</div>
