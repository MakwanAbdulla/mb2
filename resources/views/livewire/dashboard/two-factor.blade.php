<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    public string $confirmationCode  = '';
    public string $confirmPassword   = '';
    public bool   $showingQr         = false;
    public bool   $showingRecovery   = false;
    public ?string $flash            = null;
    public ?string $error            = null;

    #[Computed]
    public function enabled(): bool
    {
        return ! empty(Auth::user()->two_factor_secret);
    }

    #[Computed]
    public function confirmed(): bool
    {
        return ! empty(Auth::user()->two_factor_confirmed_at);
    }

    public function enable(): void
    {
        $this->error = null;
        app(EnableTwoFactorAuthentication::class)(Auth::user());
        $this->showingQr      = true;
        $this->showingRecovery = false;
        unset($this->enabled, $this->confirmed);
    }

    public function confirm(): void
    {
        $this->error = null;
        try {
            app(ConfirmTwoFactorAuthentication::class)(Auth::user(), $this->confirmationCode);
            $this->showingQr       = false;
            $this->showingRecovery = true;
            $this->confirmationCode = '';
            $this->flash = 'Tvåfaktorsautentisering aktiverad!';
            unset($this->enabled, $this->confirmed);
        } catch (\Throwable) {
            $this->error = 'Ogiltig kod — försök igen.';
        }
    }

    public function regenerateRecoveryCodes(): void
    {
        app(GenerateNewRecoveryCodes::class)(Auth::user());
        $this->flash = 'Återställningskoder genererades om.';
    }

    public function disable(): void
    {
        app(DisableTwoFactorAuthentication::class)(Auth::user());
        $this->showingQr = $this->showingRecovery = false;
        $this->flash = 'Tvåfaktorsautentisering avaktiverades.';
        unset($this->enabled, $this->confirmed);
    }

    public function qrCodeSvg(): string
    {
        return Auth::user()->twoFactorQrCodeSvg();
    }

    public function recoveryCodes(): array
    {
        $codes = Auth::user()->recoveryCodes();
        return is_string($codes) ? json_decode($codes, true) : ($codes ?? []);
    }
}; ?>

<div class="space-y-4">

    @if($flash)
        <div x-data x-init="setTimeout(() => $el.remove(), 5000)"
             class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
            {{ $flash }}
        </div>
    @endif

    @if($error)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
            {{ $error }}
        </div>
    @endif

    @if(! $this->enabled)
        {{-- Inte aktiverad --}}
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Tvåfaktorsautentisering är <strong class="text-zinc-900 dark:text-white">inte aktiverad</strong>.
            När aktiverat behöver du ange en engångskod från din autentiseringsapp vid varje inloggning.
        </p>
        <button wire:click="enable" class="btn btn-primary btn-sm">
            Aktivera tvåfaktorsautentisering
        </button>

    @elseif($this->enabled && ! $this->confirmed)
        {{-- Aktiverad men ej bekräftad — visa QR --}}
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Skanna QR-koden med din autentiseringsapp (t.ex. Google Authenticator, Authy) och ange sedan koden nedan för att bekräfta.
        </p>

        <div class="flex justify-center rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            {!! $this->qrCodeSvg() !!}
        </div>

        <div class="flex gap-3">
            <input wire:model="confirmationCode" type="text" inputmode="numeric"
                   placeholder="Engångskod…"
                   class="w-40 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-center tracking-widest shadow-sm focus:border-complement focus:ring-1 focus:ring-complement dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
            <button wire:click="confirm" class="btn btn-primary btn-sm">Bekräfta</button>
            <button wire:click="disable" wire:confirm="Avbryta aktivering av tvåfaktorsautentisering?"
                    class="btn btn-ghost btn-sm">Avbryt</button>
        </div>

    @else
        {{-- Aktiverad och bekräftad --}}
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Tvåfaktorsautentisering är <strong class="text-green-600 dark:text-green-400">aktiverad</strong>.
        </p>

        @if($showingRecovery)
            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <p class="mb-3 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                    Spara dessa återställningskoder på ett säkert ställe. De kan användas om du förlorar åtkomst till din autentiseringsapp.
                </p>
                <div class="grid grid-cols-2 gap-1.5 font-mono text-sm">
                    @foreach($this->recoveryCodes() as $code)
                        <span class="rounded bg-white px-2 py-1 text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">{{ $code }}</span>
                    @endforeach
                </div>
            </div>
            <button wire:click="regenerateRecoveryCodes"
                    wire:confirm="Gamla koder slutar fungera. Generera om?"
                    class="btn btn-secondary btn-sm">
                Generera om återställningskoder
            </button>
        @else
            <button wire:click="$set('showingRecovery', true)" class="btn btn-secondary btn-sm">
                Visa återställningskoder
            </button>
        @endif

        <div class="pt-2 border-t border-zinc-100 dark:border-zinc-800">
            <button wire:click="disable"
                    wire:confirm="Avaktivera tvåfaktorsautentisering? Ditt konto blir mindre säkert."
                    class="text-sm text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 transition-colors">
                Avaktivera tvåfaktorsautentisering
            </button>
        </div>
    @endif

</div>
