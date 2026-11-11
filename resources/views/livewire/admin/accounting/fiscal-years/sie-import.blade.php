<?php

use App\Models\Accounting\FiscalYear;
use App\Services\Accounting\Sie4Importer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('SIE4-import');

new class extends Component {
    use WithFileUploads;

    public $file          = null;
    public ?int $fiscalYearId = null;

    public string $step   = 'upload'; // upload | preview | done

    // Import options
    public bool   $optCreateAccounts   = true;
    public bool   $optImportIb         = true;
    public bool   $optImportEntries    = true;
    public string $optDuplicates       = 'skip'; // skip | overwrite

    // Populated after parsing
    public array $preview = [];
    public array $result  = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);

        $fy = FiscalYear::where('is_locked', false)
            ->where('starts_at', '<=', today())
            ->where('ends_at',   '>=', today())
            ->latest('starts_at')
            ->first()
            ?? FiscalYear::where('is_locked', false)->latest('starts_at')->first();

        $this->fiscalYearId = $fy?->id;
    }

    #[Computed]
    public function openFiscalYears()
    {
        return FiscalYear::where('is_locked', false)->orderByDesc('starts_at')->get();
    }

    public function parse(): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);

        $this->validate([
            'file'         => 'required|file|max:20480',
            'fiscalYearId' => 'required|exists:fiscal_years,id',
        ], [
            'file.required'         => 'Välj en SIE4-fil.',
            'file.max'              => 'Filen får vara max 20 MB.',
            'fiscalYearId.required' => 'Välj ett räkenskapsår.',
        ]);

        $fy      = FiscalYear::findOrFail($this->fiscalYearId);
        $raw     = file_get_contents($this->file->getRealPath());
        $content = Sie4Importer::decodeToUtf8($raw);

        $importer = new Sie4Importer();
        $doc      = $importer->parse($content);
        $preview  = $importer->preview($doc, $fy);

        // Store parsed document in session (avoids large Livewire payload)
        session(['sie4_import_doc' => $doc]);

        $this->preview = $preview;
        $this->step    = 'preview';
    }

    public function import(): void
    {
        abort_unless(Auth::user()->can('accounting.admin'), 403);

        $fy  = FiscalYear::findOrFail($this->fiscalYearId);
        $doc = session('sie4_import_doc');

        if (! $doc) {
            $this->step = 'upload';
            $this->addError('file', 'Sessionen har gått ut. Ladda upp filen igen.');
            return;
        }

        $importer = new Sie4Importer();
        $this->result = $importer->import($doc, $fy, [
            'create_accounts'    => $this->optCreateAccounts,
            'import_ib'          => $this->optImportIb,
            'import_entries'     => $this->optImportEntries,
            'duplicate_handling' => $this->optDuplicates,
        ]);

        session()->forget('sie4_import_doc');
        $this->step = 'done';
    }

    public function restart(): void
    {
        session()->forget('sie4_import_doc');
        $this->file    = null;
        $this->preview = [];
        $this->result  = [];
        $this->step    = 'upload';
        $this->resetErrorBag();
    }
};
?>

<div class="max-w-3xl space-y-6">

    <div>
        <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">SIE4-import</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Importera bokföring från ett annat system via SIE 4-fil. Stöder ISO-8859-1 och UTF-8.
        </p>
    </div>

    {{-- Step indicator --}}
    <div class="flex items-center gap-2 text-xs text-zinc-400">
        <span class="{{ $step === 'upload'  ? 'text-brand font-semibold' : '' }}">1. Ladda upp</span>
        <span>›</span>
        <span class="{{ $step === 'preview' ? 'text-brand font-semibold' : '' }}">2. Förhandsgranska</span>
        <span>›</span>
        <span class="{{ $step === 'done'    ? 'text-brand font-semibold' : '' }}">3. Klart</span>
    </div>

    {{-- ── Step 1: Upload ──────────────────────────────────── --}}
    @if ($step === 'upload')
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-6 space-y-5">

        <div>
            <flux:label for="fySelect">Räkenskapsår att importera till</flux:label>
            <flux:select id="fySelect" wire:model="fiscalYearId" class="mt-1">
                @foreach ($this->openFiscalYears as $fy)
                    <option value="{{ $fy->id }}">{{ $fy->name }}</option>
                @endforeach
            </flux:select>
            @error('fiscalYearId') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <flux:label>SIE4-fil (.se)</flux:label>
            <div class="mt-1">
                <input type="file" wire:model="file" accept=".se,.si,.SIE,.SI"
                    class="block w-full text-sm text-zinc-500 dark:text-zinc-400
                           file:mr-4 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5
                           file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200
                           dark:file:bg-zinc-700 dark:file:text-zinc-300 dark:hover:file:bg-zinc-600" />
            </div>
            @error('file') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-zinc-400">Stöder SIE typ 4E. Max 20 MB.</p>
        </div>

        <div class="flex justify-end">
            <flux:button wire:click="parse" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="parse">Läs fil</span>
                <span wire:loading wire:target="parse">Läser…</span>
            </flux:button>
        </div>
    </div>
    @endif

    {{-- ── Step 2: Preview ─────────────────────────────────── --}}
    @if ($step === 'preview')
    <div class="space-y-5">

        {{-- File summary --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 space-y-3">
            <h2 class="font-medium text-zinc-800 dark:text-zinc-200">Filinnehåll</h2>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <dt class="text-zinc-500">Organisation</dt>
                <dd class="text-zinc-800 dark:text-zinc-200">{{ $preview['org_name'] ?: '–' }}
                    @if ($preview['org_number']) <span class="text-zinc-400">({{ $preview['org_number'] }})</span> @endif
                </dd>

                <dt class="text-zinc-500">Program</dt>
                <dd class="text-zinc-800 dark:text-zinc-200">{{ $preview['program'] ?: '–' }}</dd>

                @if ($preview['fy_in_file'])
                    <dt class="text-zinc-500">Räkenskapsår i fil</dt>
                    <dd class="text-zinc-800 dark:text-zinc-200 font-mono text-xs">
                        {{ $preview['fy_in_file']['start'] }} – {{ $preview['fy_in_file']['end'] }}
                    </dd>
                @endif

                <dt class="text-zinc-500">Konton i fil</dt>
                <dd class="text-zinc-800 dark:text-zinc-200">{{ $preview['total_accounts'] }}
                    @if ($preview['new_accounts'] > 0)
                        <span class="text-amber-600 dark:text-amber-400">
                            ({{ $preview['new_accounts'] }} saknas i kontoplan)
                        </span>
                    @endif
                </dd>

                <dt class="text-zinc-500">IB-poster (#IB 0)</dt>
                <dd class="text-zinc-800 dark:text-zinc-200">{{ $preview['ib_accounts'] }}</dd>

                <dt class="text-zinc-500">Verifikat</dt>
                <dd class="text-zinc-800 dark:text-zinc-200">{{ $preview['total_entries'] }}
                    @if ($preview['duplicate_entries'] > 0)
                        <span class="text-amber-600 dark:text-amber-400">
                            ({{ $preview['duplicate_entries'] }} finns redan)
                        </span>
                    @endif
                </dd>
            </dl>

            @if (count($preview['new_account_codes']) > 0)
                <div class="mt-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/40 p-3">
                    <p class="text-xs font-medium text-amber-700 dark:text-amber-300 mb-1">
                        Konton som saknas och skapas om "Skapa konton" är ikryssad:
                    </p>
                    <p class="font-mono text-xs text-amber-600 dark:text-amber-400">
                        {{ implode(', ', $preview['new_account_codes']) }}
                        @if ($preview['new_accounts'] > count($preview['new_account_codes']))
                            … och {{ $preview['new_accounts'] - count($preview['new_account_codes']) }} till
                        @endif
                    </p>
                </div>
            @endif
        </div>

        {{-- Options --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5 space-y-3">
            <h2 class="font-medium text-zinc-800 dark:text-zinc-200">Alternativ</h2>

            <label class="flex items-center gap-2.5 cursor-pointer">
                <input type="checkbox" wire:model="optCreateAccounts" class="rounded border-zinc-300 text-brand focus:ring-brand" />
                <span class="text-sm text-zinc-700 dark:text-zinc-300">Skapa saknade konton automatiskt</span>
            </label>
            <label class="flex items-center gap-2.5 cursor-pointer">
                <input type="checkbox" wire:model="optImportIb" class="rounded border-zinc-300 text-brand focus:ring-brand" />
                <span class="text-sm text-zinc-700 dark:text-zinc-300">Importera ingående balanser (#IB 0)</span>
            </label>
            <label class="flex items-center gap-2.5 cursor-pointer">
                <input type="checkbox" wire:model="optImportEntries" class="rounded border-zinc-300 text-brand focus:ring-brand" />
                <span class="text-sm text-zinc-700 dark:text-zinc-300">Importera verifikat</span>
            </label>

            @if ($optImportEntries && $preview['duplicate_entries'] > 0)
            <div class="ml-6 space-y-2 border-l-2 border-zinc-100 dark:border-zinc-700 pl-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    {{ $preview['duplicate_entries'] }} verifikat finns redan — hur ska de hanteras?
                </p>
                <label class="flex items-start gap-2.5 cursor-pointer">
                    <input type="radio" wire:model="optDuplicates" value="skip"
                        class="mt-0.5 border-zinc-300 text-brand focus:ring-brand" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">
                        Hoppa över
                        <span class="block text-xs text-zinc-400">Befintliga verifikat behålls oförändrade</span>
                    </span>
                </label>
                <label class="flex items-start gap-2.5 cursor-pointer">
                    <input type="radio" wire:model="optDuplicates" value="overwrite"
                        class="mt-0.5 border-zinc-300 text-brand focus:ring-brand" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">
                        Ersätt
                        <span class="block text-xs text-zinc-400">Befintliga rader markeras som ersatta (ändringshistorik bevaras), nya rader importeras</span>
                    </span>
                </label>
            </div>
            @endif
        </div>

        <div class="flex items-center justify-between">
            <flux:button wire:click="restart" variant="ghost">
                ← Tillbaka
            </flux:button>
            <flux:button wire:click="import" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="import">Importera nu</span>
                <span wire:loading wire:target="import">Importerar…</span>
            </flux:button>
        </div>
    </div>
    @endif

    {{-- ── Step 3: Done ────────────────────────────────────── --}}
    @if ($step === 'done')
    <div class="space-y-5">

        <div class="rounded-xl border border-green-200 dark:border-green-700/40 bg-green-50 dark:bg-green-900/20 p-5">
            <div class="flex items-center gap-2 text-green-800 dark:text-green-300 font-medium mb-3">
                <x-bootstrap-icon name="check-circle-fill" class="size-5" />
                Import klar
            </div>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
                <dt class="text-green-700 dark:text-green-400">Konton skapade</dt>
                <dd class="font-semibold text-green-900 dark:text-green-200">{{ $result['accounts_created'] }}</dd>
                <dt class="text-green-700 dark:text-green-400">IB-poster importerade</dt>
                <dd class="font-semibold text-green-900 dark:text-green-200">{{ $result['ib_imported'] }}</dd>
                <dt class="text-green-700 dark:text-green-400">Verifikat importerade</dt>
                <dd class="font-semibold text-green-900 dark:text-green-200">{{ $result['entries_imported'] }}</dd>
                @if ($result['entries_overwritten'] > 0)
                <dt class="text-green-700 dark:text-green-400">Verifikat ersatta</dt>
                <dd class="font-semibold text-green-900 dark:text-green-200">{{ $result['entries_overwritten'] }}</dd>
                @endif
                @if ($result['entries_skipped'] > 0)
                <dt class="text-green-700 dark:text-green-400">Verifikat hoppades över</dt>
                <dd class="font-semibold text-green-900 dark:text-green-200">{{ $result['entries_skipped'] }}</dd>
                @endif
            </dl>
        </div>

        @if (count($result['errors']) > 0)
        <div class="rounded-xl border border-amber-200 dark:border-amber-700/40 bg-amber-50 dark:bg-amber-900/20 p-4">
            <p class="text-xs font-medium text-amber-700 dark:text-amber-300 mb-2">
                {{ count($result['errors']) }} varningar:
            </p>
            <ul class="space-y-0.5 text-xs text-amber-600 dark:text-amber-400 font-mono">
                @foreach ($result['errors'] as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <div class="flex items-center gap-3">
            <flux:button wire:click="restart" variant="ghost">Importera en till</flux:button>
            <flux:button
                href="{{ route('admin.accounting.fiscal-years.index') }}"
                wire:navigate
                variant="primary">
                Till räkenskapsår
            </flux:button>
        </div>
    </div>
    @endif

</div>
