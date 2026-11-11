<div class="grid grid-cols-2 gap-4">
    <div>
        <flux:label for="ptCode">Kod <span class="text-red-500">*</span></flux:label>
        <input id="ptCode" type="text" wire:model="formCode" placeholder="t.ex. 30"
               class="inp mt-1 w-full font-mono" />
        @error('formCode') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <flux:label for="ptDays">Antal dagar <span class="text-red-500">*</span></flux:label>
        <input id="ptDays" type="number" min="0" max="365" wire:model="formDueDays"
               placeholder="30"
               class="inp mt-1 w-full font-mono" />
        @error('formDueDays') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div class="col-span-2">
        <flux:label for="ptName">Namn <span class="text-red-500">*</span></flux:label>
        <input id="ptName" type="text" wire:model="formName" placeholder="t.ex. 30 dagar netto"
               class="inp mt-1 w-full" />
        @error('formName') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>
    <div>
        <flux:label for="ptOrder">Sorteringsordning</flux:label>
        <input id="ptOrder" type="number" min="0" wire:model="formSortOrder"
               placeholder="0"
               class="inp mt-1 w-full" />
    </div>
</div>
