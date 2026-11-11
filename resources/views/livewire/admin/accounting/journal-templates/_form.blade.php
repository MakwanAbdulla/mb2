<div class="space-y-4">

    {{-- Name + series --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <flux:label for="formName">Namn <span class="text-red-500">*</span></flux:label>
            <input id="formName" type="text" wire:model="formName"
                   placeholder="t.ex. Hyra inkl. moms, Swish-avgift…"
                   class="inp mt-1 w-full" />
            @error('formName') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <flux:label for="formSeries">Verifikationsserie</flux:label>
            <select id="formSeries" wire:model="formSeries" class="inp mt-1 w-full">
                @foreach ($this->series as $s)
                    <option value="{{ $s->code }}">{{ $s->code }} – {{ $s->name }}</option>
                @endforeach
            </select>
            @error('formSeries') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <flux:label for="formDescription">Beskrivning</flux:label>
        <input id="formDescription" type="text" wire:model="formDescription"
               placeholder="Valfri anteckning om när mallen används"
               class="inp mt-1 w-full" />
    </div>

    {{-- Lines --}}
    <div>
        <div class="flex items-center justify-between mb-2">
            <flux:label>Kontorader <span class="text-red-500">*</span></flux:label>
            <p class="text-xs text-zinc-400">
                Ange <code class="bg-zinc-100 dark:bg-zinc-700 rounded px-1">Total</code> i formelfältet för beloppsberäkning.
            </p>
        </div>

        @error('formLines') <p class="mb-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    <tr>
                        <th class="px-3 py-2 text-left w-8">#</th>
                        <th class="px-3 py-2 text-center w-20">D / K</th>
                        <th class="px-3 py-2 text-left">Konto</th>
                        <th class="px-3 py-2 text-left w-24">Ks</th>
                        <th class="px-3 py-2 text-left w-32">Beskrivning</th>
                        <th class="px-3 py-2 text-left w-36">Formel</th>
                        <th class="px-3 py-2 w-8"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($formLines as $i => $line)
                        <tr class="bg-white dark:bg-zinc-900">
                            <td class="px-3 py-2 text-xs text-zinc-400">{{ $i + 1 }}</td>

                            {{-- Debet / Kredit toggle --}}
                            <td class="px-3 py-2">
                                <div class="flex rounded overflow-hidden border border-zinc-200 dark:border-zinc-700 text-xs font-medium w-fit mx-auto">
                                    <label @class([
                                        'px-2 py-1 cursor-pointer transition-colors',
                                        'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400' => ($formLines[$i]['side'] ?? '') === 'debit',
                                        'text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800' => ($formLines[$i]['side'] ?? '') !== 'debit',
                                    ])>
                                        <input type="radio" wire:model.live="formLines.{{ $i }}.side" value="debit" class="sr-only" />
                                        D
                                    </label>
                                    <label @class([
                                        'px-2 py-1 cursor-pointer transition-colors border-l border-zinc-200 dark:border-zinc-700',
                                        'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-400' => ($formLines[$i]['side'] ?? '') === 'credit',
                                        'text-zinc-400 hover:bg-zinc-50 dark:hover:bg-zinc-800' => ($formLines[$i]['side'] ?? '') !== 'credit',
                                    ])>
                                        <input type="radio" wire:model.live="formLines.{{ $i }}.side" value="credit" class="sr-only" />
                                        K
                                    </label>
                                </div>
                            </td>

                            {{-- Account --}}
                            <td class="px-3 py-2">
                                <x-account-select
                                    :accounts="$this->accounts"
                                    model="formLines.{{ $i }}.account_id"
                                />
                                @error("formLines.{$i}.account_id") <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </td>

                            {{-- Cost center --}}
                            <td class="px-3 py-2">
                                <select wire:model="formLines.{{ $i }}.cost_center_id"
                                    class="w-full text-xs rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand">
                                    <option value="">–</option>
                                    @foreach ($this->costCenters as $cc)
                                        <option value="{{ $cc->id }}">{{ $cc->code }}</option>
                                    @endforeach
                                </select>
                            </td>

                            {{-- Description --}}
                            <td class="px-3 py-2">
                                <input type="text" wire:model="formLines.{{ $i }}.description"
                                    placeholder="Valfri"
                                    class="w-full text-xs rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand" />
                            </td>

                            {{-- Formula --}}
                            <td class="px-3 py-2">
                                <input type="text" wire:model="formLines.{{ $i }}.formula"
                                    placeholder="Total"
                                    class="w-full text-xs font-mono rounded border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 focus:border-brand focus:ring-brand" />
                                @error("formLines.{$i}.formula") <p class="mt-0.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            </td>

                            {{-- Remove --}}
                            <td class="px-3 py-2">
                                @if (count($formLines) > 2)
                                    <button wire:click="removeLine({{ $i }})" type="button"
                                        class="text-zinc-300 hover:text-red-500 dark:text-zinc-600 dark:hover:text-red-400">
                                        <x-bootstrap-icon name="x" class="size-4" />
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="border-t border-zinc-100 dark:border-zinc-800 px-3 py-2 bg-zinc-50 dark:bg-zinc-800/50">
                <button wire:click="addLine" type="button"
                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">
                    + Lägg till rad
                </button>
            </div>
        </div>

        <p class="mt-2 text-xs text-zinc-400">
            Att debitsidorna och kreditsidorna balanserar kontrolleras vid bokningstillfället, inte här.
        </p>
    </div>
</div>
