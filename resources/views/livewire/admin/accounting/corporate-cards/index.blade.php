<?php

use App\Models\Accounting\BankTransaction;
use App\Models\Accounting\CorporateCard;
use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Services\Accounting\CardReceiptMatcher;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Kontokort');

new class extends Component {

    public bool   $showForm      = false;
    public ?int   $editingId     = null;

    // Backfill result shown after a new card is saved
    public ?array $backfillResult = null;

    public string $formReference  = '';   // e.g. "K3491"
    public string $formCardNumber = '';   // full 16-digit card number
    public string $formIssuer     = 'Swedbank';
    public string $formLabel      = '';
    public string $formMemberId   = '';
    public bool   $formActive     = true;
    public string $formExpiresAt  = '';

    public string $memberSearch  = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
    }

    /**
     * K-references that appear in existing bank transactions but have no registered card.
     * These are transactions from before the card was registered (or a genuinely new card).
     */
    #[Computed]
    public function unknownRefs(): array
    {
        $registered = CorporateCard::pluck('card_reference')->all();

        return BankTransaction::whereNull('corporate_card_id')
            ->where('amount', '<', 0)
            ->get(['description'])
            ->map(fn ($tx) => CorporateCard::extractReference($tx->description))
            ->filter()
            ->unique()
            ->diff($registered)
            ->values()
            ->sort()
            ->all();
    }

    #[Computed]
    public function cards()
    {
        return CorporateCard::with('member')
            ->orderBy('card_reference')
            ->get()
            ->map(fn (CorporateCard $card) => [
                'card'    => $card,
                'missing' => CardReceiptMatcher::missingReceiptsQuery($card)->count(),
            ]);
    }

    #[Computed]
    public function memberResults()
    {
        if (strlen($this->memberSearch) < 2) {
            return collect();
        }

        return Member::where(function ($q) {
                $q->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$this->memberSearch}%"])
                  ->orWhere('member_number', 'like', "%{$this->memberSearch}%");
            })
            ->orderBy('first_name')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'member_number']);
    }

    public function openCreate(): void
    {
        $this->editingId      = null;
        $this->formReference  = '';
        $this->formCardNumber = '';
        $this->formIssuer     = 'Swedbank';
        $this->formLabel      = '';
        $this->formMemberId   = '';
        $this->formActive     = true;
        $this->formExpiresAt  = '';
        $this->memberSearch   = '';
        $this->showForm       = true;
    }

    public function openEdit(int $id): void
    {
        $card = CorporateCard::with('member')->findOrFail($id);
        $this->editingId      = $id;
        $this->formReference  = $card->card_reference;
        $this->formCardNumber = $card->card_number ?? '';
        $this->formIssuer     = $card->issuer ?? 'Swedbank';
        $this->formLabel      = $card->label ?? '';
        $this->formMemberId   = (string) $card->member_id;
        $this->formActive     = $card->is_active;
        $this->formExpiresAt  = $card->expires_at?->format('Y-m-d') ?? '';
        $this->memberSearch   = $card->member->full_name;
        $this->showForm       = true;
    }

    public function selectMember(int $id): void
    {
        $member = Member::findOrFail($id);
        $this->formMemberId = (string) $id;
        $this->memberSearch = $member->full_name;
        unset($this->memberResults);
    }

    public function save(): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);

        $uniqueRule = $this->editingId
            ? "unique:corporate_cards,card_reference,{$this->editingId}"
            : 'unique:corporate_cards,card_reference';

        $this->validate([
            'formReference'  => ["required", "string", "max:20", "regex:/^K\d+$/i", $uniqueRule],
            'formCardNumber' => 'nullable|string|max:19',
            'formIssuer'     => 'nullable|string|max:100',
            'formMemberId'   => 'required|exists:members,id',
            'formLabel'      => 'nullable|string|max:100',
            'formExpiresAt'  => 'nullable|date',
        ], [
            'formReference.required' => 'Ange kortreferens (t.ex. K3491).',
            'formReference.regex'    => 'Referensen måste börja med K följt av siffror, t.ex. K3491.',
            'formReference.unique'   => 'Den kortreferensen är redan registrerad.',
            'formMemberId.required'  => 'Välj en kortinnehavare.',
        ]);

        $data = [
            'card_reference' => strtoupper(trim($this->formReference)),
            'card_number'    => preg_replace('/\D/', '', $this->formCardNumber) ?: null,
            'issuer'         => trim($this->formIssuer) ?: null,
            'member_id'      => (int) $this->formMemberId,
            'label'          => trim($this->formLabel) ?: null,
            'is_active'      => $this->formActive,
            'expires_at'     => $this->formExpiresAt ?: null,
        ];

        $this->backfillResult = null;

        if ($this->editingId) {
            CorporateCard::findOrFail($this->editingId)->update($data);
        } else {
            $card = CorporateCard::create($data);

            AuditLog::record('corporate_card_created', 'accounting', $card->card_reference, $card);

            // Retroactively tag + match existing bank transactions for this card reference
            $result = app(CardReceiptMatcher::class)->backfillCard($card);
            if ($result['found'] > 0) {
                $this->backfillResult = $result;
            }
        }

        $this->showForm  = false;
        $this->editingId = null;
        unset($this->cards);
    }

    public function toggleActive(int $id): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        $card = CorporateCard::findOrFail($id);
        $card->update(['is_active' => ! $card->is_active]);
        AuditLog::record('corporate_card_toggled', 'accounting', $card->card_reference, $card);
        unset($this->cards);
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->can('accounting.manage'), 403);
        $card = CorporateCard::findOrFail($id);
        $reference = $card->card_reference;
        $card->delete();
        AuditLog::record('corporate_card_deleted', 'accounting', $reference, null);
        unset($this->cards);
    }
};
?>

<div>

    {{-- Unknown K-references found in existing transactions --}}
    @if (count($this->unknownRefs) > 0)
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 dark:border-red-800/40 dark:bg-red-900/10 px-4 py-3 text-sm">
            <p class="font-semibold text-red-800 dark:text-red-300 flex items-center gap-1.5 mb-1.5">
                <x-bootstrap-icon name="credit-card-2-front" class="size-4" />
                Okänd{{ count($this->unknownRefs) > 1 ? 'a kortreferenser' : ' kortreferens' }} i kontoutdrag
            </p>
            <p class="text-red-700 dark:text-red-400 mb-2">
                Följande referens{{ count($this->unknownRefs) > 1 ? 'er förekommer' : ' förekommer' }} i importerade
                banktransaktioner men {{ count($this->unknownRefs) > 1 ? 'saknar registrerade kort' : 'saknar ett registrerat kort' }}:
            </p>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->unknownRefs as $ref)
                    <span class="font-mono font-bold px-2 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-200">
                        {{ $ref }}
                    </span>
                @endforeach
            </div>
            <p class="mt-2 text-red-600 dark:text-red-400 text-xs">
                Lägg till kortet nedan — transaktionerna kopplas och matchas automatiskt.
            </p>
        </div>
    @endif

    {{-- Backfill result (shown once after a new card is registered) --}}
    @if ($backfillResult)
        <div class="mb-5 rounded-lg border border-blue-200 bg-blue-50 dark:border-blue-800/40 dark:bg-blue-900/10 px-4 py-3 text-sm">
            <p class="font-semibold text-blue-800 dark:text-blue-300 flex items-center gap-1.5 mb-1">
                <x-bootstrap-icon name="arrow-clockwise" class="size-4" />
                Bakåtmatchning klar
            </p>
            <p class="text-blue-700 dark:text-blue-400">
                {{ $backfillResult['found'] }} befintlig{{ $backfillResult['found'] !== 1 ? 'a' : '' }}
                transaktion{{ $backfillResult['found'] !== 1 ? 'er' : '' }} hittades för det nya kortet.
            </p>
            @if ($backfillResult['matched'] > 0)
                <p class="mt-0.5 flex items-center gap-1 text-green-700 dark:text-green-400">
                    <x-bootstrap-icon name="check-circle-fill" class="size-3.5" />
                    {{ $backfillResult['matched'] }} matchades automatiskt mot inlämnade kvitton.
                </p>
            @endif
            @if ($backfillResult['unmatched'] > 0)
                <p class="mt-0.5 flex items-center gap-1 text-amber-700 dark:text-amber-400">
                    <x-bootstrap-icon name="exclamation-circle-fill" class="size-3.5" />
                    {{ $backfillResult['unmatched'] }} saknar fortfarande kvitto — syns i restlistan.
                </p>
            @endif
            <button wire:click="$set('backfillResult', null)"
                    class="mt-2 text-xs text-blue-500 hover:text-blue-700 dark:hover:text-blue-300">
                Stäng
            </button>
        </div>
    @endif

    <p class="mb-6 text-sm text-zinc-500 dark:text-zinc-400 max-w-2xl">
        Registrera föreningens kontokort och koppla dem till respektive kortinnehavare.
        Vid bankimport matchas köpen automatiskt mot inlämnade kvitton.
        Saknas kvitto skickas en notifikation till kortinnehavaren.
    </p>

    {{-- Card list --}}
    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700 max-w-3xl">
        <table class="w-full text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 w-24">Referens</th>
                    <th class="px-4 py-3 w-44">Kortnummer</th>
                    <th class="px-4 py-3 w-28">Utgivare</th>
                    <th class="px-4 py-3">Kortinnehavare</th>
                    <th class="px-4 py-3">Etikett</th>
                    <th class="px-4 py-3 w-28">Utgår</th>
                    <th class="px-4 py-3 w-32 text-center">Saknade kvitton</th>
                    <th class="px-4 py-3 w-20 text-center">Aktiv</th>
                    <th class="px-4 py-3 w-28"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->cards as $row)
                    @php $card = $row['card']; $missing = $row['missing']; @endphp
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ ! $card->is_active ? 'opacity-50' : '' }}">
                        <td class="px-4 py-3 font-mono font-semibold text-zinc-800 dark:text-zinc-100">
                            {{ $card->card_reference }}
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $card->issuer ?? '–' }}
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300">
                            @if ($card->card_number)
                                @can('accounting.manage')
                                    {{ chunk_split($card->card_number, 4, ' ') }}
                                @else
                                    {{ $card->maskedNumber() }}
                                @endcan
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">–</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-zinc-700 dark:text-zinc-200">{{ $card->member->full_name }}</span>
                            @if ($card->member->member_number)
                                <span class="ml-1 text-xs text-zinc-400">#{{ $card->member->member_number }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                            {{ $card->label ?: '–' }}
                        </td>
                        <td class="px-4 py-3 text-xs text-zinc-500 dark:text-zinc-400">
                            @if ($card->expires_at)
                                {{ $card->expires_at->format('d/m Y') }}
                                @if ($card->expires_at->isPast())
                                    <span class="text-amber-600 dark:text-amber-400">(utgånget)</span>
                                @endif
                            @else
                                –
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($missing > 0)
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold
                                             bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">
                                    <x-bootstrap-icon name="exclamation-circle-fill" class="size-3" />
                                    {{ $missing }}
                                </span>
                            @else
                                <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-500" />
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button wire:click="toggleActive({{ $card->id }})"
                                    class="text-xs {{ $card->is_active
                                        ? 'text-green-600 dark:text-green-400'
                                        : 'text-zinc-400' }}">
                                <x-bootstrap-icon name="{{ $card->is_active ? 'toggle-on' : 'toggle-off' }}" class="size-5" />
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <button wire:click="openEdit({{ $card->id }})"
                                        class="text-xs text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">
                                    Redigera
                                </button>
                                <button wire:click="delete({{ $card->id }})"
                                        wire:confirm="Ta bort kortet {{ $card->card_reference }}? Historik bevaras."
                                        class="text-xs text-zinc-400 hover:text-red-600 dark:hover:text-red-400">
                                    Ta bort
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-zinc-400">
                            Inga kontokort registrerade ännu.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Create / Edit modal --}}
    <flux:modal wire:model.boolean="showForm" class="max-w-md">
        <div class="p-5 space-y-4">
            <h2 class="text-base font-semibold">
                {{ $editingId ? 'Redigera kontokort' : 'Lägg till kontokort' }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="lbl" for="formReference">Kortreferens *</label>
                    <flux:input id="formReference"
                                wire:model="formReference"
                                placeholder="K3491"
                                class="font-mono uppercase" />
                    @error('formReference')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-zinc-400">Syns i bankutdraget, t.ex. "K3491"</p>
                </div>

                <div>
                    <label class="lbl" for="formCardNumber">Kortnummer <span class="font-normal text-zinc-400">(valfritt)</span></label>
                    <flux:input id="formCardNumber"
                                wire:model="formCardNumber"
                                placeholder="4111 1111 1111 1234"
                                class="font-mono"
                                maxlength="19" />
                    @error('formCardNumber')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="lbl" for="formIssuer">Utgivare <span class="font-normal text-zinc-400">(valfritt)</span></label>
                    <flux:input id="formIssuer"
                                wire:model="formIssuer"
                                placeholder="Swedbank" />
                </div>

                <div>
                    <label class="lbl" for="formLabel">Etikett <span class="font-normal text-zinc-400">(valfri)</span></label>
                    <flux:input id="formLabel"
                                wire:model="formLabel"
                                placeholder="Inköpsansvarig" />
                </div>

                <div>
                    <label class="lbl" for="formExpiresAt">Utgångsdatum <span class="font-normal text-zinc-400">(valfritt)</span></label>
                    <flux:input type="date" id="formExpiresAt" wire:model="formExpiresAt" />
                    @error('formExpiresAt')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Member picker --}}
            <div x-data="{ open: false }">
                <label class="lbl">Kortinnehavare *</label>
                <div class="relative">
                    <flux:input wire:model.live.debounce.300ms="memberSearch"
                                placeholder="Sök på namn eller medlemsnummer…"
                                @focus="open = true"
                                @click.outside="open = false" />
                    @if ($this->memberResults->isNotEmpty())
                        <div x-show="open"
                             class="absolute z-20 mt-1 w-full rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-900 max-h-48 overflow-y-auto">
                            @foreach ($this->memberResults as $m)
                                <button type="button"
                                        wire:click="selectMember({{ $m->id }})"
                                        @click="open = false"
                                        class="w-full px-3 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                    <span class="font-medium">{{ $m->full_name }}</span>
                                    @if ($m->member_number)
                                        <span class="ml-2 text-xs text-zinc-400">#{{ $m->member_number }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
                @error('formMemberId')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            @if ($editingId)
                <div class="flex items-center gap-2">
                    <input type="checkbox" wire:model="formActive" id="formActive" class="rounded" />
                    <label for="formActive" class="text-sm">Aktivt kort</label>
                </div>
            @endif

            <div class="flex justify-end gap-2 pt-1">
                <flux:button wire:click="$set('showForm', false)" variant="ghost">Avbryt</flux:button>
                <flux:button wire:click="save" variant="primary">Spara</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
