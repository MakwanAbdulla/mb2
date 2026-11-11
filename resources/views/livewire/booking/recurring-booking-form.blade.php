<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center gap-3">
        <a href="{{ route('booking.admin.recurring-bookings.index') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Serier</a>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            {{ $editingId ? 'Redigera återkommande bokning' : 'Ny återkommande bokning' }}
        </h1>
    </div>

    @if ($saved)
        <div class="rounded-lg border border-green-200 dark:border-green-700 bg-green-50 dark:bg-green-950 px-4 py-4">
            <p class="font-semibold text-green-800 dark:text-green-300">
                {{ $editingId ? 'Serien uppdaterades.' : 'Serien skapades.' }}
            </p>
            <p class="mt-1 text-sm text-green-700 dark:text-green-400">{{ $created }} tillfällen genererades.</p>
            @if ($conflicts)
                <div class="mt-3 rounded border border-amber-300 dark:border-amber-600 bg-amber-50 dark:bg-amber-900/40 px-3 py-2">
                    <p class="text-xs font-semibold text-amber-700 dark:text-amber-300 mb-1">
                        {{ count($conflicts) }} tillfällen hoppades över (krockar):
                    </p>
                    <ul class="text-xs text-amber-700 dark:text-amber-400 space-y-0.5">
                        @foreach ($conflicts as $c)
                            <li>{{ $c['date'] }} — {{ $c['resource_name'] }}
                                @if ($c['blocking_title']) ({{ $c['blocking_title'] }}) @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <a href="{{ route('booking.admin.recurring-bookings.index') }}" wire:navigate
               class="mt-2 inline-block text-sm text-green-700 dark:text-green-400 underline">Tillbaka till listan</a>
        </div>
    @endif

    <form wire:submit="save" class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-5">

        @php $inp = 'w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron'; @endphp

        {{-- Rum (multi-select checkboxes) --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Rum *</label>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                @foreach ($rooms as $room)
                    <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-800/40 has-[:checked]:border-brand has-[:checked]:bg-brand/5">
                        <input type="checkbox" wire:model="resourceIds" value="{{ $room->id }}"
                               class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron">
                        @if ($room->icon)
                            <x-bootstrap-icon name="{{ $room->icon }}" class="size-4 shrink-0 text-zinc-400" />
                        @endif
                        <span class="truncate">{{ $room->name }}</span>
                    </label>
                @endforeach
            </div>
            @error('resourceIds') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Titel + Aktivitet --}}
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Titel *</label>
                <input wire:model="title" type="text"
                       class="{{ $inp }} @error('title') border-red-300 dark:border-red-600 @enderror" />
                @error('title') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Aktivitet *</label>
                <select wire:model="activity" class="{{ $inp }} @error('activity') border-red-300 dark:border-red-600 @enderror">
                    <option value="">Välj…</option>
                    @foreach ($activities as $act)
                        <option value="{{ $act }}">{{ $act }}</option>
                    @endforeach
                </select>
                @error('activity') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Beskrivning --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Beskrivning <span class="font-normal text-zinc-400">(valfri)</span></label>
            <textarea wire:model="description" rows="2" class="{{ $inp }}"></textarea>
            @error('description') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Tider --}}
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Starttid *</label>
                <select wire:model="startTime" class="{{ $inp }}">
                    @for ($i = 0; $i <= 47; $i++)
                        @php $val = sprintf('%02d:%02d', intdiv($i, 2), ($i % 2) * 30); @endphp
                        <option value="{{ $val }}">{{ $val }}</option>
                    @endfor
                </select>
                @error('startTime') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Sluttid *</label>
                <select wire:model="endTime" class="{{ $inp }}">
                    @for ($i = 1; $i <= 48; $i++)
                        @php $val = $i === 48 ? '24:00' : sprintf('%02d:%02d', intdiv($i, 2), ($i % 2) * 30); @endphp
                        <option value="{{ $val }}">{{ $val }}</option>
                    @endfor
                </select>
                @error('endTime') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Frekvens --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Frekvens *</label>
            <div class="flex flex-wrap gap-4">
                @foreach ([
                    'weekly'           => 'Varje vecka',
                    'monthly_weekday'  => 'Månadsvis (veckodag)',
                    'monthly_date'     => 'Månadsvis (datum)',
                    'yearly'           => 'Årsvis (datum)',
                    'yearly_week'      => 'Årsvis (ISO-vecka)',
                ] as $val => $label)
                    <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300 cursor-pointer">
                        <input type="radio" wire:model.live="frequencyType" value="{{ $val }}"
                               class="text-brand focus:ring-brand-saffron">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </div>

        {{-- WEEKLY: checkbox veckodagar + intervall --}}
        @if ($frequencyType === 'weekly')
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Veckodagar *</label>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Livewire\Booking\RecurringBookingForm::WEEKDAYS as $num => $name)
                        <label class="flex items-center gap-1.5 text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800/40 has-[:checked]:border-brand has-[:checked]:bg-brand/5">
                            <input type="checkbox" wire:model="weekdays" value="{{ $num }}"
                                   class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron">
                            {{ $name }}
                        </label>
                    @endforeach
                </div>
                @error('weekdays') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="w-48">
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Var n:te vecka *</label>
                <select wire:model="weekInterval" class="{{ $inp }}">
                    @foreach ([1 => 'Varje vecka', 2 => 'Varannan', 3 => 'Var tredje', 4 => 'Var fjärde', 5 => 'Var femte', 6 => 'Var sjätte'] as $n => $lbl)
                        <option value="{{ $n }}">{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        {{-- MONTHLY_WEEKDAY: veckodag + förekomst + intervall --}}
        @if ($frequencyType === 'monthly_weekday')
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Veckodag *</label>
                    <select wire:model="weekday" class="{{ $inp }}">
                        @foreach (\App\Livewire\Booking\RecurringBookingForm::WEEKDAYS as $num => $name)
                            <option value="{{ $num }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('weekday') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Förekomst i månaden *</label>
                    <select wire:model="monthWeek" class="{{ $inp }}">
                        @foreach (\App\Livewire\Booking\RecurringBookingForm::WEEK_NUMBERS as $n => $lbl)
                            <option value="{{ $n }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                    @error('monthWeek') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Månadsintervall</label>
                    <select wire:model="monthInterval" class="{{ $inp }}">
                        @foreach ([1 => 'Varje månad', 2 => 'Varannan', 3 => 'Var 3:e', 4 => 'Tertialvis', 6 => 'Var 6:e'] as $n => $lbl)
                            <option value="{{ $n }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif

        {{-- MONTHLY_DATE: dag i månaden + intervall --}}
        @if ($frequencyType === 'monthly_date')
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Dag i månaden *</label>
                    <select wire:model="monthDay" class="{{ $inp }}">
                        @for ($d = 1; $d <= 31; $d++)
                            <option value="{{ $d }}">{{ $d }}</option>
                        @endfor
                    </select>
                    @error('monthDay') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Månadsintervall</label>
                    <select wire:model="monthInterval" class="{{ $inp }}">
                        @foreach ([1 => 'Varje månad', 2 => 'Varannan', 3 => 'Var 3:e', 4 => 'Tertialvis', 6 => 'Var 6:e'] as $n => $lbl)
                            <option value="{{ $n }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif

        {{-- YEARLY: dag + månad --}}
        @if ($frequencyType === 'yearly')
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Dag i månaden *</label>
                    <select wire:model="monthDay" class="{{ $inp }}">
                        @for ($d = 1; $d <= 31; $d++)
                            <option value="{{ $d }}">{{ $d }}</option>
                        @endfor
                    </select>
                    @error('monthDay') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Månad *</label>
                    <select wire:model="month" class="{{ $inp }}">
                        @foreach (\App\Livewire\Booking\RecurringBookingForm::MONTHS as $n => $name)
                            <option value="{{ $n }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('month') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        {{-- YEARLY_WEEK: ISO-vecka + veckodag --}}
        @if ($frequencyType === 'yearly_week')
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">ISO-vecka (1–53) *</label>
                    <input wire:model="weekNumber" type="number" min="1" max="53"
                           class="{{ $inp }} @error('weekNumber') border-red-300 dark:border-red-600 @enderror" />
                    @error('weekNumber') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Veckodag *</label>
                    <select wire:model="weekday" class="{{ $inp }}">
                        @foreach (\App\Livewire\Booking\RecurringBookingForm::WEEKDAYS as $num => $name)
                            <option value="{{ $num }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('weekday') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        {{-- Slutar --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Serien slutar <span class="font-normal text-zinc-400">(valfritt — tomt = ett år framåt)</span>
            </label>
            <input wire:model="endsOn" type="date"
                   class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron @error('endsOn') border-red-300 @enderror" />
            @error('endsOn') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Bokningstyp --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">Typ av arrangemang *</label>
            <div class="flex gap-6">
                <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300 cursor-pointer">
                    <input type="radio" wire:model.live="bookingType" value="group"
                           class="text-brand focus:ring-brand-saffron">
                    Fylld grupp
                </label>
                <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300 cursor-pointer">
                    <input type="radio" wire:model.live="bookingType" value="open"
                           class="text-brand focus:ring-brand-saffron">
                    Öppet arrangemang
                </label>
            </div>
        </div>

        @if ($bookingType === 'open')
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    Deltagarinformation <span class="font-normal text-zinc-400">(valfritt)</span>
                </label>
                <textarea wire:model="participationInfo" rows="2" class="{{ $inp }}"></textarea>
            </div>
        @endif

        {{-- Anteckningar --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Interna anteckningar <span class="font-normal text-zinc-400">(valfritt)</span>
            </label>
            <textarea wire:model="notes" rows="2" class="{{ $inp }}"></textarea>
        </div>

        {{-- Submit --}}
        <div class="flex items-center justify-between pt-1">
            <a href="{{ route('booking.admin.recurring-bookings.index') }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">Avbryt</a>
            <button type="submit"
                    class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors disabled:opacity-50"
                    wire:loading.attr="disabled">
                <span wire:loading.remove>{{ $editingId ? 'Spara och regenerera' : 'Skapa serie' }}</span>
                <span wire:loading>Skapar…</span>
            </button>
        </div>

    </form>

</div>
