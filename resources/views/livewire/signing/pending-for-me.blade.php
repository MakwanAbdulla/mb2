<?php

use App\Enums\SigningStatus;
use App\Models\Signing\SigningRequestSigner;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

/*
 * "Väntar på din signatur" on the member's dashboard: open signing requests where
 * the logged-in user is a signer who hasn't signed yet. Renders nothing otherwise.
 */
new class extends Component {
    #[Computed]
    public function pending()
    {
        return SigningRequestSigner::query()
            ->with('signingRequest')
            ->whereNull('signed_at')
            ->whereNotNull('sign_url')
            ->whereHas('signer', fn ($q) => $q->where('user_id', Auth::id())->whereNull('anonymized_at'))
            ->whereHas('signingRequest', fn ($q) => $q->where('status', SigningStatus::Pending))
            ->get();
    }
}; ?>

<div>
    @if ($this->pending->isNotEmpty())
        <div class="mx-auto max-w-4xl px-4 pt-6 sm:px-6">
            <div class="rounded-xl border border-brand/40 bg-brand/5 p-5 dark:border-brand/30 dark:bg-brand/10">
                <p class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <x-bootstrap-icon name="pen" class="size-4" /> Väntar på din signatur
                </p>
                <ul class="mt-3 space-y-2">
                    @foreach ($this->pending as $requestSigner)
                        <li class="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <span class="text-zinc-700 dark:text-zinc-300">
                                {{ $requestSigner->signingRequest->name }}
                                @if ($requestSigner->role_label)
                                    <span class="text-zinc-500 dark:text-zinc-400">({{ $requestSigner->role_label }})</span>
                                @endif
                            </span>
                            <flux:button href="{{ $requestSigner->sign_url }}" target="_blank" rel="noopener" size="sm" variant="primary">
                                Signera nu
                            </flux:button>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Du signerar med BankID hos DocSign.</p>
            </div>
        </div>
    @endif
</div>
