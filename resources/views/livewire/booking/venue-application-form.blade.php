<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Ansökan om lån av lokal</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Fyll i formuläret nedan. Ansökan granskas av en administratör och du får besked via e-post.</p>
    </div>

    @if ($success)
        <div class="rounded-lg border border-green-200 dark:border-green-700 bg-green-50 dark:bg-green-950 p-5">
            <p class="font-semibold text-green-800 dark:text-green-300">Din ansökan har skickats in!</p>
            <p class="mt-1 text-sm text-green-700 dark:text-green-400">En administratör granskar den och återkommer via e-post.</p>
            <a href="{{ route('home') }}" wire:navigate class="mt-3 inline-block text-sm text-green-700 dark:text-green-400 underline">Tillbaka till startsidan</a>
        </div>
    @else

        @if ($opensFrom && $opensFrom > now()->format('Y-m-d'))
            <div class="mb-4 rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/50 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
                Ansökningar kan gälla datum från {{ \Carbon\Carbon::parse($opensFrom)->locale('sv')->isoFormat('D MMMM YYYY') }}.
            </div>
        @endif

        @if ($conflictMessage)
            <div class="mb-4 rounded-lg border border-red-200 dark:border-red-700 bg-red-50 dark:bg-red-950 px-4 py-3 text-sm text-red-700 dark:text-red-300">
                {{ $conflictMessage }}
            </div>
        @endif

        <form wire:submit="save" class="space-y-6 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6">

            @php $inp = 'w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron'; @endphp

            {{-- Arrangemangets namn --}}
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Arrangemangets namn *</label>
                <input wire:model="eventName" type="text" class="{{ $inp }} @error('eventName') border-red-300 dark:border-red-600 @enderror" />
                @error('eventName') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- Beskrivning --}}
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Beskrivning <span class="font-normal text-zinc-400">(valfri)</span></label>
                <textarea wire:model="description" rows="2" class="{{ $inp }}"></textarea>
                @error('description') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- Ansvarig --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Ansvarig</label>
                    <p class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 px-3 py-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $responsibleName }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Telefon</label>
                    <input wire:model="responsiblePhone" type="tel" class="{{ $inp }}" />
                    @error('responsiblePhone') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">E-post</label>
                <p class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 px-3 py-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $responsibleEmail }}</p>
            </div>

            {{-- Startdatum & tid --}}
            <div>
                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Startdatum &amp; tid *</p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-zinc-500 dark:text-zinc-400 mb-1">Datum</label>
                        <input wire:model.live="startDate" type="date" min="{{ $minDate }}" class="{{ $inp }} @error('startDate') border-red-300 dark:border-red-600 @enderror" />
                        @error('startDate') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-500 dark:text-zinc-400 mb-1">Klockan</label>
                        <select wire:model="startTime" class="{{ $inp }}">
                            @for ($i = 0; $i <= 47; $i++)
                                @php $val = sprintf('%02d:%02d', intdiv($i, 2), ($i % 2) * 30); @endphp
                                <option value="{{ $val }}">{{ $val }}</option>
                            @endfor
                        </select>
                    </div>
                </div>
            </div>

            {{-- Slutdatum & tid --}}
            <div>
                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Slutdatum &amp; tid *</p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-zinc-500 dark:text-zinc-400 mb-1">Datum</label>
                        <input wire:model="endDate" type="date" class="{{ $inp }} @error('endDate') border-red-300 dark:border-red-600 @enderror" />
                        @error('endDate') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-500 dark:text-zinc-400 mb-1">Klockan</label>
                        <select wire:model="endTime" class="{{ $inp }}">
                            @for ($i = 1; $i <= 48; $i++)
                                @php $val = $i === 48 ? '24:00' : sprintf('%02d:%02d', intdiv($i, 2), ($i % 2) * 30); @endphp
                                <option value="{{ $val }}">{{ $val }}</option>
                            @endfor
                        </select>
                        @error('endTime') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- Deltagare + Aktivitet --}}
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Antal deltagare *</label>
                    <input wire:model="participants" type="number" min="1" max="100"
                           class="w-32 rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron @error('participants') border-red-300 @enderror" />
                    @error('participants') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Aktivitet *</label>
                    <select wire:model="activity" class="{{ $inp }} @error('activity') border-red-300 dark:border-red-600 @enderror">
                        <option value="">Välj aktivitet…</option>
                        @foreach ($activities as $act)
                            <option value="{{ $act }}">{{ $act }}</option>
                        @endforeach
                    </select>
                    @error('activity') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Lokal(er) --}}
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Välj lokal(er) *</label>
                <p class="text-xs text-zinc-400 mb-2">Välj vilka delar av lokalen som ska användas.</p>
                @if ($hasAlcohol)
                    <p class="text-xs text-amber-600 dark:text-amber-400 mb-2 font-medium">Alla lokaler måste ingå i ansökan när alkohol förtärs.</p>
                @endif
                <div class="space-y-2 mt-2">
                    @foreach ($resources as $resource)
                        <label class="flex items-center gap-2.5 {{ $hasAlcohol ? 'cursor-default' : 'cursor-pointer' }}">
                            <input type="checkbox" wire:model="resourceIds" value="{{ $resource->id }}"
                                   {{ $hasAlcohol ? 'disabled' : '' }}
                                   class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron disabled:opacity-60">
                            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $resource->name }}</span>
                            @if ($resource->location)
                                <span class="text-xs text-zinc-400">{{ $resource->location }}</span>
                            @endif
                        </label>
                    @endforeach
                </div>
                @error('resourceIds') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- Övernattning --}}
            <div>
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" wire:model="needsOvernight"
                           class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron">
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Övernattning behövs</span>
                </label>
                <p class="mt-1.5 ml-6 text-xs text-zinc-400">
                    Vid övernattning måste ni anmäla detta till SÄRF på
                    <a href="http://serf.se/skola-och-foreningsliv/overnattning/" target="_blank" rel="noopener"
                       class="underline hover:text-zinc-600 dark:hover:text-zinc-200">serf.se/…/overnattning</a>.
                </p>
            </div>

            {{-- Nyckel --}}
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Behöver nyckel lånas?</label>
                <div class="flex flex-wrap gap-4">
                    @foreach (['none' => 'Nej', 'kiosk' => 'Ja, till Kiosk', 'styrelserum' => 'Ja, till Styrelserum'] as $val => $label)
                        <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300 cursor-pointer">
                            <input type="radio" wire:model="needsKey" value="{{ $val }}"
                                   class="text-brand focus:ring-brand-saffron">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Alkohol --}}
            <div>
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" wire:model.live="hasAlcohol"
                           class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron">
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Alkohol kommer att förtäras</span>
                </label>
                <p class="mt-1.5 ml-6 text-xs text-zinc-400">
                    Om alkohol förtärs <em>måste</em> arrangemanget vara ett slutet sällskap och alla lokaler ingå i ansökan.
                </p>
            </div>

            {{-- Typ av arrangemang --}}
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Typ av arrangemang *</label>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300 {{ $hasAlcohol ? 'cursor-not-allowed opacity-50' : 'cursor-pointer' }}">
                        <input type="radio" wire:model.live="eventType" value="open"
                               {{ $hasAlcohol ? 'disabled' : '' }} class="text-brand focus:ring-brand-saffron">
                        Öppet arrangemang
                    </label>
                    <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300 cursor-pointer">
                        <input type="radio" wire:model.live="eventType" value="group"
                               class="text-brand focus:ring-brand-saffron">
                        Slutet sällskap
                    </label>
                </div>
                @if ($hasAlcohol)
                    <p class="mt-1 text-xs text-amber-600 dark:text-amber-400 font-medium">
                        Arrangemanget måste vara slutet sällskap när alkohol förtärs.
                    </p>
                @endif
                @error('eventType') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            @if ($eventType === 'group')
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Motivering för slutet sällskap <span class="font-normal text-zinc-400">(valfritt)</span></label>
                    <textarea wire:model="eventTypeReason" rows="2" placeholder="Kort motivering…"
                              class="{{ $inp }}"></textarea>
                    @error('eventTypeReason') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @endif

            {{-- Anteckningar --}}
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Övriga anteckningar <span class="font-normal text-zinc-400">(valfritt)</span></label>
                <textarea wire:model="notes" rows="3" class="{{ $inp }}"></textarea>
                @error('notes') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- Non-member notice --}}
            @if (! auth()->user()->isMember())
                <div class="rounded-lg border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/50 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
                    {{ \App\Models\SystemSetting::get('non_member_message', 'Ditt konto är inaktiverat för bokningar tills årets medlemsavgift är betald.') }}
                </div>
            @endif

            {{-- Submit --}}
            <div class="flex items-center justify-between pt-1">
                <a href="{{ route('home') }}" wire:navigate
                   class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">Avbryt</a>
                <button type="submit"
                        class="rounded-lg bg-brand px-6 py-2.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        wire:loading.attr="disabled"
                        @if (! auth()->user()->isMember()) disabled @endif>
                    <span wire:loading.remove>Skicka ansökan</span>
                    <span wire:loading>Skickar…</span>
                </button>
            </div>

        </form>
    @endif
</div>
