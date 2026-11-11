<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 space-y-6">

    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Händelselogg</h1>

    {{-- Filter --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Område</label>
                <select wire:model.live="area"
                        class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-xs focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    <option value="">Alla</option>
                    @foreach ($areaLabels as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Åtgärd</label>
                <select wire:model.live="action"
                        class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-xs focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    <option value="">Alla</option>
                    @foreach ($actionLabels as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Användare</label>
                <select wire:model.live="userId"
                        class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-xs focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                    <option value="">Alla</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Från datum</label>
                <input wire:model.live="dateFrom" type="date"
                       class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-xs focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
            </div>
            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Till datum</label>
                <input wire:model.live="dateTo" type="date"
                       class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-xs focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
            </div>
            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">Rader/sida</label>
                <div class="flex items-end gap-2">
                    <select wire:model.live="perPage"
                            class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-xs focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <button wire:click="resetFilters"
                            class="rounded-lg border border-zinc-300 dark:border-zinc-600 px-2 py-1.5 text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-200 whitespace-nowrap transition-colors">
                        Rensa
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabell --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
        @if ($logs->isEmpty())
            <p class="px-6 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Inga händelser hittades.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300 whitespace-nowrap">Tidpunkt</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Användare</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Åtgärd</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Område</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Beskrivning</th>
                            <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Ändringar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                        @foreach ($logs as $log)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                <td class="px-4 py-3 text-xs text-zinc-400 whitespace-nowrap tabular-nums">
                                    {{ $log->created_at->locale('sv')->isoFormat('D MMM YYYY, H:mm') }}
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300 whitespace-nowrap">
                                    {{ $log->user?->name ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full bg-zinc-100 dark:bg-zinc-700 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:text-zinc-300">
                                        {{ $actionLabels[$log->action] ?? $log->action }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                    {{ $areaLabels[$log->area] ?? $log->area }}
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-700 dark:text-zinc-300 max-w-xs truncate">
                                    {{ $log->subject }}
                                </td>
                                <td class="px-4 py-3">
                                    @if ($log->changes)
                                        <details class="text-xs text-zinc-500 dark:text-zinc-400">
                                            <summary class="cursor-pointer hover:text-zinc-700 dark:hover:text-zinc-200">
                                                {{ count($log->changes) }} ändring(ar)
                                            </summary>
                                            <div class="mt-1 space-y-0.5">
                                                @foreach ($log->changes as $field => $change)
                                                    <p>
                                                        <span class="font-medium text-zinc-600 dark:text-zinc-300">{{ $field }}:</span>
                                                        @if (is_array($change) && array_key_exists('from', $change))
                                                            <span class="line-through">{{ $change['from'] }}</span>
                                                            → {{ $change['to'] }}
                                                        @elseif (is_array($change))
                                                            {{ json_encode($change) }}
                                                        @else
                                                            {{ $change }}
                                                        @endif
                                                    </p>
                                                @endforeach
                                            </div>
                                        </details>
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-100 dark:border-zinc-800 px-4 py-3">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

</div>
