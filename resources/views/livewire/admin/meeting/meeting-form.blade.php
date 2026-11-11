<div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 space-y-6">

    <div class="flex items-center gap-3">
        <a href="{{ route('admin.meetings.index') }}" wire:navigate
           class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">← Möten</a>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            {{ $meeting ? 'Redigera möte' : 'Nytt möte' }}
        </h1>
    </div>

    <form wire:submit="save"
          class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-sm p-6 space-y-5">

        @php $inp = 'w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm text-sm focus:border-brand focus:ring-1 focus:ring-brand-saffron px-3 py-2'; @endphp

        {{-- Mall (bara vid skapande) --}}
        @if (!$meeting)
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    Mall <span class="font-normal text-zinc-400">(valfritt)</span>
                </label>
                <select wire:model.live="templateId" class="{{ $inp }}">
                    <option value="">Ingen mall — tom dagordning</option>
                    @foreach ($templates as $t)
                        <option value="{{ $t->id }}">{{ $t->name }}{{ $t->section ? ' ('.$t->section->name.')' : '' }}</option>
                    @endforeach
                </select>
                @if ($templateId)
                    <label class="mt-2 flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300 cursor-pointer">
                        <input wire:model="importFromTemplate" type="checkbox"
                               class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron" />
                        Importera agendapunkter från mallen
                    </label>
                @endif
            </div>
        @endif

        {{-- Titel --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Rubrik *</label>
            <input wire:model="title" type="text"
                   class="{{ $inp }} @error('title') border-red-300 dark:border-red-600 @enderror" />
            @error('title') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Typ + Status --}}
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Mötestyp *</label>
                <select wire:model.live="meetingType" class="{{ $inp }}">
                    @foreach ($meetingTypes as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Status</label>
                <select wire:model="status" class="{{ $inp }}">
                    @foreach ($statuses as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Datum + Sluttid + Plats --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Starttid *</label>
                <input wire:model="scheduledAt" type="datetime-local"
                       class="{{ $inp }} @error('scheduledAt') border-red-300 dark:border-red-600 @enderror" />
                @error('scheduledAt') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Sluttid *</label>
                <input wire:model="endsAt" type="datetime-local"
                       class="{{ $inp }} @error('endsAt') border-red-300 dark:border-red-600 @enderror" />
                @error('endsAt') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Plats + Lokal --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    Plats <span class="font-normal text-zinc-400">(valfritt)</span>
                </label>
                <input wire:model="location" type="text" class="{{ $inp }}" />
            </div>
            @if($bookingResources->isNotEmpty())
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                    Lokal (rumsbokning) <span class="font-normal text-zinc-400">(valfritt)</span>
                </label>
                <select wire:model="bookingResourceId" class="{{ $inp }} @error('bookingResourceId') border-red-300 dark:border-red-600 @enderror">
                    <option value="">— Ingen rumsbokning —</option>
                    @foreach($bookingResources as $resource)
                        <option value="{{ $resource->id }}">{{ $resource->name }}{{ $resource->location ? " · {$resource->location}" : '' }}</option>
                    @endforeach
                </select>
                @error('bookingResourceId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            @endif
        </div>

        {{-- Motionsfrist (bara för möten som tar emot motioner) --}}
        @if (in_array($meetingType, \App\Models\Meeting\MeetingTemplate::MOTION_TYPES))
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Sista dag för motioner
                <span class="font-normal text-zinc-400">(valfritt)</span>
            </label>
            <input wire:model="motionDeadline" type="date"
                   class="{{ $inp }} @error('motionDeadline') border-red-300 dark:border-red-600 @enderror" />
            @error('motionDeadline') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            <p class="mt-1 text-xs text-zinc-400">Motioner stängs automatiskt efter detta datum.</p>
        </div>
        @endif

        {{-- Sektion --}}
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">
                Sektion <span class="font-normal text-zinc-400">(valfritt)</span>
            </label>
            <select wire:model="sectionId" class="{{ $inp }}">
                <option value="">Ingen sektion — föreningsnivå</option>
                @foreach ($sections as $section)
                    <option value="{{ $section->id }}">{{ $section->name }}</option>
                @endforeach
            </select>
        </div>

        {{-- Extra / ordinarie --}}
        <div class="flex items-center gap-3">
            <input wire:model="isExtraordinary" type="checkbox" id="extraordinary"
                   class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron" />
            <label for="extraordinary" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
                Extramöte
            </label>
        </div>

        {{-- Google Meet --}}
        @if($workspaceConfigured && !($meeting?->meet_link))
        <div class="flex items-center gap-3">
            <input wire:model="createMeetLink" type="checkbox" id="createMeetLink"
                   class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron" />
            <label for="createMeetLink" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
                Skapa Google Meet-länk
            </label>
        </div>
        @error('createMeetLink')
            <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror
        @endif

        {{-- Frånvaro i protokoll --}}
        <div class="flex items-center gap-3">
            <input wire:model="includeAbsencesInMinutes" type="checkbox" id="absences"
                   class="rounded border-zinc-300 dark:border-zinc-600 text-brand focus:ring-brand-saffron" />
            <label for="absences" class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer">
                Visa frånvarande i protokollet
            </label>
        </div>

        {{-- Submit --}}
        <div class="flex items-center justify-between pt-1">
            <a href="{{ route('admin.meetings.index') }}" wire:navigate
               class="text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">Avbryt</a>
            <button type="submit"
                    class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron transition-colors disabled:opacity-50"
                    wire:loading.attr="disabled">
                <span wire:loading.remove>{{ $meeting ? 'Spara' : 'Skapa möte' }}</span>
                <span wire:loading>Sparar…</span>
            </button>
        </div>

    </form>

</div>
