<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('booking.admin.venue-applications.index') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Tillbaka</a>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Lokalansökan</h1>
        @php
            [$cls, $lbl] = match($application->status) {
                'pending'   => ['bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300', 'Väntande'],
                'approved'  => ['bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-300', 'Godkänd'],
                'rejected'  => ['bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-400', 'Nekad'],
                'cancelled' => ['bg-zinc-100 dark:bg-zinc-700 text-zinc-500 dark:text-zinc-400', 'Avbruten'],
                default     => ['bg-zinc-100 dark:bg-zinc-700 text-zinc-500', $application->status],
            };
        @endphp
        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $cls }}">{{ $lbl }}</span>
    </div>

    @if ($errorMessage)
        <div class="rounded-lg border border-red-200 dark:border-red-700 bg-red-50 dark:bg-red-950 px-4 py-3 text-sm text-red-700 dark:text-red-300">
            {{ $errorMessage }}
        </div>
    @endif

    {{-- Ansökningsuppgifter --}}
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-5">

        @php $label = 'text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400'; @endphp

        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="{{ $label }}">Arrangemangets namn</p>
                <p class="mt-1 text-zinc-900 dark:text-white font-semibold text-lg">{{ $application->event_name }}</p>
            </div>
            <div>
                <p class="{{ $label }}">Inskickat av</p>
                <p class="mt-1 text-zinc-700 dark:text-zinc-300 text-sm">{{ $application->user->name }}</p>
                <p class="text-xs text-zinc-400">{{ $application->created_at->locale('sv')->isoFormat('D MMMM YYYY, H:mm') }}</p>
            </div>
        </div>

        <hr class="border-zinc-100 dark:border-zinc-800">

        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="{{ $label }}">Ansvarig</p>
                <p class="mt-1 text-zinc-700 dark:text-zinc-300 text-sm">{{ $application->responsible_name }}</p>
                @if ($application->responsible_phone)
                    <p class="text-sm text-zinc-400">{{ $application->responsible_phone }}</p>
                @endif
                <p class="text-sm text-zinc-400">{{ $application->responsible_email }}</p>
            </div>
            <div>
                <p class="{{ $label }}">Deltagare</p>
                <p class="mt-1 text-zinc-700 dark:text-zinc-300 text-sm">{{ $application->participants }} pers.</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="{{ $label }}">Start</p>
                <p class="mt-1 text-zinc-700 dark:text-zinc-300 text-sm tabular-nums">
                    {{ $application->starts_at->locale('sv')->isoFormat('dddd D MMMM YYYY') }}, {{ $application->starts_at->format('H:i') }}
                </p>
            </div>
            <div>
                <p class="{{ $label }}">Slut</p>
                <p class="mt-1 text-zinc-700 dark:text-zinc-300 text-sm tabular-nums">
                    {{ $application->ends_at->locale('sv')->isoFormat('dddd D MMMM YYYY') }}, {{ $application->ends_at->format('H:i') }}
                </p>
            </div>
        </div>

        <div>
            <p class="{{ $label }}">Begärda resurser</p>
            <div class="mt-1 flex flex-wrap gap-2">
                @foreach ($application->resource_ids as $rid)
                    <span class="rounded-full bg-zinc-100 dark:bg-zinc-700 px-2.5 py-0.5 text-xs text-zinc-700 dark:text-zinc-300">
                        {{ $resources->get($rid)?->name ?? 'Okänd resurs #'.$rid }}
                    </span>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4 text-sm">
            <div>
                <p class="{{ $label }}">Övernattning</p>
                <p class="mt-1 {{ $application->needs_overnight ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-400' }}">
                    {{ $application->needs_overnight ? 'Ja' : 'Nej' }}
                </p>
            </div>
            <div>
                <p class="{{ $label }}">Nyckel</p>
                <p class="mt-1 text-zinc-700 dark:text-zinc-300">
                    {{ match($application->needs_key) { 'none' => 'Nej', 'kiosk' => 'Ja, Kiosk', 'styrelserum' => 'Ja, Styrelserum', default => $application->needs_key } }}
                </p>
            </div>
            <div>
                <p class="{{ $label }}">Alkohol</p>
                <p class="mt-1 {{ $application->has_alcohol ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-400' }}">
                    {{ $application->has_alcohol ? 'Ja' : 'Nej' }}
                </p>
            </div>
        </div>

        <div>
            <p class="{{ $label }}">Typ av arrangemang</p>
            <p class="mt-1 text-zinc-700 dark:text-zinc-300 text-sm">
                {{ $application->event_type === 'open' ? 'Öppet arrangemang' : 'Slutet sällskap' }}
            </p>
            @if ($application->event_type_reason)
                <p class="mt-0.5 text-sm text-zinc-500 italic">{{ $application->event_type_reason }}</p>
            @endif
        </div>

        @if ($application->notes)
            <div>
                <p class="{{ $label }}">Anteckningar</p>
                <p class="mt-1 text-zinc-600 dark:text-zinc-300 text-sm whitespace-pre-wrap">{{ $application->notes }}</p>
            </div>
        @endif

    </div>

    {{-- Handläggning --}}
    @if ($application->status === 'pending')
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-4">
            <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 uppercase tracking-wide">Handläggning</h2>

            @if ($application->activity)
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Aktivitet</p>
                    <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $application->activity }}</p>
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    Interna anteckningar <span class="font-normal text-zinc-400">(valfritt)</span>
                </label>
                <textarea wire:model="adminNotes" rows="3" placeholder="Anteckningar synliga bara för admins…"
                          class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white placeholder-zinc-400 shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron"></textarea>
            </div>

            <div class="flex items-center gap-3 pt-1">
                <button wire:click="approve" wire:confirm="Godkänn ansökan och skapa bokningar?"
                        wire:loading.attr="disabled"
                        class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="approve">Godkänn och skapa bokningar</span>
                    <span wire:loading wire:target="approve">Skapar…</span>
                </button>
                <button wire:click="reject" wire:confirm="Neka ansökan?"
                        class="rounded-lg border border-red-200 dark:border-red-700 bg-white dark:bg-red-950 px-4 py-2 text-sm font-semibold text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-900 transition-colors">
                    Neka ansökan
                </button>
            </div>
        </div>
    @elseif ($application->status === 'approved')
        <div class="rounded-xl border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950 p-5">
            <p class="font-semibold text-green-800 dark:text-green-300">Ansökan godkänd</p>
            @if (! empty($application->booking_ids))
                <p class="text-sm text-green-700 dark:text-green-400 mt-1">Skapade {{ count($application->booking_ids) }} bokning(ar).</p>
            @endif
            @if ($application->admin_notes)
                <p class="text-sm text-green-600 dark:text-green-400 mt-2 italic">{{ $application->admin_notes }}</p>
            @endif
        </div>
    @elseif ($application->status === 'rejected')
        <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950 p-5">
            <p class="font-semibold text-red-800 dark:text-red-300">Ansökan nekad</p>
            @if ($application->admin_notes)
                <p class="text-sm text-red-600 dark:text-red-400 mt-1 italic">{{ $application->admin_notes }}</p>
            @endif
        </div>
    @endif

</div>
