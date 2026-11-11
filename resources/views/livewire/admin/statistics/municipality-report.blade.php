<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-6">

    {{-- Header --}}
    <div class="flex items-center gap-4 flex-wrap">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Kommunrapport</h1>
        <div class="flex gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 p-1">
            @foreach ($years as $y)
                <button wire:click="$set('year', {{ $y }})"
                        class="px-3 py-1 rounded-md text-sm font-medium transition-colors tabular-nums
                               {{ $year === $y
                                   ? 'bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white shadow-sm'
                                   : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200' }}">
                    {{ $y }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Report card --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm">

        <div class="bg-zinc-50 dark:bg-zinc-800 px-6 py-4 border-b border-zinc-200 dark:border-zinc-700">
            <h2 class="text-base font-semibold text-zinc-800 dark:text-zinc-100">
                Medlemmar boende i {{ $municipalityName }} per 31/12
            </h2>
        </div>

        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-100 dark:border-zinc-800">
                    <th class="px-6 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Grupp</th>
                    <th class="px-6 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400 tabular-nums">{{ $year }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($rows as $row)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-6 py-2.5 text-zinc-700 dark:text-zinc-300">
                            <span class="mr-1 text-zinc-400 dark:text-zinc-500 tabular-nums">{{ $row['num'] }}</span>
                            {{ $row['label'] }}
                        </td>
                        <td class="px-6 py-2.5 text-right tabular-nums {{ $row['count'] > 0 ? 'text-zinc-900 dark:text-white font-medium' : 'text-zinc-400 dark:text-zinc-600' }}">
                            {{ $row['count'] > 0 ? $row['count'] : '–' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t-2 border-zinc-200 dark:border-zinc-700">
                <tr>
                    <td class="px-6 py-3 font-semibold text-zinc-800 dark:text-zinc-200">Total:</td>
                    <td class="px-6 py-3 text-right font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $total }}</td>
                </tr>
                <tr>
                    <td class="px-6 pb-4 text-zinc-700 dark:text-zinc-300">
                        Bidragsberättigade:
                        <span class="ml-1 text-xs text-zinc-400 dark:text-zinc-500">({{ $grantAgeMin }}–{{ $grantAgeMax }} år)</span>
                    </td>
                    <td class="px-6 pb-4 text-right font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $grantEligible }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Year-specific override panel --}}
    @can('settings.manage')
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900">
        <div class="flex items-center justify-between px-5 py-3">
            <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                <x-bootstrap-icon name="sliders" class="size-4 text-zinc-400" />
                @if($hasYearOverride)
                    <span>Årsspecifika inställningar för <strong class="font-medium text-zinc-800 dark:text-zinc-200">{{ $year }}</strong> är aktiva.</span>
                @else
                    <span>Globala standardvärden används för {{ $year }}.</span>
                @endif
            </div>
            <div class="flex items-center gap-3">
                @if($hasYearOverride && !$editingOverride)
                    <button wire:click="clearOverride"
                            wire:confirm="Ta bort årsspecifika inställningar för {{ $year }}? Globala standardvärden används istället."
                            class="text-xs text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 underline underline-offset-2">
                        Återställ till standard
                    </button>
                @endif
                <button wire:click="{{ $editingOverride ? '$set(\'editingOverride\', false)' : 'openOverride' }}"
                        class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 underline underline-offset-2">
                    {{ $editingOverride ? 'Avbryt' : 'Anpassa för ' . $year }}
                </button>
            </div>
        </div>

        @if($editingOverride)
        <div class="border-t border-zinc-100 dark:border-zinc-800 px-5 py-4 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="lbl">Kommunnamn</label>
                    <input wire:model="overrideName" type="text" maxlength="100"
                           class="inp mt-1 w-64 @error('overrideName') border-red-300 @enderror" />
                    @error('overrideName') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Postnummer från</label>
                    <input wire:model="overridePostalMin" type="number" min="10000" max="99999"
                           class="inp mt-1 w-32 @error('overridePostalMin') border-red-300 @enderror" />
                    @error('overridePostalMin') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Postnummer till</label>
                    <input wire:model="overridePostalMax" type="number" min="10000" max="99999"
                           class="inp mt-1 w-32 @error('overridePostalMax') border-red-300 @enderror" />
                    @error('overridePostalMax') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Bidragsberättigad ålder från</label>
                    <input wire:model="overrideGrantMin" type="number" min="0" max="99"
                           class="inp mt-1 w-20 @error('overrideGrantMin') border-red-300 @enderror" />
                    @error('overrideGrantMin') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lbl">Bidragsberättigad ålder till</label>
                    <input wire:model="overrideGrantMax" type="number" min="0" max="99"
                           class="inp mt-1 w-20 @error('overrideGrantMax') border-red-300 @enderror" />
                    @error('overrideGrantMax') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end">
                <button wire:click="saveOverride"
                        class="btn btn-primary">
                    Spara för {{ $year }}
                </button>
            </div>
        </div>
        @endif
    </div>
    @endcan

    {{-- Notice if data is incomplete --}}
    <p class="text-xs text-zinc-400 dark:text-zinc-500">
        Endast medlemmar med angivet kön, födelsedag och postnummer ingår i rapporten.
        Kön registreras på varje members profilsida.
    </p>

</div>
