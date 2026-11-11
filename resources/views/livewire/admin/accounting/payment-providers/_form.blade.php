<div class="space-y-4">

    <div>
        <flux:label for="ppName">Namn</flux:label>
        <flux:input id="ppName" wire:model="formName" placeholder="t.ex. Stripe, SumUp, Zettle" />
        @error('formName') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <flux:label for="ppType">Typ</flux:label>
        <flux:select id="ppType" wire:model="formType">
            <option value="online">Online</option>
            <option value="card_terminal">Kortterminal</option>
        </flux:select>
        @error('formType') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <flux:label for="ppAccountId">Clearingkonto (transiterande konto)</flux:label>
        <x-account-select
            :accounts="$this->ledgerAccounts"
            model="formAccountId"
        />
        <p class="text-xs text-zinc-400 mt-1">Det konto inkommande betalningar bokförs på innan utbetalning till bank (t.ex. 1940).</p>
        @error('formAccountId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <flux:label for="ppSettlement">Utbetalningskonto (valfri)</flux:label>
        <flux:select id="ppSettlement" wire:model="formSettlementBankAccountId">
            <option value="">Välj bankkonto…</option>
            @foreach ($this->bankAccounts as $ba)
                <option value="{{ $ba->id }}">{{ $ba->name }}</option>
            @endforeach
        </flux:select>
        <p class="text-xs text-zinc-400 mt-1">Det bankkonto som utbetalningar från PSP:n landar på.</p>
        @error('formSettlementBankAccountId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <flux:label for="ppNotes">Anteckningar (valfri)</flux:label>
        <flux:textarea id="ppNotes" wire:model="formNotes" rows="2"
            placeholder="t.ex. Använd för onlinebetalningar i bokningssystemet. API-nycklar i .env." />
    </div>

</div>
