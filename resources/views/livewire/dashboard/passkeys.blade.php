<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Volt\Component;

new class extends Component {

    public ?string $flash     = null;
    public ?string $error     = null;

    public function passkeys(): \Illuminate\Database\Eloquent\Collection
    {
        return Auth::user()->passkeys()->latest()->get();
    }

    public function delete(int $id): void
    {
        $user    = Auth::user();
        $passkey = $user->passkeys()->findOrFail($id);

        try {
            app(DeletePasskey::class)($user, $passkey);
            $this->flash = 'Passnyckeln raderades.';
        } catch (\Throwable $e) {
            $this->error = 'Något gick fel. Försök igen.';
        }

        $this->dispatch('passkey-deleted');
    }
}; ?>

<div
    x-data="passkeyManager()"
    x-init="init()"
    class="space-y-4"
>
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

    <div x-show="jsError" x-cloak
         class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
        <span x-text="jsError"></span>
    </div>

    <div x-show="jsSuccess" x-cloak x-init="if(jsSuccess) setTimeout(() => jsSuccess = null, 5000)"
         class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
        <span x-text="jsSuccess"></span>
    </div>

    {{-- Registrerade passnycklar --}}
    @if($this->passkeys()->isEmpty())
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            Du har inga registrerade passnycklar ännu. Lägg till en nedan.
        </p>
    @else
        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700">
            @foreach($this->passkeys() as $key)
                <li class="flex items-center justify-between px-4 py-3">
                    <div class="flex items-center gap-3">
                        <x-bootstrap-icon name="fingerprint" class="size-4 text-zinc-400 dark:text-zinc-500" />
                        <div>
                            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $key->name }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Skapades {{ $key->created_at->locale('sv')->diffForHumans() }}
                                @if($key->last_used_at)
                                    · Senast använd {{ $key->last_used_at->locale('sv')->diffForHumans() }}
                                @endif
                            </p>
                        </div>
                    </div>
                    <button
                        wire:click="delete({{ $key->id }})"
                        wire:confirm="Ta bort passnyckeln '{{ $key->name }}'?"
                        class="text-sm text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 transition-colors">
                        Ta bort
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Lägg till ny passnyckel --}}
    <div class="flex gap-3">
        <input
            x-model="newKeyName"
            type="text"
            placeholder="Namn (t.ex. Min iPhone, Work laptop)"
            class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-complement focus:ring-1 focus:ring-complement dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
        />
        <button
            @click="register(newKeyName)"
            :disabled="loading || !newKeyName.trim()"
            class="btn btn-primary btn-sm disabled:opacity-50">
            <span x-show="!loading">Lägg till passnyckel</span>
            <span x-show="loading" x-cloak>Väntar på enhet…</span>
        </button>
    </div>

    <p class="text-xs text-zinc-400 dark:text-zinc-500">
        Passnycklar använder din enhets biometri (Face ID, fingeravtryck) eller PIN-kod för inloggning utan lösenord.
    </p>
</div>

@push('scripts')
<script type="module">
    import { startRegistration } from 'https://cdn.jsdelivr.net/npm/@simplewebauthn/browser@13/+esm';

    window.passkeyManager = function() {
        return {
            loading: false,
            jsError: null,
            jsSuccess: null,
            newKeyName: '',

            init() {},

            async register(name) {
                if (!name?.trim()) return;
                this.loading  = true;
                this.jsError  = null;
                this.jsSuccess = null;

                try {
                    const optResp = await fetch('{{ route('passkey.registration-options') }}', {
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    });
                    const { options } = await optResp.json();

                    const credential = await startRegistration({ optionsJSON: options });

                    const storeResp = await fetch('{{ route('passkey.store') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({ name, credential }),
                    });

                    if (!storeResp.ok) throw new Error('Registreringen misslyckades.');

                    this.jsSuccess = 'Passnyckeln registrerades!';
                    this.newKeyName = '';
                    this.$wire.$refresh();
                } catch (err) {
                    if (err.name === 'NotAllowedError') {
                        this.jsError = 'Registreringen avbröts.';
                    } else {
                        this.jsError = err.message ?? 'Något gick fel.';
                    }
                } finally {
                    this.loading = false;
                }
            },
        };
    };
</script>
@endpush
