<div class="space-y-5" x-data="{ trigger: @entangle('formTrigger') }">

    {{-- Trigger type --}}
    <div>
        <flux:label>Regeltyp <span class="text-red-500">*</span></flux:label>
        <div class="mt-2 flex flex-wrap gap-4">
            <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                <input type="radio" wire:model.live="formTrigger" value="bank_transaction"
                       class="text-brand focus:ring-brand-saffron" />
                <span class="text-zinc-700 dark:text-zinc-300">Banktransaktion</span>
            </label>
            <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                <input type="radio" wire:model.live="formTrigger" value="ap_invoice"
                       class="text-brand focus:ring-brand-saffron" />
                <span class="text-zinc-700 dark:text-zinc-300">Leverantörsfaktura</span>
            </label>
            <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                <input type="radio" wire:model.live="formTrigger" value="inbox_email"
                       class="text-brand focus:ring-brand-saffron" />
                <span class="text-zinc-700 dark:text-zinc-300">Fakturainkorg</span>
            </label>
        </div>
    </div>

    {{-- Name --}}
    <div>
        <flux:label for="formName">Namn på regeln <span class="text-red-500">*</span></flux:label>
        <input id="formName" type="text" wire:model="formName"
               placeholder="t.ex. Entré, Swish medlemsavgift, Lokaluthyrning…"
               class="inp mt-1 w-full" />
        @error('formName') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    {{-- ── Bank transaction fields ──────────────────────────────────────────── --}}
    <fieldset x-show="trigger === 'bank_transaction'" class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
        <legend class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 px-1">
            När en transaktion matchar följande
        </legend>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <flux:label for="formBankAccountId">Transaktionskonto</flux:label>
                <select id="formBankAccountId" wire:model="formBankAccountId" class="inp mt-1 w-full">
                    <option value="">Alla bankkonton</option>
                    @foreach ($this->bankAccounts as $ba)
                        <option value="{{ $ba->id }}">{{ $ba->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <flux:label>Transaktionstyp</flux:label>
                <div class="mt-2 flex gap-4">
                    <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                        <input type="radio" wire:model="formTransactionType" value="credit"
                               class="text-brand focus:ring-brand-saffron" />
                        <span class="text-green-700 dark:text-green-400">Inbetalning</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                        <input type="radio" wire:model="formTransactionType" value="debit"
                               class="text-brand focus:ring-brand-saffron" />
                        <span class="text-red-700 dark:text-red-400">Utbetalning</span>
                    </label>
                    <label class="flex items-center gap-1.5 cursor-pointer text-sm">
                        <input type="radio" wire:model="formTransactionType" value="any"
                               class="text-brand focus:ring-brand-saffron" />
                        <span class="text-zinc-600 dark:text-zinc-300">Båda</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <flux:label for="formValidFrom">Giltig från</flux:label>
                <input id="formValidFrom" type="date" wire:model="formValidFrom" class="inp mt-1 w-full" />
            </div>
            <div>
                <flux:label for="formValidTo">Giltig tom</flux:label>
                <input id="formValidTo" type="date" wire:model="formValidTo" class="inp mt-1 w-full" />
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <flux:label for="formDayFrom">Från dag i månaden</flux:label>
                <input id="formDayFrom" type="number" min="1" max="31" wire:model="formDayFrom"
                       placeholder="1" class="inp mt-1 w-full" />
            </div>
            <div>
                <flux:label for="formDayTo">Till dag i månaden</flux:label>
                <input id="formDayTo" type="number" min="1" max="31" wire:model="formDayTo"
                       placeholder="31" class="inp mt-1 w-full" />
            </div>
        </div>
    </fieldset>

    {{-- ── AP invoice fields ────────────────────────────────────────────────── --}}
    <fieldset x-show="trigger === 'ap_invoice'" class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
        <legend class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 px-1">
            När en faktura matchar följande
        </legend>

        <div>
            <flux:label for="formVendorId">Leverantör</flux:label>
            <select id="formVendorId" wire:model="formVendorId" class="inp mt-1 w-full">
                <option value="">Alla leverantörer</option>
                @foreach ($this->vendors as $v)
                    <option value="{{ $v->id }}">{{ $v->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-zinc-400">Lämna tomt för att matcha alla leverantörer (använd nyckelord för att precisera).</p>
            @error('formVendorId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
    </fieldset>

    {{-- ── Inbox email fields ─────────────────────────────────────────────────── --}}
    <fieldset x-show="trigger === 'inbox_email'" class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
        <legend class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 px-1">
            När ett mail från inkorgen matchar följande
        </legend>

        <div>
            <flux:label for="formFromEmailPattern">Avsändaradress (original)</flux:label>
            <input id="formFromEmailPattern" type="text" wire:model="formFromEmailPattern"
                   placeholder="no-reply@notification.sumup.com eller @notification.sumup.com"
                   class="inp mt-1 w-full font-mono text-sm" />
            <p class="mt-1 text-xs text-zinc-400">
                Exakt adress eller <code class="font-mono">@domän</code> för hela domänen.
                Matchas mot ursprunglig avsändare i vidarebefordrade mail. Tom = alla.
            </p>
            @error('formFromEmailPattern') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
    </fieldset>

    {{-- ── Shared match fields ──────────────────────────────────────────────── --}}
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <flux:label for="formAmountMin">Belopp SEK min</flux:label>
            <input id="formAmountMin" type="number" step="0.01" min="0" wire:model="formAmountMin"
                   placeholder="Inget minimum" class="inp mt-1 w-full" />
            @error('formAmountMin') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <flux:label for="formAmountMax">Belopp SEK max</flux:label>
            <input id="formAmountMax" type="number" step="0.01" min="0" wire:model="formAmountMax"
                   placeholder="Inget maximum" class="inp mt-1 w-full" />
            @error('formAmountMax') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <flux:label for="formKeyword">
            <span x-show="trigger === 'bank_transaction'">Nyckelord i transaktionstext eller referens</span>
            <span x-show="trigger === 'ap_invoice'">Nyckelord i fakturanummer eller anteckningar</span>
            <span x-show="trigger === 'inbox_email'">Nyckelord i ämnesraden</span>
        </flux:label>
        <input id="formKeyword" type="text" wire:model="formKeyword"
               placeholder="t.ex. Daglig, Månadsvis, faktura…"
               class="inp mt-1 w-full font-mono text-sm" />
        <p class="mt-1 text-xs text-zinc-400">Skiftlägesokänslig. Tom = matchar alla.</p>
        @error('formKeyword') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    {{-- ── Action fields ────────────────────────────────────────────────────── --}}
    <fieldset class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
        <legend class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 px-1">
            <span x-show="trigger === 'bank_transaction'">Bokför transaktionen så här</span>
            <span x-show="trigger === 'ap_invoice'">Förfyll fakturaraden med</span>
            <span x-show="trigger === 'inbox_email'">Föreslå konto vid import</span>
        </legend>

        <div class="grid gap-4 sm:grid-cols-2">
            {{-- Series — for bank transactions and inbox email (both create journal entries) --}}
            <div x-show="trigger === 'bank_transaction' || trigger === 'inbox_email'">
                <flux:label for="formSeries">Verifikationsserie <span class="text-red-500">*</span></flux:label>
                <select id="formSeries" wire:model="formSeries" class="inp mt-1 w-full">
                    @foreach ($this->series as $s)
                        <option value="{{ $s->code }}">{{ $s->code }} – {{ $s->name }}</option>
                    @endforeach
                </select>
                @error('formSeries') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:label for="formCostCenterId">Kostnadsställe</flux:label>
                <select id="formCostCenterId" wire:model="formCostCenterId" class="inp mt-1 w-full">
                    <option value="">–</option>
                    @foreach ($this->costCenters as $cc)
                        <option value="{{ $cc->id }}">{{ $cc->code }} – {{ $cc->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- bank_transaction / ap_invoice: single counter account --}}
        <div x-show="trigger !== 'inbox_email'">
            <flux:label for="formCounterAccountId">
                <span x-show="trigger === 'bank_transaction'">Motkonto <span class="text-red-500">*</span></span>
                <span x-show="trigger === 'ap_invoice'">Konto (kostnad) <span class="text-red-500">*</span></span>
            </flux:label>
            <x-account-select
                :accounts="$this->accounts"
                model="formCounterAccountId"
                class="mt-1"
            />
            <p class="mt-1 text-xs text-zinc-400" x-show="trigger === 'bank_transaction'">
                Inbetalning → krediteras. Utbetalning → debiteras.
            </p>
            <p class="mt-1 text-xs text-zinc-400" x-show="trigger === 'ap_invoice'">
                Kontot som förfylls på fakturaraden. Du kan justera det manuellt innan du sparar fakturan.
            </p>
            @error('formCounterAccountId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- bank_transaction: optional vendor tag --}}
        <div x-show="trigger === 'bank_transaction'">
            <flux:label for="formVendorIdBank">Tagga som leverantör</flux:label>
            <select id="formVendorIdBank" wire:model="formVendorId" class="inp mt-1 w-full">
                <option value="">– Ingen –</option>
                @foreach ($this->vendors as $v)
                    <option value="{{ $v->id }}">{{ $v->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-zinc-400">
                Valfritt. Taggar transaktionen med leverantören så att köp kan summeras per leverantör i rapporter.
            </p>
        </div>

        {{-- inbox_email: explicit debit + credit pair → creates full journal entry on import --}}
        <div x-show="trigger === 'inbox_email'" class="grid gap-4 sm:grid-cols-2">
            <div>
                <flux:label for="formDebitAccountId">Debetkonto <span class="text-red-500">*</span></flux:label>
                <x-account-select
                    :accounts="$this->accounts"
                    model="formDebitAccountId"
                    class="mt-1"
                />
                @error('formDebitAccountId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:label for="formCreditAccountId">Kreditkonto <span class="text-red-500">*</span></flux:label>
                <x-account-select
                    :accounts="$this->accounts"
                    model="formCreditAccountId"
                    class="mt-1"
                />
                @error('formCreditAccountId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <p class="col-span-2 -mt-2 text-xs text-zinc-400">
                Verifikatet skapas automatiskt vid import om PDF-beloppet och datumet kunnat extraheras.
            </p>
        </div>

        <div class="w-32">
            <flux:label for="formSortOrder">Prioritet (lägre = körs först)</flux:label>
            <input id="formSortOrder" type="number" min="0" wire:model="formSortOrder"
                   class="inp mt-1 w-full" />
        </div>

        {{-- Requires receipt — only relevant when a journal entry is auto-created --}}
        <div x-show="trigger === 'bank_transaction' || trigger === 'inbox_email'"
             class="flex items-start gap-3 pt-1">
            <input id="formRequiresReceipt" type="checkbox" wire:model="formRequiresReceipt"
                   class="mt-0.5 size-4 rounded text-brand focus:ring-brand-saffron" />
            <div>
                <label for="formRequiresReceipt" class="text-sm font-medium text-zinc-700 dark:text-zinc-300 cursor-pointer">
                    Kvitto krävs
                </label>
                <p class="text-xs text-zinc-400 mt-0.5">
                    Flaggar verifikatet som "kvitto saknas" tills ett kvitto laddas upp. Används för utbetalningar och kortköp.
                </p>
            </div>
        </div>
    </fieldset>

</div>
