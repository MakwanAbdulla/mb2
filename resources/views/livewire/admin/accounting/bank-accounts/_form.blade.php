<div class="space-y-5">

    {{-- Namn + Redovisningskonto --}}
    <div class="grid grid-cols-2 gap-4">
        <div>
            <flux:label for="baName">Namn</flux:label>
            <flux:input id="baName" wire:model="formName" placeholder="t.ex. Swish föreningskonto" />
            @error('formName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <flux:label for="baAccountId">Redovisningskonto</flux:label>
            <x-account-select
                :accounts="$this->ledgerAccounts"
                model="formAccountId"
            />
            @error('formAccountId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Bank + Valuta --}}
    <div class="grid grid-cols-2 gap-4">
        <div>
            <flux:label for="baBankName">Bank</flux:label>
            <flux:input id="baBankName" wire:model="formBankName" placeholder="t.ex. Swedbank" />
        </div>
        <div>
            <flux:label for="baCurrency">Valuta</flux:label>
            <flux:input id="baCurrency" wire:model="formCurrency" placeholder="SEK" maxlength="3" class="font-mono uppercase w-24" />
            @error('formCurrency') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Kontonummer --}}
    <div class="grid grid-cols-2 gap-4">
        <div>
            <flux:label for="baClearingNumber">Clearingnummer</flux:label>
            <flux:input id="baClearingNumber" wire:model="formClearingNumber" placeholder="t.ex. 8327" class="font-mono" />
            @error('formClearingNumber') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <flux:label for="baAccountNumber">Kontonummer</flux:label>
            <flux:input id="baAccountNumber" wire:model="formAccountNumber" placeholder="t.ex. 0123456789" class="font-mono" />
            @error('formAccountNumber') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- IBAN --}}
    <div>
        <flux:label for="baIban">IBAN (valfri)</flux:label>
        <flux:input id="baIban" wire:model="formIban" placeholder="SE00 0000 0000 0000 0000 0000" class="font-mono" />
        @error('formIban') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="border-t border-zinc-100 dark:border-zinc-700 pt-4">
        <p class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-3">Betalningskanaler</p>

        {{-- Bankgiro + Plusgiro --}}
        <div class="grid grid-cols-2 gap-4">
            <div>
                <flux:label for="baBankgiro">Bankgiro</flux:label>
                <flux:input id="baBankgiro" wire:model="formBankgiro" placeholder="t.ex. 123-4567" class="font-mono" />
                @error('formBankgiro') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:label for="baPlusgiro">Plusgiro (valfri)</flux:label>
                <flux:input id="baPlusgiro" wire:model="formPlusgiro" placeholder="t.ex. 12 34 56-7" class="font-mono" />
                @error('formPlusgiro') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Swish --}}
        <div class="grid grid-cols-2 gap-4 mt-4">
            <div>
                <flux:label for="baSwishNumber">Swishnummer</flux:label>
                <flux:input id="baSwishNumber" wire:model="formSwishNumber" placeholder="t.ex. 1234567890" class="font-mono" />
                @error('formSwishNumber') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:label for="baSwishType">Swish-typ</flux:label>
                <flux:select id="baSwishType" wire:model="formSwishType">
                    <option value="">Ingen Swish</option>
                    <option value="handel">Swish Handel</option>
                    <option value="private">Swish Privat</option>
                </flux:select>
                @error('formSwishType') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- Kostnadsställe --}}
    <div>
        <flux:label for="baCostCenter">Kostnadsställe (valfri)</flux:label>
        <flux:select id="baCostCenter" wire:model="formCostCenterId">
            <option value="">– Inget –</option>
            @foreach ($this->costCenters as $cc)
                <option value="{{ $cc->id }}">{{ $cc->code }} {{ $cc->name }}</option>
            @endforeach
        </flux:select>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            Används för automatisk fördelning av bankavgifter.
        </p>
    </div>

    {{-- Anteckningar --}}
    <div>
        <flux:label for="baNotes">Anteckningar (valfri)</flux:label>
        <flux:textarea id="baNotes" wire:model="formNotes" rows="2" />
    </div>

</div>
