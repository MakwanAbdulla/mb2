<?php

use App\Models\Accounting\CostCenter;
use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingResource;
use App\Models\Events\Event;
use App\Models\Events\EventOccurrence;
use App\Models\Membership\Member;
use App\Models\Organization\Section;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Skapa evenemang');

new class extends Component {

    // Basic
    public string $eventTitle       = '';
    public string $description      = '';
    public string $status           = Event::STATUS_DRAFT;
    public string $brand            = Event::BRAND_ARMAGEDON;
    public string $visibility       = Event::VISIBILITY_PUBLIC;
    public ?int   $visibilitySectionId = null;
    public bool   $requiresMembership  = false;
    public ?int   $accessSectionId     = null;
    public ?int   $minAge              = null;
    public ?int   $maxAge              = null;

    // Organizer
    public string $organizerExternalName = '';
    public ?int   $organizerSectionId    = null;
    public ?int   $costCenterId          = null;

    // Location
    public string  $locationType = Event::LOCATION_ROOM;
    public string  $locationName = '';
    public string  $locationUrl  = '';

    // Registration
    public string  $registrationMode  = Event::REGISTRATION_STANDARD;
    public string  $admissionType     = Event::ADMISSION_ON_PAYMENT;
    public bool    $allowTicketTransfer = false;
    public string  $waitlistMode       = Event::WAITLIST_NONE;
    public string  $registrationOpensAt  = '';
    public string  $registrationClosesAt = '';
    public string  $registrationInstructions = '';
    public bool    $notifyOrganizerOnRegistration = false;

    // Occurrences — array of rows
    public array $occurrences = [];

    // Price tiers — array of rows
    public array $priceTiers = [];

    // Addons — array of rows
    public array $eventAddons = [];

    #[Computed]
    public function costCenters()
    {
        return CostCenter::active()->orderBy('code')->get(['id', 'code', 'name']);
    }

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('events.create'), 403);

        if ($sectionId = request()->query('section')) {
            $this->organizerSectionId = (int) $sectionId;
        }

        // Start with one occurrence and one price tier
        $this->occurrences = [
            ['starts_at' => '', 'ends_at' => '', 'capacity' => '', 'location_override' => '', 'booking_resource_id' => null],
        ];
        $this->priceTiers = [
            ['label' => 'Standardbiljett', 'price_ore' => '0', 'capacity' => '', 'description' => ''],
        ];
    }

    #[Computed]
    public function bookingResources()
    {
        return BookingResource::where('active', true)->orderBy('name')->get(['id', 'name', 'location']);
    }

    public function addOccurrence(): void
    {
        $this->occurrences[] = ['starts_at' => '', 'ends_at' => '', 'capacity' => '', 'location_override' => '', 'booking_resource_id' => null];
    }

    public function removeOccurrence(int $index): void
    {
        if (count($this->occurrences) > 1) {
            array_splice($this->occurrences, $index, 1);
        }
    }

    public function addPriceTier(): void
    {
        $this->priceTiers[] = ['label' => '', 'price_ore' => '0', 'capacity' => '', 'description' => ''];
    }

    public function removePriceTier(int $index): void
    {
        if (count($this->priceTiers) > 1) {
            array_splice($this->priceTiers, $index, 1);
        }
    }

    public function addAddon(): void
    {
        $this->eventAddons[] = ['name' => '', 'description' => '', 'price_ore' => '0', 'options' => '', 'capacity' => ''];
    }

    public function removeAddon(int $index): void
    {
        array_splice($this->eventAddons, $index, 1);
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('events.create'), 403);

        $this->validate([
            'eventTitle'  => ['required', 'string', 'max:255'],
            'status'      => ['required', Rule::in([Event::STATUS_DRAFT, Event::STATUS_PUBLISHED])],
            'brand'       => ['required', Rule::in([Event::BRAND_ARMAGEDON, Event::BRAND_BSK])],
            'visibility'  => ['required', Rule::in([
                Event::VISIBILITY_PUBLIC, Event::VISIBILITY_MEMBERS_ONLY,
                Event::VISIBILITY_SECTION_ONLY, Event::VISIBILITY_INVITE_ONLY,
            ])],
            'locationType' => ['required', Rule::in([
                Event::LOCATION_ROOM, Event::LOCATION_VENUE_APPLICATION,
                Event::LOCATION_EXTERNAL, Event::LOCATION_DIGITAL,
            ])],
            'registrationMode' => ['required', Rule::in([
                Event::REGISTRATION_STANDARD, Event::REGISTRATION_DROPIN, Event::REGISTRATION_BOTH,
            ])],
            'admissionType' => ['required', Rule::in([
                Event::ADMISSION_ON_PAYMENT, Event::ADMISSION_AUTOMATIC, Event::ADMISSION_MANUAL,
            ])],
            'occurrences'              => ['required', 'array', 'min:1'],
            'occurrences.*.starts_at'  => ['required', 'date'],
            'occurrences.*.ends_at'    => ['required', 'date', 'after_or_equal:occurrences.*.starts_at'],
            'occurrences.*.capacity'   => ['nullable', 'integer', 'min:1'],
            'priceTiers'               => ['required', 'array', 'min:1'],
            'priceTiers.*.label'       => ['required', 'string', 'max:100'],
            'priceTiers.*.price_ore'   => ['required', 'integer', 'min:0'],
            'priceTiers.*.capacity'    => ['nullable', 'integer', 'min:1'],
            'eventAddons'              => ['nullable', 'array'],
            'eventAddons.*.name'       => ['required', 'string', 'max:100'],
            'eventAddons.*.price_ore'  => ['required', 'integer', 'min:0'],
            'eventAddons.*.capacity'   => ['nullable', 'integer', 'min:1'],
            'minAge'                   => ['nullable', 'integer', 'min:0', 'max:120'],
            'maxAge'                   => ['nullable', 'integer', 'min:0', 'max:120'],
            'registrationOpensAt'      => ['nullable', 'date'],
            'registrationClosesAt'     => ['nullable', 'date'],
        ], [
            'eventTitle.required'           => 'Ange en titel.',
            'occurrences.*.starts_at.required' => 'Ange starttid för alla tillfällen.',
            'occurrences.*.ends_at.required'   => 'Ange sluttid för alla tillfällen.',
            'occurrences.*.ends_at.after_or_equal' => 'Sluttid måste vara efter starttid.',
            'priceTiers.*.label.required'   => 'Ange namn på alla biljettyper.',
            'priceTiers.*.price_ore.required' => 'Ange pris för alla biljettyper (0 = gratis).',
        ]);

        // Pre-validate booking room availability
        $hasConflict = false;
        foreach ($this->occurrences as $i => $occ) {
            $resourceId = $occ['booking_resource_id'] ?? null;
            if (! $resourceId || empty($occ['starts_at']) || empty($occ['ends_at'])) continue;
            $resource = BookingResource::find($resourceId);
            if (! $resource) continue;
            if (! $resource->isAvailable(Carbon::parse($occ['starts_at']), Carbon::parse($occ['ends_at']))) {
                $this->addError("occurrences.{$i}.booking_resource_id", "\"{$resource->name}\" är redan bokad under det här tillfället.");
                $hasConflict = true;
            }
        }
        if ($hasConflict) return;

        $event = Event::create([
            'title'                  => $this->eventTitle,
            'description'            => $this->description ?: null,
            'status'                 => $this->status,
            'brand'                  => $this->brand,
            'visibility'             => $this->visibility,
            'visibility_section_id'  => $this->visibility === Event::VISIBILITY_SECTION_ONLY ? $this->visibilitySectionId : null,
            'requires_membership'    => $this->requiresMembership,
            'access_section_id'      => $this->accessSectionId,
            'min_age'                => $this->minAge,
            'max_age'                => $this->maxAge,
            'organizer_external_name' => $this->organizerExternalName ?: null,
            'organizer_section_id'   => $this->organizerSectionId,
            'cost_center_id'         => $this->costCenterId,
            'location_type'          => $this->locationType,
            'location_name'          => $this->locationName ?: null,
            'location_url'           => $this->locationUrl ?: null,
            'registration_mode'      => $this->registrationMode,
            'admission_type'         => $this->admissionType,
            'allow_ticket_transfer'  => $this->allowTicketTransfer,
            'waitlist_mode'          => $this->waitlistMode,
            'registration_opens_at'  => $this->registrationOpensAt ?: null,
            'registration_closes_at' => $this->registrationClosesAt ?: null,
            'registration_instructions' => $this->registrationInstructions ?: null,
            'notify_organizer_on_registration' => $this->notifyOrganizerOnRegistration,
        ]);

        // Attach current user's member as admin organizer
        $member = Auth::user()->member;
        if ($member) {
            $event->organizers()->attach($member->id, ['role' => Event::ORGANIZER_ADMIN]);
        }

        // Create occurrences and sync room bookings
        foreach ($this->occurrences as $sort => $occ) {
            $occurrence = $event->occurrences()->create([
                'starts_at'        => $occ['starts_at'],
                'ends_at'          => $occ['ends_at'],
                'max_participants'  => $occ['capacity'] ?: null,
                'location_override' => $occ['location_override'] ?: null,
            ]);
            $this->syncOccurrenceBooking($occurrence, $occ['booking_resource_id'] ?? null);
        }

        // Create price tiers
        foreach ($this->priceTiers as $sort => $tier) {
            $event->priceTiers()->create([
                'label'      => $tier['label'],
                'price_ore'  => (int) $tier['price_ore'],
                'capacity'   => $tier['capacity'] ?: null,
                'description' => $tier['description'] ?: null,
                'sort_order' => $sort,
            ]);
        }

        // Create addons
        foreach ($this->eventAddons as $sort => $addon) {
            $options = array_values(array_filter(
                array_map('trim', explode(',', $addon['options'] ?? ''))
            ));
            $event->addons()->create([
                'name'        => $addon['name'],
                'description' => $addon['description'] ?: null,
                'price_ore'   => (int) $addon['price_ore'],
                'options'     => $options ?: null,
                'max_count'   => $addon['capacity'] ?: null,
                'sort_order'  => $sort,
            ]);
        }

        AuditLog::record('event_created', 'events', $event->title, $event);

        $this->redirect(route('admin.events.show', $event), navigate: true);
    }

    private function syncOccurrenceBooking(EventOccurrence $occurrence, ?int $resourceId): void
    {
        if (! $resourceId) return;

        $resource = BookingResource::find($resourceId);
        if (! $resource) return;

        $booking = Booking::create([
            'source_type'       => EventOccurrence::class,
            'source_id'         => $occurrence->id,
            'resource_id'       => $resourceId,
            'user_id'           => Auth::id(),
            'section_id'        => $this->organizerSectionId,
            'title'             => $this->eventTitle,
            'activity'          => 'Evenemang',
            'booking_type'      => 'group',
            'starts_at'         => $occurrence->starts_at,
            'ends_at'           => $occurrence->ends_at,
            'status'            => $resource->requires_approval ? 'pending' : 'confirmed',
            'exempt_from_limit' => true,
        ]);
        $occurrence->update(['booking_id' => $booking->id]);
    }
}; ?>

<x-slot:header>
    <x-page-header permission="events.view" title="Skapa evenemang" subtitle="Fyll i grunduppgifter och tillfällen." />
</x-slot:header>

<div>
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">

        {{-- Basic info --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Grunduppgifter</span>
            </div>
            <div class="space-y-5 p-5">

                <div>
                    <label class="lbl">Titel <span class="text-red-500">*</span></label>
                    <input wire:model="eventTitle" type="text" class="inp mt-1 w-full" placeholder="t.ex. Spelträff #12" />
                    @error('eventTitle') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="lbl">Beskrivning</label>
                    <textarea wire:model="description" rows="4" class="inp mt-1 w-full" placeholder="Beskriv evenemanget…"></textarea>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                    <div>
                        <label class="lbl">Status</label>
                        <select wire:model="status" class="inp mt-1 w-full">
                            <option value="{{ Event::STATUS_DRAFT }}">Utkast</option>
                            <option value="{{ Event::STATUS_PUBLISHED }}">Publicerat</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Synlighet</label>
                        <select wire:model.live="visibility" class="inp mt-1 w-full">
                            <option value="{{ Event::VISIBILITY_PUBLIC }}">Öppet för alla</option>
                            <option value="{{ Event::VISIBILITY_MEMBERS_ONLY }}">Enbart medlemmar</option>
                            <option value="{{ Event::VISIBILITY_SECTION_ONLY }}">Enbart specifik grupp</option>
                            <option value="{{ Event::VISIBILITY_INVITE_ONLY }}">Enbart inbjudna</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Varumärke</label>
                        <select wire:model="brand" class="inp mt-1 w-full">
                            <option value="{{ Event::BRAND_ARMAGEDON }}">Armagedon</option>
                            <option value="{{ Event::BRAND_BSK }}">Borås Spelkonvent</option>
                        </select>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Styr logga/färger på incheckningsscannern.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label class="lbl">Minimiålder</label>
                        <input wire:model="minAge" type="number" min="0" max="120" class="inp mt-1 w-full" placeholder="–" />
                        @error('minAge') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">Maxålder</label>
                        <input wire:model="maxAge" type="number" min="0" max="120" class="inp mt-1 w-full" placeholder="–" />
                        @error('maxAge') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <input wire:model="requiresMembership" type="checkbox" id="req_membership"
                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                    <label for="req_membership" class="text-sm text-zinc-700 dark:text-zinc-300">Kräver aktivt medlemskap för anmälan</label>
                </div>

                <div>
                    <label class="lbl">Extern arrangör (om tillämpligt)</label>
                    <input wire:model="organizerExternalName" type="text" class="inp mt-1 w-full" placeholder="Arrangörens namn" />
                </div>

                <div>
                    <label class="lbl">Kostnadsställe</label>
                    <select wire:model="costCenterId" class="inp mt-1 w-full">
                        <option value="">– Inget –</option>
                        @foreach ($this->costCenters as $cc)
                            <option value="{{ $cc->id }}">{{ $cc->code }} {{ $cc->name }}</option>
                        @endforeach
                    </select>
                </div>

            </div>
        </div>

        {{-- Location --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Plats</span>
            </div>
            <div class="space-y-5 p-5">
                <div>
                    <label class="lbl">Platstyp</label>
                    <select wire:model.live="locationType" class="inp mt-1 w-full">
                        <option value="{{ Event::LOCATION_ROOM }}">Lokal (bokning)</option>
                        <option value="{{ Event::LOCATION_EXTERNAL }}">Extern plats</option>
                        <option value="{{ Event::LOCATION_DIGITAL }}">Digitalt</option>
                        <option value="{{ Event::LOCATION_VENUE_APPLICATION }}">Lokalansökan</option>
                    </select>
                </div>
                @if($locationType !== Event::LOCATION_ROOM && $locationType !== Event::LOCATION_VENUE_APPLICATION)
                    <div>
                        <label class="lbl">Platsnamn</label>
                        <input wire:model="locationName" type="text" class="inp mt-1 w-full" placeholder="t.ex. Armagedon – plan 2" />
                    </div>
                    <div>
                        <label class="lbl">Länk / adress (valfritt)</label>
                        <input wire:model="locationUrl" type="text" class="inp mt-1 w-full" placeholder="https://…" />
                    </div>
                @endif
            </div>
        </div>

        {{-- Occurrences --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Tillfällen</span>
                <button wire:click="addOccurrence" type="button" class="btn btn-ghost btn-sm">
                    <x-bootstrap-icon name="plus-lg" class="size-3.5" /> Lägg till tillfälle
                </button>
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($occurrences as $i => $occ)
                    <div class="p-5 space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Tillfälle {{ $i + 1 }}</span>
                            @if(count($occurrences) > 1)
                                <button wire:click="removeOccurrence({{ $i }})" type="button"
                                        class="text-xs text-red-500 hover:text-red-700 dark:hover:text-red-400">Ta bort</button>
                            @endif
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="lbl">Start <span class="text-red-500">*</span></label>
                                <input wire:model="occurrences.{{ $i }}.starts_at"
                                       type="datetime-local" class="inp mt-1 w-full" />
                                @error("occurrences.{$i}.starts_at") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Slut <span class="text-red-500">*</span></label>
                                <input wire:model="occurrences.{{ $i }}.ends_at"
                                       type="datetime-local" class="inp mt-1 w-full" />
                                @error("occurrences.{$i}.ends_at") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Maxplatser (lämna tomt = obegränsat)</label>
                                <input wire:model="occurrences.{{ $i }}.capacity"
                                       type="number" min="1" class="inp mt-1 w-full" placeholder="–" />
                                @error("occurrences.{$i}.capacity") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Platsöverlagring (valfritt)</label>
                                <input wire:model="occurrences.{{ $i }}.location_override"
                                       type="text" class="inp mt-1 w-full" placeholder="Ersätter eventets plats för detta tillfälle" />
                            </div>
                        </div>
                        @if($this->bookingResources->isNotEmpty())
                            <div>
                                <label class="lbl">Lokal (rumsbokning)</label>
                                <select wire:model="occurrences.{{ $i }}.booking_resource_id" class="inp mt-1 w-full">
                                    <option value="">— Ingen rumsbokning —</option>
                                    @foreach($this->bookingResources as $resource)
                                        <option value="{{ $resource->id }}">
                                            {{ $resource->name }}{{ $resource->location ? " · {$resource->location}" : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error("occurrences.{$i}.booking_resource_id")
                                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Price tiers --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Biljettyper / priser</span>
                <button wire:click="addPriceTier" type="button" class="btn btn-ghost btn-sm">
                    <x-bootstrap-icon name="plus-lg" class="size-3.5" /> Lägg till biljettyp
                </button>
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($priceTiers as $i => $tier)
                    <div class="p-5 space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Biljettyp {{ $i + 1 }}</span>
                            @if(count($priceTiers) > 1)
                                <button wire:click="removePriceTier({{ $i }})" type="button"
                                        class="text-xs text-red-500 hover:text-red-700 dark:hover:text-red-400">Ta bort</button>
                            @endif
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="lbl">Namn <span class="text-red-500">*</span></label>
                                <input wire:model="priceTiers.{{ $i }}.label"
                                       type="text" class="inp mt-1 w-full" placeholder="t.ex. Vuxen, Ungdom, Heldagsbiljett" />
                                @error("priceTiers.{$i}.label") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Pris (öre, 0 = gratis) <span class="text-red-500">*</span></label>
                                <input wire:model="priceTiers.{{ $i }}.price_ore"
                                       type="number" min="0" step="100" class="inp mt-1 w-full" placeholder="0" />
                                @error("priceTiers.{$i}.price_ore") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Maxplatser (lämna tomt = obegränsat)</label>
                                <input wire:model="priceTiers.{{ $i }}.capacity"
                                       type="number" min="1" class="inp mt-1 w-full" placeholder="–" />
                            </div>
                            <div>
                                <label class="lbl">Beskrivning (valfritt)</label>
                                <input wire:model="priceTiers.{{ $i }}.description"
                                       type="text" class="inp mt-1 w-full" placeholder="t.ex. Gäller lör–sön" />
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Addons --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Tillägg (valfritt)</span>
                <button wire:click="addAddon" type="button" class="btn btn-ghost btn-sm">
                    <x-bootstrap-icon name="plus-lg" class="size-3.5" /> Lägg till tillägg
                </button>
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($eventAddons as $i => $addon)
                    <div class="p-5 space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Tillägg {{ $i + 1 }}</span>
                            <button wire:click="removeAddon({{ $i }})" type="button"
                                    class="text-xs text-red-500 hover:text-red-700 dark:hover:text-red-400">Ta bort</button>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="lbl">Namn <span class="text-red-500">*</span></label>
                                <input wire:model="eventAddons.{{ $i }}.name"
                                       type="text" class="inp mt-1 w-full" placeholder="t.ex. T-shirt, Lunch" />
                                @error("eventAddons.{$i}.name") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Pris (öre, 0 = ingår) <span class="text-red-500">*</span></label>
                                <input wire:model="eventAddons.{{ $i }}.price_ore"
                                       type="number" min="0" step="100" class="inp mt-1 w-full" placeholder="0" />
                                @error("eventAddons.{$i}.price_ore") <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="lbl">Varianter (kommaseparerat, t.ex. S,M,L)</label>
                                <input wire:model="eventAddons.{{ $i }}.options"
                                       type="text" class="inp mt-1 w-full" placeholder="lämna tomt om inga varianter" />
                            </div>
                            <div>
                                <label class="lbl">Maxantal (tomt = obegränsat)</label>
                                <input wire:model="eventAddons.{{ $i }}.capacity"
                                       type="number" min="1" class="inp mt-1 w-full" placeholder="–" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="lbl">Beskrivning (valfritt)</label>
                                <input wire:model="eventAddons.{{ $i }}.description"
                                       type="text" class="inp mt-1 w-full" />
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-zinc-400 dark:text-zinc-500">Inga tillägg. Klicka "Lägg till tillägg" för att lägga till extra val för deltagare.</p>
                @endforelse
            </div>
        </div>

        {{-- Registration settings --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Anmälan</span>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label class="lbl">Anmälningsläge</label>
                        <select wire:model="registrationMode" class="inp mt-1 w-full">
                            <option value="{{ Event::REGISTRATION_STANDARD }}">Föranmälan</option>
                            <option value="{{ Event::REGISTRATION_DROPIN }}">Drop-in</option>
                            <option value="{{ Event::REGISTRATION_BOTH }}">Båda</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Antagningstyp</label>
                        <select wire:model="admissionType" class="inp mt-1 w-full">
                            <option value="{{ Event::ADMISSION_ON_PAYMENT }}">Vid betalning</option>
                            <option value="{{ Event::ADMISSION_AUTOMATIC }}">Automatisk (plats reserveras direkt)</option>
                            <option value="{{ Event::ADMISSION_MANUAL }}">Manuell granskning</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Väntelista</label>
                        <select wire:model="waitlistMode" class="inp mt-1 w-full">
                            <option value="{{ Event::WAITLIST_NONE }}">Ingen väntelista</option>
                            <option value="{{ Event::WAITLIST_MANUAL }}">Aktivera väntelista</option>
                        </select>
                    </div>
                    <div>
                        <label class="lbl">Anmälan öppnar</label>
                        <input wire:model="registrationOpensAt" type="datetime-local" class="inp mt-1 w-full" />
                    </div>
                    <div>
                        <label class="lbl">Anmälan stänger</label>
                        <input wire:model="registrationClosesAt" type="datetime-local" class="inp mt-1 w-full" />
                    </div>
                </div>
                <div>
                    <label class="lbl">Anmälningsinstruktioner (visas för deltagare)</label>
                    <textarea wire:model="registrationInstructions" rows="3" class="inp mt-1 w-full"></textarea>
                </div>
                <div class="flex flex-col gap-3">
                    <div class="flex items-center gap-2">
                        <input wire:model="allowTicketTransfer" type="checkbox" id="allow_transfer"
                               class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                        <label for="allow_transfer" class="text-sm text-zinc-700 dark:text-zinc-300">Tillåt biljettöverlåtelse</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <input wire:model="notifyOrganizerOnRegistration" type="checkbox" id="notify_org"
                               class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                        <label for="notify_org" class="text-sm text-zinc-700 dark:text-zinc-300">Meddela arrangör vid ny anmälan</label>
                    </div>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.events.index') }}" wire:navigate class="btn btn-ghost">Avbryt</a>
            <button wire:click="save" wire:loading.attr="disabled" class="btn btn-primary">
                <span wire:loading.remove wire:target="save">
                    <x-bootstrap-icon name="check2" class="size-4" /> Spara evenemang
                </span>
                <span wire:loading wire:target="save">Sparar…</span>
            </button>
        </div>

    </div>
</div>
