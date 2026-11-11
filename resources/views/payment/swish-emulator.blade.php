<x-layouts.onboarding>

<div class="container mx-auto max-w-xl px-4 py-12 sm:px-6">

    {{-- Emulator warning banner — always visible while this page is reachable --}}
    <div class="mb-6 rounded-xl border-2 border-dashed border-amber-400 bg-amber-50 p-4 dark:border-amber-600 dark:bg-amber-900/20">
        <div class="flex items-center gap-2 text-amber-800 dark:text-amber-300">
            <x-bootstrap-icon name="exclamation-triangle-fill" class="size-5 shrink-0" />
            <p class="text-sm font-bold">SWISH-EMULATOR AKTIV — ingen riktig betalning sker</p>
        </div>
        <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">
            "Aktivera Swish-emulator" är ikryssat under Admin → Inställningar → Betalningar i den här miljön. Detta ska aldrig vara ikryssat i produktion.
        </p>
    </div>

    <div class="text-center">
        <div class="mx-auto mb-6 flex size-16 items-center justify-center rounded-full bg-[#4db848]/10">
            <x-bootstrap-icon name="phone" class="size-8 text-[#4db848]" />
        </div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Swish-betalning (emulerad)</h1>
        <p class="mt-2 text-zinc-500 dark:text-zinc-400">
            Simulerar vad som händer i den riktiga Swish-appen. Välj om "betalningen" ska godkännas eller nekas.
        </p>
    </div>

    <div class="mt-8 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="flex items-center justify-between px-5 py-3">
            <span class="text-sm text-zinc-600 dark:text-zinc-400">Belopp</span>
            <span class="text-lg font-bold text-zinc-900 dark:text-white">
                {{ number_format($order->total_ore / 100, 2, ',', ' ') }} kr
            </span>
        </div>
        <div class="flex items-center justify-between border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
            <span class="text-sm text-zinc-600 dark:text-zinc-400">Referens</span>
            <span class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">{{ $order->payment_reference }}</span>
        </div>
    </div>

    <div class="mt-6 flex gap-3">
        <form method="POST" action="{{ route('payment.swish.emulator.approve') }}" class="flex-1">
            @csrf
            <button type="submit"
                    class="w-full rounded-lg bg-[#4db848] px-5 py-3 text-sm font-semibold text-white hover:bg-[#3da038]">
                Godkänn (fejk)
            </button>
        </form>
        <form method="POST" action="{{ route('payment.swish.emulator.decline') }}" class="flex-1">
            @csrf
            <button type="submit"
                    class="w-full rounded-lg border border-red-300 bg-white px-5 py-3 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-800 dark:bg-zinc-900 dark:text-red-400 dark:hover:bg-red-900/20">
                Neka (fejk)
            </button>
        </form>
    </div>

</div>

</x-layouts.onboarding>
