<?php

use App\Mail\EventRegistrationMail;
use App\Models\AuditLog;
use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Models\Shop\DiscountUse;
use App\Models\Events\Event;
use App\Models\Events\EventInterest;
use App\Models\Events\EventInvitation;
use App\Models\Events\EventRegistration;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\Form\FormAnswer;
use App\Models\Form\FormSubmission;
use App\Enums\QuestionType;
use App\Enums\SurveyStatus;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Evenemang');

new class extends Component {

    public Event $event;

    // Registration flow step: view | identify | form | done
    public string $step = 'view';

    // Guest identity
    public string $guestName  = '';
    public string $guestEmail = '';
    public string $guestPhone = '';

    // Registration form
    public ?int   $selectedTierId    = null;
    public array  $selectedAddonIds  = [];  // [addon_id, ...]
    public array  $addonOptionValues = [];  // [addon_id => option_value]
    public string $notes             = '';

    // Promo code
    public string  $promoCodeInput   = '';
    public ?int    $promoCodeId       = null;
    public ?int    $promoDiscountId   = null;
    public string  $promoCodeDisplay  = '';
    public string  $promoError        = '';

    // Survey answers: [question_id => answer] (text) or [question_id => [option_id, ...]] (checkbox)
    public array $surveyAnswers = [];

    // Outcome of register() for done-step messaging
    public ?int    $confirmedRegistrationId = null;
    public ?string $registrationOutcome     = null; // confirmed|pending_approval|waitlisted

    public function mount(Event $event): void
    {
        $this->event = $event->load(['occurrences', 'priceTiers', 'addons', 'organizers']);

        // Resolve visibility
        if ($event->status !== Event::STATUS_PUBLISHED) {
            abort(404);
        }

        $user   = Auth::user();
        $member = $user?->member;

        $memberSectionIds = $member
            ? $member->sections()->pluck('sections.id')->toArray()
            : [];

        $canView = match ($event->visibility) {
            Event::VISIBILITY_PUBLIC       => true,
            Event::VISIBILITY_MEMBERS_ONLY => $user !== null,
            Event::VISIBILITY_SECTION_ONLY => $user !== null
                && in_array($event->visibility_section_id, $memberSectionIds, true),
            Event::VISIBILITY_INVITE_ONLY  => false, // only via invitation link
            default                        => false,
        };

        abort_unless($canView, 403);

        // Pre-select single tier
        if ($this->event->priceTiers->count() === 1) {
            $this->selectedTierId = $this->event->priceTiers->first()->id;
        }

        // Auto-apply promo code from ?code= param
        if ($code = request()->query('code')) {
            $this->promoCodeInput = strtoupper(trim($code));
            $this->applyPromoCode();
        }
    }

    #[Computed]
    public function userInterest(): ?EventInterest
    {
        $member = Auth::user()?->member;
        if (! $member) {
            return null;
        }
        return EventInterest::where('event_id', $this->event->id)
            ->where('member_id', $member->id)
            ->first();
    }

    #[Computed]
    public function userInvitation(): ?EventInvitation
    {
        $member = Auth::user()?->member;
        if (! $member) {
            return null;
        }
        return $this->event->invitations()
            ->where('member_id', $member->id)
            ->first();
    }

    public function toggleInterest(): void
    {
        $member = Auth::user()?->member;
        abort_unless($member, 403);

        $existing = EventInterest::where('event_id', $this->event->id)
            ->where('member_id', $member->id)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            EventInterest::create(['event_id' => $this->event->id, 'member_id' => $member->id]);
        }

        unset($this->userInterest);
    }

    #[Computed]
    public function userRegistration(): ?EventRegistration
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        return EventRegistration::where('event_id', $this->event->id)
            ->where('user_id', $user->id)
            ->whereIn('status', [
                EventRegistration::STATUS_CONFIRMED,
                EventRegistration::STATUS_PENDING_PAYMENT,
                EventRegistration::STATUS_PENDING_APPROVAL,
            ])
            ->latest()
            ->first();
    }

    #[Computed]
    public function canRegister(): bool
    {
        if ($this->userRegistration) {
            return false;
        }

        if (! $this->event->isRegistrationOpen()) {
            return false;
        }

        $user   = Auth::user();
        $member = $user?->member;

        if ($this->event->requires_membership) {
            if (! $member || ! $member->hasFullAccess()) {
                return false;
            }
        }

        if ($this->event->access_section_id) {
            if (! $member) {
                return false;
            }
            $sectionIds = $member->sections()->pluck('sections.id')->toArray();
            if (! in_array($this->event->access_section_id, $sectionIds, true)) {
                return false;
            }
        }

        return true;
    }

    #[Computed]
    public function registrationBlockedReason(): ?string
    {
        if ($this->userRegistration) {
            return $this->userRegistration->isPendingPayment()
                ? 'Du har en pågående anmälan som väntar på betalning.'
                : 'Du är redan anmäld till detta evenemang.';
        }

        if (! $this->event->isRegistrationOpen()) {
            if ($this->event->registration_opens_at && now()->lt($this->event->registration_opens_at)) {
                return 'Anmälan öppnar ' . $this->event->registration_opens_at->isoFormat('D MMMM [kl.] HH:mm') . '.';
            }
            if ($this->event->registration_closes_at && now()->gt($this->event->registration_closes_at)) {
                return 'Anmälningstiden har gått ut.';
            }
            return 'Anmälan är stängd.';
        }

        $user   = Auth::user();
        $member = $user?->member;

        if ($this->event->requires_membership && (! $member || ! $member->hasFullAccess())) {
            return 'Du måste vara aktiv medlem för att anmäla dig till detta evenemang.';
        }

        if ($this->event->access_section_id) {
            if (! $member) {
                return 'Du måste vara inloggad och tillhöra rätt grupp för att anmäla dig.';
            }
            $sectionIds = $member->sections()->pluck('sections.id')->toArray();
            if (! in_array($this->event->access_section_id, $sectionIds, true)) {
                return 'Du måste tillhöra en specifik grupp för att anmäla dig till detta evenemang.';
            }
        }

        return null;
    }

    #[Computed]
    public function selectedTier()
    {
        return $this->selectedTierId
            ? $this->event->priceTiers->firstWhere('id', $this->selectedTierId)
            : null;
    }

    #[Computed]
    public function appliedDiscount(): ?Discount
    {
        return $this->promoDiscountId ? Discount::find($this->promoDiscountId) : null;
    }

    #[Computed]
    public function discountAmount(): int
    {
        if (! $this->appliedDiscount || ! $this->selectedTier) {
            return 0;
        }
        return $this->appliedDiscount->calculate($this->selectedTier->price_ore);
    }

    #[Computed]
    public function totalOre(): int
    {
        $addonTotal = collect($this->selectedAddonIds)->sum(
            fn ($id) => $this->event->addons->firstWhere('id', (int) $id)?->price_ore ?? 0
        );
        return max(0, ($this->selectedTier?->price_ore ?? 0) + $addonTotal - $this->discountAmount);
    }

    public function startRegistration(): void
    {
        if (Auth::check()) {
            $this->step = 'form';
        } else {
            $this->step = 'identify';
        }
    }

    public function continueAsGuest(): void
    {
        $this->validate([
            'guestName'  => ['required', 'string', 'max:100'],
            'guestEmail' => ['required', 'email', 'max:255'],
            'guestPhone' => ['nullable', 'string', 'max:30'],
        ], [
            'guestName.required'  => 'Ange ditt namn.',
            'guestEmail.required' => 'Ange din e-postadress.',
            'guestEmail.email'    => 'Ange en giltig e-postadress.',
        ]);

        // Check if this email belongs to a real (non-provisional) account
        $existing = \App\Models\User::where('email', $this->guestEmail)->first();
        if ($existing) {
            $this->addError('guestEmail', 'Det finns redan ett konto med den e-postadressen. Logga in för att anmäla dig.');
            return;
        }

        $this->step = 'form';
    }

    public function applyPromoCode(): void
    {
        $this->promoError = '';
        $input = strtoupper(trim($this->promoCodeInput));

        if ($input === '') {
            $this->promoError = 'Ange en rabattkod.';
            return;
        }

        $code = DiscountCode::where('code', $input)->first();

        if (! $code) {
            $this->promoError = 'Ogiltig rabattkod.';
            return;
        }

        if ($code->is_single_use && $code->redeemed_at) {
            $this->promoError = 'Den här koden har redan använts.';
            return;
        }

        $discount = $code->discount;

        if (! $discount || ! $discount->isValid()) {
            $this->promoError = 'Rabattkoden är inte giltig just nu.';
            return;
        }

        if (! in_array($discount->applies_to, [Discount::APPLIES_EVENT, Discount::APPLIES_ALL])) {
            $this->promoError = 'Den här koden gäller inte för evenemang.';
            return;
        }

        $this->promoCodeId      = $code->id;
        $this->promoDiscountId  = $discount->id;
        $this->promoCodeDisplay = $input;
        $this->promoCodeInput   = '';
    }

    public function removePromoCode(): void
    {
        $this->promoCodeId      = null;
        $this->promoDiscountId  = null;
        $this->promoCodeDisplay = '';
        $this->promoCodeInput   = '';
        $this->promoError       = '';
    }

    public function register(): void
    {
        if (! $this->canRegister) {
            return;
        }

        $this->validate([
            'selectedTierId' => ['required', 'integer'],
        ], [
            'selectedTierId.required' => 'Välj en biljettyp.',
        ]);

        // Validate required survey questions
        $survey = $this->event->registrationSurvey;
        if ($survey) {
            foreach ($survey->questions()->where('is_required', true)->get() as $question) {
                $answer = $this->surveyAnswers[$question->id] ?? null;
                $empty  = $answer === null || $answer === '' || $answer === [];
                if ($empty) {
                    $this->addError("surveyAnswers.{$question->id}", 'Detta fält är obligatoriskt.');
                }
            }
            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }
        }

        $tier = $this->event->priceTiers->firstWhere('id', $this->selectedTierId);
        if (! $tier) {
            return;
        }

        // Check capacity — pending_payment slots expire after PENDING_PAYMENT_EXPIRY_MINUTES
        $isFull = $tier->max_count !== null && EventRegistration::where('price_tier_id', $tier->id)
            ->countsTowardCapacity()
            ->count() >= $tier->max_count;

        if ($isFull && $this->event->waitlist_mode === Event::WAITLIST_NONE) {
            $this->addError('selectedTierId', 'Den här biljettypen är slutsåld.');
            return;
        }

        $user     = Auth::user();
        $totalOre = $this->totalOre;

        // Determine initial registration status before entering transaction
        if ($isFull) {
            $initialStatus = EventRegistration::STATUS_WAITLISTED;
        } else {
            $initialStatus = match ($this->event->admission_type) {
                Event::ADMISSION_AUTOMATIC => EventRegistration::STATUS_CONFIRMED,
                Event::ADMISSION_MANUAL    => EventRegistration::STATUS_PENDING_APPROVAL,
                default => $totalOre === 0
                    ? EventRegistration::STATUS_CONFIRMED
                    : EventRegistration::STATUS_PENDING_PAYMENT,
            };
        }

        [$order, $registration] = DB::transaction(function () use ($tier, $user, $totalOre, $initialStatus, $survey) {
            $order = Order::create([
                'user_id'        => $user?->id,
                'guest_name'     => $user ? null : $this->guestName,
                'guest_email'    => $user ? null : $this->guestEmail,
                'guest_phone'    => $user ? null : ($this->guestPhone ?: null),
                'status'         => $totalOre === 0 ? 'paid' : 'pending',
                'total_ore'      => $totalOre,
                'payment_method' => $totalOre === 0 ? 'free' : null,
                'paid_at'        => $totalOre === 0 ? now() : null,
            ]);

            $line = OrderLine::create([
                'order_id'       => $order->id,
                'category'       => OrderLine::CATEGORY_EVENT,
                'orderable_type' => \App\Models\Events\EventPriceTier::class,
                'orderable_id'   => $tier->id,
                'description'    => $tier->label . ' — ' . $this->event->title,
                'unit_price_ore' => $tier->price_ore,
                'quantity'       => 1,
                'line_total_ore' => $tier->price_ore,
            ]);

            // Apply discount
            if ($this->appliedDiscount && $this->discountAmount > 0) {
                DiscountUse::create([
                    'discount_id'       => $this->appliedDiscount->id,
                    'discount_code_id'  => $this->promoCodeId,
                    'discountable_type' => OrderLine::class,
                    'discountable_id'   => $line->id,
                    'original_amount'   => $tier->price_ore,
                    'discount_amount'   => $this->discountAmount,
                ]);

                if ($this->promoCodeId) {
                    $usedCode = DiscountCode::find($this->promoCodeId);
                    if ($usedCode?->is_single_use) {
                        $usedCode->update(['redeemed_at' => now()]);
                    }
                }
            }

            $registration = EventRegistration::create([
                'event_id'      => $this->event->id,
                'user_id'       => $user?->id,
                'guest_name'    => $user ? null : $this->guestName,
                'guest_email'   => $user ? null : $this->guestEmail,
                'price_tier_id' => $tier->id,
                'order_id'      => $order->id,
                'status'        => $initialStatus,
                'notes'         => $this->notes ?: null,
            ]);

            // Save form submission linked to this registration
            if ($survey && $survey->form_id && count($this->surveyAnswers) > 0) {
                $submission = FormSubmission::create([
                    'form_id'          => $survey->form_id,
                    'submittable_type' => \App\Models\Survey\Survey::class,
                    'submittable_id'   => $survey->id,
                    'user_id'          => $user?->id,
                    'ip_address'       => request()->ip(),
                ]);

                $registration->update(['form_submission_id' => $submission->id]);

                foreach ($survey->questions as $question) {
                    $answer = $this->surveyAnswers[$question->id] ?? null;
                    if ($answer === null || $answer === '') {
                        continue;
                    }

                    if ($question->type === QuestionType::Checkbox && is_array($answer)) {
                        foreach ($answer as $optionId) {
                            FormAnswer::create([
                                'submission_id' => $submission->id,
                                'question_id'   => $question->id,
                                'option_id'     => (int) $optionId,
                            ]);
                        }
                    } elseif ($question->type === QuestionType::Radio) {
                        FormAnswer::create([
                            'submission_id' => $submission->id,
                            'question_id'   => $question->id,
                            'option_id'     => (int) $answer,
                        ]);
                    } else {
                        FormAnswer::create([
                            'submission_id' => $submission->id,
                            'question_id'   => $question->id,
                            'answer_text'   => (string) $answer,
                        ]);
                    }
                }
            }

            // Create addon records and addon order lines
            foreach ($this->selectedAddonIds as $addonId) {
                $addon = $this->event->addons->firstWhere('id', (int) $addonId);
                if (! $addon) {
                    continue;
                }
                \App\Models\Events\EventRegistrationAddon::create([
                    'registration_id' => $registration->id,
                    'addon_id'        => $addon->id,
                    'option_value'    => $this->addonOptionValues[$addonId] ?? null,
                    'quantity'        => 1,
                ]);
                if ($addon->price_ore > 0) {
                    $optionLabel = $this->addonOptionValues[$addonId] ?? null;
                    OrderLine::create([
                        'order_id'       => $order->id,
                        'category'       => OrderLine::CATEGORY_EVENT,
                        'orderable_type' => \App\Models\Events\EventAddon::class,
                        'orderable_id'   => $addon->id,
                        'description'    => $addon->name . ($optionLabel ? ' (' . $optionLabel . ')' : '') . ' — ' . $this->event->title,
                        'unit_price_ore' => $addon->price_ore,
                        'quantity'       => 1,
                        'line_total_ore' => $addon->price_ore,
                    ]);
                }
            }

            return [$order, $registration];
        });

        $this->confirmedRegistrationId = $registration->id;

        AuditLog::record('event_registered', 'events', $this->event->title . ' — ' . $registration->displayName, $registration);
        EventRegistrationMail::sendFor($registration);

        // Waitlisted or pending_approval — stay on page, show outcome message
        if (in_array($initialStatus, [
            EventRegistration::STATUS_WAITLISTED,
            EventRegistration::STATUS_PENDING_APPROVAL,
        ])) {
            $this->registrationOutcome = $initialStatus;
            $this->step = 'done';
            return;
        }

        // Confirmed (free or automatic admission) — done
        if ($initialStatus === EventRegistration::STATUS_CONFIRMED) {
            $this->registrationOutcome = 'confirmed';
            $this->step = 'done';
            return;
        }

        // Pending payment — redirect to payment page
        session(['event_order_id' => $order->id]);
        $this->redirect(route('events.payment', $this->event->slug), navigate: true);
    }
}; ?>

<x-slot:header>
    <x-page-header title="{{ $event->title }}" subtitle="" />
</x-slot:header>

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">

    @php $firstOcc = $event->occurrences->first(); @endphp

    {{-- ── Event info ─────────────────────────────────────────────────────── --}}
    <div class="space-y-6">

        {{-- Meta strip --}}
        <div class="flex flex-wrap gap-4 text-sm text-zinc-600 dark:text-zinc-400">
            @if($firstOcc)
                <div class="flex items-center gap-1.5">
                    <x-bootstrap-icon name="calendar3" class="size-4 shrink-0 text-zinc-400" />
                    <span>
                        {{ $firstOcc->starts_at->isoFormat('dddd D MMMM YYYY, HH:mm') }}
                        @if($event->occurrences->count() > 1)
                            <span class="text-zinc-400">(+{{ $event->occurrences->count() - 1 }} fler tillfällen)</span>
                        @endif
                    </span>
                </div>
            @endif
            @if($event->location_name)
                <div class="flex items-center gap-1.5">
                    <x-bootstrap-icon name="geo-alt" class="size-4 shrink-0 text-zinc-400" />
                    @if($event->location_url)
                        <a href="{{ $event->location_url }}" target="_blank" rel="noopener"
                           class="hover:text-complement dark:hover:text-complement-light">{{ $event->location_name }}</a>
                    @else
                        <span>{{ $event->location_name }}</span>
                    @endif
                </div>
            @elseif($event->location_type === App\Models\Events\Event::LOCATION_DIGITAL)
                <div class="flex items-center gap-1.5">
                    <x-bootstrap-icon name="camera-video" class="size-4 shrink-0 text-zinc-400" />
                    <span>Digitalt evenemang</span>
                </div>
            @endif
            @if($event->organizer_external_name)
                <div class="flex items-center gap-1.5">
                    <x-bootstrap-icon name="person" class="size-4 shrink-0 text-zinc-400" />
                    <span>Arrangör: {{ $event->organizer_external_name }}</span>
                </div>
            @elseif($event->organizers->isNotEmpty())
                <div class="flex items-center gap-1.5">
                    <x-bootstrap-icon name="person" class="size-4 shrink-0 text-zinc-400" />
                    <span>{{ $event->organizers->pluck('full_name')->join(', ') }}</span>
                </div>
            @endif
        </div>

        {{-- Description --}}
        @if($event->description)
            <div class="prose prose-zinc max-w-none dark:prose-invert">
                {!! nl2br(e($event->description)) !!}
            </div>
        @endif

        {{-- All occurrences (if multiple) --}}
        @if($event->occurrences->count() > 1)
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                    <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Tillfällen</span>
                </div>
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($event->occurrences as $occ)
                        <li class="flex items-center justify-between px-5 py-3 text-sm">
                            <span class="text-zinc-900 dark:text-white">
                                {{ $occ->starts_at->isoFormat('dddd D MMMM, HH:mm') }}
                                – {{ $occ->ends_at->isoFormat($occ->starts_at->isSameDay($occ->ends_at) ? 'HH:mm' : 'D MMM HH:mm') }}
                            </span>
                            @if($occ->isCancelled())
                                <span class="text-xs text-red-500">Inställt</span>
                            @elseif($occ->capacity)
                                <span class="text-xs text-zinc-400">{{ $occ->confirmedCount() }}/{{ $occ->capacity }} platser</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ── Registration section ───────────────────────────────────────── --}}

        @if($step === 'done')
            @if($registrationOutcome === 'waitlisted')
                {{-- Waitlisted --}}
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-center dark:border-amber-800/40 dark:bg-amber-900/20">
                    <x-bootstrap-icon name="hourglass-split" class="mx-auto mb-3 size-10 text-amber-600 dark:text-amber-400" />
                    <h2 class="text-lg font-semibold text-amber-900 dark:text-amber-200">Du är på väntelistan!</h2>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">
                        Den här biljettypen är slutsåld just nu. Du har fått ett plats på väntelistan och kontaktas om en plats öppnas.
                    </p>
                </div>
            @elseif($registrationOutcome === 'pending_approval')
                {{-- Pending approval --}}
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-6 text-center dark:border-blue-800/40 dark:bg-blue-900/20">
                    <x-bootstrap-icon name="clock" class="mx-auto mb-3 size-10 text-blue-600 dark:text-blue-400" />
                    <h2 class="text-lg font-semibold text-blue-900 dark:text-blue-200">Ansökan mottagen!</h2>
                    <p class="mt-1 text-sm text-blue-700 dark:text-blue-400">
                        Din anmälan granskas av arrangören. Du kontaktas när den godkänts.
                    </p>
                </div>
            @else
                {{-- Confirmed --}}
                <div class="rounded-xl border border-green-200 bg-green-50 p-6 text-center dark:border-green-800/40 dark:bg-green-900/20">
                    <x-bootstrap-icon name="check-circle-fill" class="mx-auto mb-3 size-10 text-green-600 dark:text-green-400" />
                    <h2 class="text-lg font-semibold text-green-900 dark:text-green-200">Du är anmäld!</h2>
                    <p class="mt-1 text-sm text-green-700 dark:text-green-400">
                        En bekräftelse har skickats till
                        {{ Auth::user()?->email ?? $guestEmail }}.
                    </p>
                    @auth
                        <a href="{{ route('events.my-registrations') }}" wire:navigate
                           class="mt-4 inline-block text-sm font-medium text-green-800 underline dark:text-green-300">
                            Visa mina anmälningar
                        </a>
                    @endauth
                </div>
            @endif

        @elseif($this->registrationBlockedReason)
            {{-- Registration not available --}}
            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    <x-bootstrap-icon name="info-circle" class="mr-1 inline size-4" />
                    {{ $this->registrationBlockedReason }}
                </p>
                @if($this->userRegistration?->isPendingPayment())
                    <div class="mt-3">
                        <a href="{{ route('events.payment', $event->slug) }}" wire:navigate class="btn btn-primary btn-sm">Slutför betalning →</a>
                    </div>
                @elseif($event->requires_membership && ! auth()->check())
                    <div class="mt-3 flex gap-3">
                        <a href="{{ route('login') }}" class="btn btn-primary btn-sm">Logga in</a>
                        <a href="{{ route('membership.apply') }}" wire:navigate class="btn btn-secondary btn-sm">Bli medlem</a>
                    </div>
                @endif
            </div>

        @elseif($step === 'view')
            {{-- Registration CTA --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                    <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Anmälan</span>
                </div>
                <div class="space-y-4 p-5">
                    {{-- Price tiers preview --}}
                    @foreach($event->priceTiers as $tier)
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $tier->label }}</span>
                                @if($tier->description)
                                    <p class="text-xs text-zinc-400 dark:text-zinc-500">{{ $tier->description }}</p>
                                @endif
                            </div>
                            <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                {{ $tier->isFree() ? 'Gratis' : number_format($tier->price_ore / 100, 0, ',', ' ') . ' kr' }}
                            </span>
                        </div>
                    @endforeach

                    @if($event->registration_instructions)
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $event->registration_instructions }}</p>
                    @endif

                    {{-- Inbjudan-badge --}}
                    @if($this->userInvitation && ! $this->userInvitation->isAccepted())
                        <div class="flex items-center gap-2 rounded-lg border border-complement/30 bg-complement/5 px-3 py-2 text-sm dark:border-complement/20 dark:bg-complement/10">
                            <x-bootstrap-icon name="envelope-open" class="size-4 shrink-0 text-complement dark:text-complement-light" />
                            <span class="text-zinc-700 dark:text-zinc-300">Du är inbjuden till detta evenemang.</span>
                        </div>
                    @endif

                    <button wire:click="startRegistration" class="btn btn-primary w-full">
                        Anmäl mig
                    </button>

                    @if(! auth()->check())
                        <p class="text-center text-xs text-zinc-400 dark:text-zinc-500">
                            Har du redan ett konto?
                            <a href="{{ route('login') }}" class="underline hover:text-zinc-700 dark:hover:text-zinc-300">Logga in</a>
                        </p>
                    @elseif(auth()->user()?->member)
                        {{-- Intresse-toggle (bara för inloggade medlemmar utan aktiv anmälan) --}}
                        @unless($this->userRegistration)
                            <button wire:click="toggleInterest"
                                    class="flex w-full items-center justify-center gap-2 text-xs transition-colors
                                           {{ $this->userInterest
                                               ? 'text-complement dark:text-complement-light'
                                               : 'text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300' }}">
                                <x-bootstrap-icon name="{{ $this->userInterest ? 'star-fill' : 'star' }}" class="size-3.5" />
                                {{ $this->userInterest ? 'Intresserad (klicka för att ta bort)' : 'Markera intresse' }}
                            </button>
                        @endunless
                    @endif
                </div>
            </div>

        @elseif($step === 'identify')
            {{-- Guest identification --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                    <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Dina uppgifter</span>
                </div>
                <div class="space-y-4 p-5">
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        Fyll i dina uppgifter för att fortsätta. Du behöver inte skapa ett konto.
                    </p>
                    <div>
                        <label class="lbl">Namn <span class="text-red-500">*</span></label>
                        <input wire:model="guestName" type="text" class="inp mt-1 w-full"
                               placeholder="Förnamn Efternamn" />
                        @error('guestName') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">E-postadress <span class="text-red-500">*</span></label>
                        <input wire:model="guestEmail" type="email" class="inp mt-1 w-full"
                               placeholder="din@epost.se" />
                        @error('guestEmail') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lbl">Telefon (valfritt)</label>
                        <input wire:model="guestPhone" type="tel" class="inp mt-1 w-full"
                               placeholder="070-000 00 00" />
                    </div>
                    <div class="flex gap-3">
                        <button wire:click="continueAsGuest" class="btn btn-primary flex-1">
                            Fortsätt
                        </button>
                        <button wire:click="$set('step', 'view')" class="btn btn-ghost">
                            Avbryt
                        </button>
                    </div>
                    <p class="text-center text-xs text-zinc-400 dark:text-zinc-500">
                        Har du ett konto?
                        <a href="{{ route('login') }}" class="underline hover:text-zinc-700 dark:hover:text-zinc-300">Logga in istället</a>
                    </p>
                </div>
            </div>

        @elseif($step === 'form')
            {{-- Registration form --}}
            <div class="space-y-4">

                {{-- Who is registering --}}
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        <x-bootstrap-icon name="person-circle" class="mr-1 inline size-4" />
                        Anmälan för:
                        <span class="font-medium text-zinc-900 dark:text-white">
                            {{ Auth::user()?->name ?? $guestName }}
                        </span>
                        <span class="text-zinc-400">({{ Auth::user()?->email ?? $guestEmail }})</span>
                    </p>
                </div>

                {{-- Tier selection (if multiple) --}}
                @if($event->priceTiers->count() > 1)
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Välj biljettyp</span>
                        </div>
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($event->priceTiers as $tier)
                                <label class="flex cursor-pointer items-center gap-4 px-5 py-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <input wire:model.live="selectedTierId" type="radio" name="tier"
                                           value="{{ $tier->id }}"
                                           class="text-brand focus:ring-brand-saffron" />
                                    <div class="flex-1">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ $tier->label }}</div>
                                        @if($tier->description)
                                            <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $tier->description }}</div>
                                        @endif
                                    </div>
                                    <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                        {{ $tier->isFree() ? 'Gratis' : number_format($tier->price_ore / 100, 0, ',', ' ') . ' kr' }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    @error('selectedTierId') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                @endif

                {{-- Addons --}}
                @if($event->addons->isNotEmpty())
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Tillägg</span>
                        </div>
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($event->addons as $addon)
                                <div class="flex items-start gap-4 px-5 py-4">
                                    <input wire:model.live="selectedAddonIds" type="checkbox"
                                           value="{{ $addon->id }}"
                                           id="addon-{{ $addon->id }}"
                                           class="mt-0.5 rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                                    <label for="addon-{{ $addon->id }}" class="flex-1 cursor-pointer">
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $addon->name }}</span>
                                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                                @if($addon->price_ore > 0)
                                                    +{{ number_format($addon->price_ore / 100, 2, ',', ' ') }} kr
                                                @else
                                                    Ingår
                                                @endif
                                            </span>
                                        </div>
                                        @if($addon->description)
                                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $addon->description }}</p>
                                        @endif
                                        @if($addon->hasVariants() && in_array($addon->id, $selectedAddonIds))
                                            <select wire:model="addonOptionValues.{{ $addon->id }}"
                                                    class="inp mt-2 w-full text-sm">
                                                <option value="">Välj variant…</option>
                                                @foreach($addon->options as $opt)
                                                    <option value="{{ $opt }}">{{ $opt }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Promo code --}}
                @if($this->selectedTier && ! $this->selectedTier->isFree())
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Rabattkod</span>
                        </div>
                        <div class="p-5">
                            @if($promoCodeDisplay)
                                @php
                                    $d = $this->appliedDiscount;
                                @endphp
                                <div class="flex items-center justify-between rounded-lg bg-green-50 px-4 py-3 dark:bg-green-900/10">
                                    <div class="flex items-center gap-2">
                                        <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-600 dark:text-green-400" />
                                        <span class="font-mono text-sm font-semibold text-green-800 dark:text-green-300">{{ $promoCodeDisplay }}</span>
                                        @if($d)
                                            <span class="text-sm text-green-700 dark:text-green-400">
                                                — {{ $d->type === 'percentage' ? $d->value . '%' : number_format($d->value / 100, 2, ',', ' ') . ' kr' }} rabatt
                                                @if($this->discountAmount > 0)
                                                    (−{{ number_format($this->discountAmount / 100, 2, ',', ' ') }} kr)
                                                @endif
                                            </span>
                                        @endif
                                    </div>
                                    <button wire:click="removePromoCode" type="button"
                                            class="text-sm text-red-500 hover:text-red-700 dark:hover:text-red-400">
                                        Ta bort
                                    </button>
                                </div>
                            @else
                                <div class="flex gap-3">
                                    <input wire:model="promoCodeInput" type="text"
                                           placeholder="Ange rabattkod…"
                                           class="inp flex-1 font-mono uppercase"
                                           wire:keydown.enter.prevent="applyPromoCode" />
                                    <button wire:click="applyPromoCode" type="button" class="btn btn-secondary btn-sm">
                                        Använd
                                    </button>
                                </div>
                                @if($promoError)
                                    <p class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $promoError }}</p>
                                @endif
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Survey questions --}}
                @php $survey = $event->survey; @endphp
                @if($survey && $survey->questions->isNotEmpty())
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Ytterligare information</span>
                        </div>
                        <div class="divide-y divide-zinc-100 px-5 dark:divide-zinc-800">
                            @foreach($survey->questions as $question)
                                <div class="py-4">
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $question->label }}
                                        @if($question->is_required)
                                            <span class="text-red-500">*</span>
                                        @endif
                                    </label>
                                    @if($question->description)
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $question->description }}</p>
                                    @endif

                                    @if($question->type === QuestionType::Textarea)
                                        <textarea wire:model="surveyAnswers.{{ $question->id }}"
                                                  rows="3" class="inp mt-2 w-full text-sm"></textarea>

                                    @elseif($question->type === QuestionType::Radio)
                                        <div class="mt-2 space-y-1.5">
                                            @foreach($question->options as $option)
                                                <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                                    <input wire:model="surveyAnswers.{{ $question->id }}"
                                                           type="radio" value="{{ $option->id }}"
                                                           class="text-brand focus:ring-brand-saffron" />
                                                    {{ $option->text }}
                                                </label>
                                            @endforeach
                                        </div>

                                    @elseif($question->type === QuestionType::Checkbox)
                                        <div class="mt-2 space-y-1.5">
                                            @foreach($question->options as $option)
                                                <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                                    <input wire:model="surveyAnswers.{{ $question->id }}"
                                                           type="checkbox" value="{{ $option->id }}"
                                                           class="rounded border-zinc-300 text-brand focus:ring-brand-saffron dark:border-zinc-600" />
                                                    {{ $option->text }}
                                                </label>
                                            @endforeach
                                        </div>

                                    @elseif($question->type === QuestionType::Scale)
                                        <div class="mt-2 flex gap-2">
                                            @for($i = $question->scale_min; $i <= $question->scale_max; $i++)
                                                <label class="flex flex-col items-center gap-1 text-xs text-zinc-500">
                                                    <input wire:model="surveyAnswers.{{ $question->id }}"
                                                           type="radio" value="{{ $i }}"
                                                           class="text-brand focus:ring-brand-saffron" />
                                                    {{ $i }}
                                                </label>
                                            @endfor
                                        </div>

                                    @elseif($question->type === QuestionType::Datetime)
                                        <input wire:model="surveyAnswers.{{ $question->id }}"
                                               type="{{ $question->datetime_variant === 'date' ? 'date' : ($question->datetime_variant === 'time' ? 'time' : 'datetime-local') }}"
                                               class="inp mt-2 text-sm" />

                                    @else
                                        <input wire:model="surveyAnswers.{{ $question->id }}"
                                               type="text" class="inp mt-2 w-full text-sm" />
                                    @endif

                                    @error("surveyAnswers.{$question->id}")
                                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Notes --}}
                <div>
                    <label class="lbl">Meddelande till arrangören (valfritt)</label>
                    <textarea wire:model="notes" rows="2" class="inp mt-1 w-full"
                              placeholder="Allergier, frågor eller önskemål…"></textarea>
                </div>

                {{-- Summary --}}
                @if($this->selectedTier)
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <div class="flex justify-between px-5 py-3 text-sm">
                                <span class="text-zinc-600 dark:text-zinc-400">{{ $this->selectedTier->label }}</span>
                                <span>{{ number_format($this->selectedTier->price_ore / 100, 2, ',', ' ') }} kr</span>
                            </div>
                            @foreach($selectedAddonIds as $addonId)
                                @php $addon = $event->addons->firstWhere('id', (int) $addonId); @endphp
                                @if($addon)
                                    <div class="flex justify-between px-5 py-3 text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">
                                            {{ $addon->name }}
                                            @if($addonOptionValues[$addonId] ?? null)
                                                ({{ $addonOptionValues[$addonId] }})
                                            @endif
                                        </span>
                                        <span>
                                            @if($addon->price_ore > 0)
                                                +{{ number_format($addon->price_ore / 100, 2, ',', ' ') }} kr
                                            @else
                                                Ingår
                                            @endif
                                        </span>
                                    </div>
                                @endif
                            @endforeach
                            @if($this->discountAmount > 0)
                                <div class="flex justify-between px-5 py-3 text-sm text-green-700 dark:text-green-400">
                                    <span>Rabatt ({{ $promoCodeDisplay }})</span>
                                    <span>−{{ number_format($this->discountAmount / 100, 2, ',', ' ') }} kr</span>
                                </div>
                            @endif
                            <div class="flex justify-between px-5 py-3 font-semibold">
                                <span class="text-zinc-900 dark:text-white">Totalt</span>
                                <span class="text-zinc-900 dark:text-white">
                                    {{ $this->totalOre === 0 ? 'Gratis' : number_format($this->totalOre / 100, 2, ',', ' ') . ' kr' }}
                                </span>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Submit --}}
                <div class="flex gap-3">
                    <button wire:click="register" wire:loading.attr="disabled"
                            @class(['btn btn-primary flex-1', 'opacity-50 cursor-not-allowed' => ! $this->selectedTier])>
                        <span wire:loading.remove wire:target="register">
                            @if($this->totalOre === 0)
                                <x-bootstrap-icon name="check2" class="size-4" /> Bekräfta anmälan
                            @else
                                <x-bootstrap-icon name="credit-card" class="size-4" /> Gå till betalning
                            @endif
                        </span>
                        <span wire:loading wire:target="register">Behandlar…</span>
                    </button>
                    <button wire:click="$set('step', 'view')" class="btn btn-ghost">
                        Avbryt
                    </button>
                </div>

            </div>
        @endif

    </div>
</div>
