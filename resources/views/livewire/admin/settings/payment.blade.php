<?php

use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public string $swishNumber             = '';
    public string $swishType              = 'private';
    public $swishCertificateFile           = null;
    public string $swishCertificatePassphrase = '';
    public bool   $swishEmulatorEnabled    = false;
    public string $bankgiro               = '';
    public string $bankgiroReferenceType  = 'message';
    public bool   $stripeEnabled          = false;
    public string $stripePublishableKey   = '';
    public string $stripeSecretKey        = '';
    public string $stripeWebhookSecret    = '';
    public bool   $saved                  = false;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('settings.manage'), 403);

        $this->swishNumber            = (string) SystemSetting::get('payment.swish_number', '');
        $this->swishType              = (string) SystemSetting::get('payment.swish_type', 'private');
        $this->swishCertificatePassphrase = (string) SystemSetting::get('payment.swish_certificate_passphrase', '');
        $this->swishEmulatorEnabled    = (bool)   SystemSetting::get('payment.swish_emulator_enabled', false);
        $this->bankgiro               = (string) SystemSetting::get('payment.bankgiro', '');
        $this->bankgiroReferenceType  = (string) SystemSetting::get('payment.bankgiro_reference_type', 'message');
        $this->stripeEnabled          = (bool)   SystemSetting::get('payment.stripe_enabled', false);
        $this->stripePublishableKey   = (string) SystemSetting::get('payment.stripe_publishable_key', '');
        $this->stripeSecretKey        = (string) SystemSetting::get('payment.stripe_secret_key', '');
        $this->stripeWebhookSecret    = (string) SystemSetting::get('payment.stripe_webhook_secret', '');
    }

    public function hasSwishCertificate(): bool
    {
        $path = (string) SystemSetting::get('payment.swish_certificate_path', '');

        return $path !== '' && file_exists($path);
    }

    public function removeSwishCertificate(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $path = (string) SystemSetting::get('payment.swish_certificate_path', '');
        if ($path !== '' && file_exists($path)) {
            @unlink($path);
        }

        SystemSetting::set('payment.swish_certificate_path', '');
        SystemSetting::set('payment.swish_certificate_passphrase', '', SystemSetting::TYPE_ENCRYPTED);
        $this->swishCertificatePassphrase = '';

        AuditLog::record('settings_saved', 'settings', 'Swish-certifikat borttaget', null);
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('settings.manage'), 403);

        $swishNumberPattern = match($this->swishType) {
            'private'  => '/^07[0-9]{8}$/',
            default    => '/^123[0-9]{7}$/',
        };

        $this->validate([
            'swishNumber'                => ['nullable', 'string', 'regex:' . $swishNumberPattern],
            'swishType'                  => ['required', 'in:private,company,merchant'],
            'swishCertificateFile'       => ['nullable', 'file', 'mimes:p12,pfx', 'max:1024'],
            'swishCertificatePassphrase' => ['nullable', 'string', 'max:255'],
            'swishEmulatorEnabled'       => ['boolean'],
            'bankgiro'               => ['nullable', 'string', 'max:20'],
            'bankgiroReferenceType'  => ['required', 'in:message,ocr'],
            'stripeEnabled'          => ['boolean'],
            'stripePublishableKey'   => ['nullable', 'string', 'max:255', 'regex:/^pk_(test|live)_/'],
            'stripeSecretKey'        => ['nullable', 'string', 'max:255', 'regex:/^sk_(test|live)_/'],
            'stripeWebhookSecret'    => ['nullable', 'string', 'max:255', 'regex:/^whsec_/'],
        ], [
            'swishNumber.regex'          => match($this->swishType) {
                'private' => 'Swish Privat-nummer måste ha formatet 07XXXXXXXX (10 siffror).',
                default   => 'Swish Företag/Handel-nummer måste ha formatet 123XXXXXXX (10 siffror).',
            },
            'swishCertificateFile.mimes' => 'Certifikatet måste vara en .p12- eller .pfx-fil.',
            'stripePublishableKey.regex' => 'Publiceringsnyckel måste börja med pk_test_ eller pk_live_.',
            'stripeSecretKey.regex'      => 'Hemlig nyckel måste börja med sk_test_ eller sk_live_.',
            'stripeWebhookSecret.regex'  => 'Webhook-hemlighet måste börja med whsec_.',
        ]);

        $swishCertificatePath = (string) SystemSetting::get('payment.swish_certificate_path', '');

        if ($this->swishCertificateFile) {
            $this->swishCertificateFile->storeAs('private/swish', 'certificate.p12', 'local');
            $swishCertificatePath = Storage::disk('local')->path('private/swish/certificate.p12');
        }

        // Sensitive keys — log change without exposing values, and encrypt at rest
        $sensitiveKeys = ['payment.stripe_secret_key', 'payment.stripe_webhook_secret', 'payment.swish_certificate_passphrase'];
        $booleanKeys   = ['payment.swish_emulator_enabled', 'payment.stripe_enabled'];

        $map = [
            'payment.swish_number'                 => $this->swishNumber ?: '',
            'payment.swish_type'                   => $this->swishType,
            'payment.swish_certificate_path'        => $swishCertificatePath,
            'payment.swish_certificate_passphrase'  => $this->swishCertificatePassphrase ?: '',
            'payment.swish_emulator_enabled'         => $this->swishEmulatorEnabled ? '1' : '0',
            'payment.bankgiro'                => $this->bankgiro ?: '',
            'payment.bankgiro_reference_type' => $this->bankgiroReferenceType,
            'payment.stripe_enabled'          => $this->stripeEnabled ? '1' : '0',
            'payment.stripe_publishable_key'  => $this->stripePublishableKey ?: '',
            'payment.stripe_secret_key'       => $this->stripeSecretKey ?: '',
            'payment.stripe_webhook_secret'   => $this->stripeWebhookSecret ?: '',
        ];

        $changes = [];
        foreach ($map as $key => $new) {
            $old = (string) SystemSetting::get($key, '');
            if ($old !== (string) $new) {
                if (in_array($key, $sensitiveKeys, true)) {
                    $changes[$key] = ['from' => $old ? '[ändrad]' : '[tom]', 'to' => $new ? '[ändrad]' : '[tom]'];
                } else {
                    $changes[$key] = ['from' => $old, 'to' => (string) $new];
                }
            }
        }
        foreach ($map as $key => $value) {
            $type = match (true) {
                in_array($key, $sensitiveKeys, true) => SystemSetting::TYPE_ENCRYPTED,
                in_array($key, $booleanKeys, true)   => SystemSetting::TYPE_BOOLEAN,
                default                               => SystemSetting::TYPE_STRING,
            };
            SystemSetting::set($key, $value, $type);
        }

        $this->swishCertificateFile = null;

        AuditLog::record('settings_saved', 'settings', 'Betalningsinställningar uppdaterade', null, $changes ?: null);
        $this->saved = true;
    }
}; ?>

<form wire:submit="save" class="space-y-6">
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">

        {{-- Betalningsmetoder --}}
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Betalningsmetoder</h2>
        </div>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">

            {{-- Swish --}}
            <div class="space-y-3 p-5">
                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Swish</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="lbl">
                            Swish-nummer
                            <span class="font-normal text-zinc-400">(tomt = dölj Swish)</span>
                        </label>
                        <input wire:model="swishNumber" type="text"
                               placeholder="{{ $swishType === 'private' ? '0701234567' : '1231234567' }}"
                               class="inp mt-1 font-mono @error('swishNumber') border-red-300 @enderror" />
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                            @if($swishType === 'private') 10 siffror, börjar med 07
                            @else 10 siffror, börjar med 123
                            @endif
                        </p>
                        @error('swishNumber') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">Typ</label>
                        <div class="mt-1 flex flex-col gap-1.5">
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="radio" wire:model.live="swishType" value="private"
                                       class="mt-0.5 border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                    Swish Privat
                                    <span class="block text-xs text-zinc-400">Mobilnummer (07…), manuell betalning</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="radio" wire:model.live="swishType" value="company"
                                       class="mt-0.5 border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                    Swish Företag
                                    <span class="block text-xs text-zinc-400">Organisationsnummer (123…), manuell betalning</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="radio" wire:model.live="swishType" value="merchant"
                                       class="mt-0.5 border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                    Swish Handel
                                    <span class="block text-xs text-zinc-400">Organisationsnummer (123…), API-betalningar</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
                @if($swishType === 'merchant')
                    <div class="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20"
                         x-data="{ showSwishPassphrase: false }">
                        <p class="text-xs text-amber-700 dark:text-amber-400">
                            Swish Handel kräver ett handlaravtal och ett API-certifikat (.p12-fil) från Swish.
                            Används nummer <span class="font-mono">1234679304</span> (Swish's publika testnummer för MSS-sandboxen) väljer appen automatiskt sandbox-API istället för produktion.
                        </p>

                        <div>
                            <label class="lbl">Certifikat <span class="font-normal text-zinc-400">(.p12/.pfx)</span></label>
                            <div class="mt-1 flex items-center gap-3">
                                <input type="file" wire:model="swishCertificateFile" accept=".p12,.pfx"
                                       class="block w-full text-xs text-zinc-600 dark:text-zinc-400" />
                                @if($this->hasSwishCertificate())
                                    <button type="button" wire:click="removeSwishCertificate"
                                            wire:confirm="Ta bort det uppladdade certifikatet?"
                                            class="shrink-0 rounded-lg border border-red-200 px-2.5 py-1 text-xs text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20">
                                        Ta bort
                                    </button>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                                @if($this->hasSwishCertificate())
                                    <span class="text-green-600 dark:text-green-400">Certifikat uppladdat.</span> Ladda upp en ny fil för att ersätta det.
                                @else
                                    Inget certifikat uppladdat — Swish Handel-betalningar är inte möjliga förrän ett laddas upp.
                                @endif
                            </p>
                            @error('swishCertificateFile') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="lbl">Certifikat-lösenord</label>
                            <div class="mt-1 flex gap-2">
                                <input wire:model="swishCertificatePassphrase" :type="showSwishPassphrase ? 'text' : 'password'"
                                       class="inp flex-1 font-mono text-xs @error('swishCertificatePassphrase') border-red-300 @enderror" />
                                <button type="button" @click="showSwishPassphrase = !showSwishPassphrase"
                                        class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs text-zinc-500 hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-800"
                                        :aria-label="showSwishPassphrase ? 'Dölj lösenord' : 'Visa lösenord'">
                                    <x-bootstrap-icon name="eye" class="size-4" x-show="!showSwishPassphrase" />
                                    <x-bootstrap-icon name="eye-slash" class="size-4" x-show="showSwishPassphrase" x-cloak />
                                </button>
                            </div>
                            @error('swishCertificatePassphrase') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <label class="flex cursor-pointer items-start gap-2">
                            <input type="checkbox" wire:model="swishEmulatorEnabled"
                                   class="mt-0.5 rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                            <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                Aktivera Swish-emulator
                                <span class="block text-xs text-amber-700 dark:text-amber-400">Hoppar helt över Swish API — ingen riktig betalning sker. Endast för dev/test, aldrig i produktion.</span>
                            </span>
                        </label>
                    </div>
                @endif
            </div>

            {{-- Bankgiro --}}
            <div class="space-y-3 p-5">
                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Bankgiro</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="lbl">
                            Bankgironummer
                            <span class="font-normal text-zinc-400">(tomt = dölj BG)</span>
                        </label>
                        <input wire:model="bankgiro" type="text" placeholder="123-4567"
                               class="inp mt-1 @error('bankgiro') border-red-300 @enderror" />
                        @error('bankgiro') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">Referenstyp</label>
                        <div class="mt-1 flex flex-col gap-1.5">
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="radio" wire:model="bankgiroReferenceType" value="message"
                                       class="mt-0.5 border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                    Meddelande
                                    <span class="block text-xs text-zinc-400">Betalaren skriver referensen som ett fritt meddelande</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-2">
                                <input type="radio" wire:model="bankgiroReferenceType" value="ocr"
                                       class="mt-0.5 border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">
                                    OCR-nummer
                                    <span class="block text-xs text-zinc-400">Strukturerat OCR-nummer med kontrollsiffra, möjliggör automatisk matchning vid filimport</span>
                                </span>
                            </label>
                        </div>
                        @error('bankgiroReferenceType') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- Stripe --}}
            <div class="p-5">
                <label class="flex cursor-pointer items-center gap-2.5">
                    <input type="checkbox" wire:model="stripeEnabled"
                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600">
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Aktivera kortbetalning via Stripe</span>
                </label>
            </div>

        </div>

        <hr class="border-zinc-100 dark:border-zinc-800">

        {{-- Stripe-konfiguration --}}
        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Stripe-konfiguration</h2>
        </div>
        <div class="space-y-4 p-5" x-data="{ showSecret: false, showWebhook: false, copiedWebhookUrl: false }">
            <p class="text-xs text-zinc-400 dark:text-zinc-500">
                Nycklar hittas i
                <span class="font-mono">dashboard.stripe.com → Developers → API keys</span>.
                Använd testnycklar (<span class="font-mono">pk_test_</span> / <span class="font-mono">sk_test_</span>) i utvecklingsmiljö.
            </p>
            <div>
                <label class="lbl">Webhook-URL <span class="font-normal text-zinc-400">(ange denna i Stripe-dashboarden)</span></label>
                <div class="mt-1 flex gap-2">
                    <input type="text" readonly value="{{ route('stripe.webhook') }}"
                           onclick="this.select()"
                           class="inp flex-1 cursor-text font-mono text-xs" />
                    <button type="button"
                            x-on:click="navigator.clipboard.writeText({{ Js::from(route('stripe.webhook')) }}).then(() => { copiedWebhookUrl = true; setTimeout(() => copiedWebhookUrl = false, 2000) })"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs text-zinc-500 hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-800"
                            aria-label="Kopiera webhook-URL">
                        <template x-if="!copiedWebhookUrl">
                            <x-bootstrap-icon name="clipboard" class="size-4" />
                        </template>
                        <template x-if="copiedWebhookUrl">
                            <x-bootstrap-icon name="clipboard-check" class="size-4 text-green-600" />
                        </template>
                    </button>
                </div>
                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                    Skapas under <span class="font-mono">Developers → Webhooks → Add endpoint</span> med den här URL:n.
                    Events att skicka: <span class="font-mono">checkout.session.completed</span> och <span class="font-mono">checkout.session.expired</span>
                    (den sistnämnda skickas inte automatiskt om du inte väljer "receive all events" eller kryssar den explicit).
                </p>
            </div>
            <div>
                <label class="lbl">Publiceringsnyckel <span class="font-normal text-zinc-400">(pk_live_… eller pk_test_…)</span></label>
                <input wire:model="stripePublishableKey" type="text" placeholder="pk_live_…"
                       class="inp mt-1 font-mono text-xs @error('stripePublishableKey') border-red-300 @enderror" />
                @error('stripePublishableKey') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lbl">Hemlig nyckel <span class="font-normal text-zinc-400">(sk_live_… eller sk_test_…)</span></label>
                <div class="mt-1 flex gap-2">
                    <input wire:model="stripeSecretKey" :type="showSecret ? 'text' : 'password'" placeholder="sk_live_…"
                           class="inp flex-1 font-mono text-xs @error('stripeSecretKey') border-red-300 @enderror" />
                    <button type="button" @click="showSecret = !showSecret"
                            class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs text-zinc-500 hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-800"
                            :aria-label="showSecret ? 'Dölj nyckel' : 'Visa nyckel'">
                        <x-bootstrap-icon name="eye" class="size-4" x-show="!showSecret" />
                        <x-bootstrap-icon name="eye-slash" class="size-4" x-show="showSecret" x-cloak />
                    </button>
                </div>
                @error('stripeSecretKey') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lbl">Webhook-hemlighet <span class="font-normal text-zinc-400">(whsec_…)</span></label>
                <div class="mt-1 flex gap-2">
                    <input wire:model="stripeWebhookSecret" :type="showWebhook ? 'text' : 'password'" placeholder="whsec_…"
                           class="inp flex-1 font-mono text-xs @error('stripeWebhookSecret') border-red-300 @enderror" />
                    <button type="button" @click="showWebhook = !showWebhook"
                            class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs text-zinc-500 hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-800"
                            :aria-label="showWebhook ? 'Dölj hemlighet' : 'Visa hemlighet'">
                        <x-bootstrap-icon name="eye" class="size-4" x-show="!showWebhook" />
                        <x-bootstrap-icon name="eye-slash" class="size-4" x-show="showWebhook" x-cloak />
                    </button>
                </div>
                <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">Sätts upp under <span class="font-mono">Developers → Webhooks</span> i Stripe-dashboarden.</p>
                @error('stripeWebhookSecret') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

    </div>

    <div class="flex items-center justify-end gap-3">
        @if($saved)
            <span class="flex items-center gap-1.5 text-sm text-green-600 dark:text-green-400">
                <x-bootstrap-icon name="check-circle-fill" class="size-4" />
                Sparat
            </span>
        @endif
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="save">Spara</span>
            <span wire:loading wire:target="save">Sparar…</span>
        </button>
    </div>
</form>
