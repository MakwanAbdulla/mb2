<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Mina ärenden</h1>
        <button wire:click="$set('showForm', true)"
                class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
            + Skicka in ärende
        </button>
    </div>

    {{-- Formulär --}}
    @if ($showForm)
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-4">
        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Nytt ärende</h2>

        {{-- Typ --}}
        <div class="flex flex-wrap gap-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" wire:model.live="newType" value="issue"
                       class="text-brand focus:ring-brand-saffron border-zinc-300 dark:border-zinc-600" />
                <span class="text-sm text-zinc-700 dark:text-zinc-300">Ärende</span>
                <span class="text-xs text-zinc-400">(förslag, önskemål, rapport)</span>
            </label>
            @if ($motionsOpen)
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" wire:model.live="newType" value="motion"
                       class="text-brand focus:ring-brand-saffron border-zinc-300 dark:border-zinc-600" />
                <span class="text-sm text-zinc-700 dark:text-zinc-300">Motion</span>
                <span class="text-xs text-zinc-400">(till medlems-/årsmöte)</span>
            </label>
            @else
            <span class="flex items-center gap-2 opacity-50 cursor-not-allowed">
                <span class="text-sm text-zinc-400">Motion</span>
                <span class="text-xs text-zinc-400">(motionstiden stängt)</span>
            </span>
            @endif
        </div>
        @error('newType') <p class="text-xs text-red-500">{{ $message }}</p> @enderror

        {{-- Riktat till --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Riktat till
            </label>
            <select wire:model="newSectionId"
                    class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron">
                <option value="">Styrelsen</option>
                @foreach ($sections as $section)
                    <option value="{{ $section->id }}">{{ $section->name }}</option>
                @endforeach
            </select>
            @if ($newType === 'motion')
                <p class="mt-1 text-xs text-zinc-400">Motioner behandlas alltid på members-/årsmöte oavsett grupp.</p>
            @endif
        </div>

        {{-- Rubrik --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Rubrik *</label>
            <input wire:model="newTitle" type="text"
                   placeholder="{{ $newType === 'motion' ? 'Att föreningen ska…' : 'Vad gäller ärendet?' }}"
                   class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron" />
            @error('newTitle') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        {{-- Beskrivning --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Beskrivning
                <span class="font-normal text-zinc-400">(valfritt)</span>
            </label>
            <textarea wire:model="newBody" rows="5"
                      placeholder="{{ $newType === 'motion' ? "Bakgrund och motivering…" : "Beskriv ärendet mer detaljerat…" }}"
                      class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white text-sm px-3 py-2 focus:border-brand focus:ring-1 focus:ring-brand-saffron"></textarea>
        </div>

        <div class="flex gap-3">
            <button wire:click="submit"
                    class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors">
                Skicka in
            </button>
            <button wire:click="$set('showForm', false)"
                    class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400">
                Avbryt
            </button>
        </div>
    </div>
    @endif

    {{-- Mina inlämnade --}}
    @if ($mySubmissions->isEmpty() && !$showForm)
        <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-10 text-center space-y-3">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Du har inga inlämnade ärenden ännu.</p>
            <button wire:click="$set('showForm', true)"
                    class="text-sm text-complement dark:text-complement-light hover:underline">
                Skicka in ditt första ärende
            </button>
        </div>
    @elseif ($mySubmissions->isNotEmpty())
        <div class="space-y-3">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Inlämnade ärenden</h2>
            @foreach ($mySubmissions as $sub)
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
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-zinc-900 dark:text-white text-sm">{{ $sub->title }}</span>
                                <span class="rounded-full bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $sub->typeLabel() }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-zinc-400">
                                {{ $sub->section?->name ?? 'Styrelsen' }} · {{ $sub->created_at->isoFormat('D MMM YYYY') }}
                            </p>
                            @if ($sub->agendaItem)
                                <p class="mt-1 text-xs text-complement dark:text-complement-light">
                                    På dagordningen för {{ $sub->agendaItem->meeting->title ?? '' }}
                                </p>
                            @endif
                        </div>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium {{ $statusColor }}">
                            {{ $sub->statusLabel() }}
                        </span>
                    </div>
                    @if ($sub->body)
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400 line-clamp-2">{{ $sub->body }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

</div>
