<?php

use App\Mail\MembershipApplied;
use App\Mail\NewMemberAdminMail;
use App\Models\AuditLog;
use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Models\Shop\DiscountUse;
use App\Models\Membership\FamilyGroup;
use App\Models\Membership\Member;
use App\Models\Membership\MembershipFee;
use App\Models\Membership\MemberTag;
use App\Models\Membership\MembershipPeriod;
use App\Models\Membership\TagYearPrice;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\Rental\Rental;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.onboarding');
title('Bli medlem');

new class extends Component {

    public string $first_name      = '';
    public string $last_name       = '';
    public string $email           = '';
    public string $phone           = '';
    public string $address         = '';
    public string $postal_code     = '';
    public string $city            = '';
    public string $date_of_birth   = '';
    public string $membership_type = Member::TYPE_SINGLE;

    /** @var array<int, array{first_name: string, last_name: string, date_of_birth: string, email: string}> */
    public array $familyMembers = [];

    /** @var \Illuminate\Support\Collection<int, MembershipFee> */
    public $availableFees = [];

    // Promo code
    public string       $promoCodeInput   = '';
    public ?int         $promoCodeId      = null;  // DiscountCode id when applied
    public ?int         $promoDiscountId  = null;  // Discount id when applied
    public string       $promoCodeDisplay = '';    // Code text to show
    public string       $promoError       = '';

    public function targetYear(): int
    {
        $fromMonth = (int) SystemSetting::get('membership.new_member_next_year_from_month', 10);
        return (now()->month >= $fromMonth) ? now()->year + 1 : now()->year;
    }

    /**
     * Valid automatic first-membership discounts, loaded once per render.
     * Only applicable when registering for the current year.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Discount>
     */
    #[Computed]
    public function firstMembershipDiscounts(): \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection
    {
        if ($this->targetYear() !== now()->year) {
            return collect();
        }

        // New self-registrants are non-members, so requires_membership must be null or false.
        return Discount::where('condition', Discount::CONDITION_FIRST_MEMBERSHIP)
            ->whereIn('applies_to', [Discount::APPLIES_MEMBERSHIP, Discount::APPLIES_ALL])
            ->where('is_active', true)
            ->whereIn('requires_membership', [null, false])
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
            ->get()
            ->filter(fn ($d) => $d->isValid())
            ->values();
    }

    /**
     * Return the best applicable discount for a given membership type.
     * Promo code discount takes priority over automatic discounts; otherwise
     * type-specific automatic discounts beat generic ones.
     */
    public function discountFor(string $membershipType): ?Discount
    {
        // Promo code wins if applied and applicable
        if ($this->promoDiscountId) {
            $promo = Discount::find($this->promoDiscountId);
            if ($promo && $promo->isValid()
                && in_array($promo->applies_to, [Discount::APPLIES_MEMBERSHIP, Discount::APPLIES_ALL])
                && ($promo->membership_type === null || $promo->membership_type === $membershipType)) {
                return $promo;
            }
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Discount> $discounts */
        $discounts = $this->firstMembershipDiscounts();
        return $discounts->first(fn (Discount $d) => $d->membership_type === $membershipType)
            ?? $discounts->first(fn (Discount $d) => $d->membership_type === null);
    }

    public function applyPromoCode(): void
    {
        $this->promoError = '';
        $input = strtoupper(trim($this->promoCodeInput));

        if ($input === '') {
            $this->promoError = 'Ange en rabattkod.';
            return;
        }

        $code = DiscountCode::where('code', $input)->with('discount')->first();

        if (! $code) {
            $this->promoError = 'Koden hittades inte.';
            return;
        }

        if (! $code->isAvailable()) {
            $this->promoError = 'Koden är inte längre giltig.';
            return;
        }

        $discount = $code->discount;

        if (! $discount->isValid()) {
            $this->promoError = 'Koden är inte aktiv just nu.';
            return;
        }

        if (! in_array($discount->applies_to, [Discount::APPLIES_MEMBERSHIP, Discount::APPLIES_ALL])) {
            $this->promoError = 'Den här koden gäller inte för medlemskap.';
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

    public function mount(): void
    {
        if (Member::where('user_id', auth()->id())->exists()) {
            $this->redirect(route('membership.show'), navigate: true);
            return;
        }

        $user             = auth()->user();
        $this->email      = $user->email;
        $parts            = explode(' ', $user->name, 2);
        $this->first_name = $parts[0] ?? '';
        $this->last_name  = $parts[1] ?? '';

        $this->availableFees = MembershipFee::forRegistration($this->targetYear());

        // Pre-select membership type from ?type= query param (e.g. from scan station)
        $qType = request()->query('type');
        if ($qType && $this->availableFees->contains('membership_type', $qType)) {
            $this->membership_type = $qType;
        } elseif ($this->availableFees->isNotEmpty() && ! $this->availableFees->contains('membership_type', $this->membership_type)) {
            $this->membership_type = $this->availableFees->first()->membership_type;
        }

        // Auto-apply promo code from ?code= query param (e.g. from scan station)
        $qCode = request()->query('code');
        if ($qCode) {
            $this->promoCodeInput = strtoupper(trim($qCode));
            $this->applyPromoCode();
        }
    }

    public function updatedMembershipType(): void
    {
        if ($this->membership_type === Member::TYPE_FAMILY && empty($this->familyMembers)) {
            $this->addFamilyMember();
        }
        if ($this->membership_type !== Member::TYPE_FAMILY) {
            $this->familyMembers = [];
        }
    }

    public function addFamilyMember(): void
    {
        $maxAdults   = (int) SystemSetting::get('family.max_adults', 2);
        $maxChildren = (int) SystemSetting::get('family.max_children', 5);
        $maxTotal    = $maxAdults + $maxChildren - 1; // -1 because primary registrant counts as adult

        if (count($this->familyMembers) >= $maxTotal) {
            return;
        }

        $this->familyMembers[] = ['first_name' => '', 'last_name' => '', 'date_of_birth' => '', 'email' => ''];
    }

    public function removeFamilyMember(int $index): void
    {
        array_splice($this->familyMembers, $index, 1);
    }

    public function save(): void
    {
        if (Member::where('user_id', auth()->id())->exists()) {
            $this->redirect(route('membership.show'), navigate: true);
            return;
        }

        $rules = [
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'email'           => 'required|email|max:255',
            'phone'           => 'nullable|string|max:30',
            'address'         => 'nullable|string|max:255',
            'postal_code'     => 'nullable|string|max:10',
            'city'            => 'nullable|string|max:100',
            'date_of_birth'   => 'nullable|date|before:today',
            'membership_type' => 'required|in:single,family,student,youth,child,senior',
        ];

        $messages = [
            'first_name.required'  => 'Förnamn är obligatoriskt.',
            'last_name.required'   => 'Efternamn är obligatoriskt.',
            'email.required'       => 'E-post är obligatorisk.',
            'email.email'          => 'Ange en giltig e-postadress.',
            'date_of_birth.before' => 'Ange ett giltigt födelsedatum.',
        ];

        if ($this->membership_type === Member::TYPE_FAMILY) {
            $rules['familyMembers']                    = 'array';
            $rules['familyMembers.*.first_name']       = 'required|string|max:100';
            $rules['familyMembers.*.last_name']        = 'required|string|max:100';
            $rules['familyMembers.*.date_of_birth']    = 'nullable|date|before:today';
            $rules['familyMembers.*.email']            = 'nullable|email|max:255';

            $messages['familyMembers.*.first_name.required'] = 'Förnamn är obligatoriskt för varje familjemedlem.';
            $messages['familyMembers.*.last_name.required']  = 'Efternamn är obligatoriskt för varje familjemedlem.';
            $messages['familyMembers.*.email.email']         = 'Ange en giltig e-postadress för familjemedlemmen.';
        }

        $this->validate($rules, $messages);

        $member = Member::create([
            'user_id'         => auth()->id(),
            'first_name'      => $this->first_name,
            'last_name'       => $this->last_name,
            'email'           => $this->email,
            'phone'           => $this->phone ?: null,
            'address'         => $this->address ?: null,
            'postal_code'     => $this->postal_code ?: null,
            'city'            => $this->city ?: null,
            'date_of_birth'   => $this->date_of_birth ?: null,
            'membership_type' => $this->membership_type,
            'status'          => Member::STATUS_PENDING,
        ]);

        $fee = MembershipFee::where('year', $this->targetYear())
            ->where('membership_type', $this->membership_type)
            ->first();

        $discount       = $this->discountFor($this->membership_type);
        $discountAmount = ($discount && $fee) ? $discount->calculate($fee->amount) : 0;
        $unitPrice      = $fee?->amount ?? 0;
        $lineTotal      = max(0, $unitPrice - $discountAmount);

        $order = Order::create([
            'user_id'   => auth()->id(),
            'total_ore' => $lineTotal,
        ]);

        $typeLabels = [
            'single'  => 'Vuxenmedlemskap',  'family'  => 'Familjemedlemskap',
            'youth'   => 'Ungdomsmedlemskap', 'child'   => 'Barnmedlemskap',
            'student' => 'Studentmedlemskap', 'senior'  => 'Seniormedlemskap',
        ];

        $period = MembershipPeriod::create([
            'member_id'       => $member->id,
            'order_id'        => $order->id,
            'year'            => $this->targetYear(),
            'membership_type' => $this->membership_type,
            'status'          => MembershipPeriod::STATUS_PENDING_PAYMENT,
        ]);

        $line = OrderLine::create([
            'order_id'        => $order->id,
            'category'        => OrderLine::CATEGORY_MEMBERSHIP,
            'orderable_type'  => MembershipPeriod::class,
            'orderable_id'    => $period->id,
            'description'     => ($typeLabels[$this->membership_type] ?? 'Medlemskap') . ' ' . $this->targetYear(),
            'unit_price_ore'  => $unitPrice,
            'quantity'        => 1,
            'discount_ore'    => $discountAmount,
            'line_total_ore'  => $lineTotal,
        ]);

        // Bundle active rentals for the membership year being paid, if not already invoiced this year
        $targetYear = $this->targetYear();
        $member->activeRentals()->with('item.type')->each(function (Rental $rental) use ($order, $targetYear) {
            $alreadyBilled = $rental->orderLines()
                ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->whereYear('order_lines.created_at', $targetYear)
                ->exists();
            if (! $alreadyBilled) {
                $rental->addLineToOrder($order, $targetYear);
            }
        });

        // Bundle annual tag fee if a price is configured for this year and not already invoiced.
        // Always adds a line (even 0 kr) so it's clear the fee was processed.
        $tagYearPrice = TagYearPrice::where('year', $targetYear)->first();
        if ($tagYearPrice) {
            $tagAnnualFee = $tagYearPrice->annual_fee_ore;
            $member->tags()
                ->where('status', MemberTag::STATUS_ACTIVE)
                ->each(function (MemberTag $tag) use ($order, $targetYear, $tagAnnualFee) {
                    $alreadyBilled = $tag->orderLines()
                        ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
                        ->where('category', OrderLine::CATEGORY_TAG)
                        ->whereYear('order_lines.created_at', $targetYear)
                        ->exists();
                    if (! $alreadyBilled) {
                        OrderLine::create([
                            'order_id'       => $order->id,
                            'category'       => OrderLine::CATEGORY_TAG,
                            'orderable_type' => MemberTag::class,
                            'orderable_id'   => $tag->id,
                            'description'    => 'RFID-tagg årsavgift ' . $targetYear,
                            'unit_price_ore' => $tagAnnualFee,
                            'quantity'       => 1,
                            'discount_ore'   => 0,
                            'line_total_ore' => $tagAnnualFee,
                        ]);
                        if ($tagAnnualFee > 0) {
                            $order->increment('total_ore', $tagAnnualFee);
                        }
                    }
                });
        }

        if ($discount && $fee && $discountAmount > 0) {
            DiscountUse::create([
                'discount_id'       => $discount->id,
                'discount_code_id'  => $this->promoCodeId,
                'member_id'         => $member->id,
                'discountable_type' => OrderLine::class,
                'discountable_id'   => $line->id,
                'original_amount'   => $fee->amount,
                'discount_amount'   => $discountAmount,
            ]);

            // Mark single-use code as redeemed
            if ($this->promoCodeId) {
                $usedCode = DiscountCode::find($this->promoCodeId);
                if ($usedCode?->is_single_use) {
                    $usedCode->update([
                        'redeemed_by_member_id' => $member->id,
                        'redeemed_at'           => now(),
                    ]);
                }
            }
        }

        if ($this->membership_type === Member::TYPE_FAMILY && ! empty($this->familyMembers)) {
            $maxChildAge = (int) SystemSetting::get('family.max_child_age', 18);
            $adultCutoff = now()->subYears($maxChildAge);

            $familyGroup = FamilyGroup::create(['administrator_member_id' => $member->id]);

            $member->update(['family_group_id' => $familyGroup->id]);

            DB::table('family_members')->insert([
                'family_group_id' => $familyGroup->id,
                'member_id'       => $member->id,
                'role'            => FamilyGroup::ROLE_ADULT,
                'has_own_account' => true,
            ]);

            foreach ($this->familyMembers as $fm) {
                $dob  = ($fm['date_of_birth'] ?? '') ? Carbon::parse($fm['date_of_birth']) : null;
                $role = ($dob && $dob->isAfter($adultCutoff))
                    ? FamilyGroup::ROLE_CHILD
                    : FamilyGroup::ROLE_ADULT;

                $fmember = Member::create([
                    'user_id'         => null,
                    'family_group_id' => $familyGroup->id,
                    'first_name'      => $fm['first_name'],
                    'last_name'       => $fm['last_name'],
                    'email'           => ($fm['email'] ?? '') ?: null,
                    'date_of_birth'   => ($fm['date_of_birth'] ?? '') ?: null,
                    'membership_type' => Member::TYPE_FAMILY,
                    'status'          => Member::STATUS_PENDING,
                ]);

                MembershipPeriod::create([
                    'member_id'       => $fmember->id,
                    'year'            => $this->targetYear(),
                    'membership_type' => Member::TYPE_FAMILY,
                    'status'          => MembershipPeriod::STATUS_PENDING_PAYMENT,
                ]);

                DB::table('family_members')->insert([
                    'family_group_id' => $familyGroup->id,
                    'member_id'       => $fmember->id,
                    'role'            => $role,
                    'has_own_account' => false,
                ]);
            }
        }

        AuditLog::record('membership_applied', 'members', $member->full_name . ' (' . $this->membership_type . ')', $member);

        if (auth()->user()->email) {
            try {
                Mail::to(auth()->user()->email)->queue(new MembershipApplied($member));
            } catch (\Throwable $e) {
                Log::error('Failed to queue MembershipApplied mail', [
                    'member_id' => $member->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $adminEmail = config('mail.contact_address', config('mail.from.address'));
        try {
            Mail::to($adminEmail)->queue(new NewMemberAdminMail($member));
        } catch (\Throwable $e) {
            Log::error('Failed to queue NewMemberAdminMail', [
                'member_id' => $member->id,
                'error'     => $e->getMessage(),
            ]);
        }

        $this->redirect(route('membership.payment'), navigate: true);
    }

    public function typeLabel(string $type): string
    {
        return match($type) {
            Member::TYPE_SINGLE   => 'Vuxen',
            Member::TYPE_FAMILY   => 'Familj',
            Member::TYPE_YOUTH    => 'Ungdom',
            Member::TYPE_CHILD    => 'Barn',
            Member::TYPE_STUDENT  => 'Student',
            Member::TYPE_SENIOR   => 'Senior',
            Member::TYPE_HONORARY => 'Hedersmedlem',
            default               => $type,
        };
    }
}; ?>

<div class="container mx-auto max-w-2xl px-4 py-12 sm:px-6">

    <div class="mb-8">
        <h1 class="text-3xl font-bold text-zinc-900 dark:text-white">Bli medlem</h1>
        <p class="mt-2 text-zinc-500 dark:text-zinc-400">
            Fyll i dina uppgifter nedan för att anmäla dig som medlem. Betalning ordnas i nästa steg.
        </p>
    </div>

    <form wire:submit="save" class="space-y-6">

        {{-- Personal details --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Personuppgifter</h2>
            </div>
            <div class="space-y-5 p-5">

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Förnamn <span class="text-red-500">*</span>
                        </label>
                        <input wire:model="first_name" type="text" autocomplete="given-name"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('first_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Efternamn <span class="text-red-500">*</span>
                        </label>
                        <input wire:model="last_name" type="text" autocomplete="family-name"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('last_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        E-post <span class="text-red-500">*</span>
                    </label>
                    <input wire:model="email" type="email" autocomplete="email"
                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Telefon</label>
                        <input wire:model="phone" type="tel" autocomplete="tel" placeholder="070-123 45 67"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Födelsedag</label>
                        <input wire:model="date_of_birth" type="date" autocomplete="bday"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('date_of_birth') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

            </div>
        </div>

        {{-- Address --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Adress</h2>
            </div>
            <div class="space-y-4 p-5">
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Gatuadress</label>
                    <input wire:model="address" type="text" autocomplete="street-address" placeholder="Storgatan 1"
                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                    @error('address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_2fr]">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Postnummer</label>
                        <input wire:model="postal_code" type="text" autocomplete="postal-code" placeholder="503 38"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('postal_code') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Ort</label>
                        <input wire:model="city" type="text" autocomplete="address-level2" placeholder="Borås"
                               class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                        @error('city') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Membership type --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">
                    Typ av medlemskap
                    @if($this->targetYear() !== now()->year)
                        <span class="ml-1.5 inline-flex items-center rounded-full bg-complement/10 px-2 py-0.5 text-xs font-medium text-complement dark:bg-complement/20 dark:text-complement-light">
                            {{ $this->targetYear() }}
                        </span>
                    @endif
                </h2>
                @if($this->targetYear() !== now()->year)
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                        Du registrerar dig nu för {{ $this->targetYear() }} – avgiften gäller hela det kommande året.
                    </p>
                @endif
            </div>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse($availableFees as $fee)
                    @php
                        $type           = $fee->membership_type;
                        $feeDiscount    = $this->discountFor($type);
                        $discountOre    = $feeDiscount ? $feeDiscount->calculate($fee->amount) : 0;
                        $finalOre       = $fee->amount - $discountOre;
                        $finalFormatted = number_format($finalOre / 100, 2, ',', ' ') . ' kr';
                    @endphp
                    <label class="flex cursor-pointer items-center gap-4 px-5 py-4 hover:bg-zinc-50 dark:hover:bg-zinc-800/50
                        {{ $membership_type === $type ? 'bg-brand/5 dark:bg-brand/5' : '' }}">
                        <input type="radio" wire:model.live="membership_type" value="{{ $type }}"
                               class="size-4 accent-brand-dark" />
                        <div class="flex-1">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $this->typeLabel($type) }}</span>
                            @if($type === Member::TYPE_FAMILY)
                                <span class="ml-2 text-xs text-zinc-400">Upp till 2 vuxna + barn</span>
                            @elseif($type === Member::TYPE_STUDENT)
                                <span class="ml-2 text-xs text-zinc-400">Giltig studentlegitimation krävs</span>
                            @elseif($fee->ageLabel())
                                <span class="ml-2 text-xs text-zinc-400">{{ $fee->ageLabel() }}</span>
                            @endif
                        </div>
                        @if($discountOre > 0)
                            <div class="text-right">
                                <span class="block text-xs text-zinc-400 line-through">{{ $fee->formattedAmount() }}</span>
                                <span class="font-semibold text-green-700 dark:text-green-400">{{ $finalFormatted }}</span>
                            </div>
                        @else
                            <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $fee->formattedAmount() }}</span>
                        @endif
                    </label>
                @empty
                    <p class="px-5 py-4 text-sm text-zinc-500 dark:text-zinc-400">
                        Inga medlemskapstyper är konfigurerade för {{ $this->targetYear() }}.
                    </p>
                @endforelse
            </div>
            @error('membership_type') <p class="px-5 pb-3 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        {{-- Family members --}}
        @if($membership_type === Member::TYPE_FAMILY)
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="border-b border-zinc-200 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Familjemedlemmar</h2>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                        Lägg till övriga familjemedlemmar. Vuxna och barn bestäms automatiskt utifrån ålder.
                    </p>
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    {{-- Primary member (read-only) --}}
                    <div class="flex items-center gap-3 px-5 py-3.5">
                        <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand/10 text-xs font-bold text-brand-dark dark:bg-brand/20 dark:text-brand">
                            {{ mb_strtoupper(mb_substr($first_name, 0, 1)) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                {{ trim("$first_name $last_name") ?: 'Du' }}
                            </p>
                            <p class="text-xs text-zinc-400">Vuxen · betalningsansvarig</p>
                        </div>
                    </div>

                    {{-- Additional family members --}}
                    @foreach($familyMembers as $i => $fm)
                        <div class="px-5 py-4">
                            <div class="mb-3 flex items-center justify-between">
                                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                                    Familjemedlem {{ $i + 2 }}
                                </span>
                                <button type="button" wire:click="removeFamilyMember({{ $i }})"
                                        class="text-xs text-zinc-400 hover:text-red-500 dark:text-zinc-500 dark:hover:text-red-400">
                                    Ta bort
                                </button>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                        Förnamn <span class="text-red-500">*</span>
                                    </label>
                                    <input wire:model="familyMembers.{{ $i }}.first_name" type="text"
                                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                                    @error("familyMembers.{$i}.first_name") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                        Efternamn <span class="text-red-500">*</span>
                                    </label>
                                    <input wire:model="familyMembers.{{ $i }}.last_name" type="text"
                                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                                    @error("familyMembers.{$i}.last_name") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Födelsedag</label>
                                    <input wire:model="familyMembers.{{ $i }}.date_of_birth" type="date"
                                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                                    @error("familyMembers.{$i}.date_of_birth") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                        E-post <span class="font-normal text-zinc-400">(valfritt)</span>
                                    </label>
                                    <input wire:model="familyMembers.{{ $i }}.email" type="email" placeholder="För bekräftelse…"
                                           class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100" />
                                    @error("familyMembers.{$i}.email") <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
                    <button type="button" wire:click="addFamilyMember"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-complement hover:text-complement-light dark:text-complement-light">
                        <x-bootstrap-icon name="plus-circle" class="size-4" />
                        Lägg till familjemedlem
                    </button>
                </div>
            </div>
        @endif

        {{-- Promo code --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 bg-zinc-50 px-5 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">Rabattkod</h2>
            </div>
            <div class="p-5">
                @if($promoCodeDisplay)
                    {{-- Code applied --}}
                    @php
                        $promoDiscount = $promoDiscountId ? \App\Models\Shop\Discount::find($promoDiscountId) : null;
                        $selectedFee   = $availableFees->firstWhere('membership_type', $membership_type);
                        $promoAmount   = ($promoDiscount && $selectedFee) ? $promoDiscount->calculate($selectedFee->amount) : 0;
                    @endphp
                    <div class="flex items-center justify-between rounded-lg bg-green-50 px-4 py-3 dark:bg-green-900/10">
                        <div class="flex items-center gap-2">
                            <x-bootstrap-icon name="check-circle-fill" class="size-4 text-green-600 dark:text-green-400" />
                            <span class="font-mono text-sm font-semibold text-green-800 dark:text-green-300">{{ $promoCodeDisplay }}</span>
                            @if($promoDiscount)
                                <span class="text-sm text-green-700 dark:text-green-400">
                                    — {{ $promoDiscount->type === 'percentage' ? $promoDiscount->value . '%' : number_format($promoDiscount->value / 100, 2, ',', ' ') . ' kr' }} rabatt
                                    @if($promoAmount > 0 && $selectedFee)
                                        (−{{ number_format($promoAmount / 100, 2, ',', ' ') }} kr)
                                    @endif
                                </span>
                            @endif
                        </div>
                        <button type="button" wire:click="removePromoCode"
                                class="text-xs text-zinc-400 hover:text-red-500 dark:text-zinc-500 dark:hover:text-red-400">
                            Ta bort
                        </button>
                    </div>
                @else
                    <div class="flex gap-3">
                        <input wire:model="promoCodeInput" type="text" placeholder="Ange rabattkod…"
                               class="inp flex-1 font-mono uppercase"
                               wire:keydown.enter.prevent="applyPromoCode" />
                        <button type="button" wire:click="applyPromoCode"
                                class="btn">
                            Använd
                        </button>
                    </div>
                    @if($promoError)
                        <p class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $promoError }}</p>
                    @endif
                    <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">Valfritt. Lämna tomt om du inte har en kod.</p>
                @endif
            </div>
        </div>

        {{-- Submit --}}
        <div class="flex gap-3">
            <button type="submit"
                    class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                Anmäl dig
            </button>
            <a href="{{ route('dashboard') }}" wire:navigate
               class="rounded-lg px-4 py-2.5 text-sm font-semibold text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                Avbryt
            </a>
        </div>

    </form>
</div>
