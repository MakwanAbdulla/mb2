<x-layouts.onboarding>

<div class="container mx-auto max-w-xl px-4 py-12 sm:px-6"
     x-data="{
         status: 'waiting',
         showQr: false,
         isMobile: navigator.maxTouchPoints > 0 || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)
     }"
     x-init="
         const statusUrl = '{{ route('payment.swish.status') }}';
         const doneUrl   = '{{ route('membership.show') }}';

         // On touch devices, auto-open the Swish deep link
         if (isMobile && {{ $token ? 'true' : 'false' }}) {
             setTimeout(() => {
                 window.location.href = 'swish://paymentrequest?token={{ $token }}';
             }, 800);
         }

         // Poll for payment confirmation
         let attempts = 0;
         const interval = setInterval(async () => {
             attempts++;
             try {
                 const res = await fetch(statusUrl, {
                     headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                 });
                 const data = await res.json();
                 if (data.status === 'PAID') {
                     clearInterval(interval);
                     status = 'paid';
                     setTimeout(() => window.location.href = doneUrl, 800);
                 } else if (['DECLINED', 'ERROR', 'CANCELLED'].includes(data.status)) {
                     clearInterval(interval);
                     status = data.status.toLowerCase();
                 }
             } catch (_) {}
             if (attempts >= 40) { clearInterval(interval); status = 'timeout'; }
         }, 3000);
     ">

    {{-- Waiting --}}
    <div x-show="status === 'waiting'">

        <div class="text-center">
            <div class="mx-auto mb-6 flex size-16 items-center justify-center rounded-full bg-[#4db848]/10">
                <x-bootstrap-icon name="phone" class="size-8 text-[#4db848]" />
            </div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Godkänn i Swish-appen</h1>
        </div>

        {{-- Mobile: deep link primary --}}
        <div x-show="isMobile" class="mt-6 space-y-4">
            <p class="text-center text-zinc-500 dark:text-zinc-400">
                Swish öppnas automatiskt. Om appen inte öppnas, tryck på knappen nedan.
            </p>
            @if($token)
                <div class="text-center">
                    <a href="swish://paymentrequest?token={{ $token }}"
                       class="inline-flex items-center gap-2 rounded-lg bg-[#4db848] px-6 py-3 text-sm font-semibold text-white hover:bg-[#3da038]">
                        <x-bootstrap-icon name="phone" class="size-4" />
                        Öppna Swish-appen
                    </a>
                </div>

                {{-- QR fallback toggle on mobile --}}
                @if($qrDataUrl)
                    <div class="text-center">
                        <button type="button" @click="showQr = !showQr"
                                class="text-sm text-zinc-400 underline hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                            <span x-show="!showQr">Ingen app installerad? Visa QR-kod</span>
                            <span x-show="showQr" x-cloak>Dölj QR-kod</span>
                        </button>
                        <div x-show="showQr" x-transition class="mt-4">
                            <p class="mb-3 text-sm text-zinc-500 dark:text-zinc-400">Scanna med Swish på en annan enhet.</p>
                            <div class="flex justify-center">
                                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                                    <img src="{{ $qrDataUrl }}" alt="Swish QR-kod" class="size-[240px]" />
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <p class="text-center text-sm text-zinc-400 dark:text-zinc-500">
                        Ingen app? Öppna Swish på en annan enhet och godkänn betalningsförfrågan manuellt.
                    </p>
                @endif
            @endif
        </div>

        {{-- Desktop: QR primary --}}
        <div x-show="!isMobile" class="mt-6">
            @if($qrDataUrl)
                <p class="mb-4 text-center text-zinc-500 dark:text-zinc-400">
                    Scanna QR-koden med Swish-appen på din telefon för att betala.
                </p>
                <div class="flex justify-center">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <img src="{{ $qrDataUrl }}" alt="Swish QR-kod" class="size-[300px]" />
                    </div>
                </div>
                @if($token)
                    <p class="mt-4 text-center text-xs text-zinc-400 dark:text-zinc-500">
                        Har du Swish på den här datorn?
                        <a href="swish://paymentrequest?token={{ $token }}" class="underline hover:text-zinc-600 dark:hover:text-zinc-300">Öppna appen</a>
                    </p>
                @endif
            @elseif($token)
                <p class="mb-4 text-center text-zinc-500 dark:text-zinc-400">
                    Öppna Swish-appen på din telefon och godkänn betalningsförfrågan.
                </p>
                <div class="mt-4 text-center">
                    <a href="swish://paymentrequest?token={{ $token }}"
                       class="inline-flex items-center gap-2 rounded-lg bg-[#4db848] px-6 py-3 text-sm font-semibold text-white hover:bg-[#3da038]">
                        <x-bootstrap-icon name="phone" class="size-4" />
                        Öppna Swish-appen
                    </a>
                </div>
            @endif
        </div>

        {{-- Spinner --}}
        <div class="mt-8 flex items-center justify-center gap-3 text-sm text-zinc-500 dark:text-zinc-400">
            <svg class="size-5 animate-spin text-[#4db848]" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
            </svg>
            Väntar på godkännande…
        </div>

    </div>

    {{-- Paid --}}
    <div x-show="status === 'paid'" x-cloak class="text-center">
        <div class="mx-auto mb-6 flex size-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
            <x-bootstrap-icon name="check-circle-fill" class="size-8 text-green-600 dark:text-green-400" />
        </div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Betalning godkänd!</h1>
        <p class="mt-3 text-zinc-500 dark:text-zinc-400">Du omdirigeras…</p>
    </div>

    {{-- Declined / Error / Cancelled --}}
    <div x-show="['declined','error','cancelled'].includes(status)" x-cloak>
        <div class="rounded-xl border border-red-200 bg-red-50 p-6 dark:border-red-800/40 dark:bg-red-900/20">
            <div class="flex gap-3">
                <x-bootstrap-icon name="x-circle-fill" class="mt-0.5 size-5 shrink-0 text-red-600 dark:text-red-400" />
                <div>
                    <p class="text-sm font-medium text-red-800 dark:text-red-300">Betalningen misslyckades</p>
                    <p class="mt-1 text-sm text-red-700 dark:text-red-400">
                        Swish-betalningen nekades eller avbröts. Gå tillbaka och försök igen.
                    </p>
                </div>
            </div>
        </div>
        <div class="mt-4">
            <a href="{{ route('membership.payment') }}"
               class="inline-block rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                Tillbaka till betalning
            </a>
        </div>
    </div>

    {{-- Timeout --}}
    <div x-show="status === 'timeout'" x-cloak class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-800/40 dark:bg-amber-900/20">
        <p class="text-sm text-amber-800 dark:text-amber-300">
            Vi fick ingen bekräftelse. Om du godkände betalningen i Swish aktiveras kontot automatiskt inom kort.
            Du kan stänga den här sidan — du får ett e-postmeddelande när allt är klart.
        </p>
        <a href="{{ route('dashboard') }}"
           class="mt-4 inline-block rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
            Till min sida
        </a>
    </div>

</div>

</x-layouts.onboarding>
