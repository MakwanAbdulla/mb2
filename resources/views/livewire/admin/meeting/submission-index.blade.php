<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Ärenden och motioner</h1>
    </div>

    {{-- Filter --}}
    <div class="flex flex-wrap items-center gap-3">
        {{-- Typ --}}
        <div class="flex gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 p-1">
            @foreach (['all' => 'Alla', 'issue' => 'Ärenden', 'motion' => 'Motioner'] as $val => $label)
                <button wire:click="$set('typeFilter', '{{ $val }}')"
                        class="rounded-md px-3 py-1 text-sm font-medium transition-colors
                               {{ $typeFilter === $val
                                   ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm'
                                   : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Status --}}
        <div class="flex gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 p-1">
            @foreach (['open' => 'Öppna', 'on_agenda' => 'På dagordning', 'resolved' => 'Behandlade', 'all' => 'Alla'] as $val => $label)
                <button wire:click="$set('statusFilter', '{{ $val }}')"
                        class="rounded-md px-3 py-1 text-sm font-medium transition-colors
                               {{ $statusFilter === $val
                                   ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm'
                                   : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Grupp --}}
        <select wire:model.live="sectionFilter"
                class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-zinc-700 dark:text-zinc-300 px-3 py-1.5 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
            <option value="all">Alla grupper</option>
            <option value="board">Styrelsen</option>
            @foreach ($sections as $section)
                <option value="{{ $section->id }}">{{ $section->name }}</option>
            @endforeach
        </select>

        <input wire:model.live.debounce.250ms="search" type="text"
               placeholder="Sök…"
               class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-zinc-700 dark:text-zinc-300 px-3 py-1.5 focus:border-brand focus:ring-1 focus:ring-brand-saffron w-48" />
    </div>

    {{-- Lista --}}
    @if ($submissions->isEmpty())
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-10 text-center">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga ärenden att visa.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Ärende</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300 hidden sm:table-cell">Inlämnat av</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300 hidden md:table-cell">Grupp</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Status</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300 hidden lg:table-cell">Datum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 bg-white dark:bg-zinc-900">
                    @foreach ($submissions as $sub)
                        @php
                            $statusColor = match($sub->status) {
                                'submitted'    => 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300',
                                'under_review' => 'bg-amber-100 dark:bg-amber-900 text-amber-700 dark:text-amber-300',
                                'on_agenda'    => 'bg-complement/15 text-complement dark:text-complement-light',
                                'resolved'     => 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300',
                                'rejected'     => 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400',
                                default        => 'bg-zinc-100 text-zinc-500',
                            };
                        @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.submissions.show', $sub) }}" wire:navigate
                                   class="font-medium text-zinc-900 dark:text-white hover:underline">
                                    {{ $sub->title }}
                                </a>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span class="rounded-full bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $sub->typeLabel() }}
                                    </span>
                                    @if ($sub->agendaItem)
                                        <span class="text-xs text-zinc-400">→ {{ $sub->agendaItem->meeting->title ?? '–' }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 text-xs hidden sm:table-cell">
                                {{ $sub->createdBy?->name ?? '–' }}
                            </td>
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400 text-xs hidden md:table-cell">
                                {{ $sub->section?->name ?? 'Styrelsen' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColor }}">
                                    {{ $sub->statusLabel() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-zinc-400 hidden lg:table-cell">
                                {{ $sub->created_at->isoFormat('D MMM YYYY') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div>
