<div class="space-y-4">

    <div>
        <label class="lbl">Namn</label>
        <flux:input wire:model="formName" placeholder="Företag eller person" />
        @error('formName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="lbl">Org.nr</label>
            <flux:input wire:model="formOrgNumber" placeholder="556000-0000" />
        </div>
        <div>
            <label class="lbl">E-post</label>
            <flux:input wire:model="formEmail" type="email" placeholder="faktura@foretag.se" />
        </div>
    </div>

    <div>
        <label class="lbl">Kopplad medlem</label>
        <flux:select wire:model="formMemberId">
            <option value="">– Ingen –</option>
            @foreach ($this->members as $m)
                <option value="{{ $m->id }}">{{ $m->last_name }}, {{ $m->first_name }}</option>
            @endforeach
        </flux:select>
    </div>

    <div>
        <label class="lbl">Standardkonto (intäkt)</label>
        <flux:select wire:model="formDefaultAccountId">
            <option value="">– Inget –</option>
            @foreach ($this->incomeAccounts as $a)
                <option value="{{ $a->id }}">{{ $a->code }} – {{ $a->name }}</option>
            @endforeach
        </flux:select>
    </div>

</div>
