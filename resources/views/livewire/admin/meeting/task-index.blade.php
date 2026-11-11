<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Uppgifter</h1>
        @can('tasks.manage')
        <button wire:click="$set('showNewForm', true)"
                class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
            + Ny uppgift
        </button>
        @endcan
    </div>

    {{-- Ny uppgift --}}
    @if ($showNewForm)
    @can('tasks.manage')
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4 space-y-3">
        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Ny uppgift</p>
        <div class="grid grid-cols-3 gap-3">
            <div class="col-span-3 sm:col-span-1">
                <input wire:model="newTitle"
                       wire:keydown.enter="createTask"
                       type="text"
                       placeholder="Titel *"
                       class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
                @error('newTitle') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <select wire:model="newSectionId"
                        class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    <option value="">Styrelse / föreningen</option>
                    @foreach ($sections as $section)
                        <option value="{{ $section->id }}">{{ $section->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <input wire:model="newDueDate" type="date"
                       class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
            </div>
        </div>
        <div class="flex gap-3">
            <button wire:click="createTask"
                    class="rounded-lg bg-brand px-4 py-1.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                Skapa
            </button>
            <button wire:click="$set('showNewForm', false)"
                    class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">
                Avbryt
            </button>
        </div>
    </div>
    @endcan
    @endif

    {{-- Filter --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 p-1">
            @foreach (['open' => 'Öppna', 'in_progress' => 'Pågår', 'done' => 'Klara', 'all' => 'Alla'] as $val => $label)
                <button wire:click="$set('statusFilter', '{{ $val }}')"
                        class="rounded-md px-3 py-1 text-sm font-medium transition-colors
                               {{ $statusFilter === $val
                                   ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm'
                                   : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <select wire:model.live="sectionFilter"
                class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-zinc-700 dark:text-zinc-300 px-3 py-1.5 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
            <option value="">Alla grupper</option>
            <option value="__none__">Styrelse / föreningen</option>
            @foreach ($sections as $section)
                <option value="{{ $section->id }}">{{ $section->name }}</option>
            @endforeach
        </select>

        <input wire:model.live.debounce.250ms="search" type="text"
               placeholder="Sök…"
               class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-zinc-700 dark:text-zinc-300 px-3 py-1.5 focus:border-brand focus:ring-1 focus:ring-brand-saffron w-48" />
    </div>

    {{-- Lista --}}
    @if ($tasks->isEmpty())
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-10 text-center">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga uppgifter att visa.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Uppgift</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300 hidden sm:table-cell">Grupp</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300 hidden md:table-cell">Tilldelade</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300 hidden lg:table-cell">Förfaller</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                    @foreach ($tasks as $task)
                        @php
                            $statusColor = match($task->status) {
                                'open'        => 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300',
                                'in_progress' => 'bg-amber-100 dark:bg-amber-900 text-amber-700 dark:text-amber-300',
                                'done'        => 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300',
                                'cancelled'   => 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400',
                                default       => 'bg-zinc-100 text-zinc-500',
                            };
                        @endphp
                        <tr wire:click="$dispatch('navigate', { url: '{{ route('admin.tasks.show', $task) }}' })"
                            onclick="window.livewire ? null : window.location='{{ route('admin.tasks.show', $task) }}'"
                            class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40 cursor-pointer">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.tasks.show', $task) }}" wire:navigate
                                   class="font-medium text-zinc-900 dark:text-white hover:underline">
                                    {{ $task->title }}
                                </a>
                                @if ($task->sourceable)
                                    <div class="text-xs text-zinc-400">{{ $task->sourceable->title ?? class_basename($task->sourceable_type) }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 text-xs hidden sm:table-cell">
                                {{ $task->section?->name ?? 'Styrelse' }}
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell">
                                @if ($task->assignees->isNotEmpty())
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($task->assignees->take(3) as $member)
                                            <span class="rounded-full bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 text-xs text-zinc-600 dark:text-zinc-300">
                                                {{ $member->first_name }}
                                            </span>
                                        @endforeach
                                        @if ($task->assignees->count() > 3)
                                            <span class="text-xs text-zinc-400">+{{ $task->assignees->count() - 3 }}</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-xs text-zinc-400">–</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs hidden lg:table-cell
                                       {{ $task->isOverdue() ? 'text-red-600 dark:text-red-400 font-medium' : 'text-zinc-500 dark:text-zinc-400' }}">
                                {{ $task->due_date?->isoFormat('D MMM YYYY') ?? '–' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColor }}">
                                    {{ $task->statusLabel() }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div>
