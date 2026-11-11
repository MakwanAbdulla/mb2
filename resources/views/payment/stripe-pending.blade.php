<x-layouts.onboarding>

<div class="container mx-auto max-w-xl px-4 py-12 sm:px-6"
     x-data="{ checking: true }"
     x-init="
         let attempts = 0;
         const successUrl = '{{ route('payment.stripe.success') }}?session_id={{ $sessionId }}';
         const interval = setInterval(async () => {
             attempts++;
             try {
                 const res = await fetch(successUrl, { redirect: 'follow' });
                 if (res.url && !res.url.includes('/bli-medlem/betala/stripe')) {
                     clearInterval(interval);
                     window.location.href = res.url;
                     return;
                 }
             } catch (_) {}
             if (attempts >= 20) { clearInterval(interval); checking = false; }
         }, 3000);
     ">

    <div class="text-center">
        <div class="mx-auto mb-6 flex size-16 items-center justify-center rounded-full bg-brand/10">
            <x-bootstrap-icon name="hourglass-split" class="size-8 text-brand-orange" />
        </div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Betalning behandlas</h1>
        <p class="mt-3 text-zinc-500 dark:text-zinc-400">
            Vi har tagit emot din betalning och håller på att bekräfta den. Det tar normalt bara några sekunder.
        </p>
    </div>

    <div x-show="checking" class="mt-8 flex items-center justify-center gap-3 text-sm text-zinc-500 dark:text-zinc-400">
        <svg class="size-5 animate-spin text-brand-orange" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
        </svg>
        Väntar på bekräftelse…
    </div>

    <div x-show="!checking" class="mt-8 rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-800/40 dark:bg-amber-900/20">
        <p class="text-sm text-amber-800 dark:text-amber-300">
            Det dröjer lite längre än vanligt. Ditt konto aktiveras automatiskt när betalningen bekräftats.
            Du kan stänga den här sidan — du får ett e-postmeddelande när allt är klart.
        </p>
        <a href="{{ route('dashboard') }}"
           class="mt-4 inline-block rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
            Till min sida
        </a>
    </div>

</div>

</x-layouts.onboarding>
