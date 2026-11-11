<div class="space-y-4">

    <div class="grid grid-cols-3 gap-4">
        <div>
            <flux:label for="vCode">Kod</flux:label>
            <flux:input id="vCode" wire:model="formCode" placeholder="t.ex. ELGIG" class="font-mono uppercase" />
            @error('formCode') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="col-span-2">
            <flux:label for="vName">Namn</flux:label>
            <flux:input id="vName" wire:model="formName" placeholder="Leverantörens namn" />
            @error('formName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <flux:label for="vType">Typ</flux:label>
            <flux:select id="vType" wire:model.live="formType">
                <option value="external">Extern</option>
                <option value="member">Medlem</option>
            </flux:select>
        </div>
        @if ($formType === 'member')
            <div>
                <flux:label for="vMember">Medlem</flux:label>
                <flux:select id="vMember" wire:model="formMemberId">
                    <option value="">Välj medlem…</option>
                    @foreach ($this->members as $m)
                        <option value="{{ $m->id }}">{{ $m->last_name }}, {{ $m->first_name }}</option>
                    @endforeach
                </flux:select>
                @error('formMemberId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        @else
            <div>
                <flux:label for="vOrgNumber">Organisationsnummer</flux:label>
                <flux:input id="vOrgNumber" wire:model="formOrgNumber" placeholder="556123-4567" class="font-mono" />
            </div>
        @endif
    </div>

    <div class="border-t border-zinc-100 dark:border-zinc-700 pt-4">
        <p class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-3">Bankuppgifter</p>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <flux:label for="vBankgiro">Bankgiro</flux:label>
                <flux:input id="vBankgiro" wire:model="formBankgiro" placeholder="123-4567" class="font-mono" />
            </div>
            <div>
                <flux:label for="vPlusgiro">Plusgiro</flux:label>
                <flux:input id="vPlusgiro" wire:model="formPlusgiro" placeholder="12 34 56-7" class="font-mono" />
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4 mt-3">
            <div>
                <flux:label for="vClearing">Clearingnr</flux:label>
                <flux:input id="vClearing" wire:model="formBankClearingNumber" placeholder="8327" class="font-mono" />
            </div>
            <div>
                <flux:label for="vIban">IBAN</flux:label>
                <flux:input id="vIban" wire:model="formIban" placeholder="SE00…" class="font-mono" />
            </div>
        </div>
    </div>

    <div class="border-t border-zinc-100 dark:border-zinc-700 pt-4">
        <p class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mb-3">Standardvärden för fakturor</p>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <flux:label for="vDefaultAccount">Standardkonto (kostnad)</flux:label>
                <x-account-select
                    :accounts="$this->expenseAccounts"
                    model="formDefaultAccountId"
                    :nullable="true"
                    null-label="Inget standardkonto"
                />
            </div>
            <div>
                <flux:label for="vDefaultVatRate">Standard-moms</flux:label>
                <flux:select id="vDefaultVatRate" wire:model="formDefaultVatRate">
                    <option value="0">0% (momsfri)</option>
                    <option value="6">6%</option>
                    <option value="12">12%</option>
                    <option value="25">25%</option>
                </flux:select>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <flux:label for="vEmail">E-post</flux:label>
            <flux:input id="vEmail" wire:model="formEmail" type="email" />
            @error('formEmail') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <flux:label for="vPhone">Telefon</flux:label>
            <flux:input id="vPhone" wire:model="formPhone" />
        </div>
    </div>

    <div>
        <flux:label for="vNotes">Anteckningar</flux:label>
        <flux:textarea id="vNotes" wire:model="formNotes" rows="2" />
    </div>

</div>
