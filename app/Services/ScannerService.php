<?php

namespace App\Services;

use App\Models\Events\Event;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventRegistrationAddon;
use App\Models\Membership\Member;
use App\Models\Membership\MemberTag;
use App\Models\Shop\Discount;
use App\Models\Shop\DiscountCode;
use App\Models\Shop\OrderFulfillment;
use App\Services\MemberCardService;

/**
 * Central lookup service for the scanner station.
 *
 * Each "resolver" handles one code namespace. Add new resolvers here
 * when new scannable types are introduced (event registrations, etc.).
 *
 * A resolver returns a ScanResult or null if the code doesn't belong to it.
 * Resolvers are tried in priority order; the first non-null result wins.
 */
class ScannerService
{
    /**
     * Prefixes reserved for non-discount code namespaces.
     * Auto-generated discount codes never contain hyphens, so they cannot
     * collide. Manually entered codes are validated against this list.
     *
     * Add a new entry here whenever a new scannable type is introduced.
     * Phase 3: 'REG-' for event registrations / tickets.
     */
    public const RESERVED_PREFIXES = [
        'REG-',   // Event registrations
        'TKT-',   // Tickets (reserved, unused)
        'DIS-',   // Discount codes (optional prefix on printed/QR material)
        'ITM-',   // Fulfillable items: event add-ons + webshop order fulfillments
    ];
    /**
     * Resolve a raw scanned string into a typed ScanResult.
     *
     * Pass $event to enable member/walk-in resolution (RFID tag UID and membership numbers).
     * Without $event, member codes fall through to not_found.
     */
    public function resolve(string $rawCode, ?Event $event = null): ScanResult
    {
        $code = strtoupper(trim($rawCode));

        if ($code === '') {
            return ScanResult::empty();
        }

        // --- Resolver: member card QR URL (verifiera/{id}/{token}) ---
        if (preg_match('#/verifiera/(\d+)/([0-9a-f]{64})#i', $rawCode, $m)) {
            return $this->resolveMemberCardQr((int) $m[1], $m[2], $event);
        }

        // --- Resolver: event registration (prefix REG-) ---
        if (str_starts_with($code, 'REG-')) {
            return $this->resolveEventRegistration($code);
        }

        // --- Resolver: fulfillable item (prefix ITM-) ---
        if (str_starts_with($code, 'ITM-')) {
            return $this->resolveItem($code);
        }

        // --- Resolver: RFID tag UID (hex string, 8–16 chars, optionally with colons/spaces) ---
        $cleanHex = preg_replace('/[\s:]/', '', $code);
        if ($event && preg_match('/^[0-9A-F]{8,16}$/', $cleanHex)) {
            $result = $this->resolveMemberByTagUid($cleanHex, $event);
            if ($result !== null) {
                return $result;
            }
        }

        // --- Resolver: membership number (pure digits) ---
        if ($event && preg_match('/^\d{1,8}$/', $code)) {
            $result = $this->resolveMemberByNumber($code, $event);
            if ($result !== null) {
                return $result;
            }
        }

        // --- Resolver: discount code (bare alphanumeric, or with optional DIS- prefix) ---
        $discountCode = str_starts_with($code, 'DIS-') ? substr($code, 4) : $code;
        $result = $this->resolveDiscountCode($discountCode);
        if ($result !== null) {
            return $result;
        }

        return ScanResult::notFound($code);
    }

    // -------------------------------------------------------------------------
    // Member resolvers (RFID tag UID + membership number)
    // -------------------------------------------------------------------------

    private function resolveMemberByTagUid(string $hexUid, Event $event): ?ScanResult
    {
        $tag = MemberTag::with(['member.user'])
            ->where('tag_uid', $hexUid)
            ->where('status', MemberTag::STATUS_ACTIVE)
            ->first();

        if (! $tag) {
            return null;
        }

        return $this->memberResult($tag->member, $event);
    }

    private function resolveMemberByNumber(string $number, Event $event): ?ScanResult
    {
        $member = Member::with(['user'])
            ->where('membership_number', $number)
            ->first();

        if (! $member) {
            return null;
        }

        return $this->memberResult($member, $event);
    }

    private function memberResult(Member $member, Event $event): ScanResult
    {
        $registration = EventRegistration::with(['priceTier'])
            ->where('event_id', $event->id)
            ->where(function ($q) use ($member) {
                $q->where('user_id', $member->user_id)
                  ->orWhereHas('user', fn ($u) => $u->where('id', $member->user_id));
            })
            ->whereIn('status', [
                EventRegistration::STATUS_CONFIRMED,
                EventRegistration::STATUS_PENDING_PAYMENT,
                EventRegistration::STATUS_PENDING_APPROVAL,
            ])
            ->first();

        if ($registration) {
            // Already registered — forward to normal event registration resolver result
            if ($registration->status !== EventRegistration::STATUS_CONFIRMED) {
                return ScanResult::make(
                    type:    ScanResult::TYPE_EVENT_REGISTRATION,
                    status:  ScanResult::STATUS_INVALID,
                    model:   $registration,
                    message: 'Anmälan har status "' . $registration->status . '" och kan inte checkas in.',
                );
            }

            return ScanResult::make(
                type:   ScanResult::TYPE_EVENT_REGISTRATION,
                status: ScanResult::STATUS_AVAILABLE,
                model:  $registration,
            );
        }

        // Member found but not registered for this event
        return ScanResult::make(
            type:    ScanResult::TYPE_MEMBER,
            status:  ScanResult::STATUS_NOT_REGISTERED,
            model:   $member,
            message: null,
        );
    }

    // -------------------------------------------------------------------------
    // Member card QR URL resolver
    // -------------------------------------------------------------------------

    private function resolveMemberCardQr(int $memberId, string $token, ?Event $event): ScanResult
    {
        // Check valid (±5s)
        $valid = MemberCardService::verifyToken($memberId, $token, tolerance: 5);
        // Check expired (±60s) to distinguish expired vs forged
        $expired = !$valid && MemberCardService::verifyToken($memberId, $token, tolerance: 60);

        if (!$valid && !$expired) {
            return ScanResult::make(
                type:    ScanResult::TYPE_NOT_FOUND,
                status:  ScanResult::STATUS_INVALID,
                model:   null,
                message: 'Ogiltig QR-kod — inte utfärdad av Armagedon.',
            );
        }

        $member = Member::with(['user', 'membershipPeriods'])
            ->find($memberId);

        if (!$member) {
            return ScanResult::notFound((string) $memberId);
        }

        if ($expired) {
            return ScanResult::make(
                type:    ScanResult::TYPE_MEMBER,
                status:  ScanResult::STATUS_EXPIRED,
                model:   $member,
                message: 'QR-koden har gått ut — be medlemmen visa en ny.',
            );
        }

        // Valid — now check event registration status
        if ($event) {
            return $this->memberResult($member, $event);
        }

        return ScanResult::make(
            type:   ScanResult::TYPE_MEMBER,
            status: ScanResult::STATUS_AVAILABLE,
            model:  $member,
        );
    }

    // -------------------------------------------------------------------------
    // Discount code resolver
    // -------------------------------------------------------------------------

    private function resolveDiscountCode(string $code): ?ScanResult
    {
        $discountCode = DiscountCode::with(['discount', 'redeemedBy'])
            ->where('code', $code)
            ->first();

        if (! $discountCode) {
            return null;
        }

        if ($discountCode->is_single_use && $discountCode->redeemed_at) {
            return ScanResult::make(
                type:    ScanResult::TYPE_DISCOUNT,
                status:  ScanResult::STATUS_ALREADY_USED,
                model:   $discountCode,
                message: "Koden är redan inlöst " . $discountCode->redeemed_at->format('d/m Y \k\l. H:i') . ".",
            );
        }

        if (! $discountCode->discount->isValid()) {
            return ScanResult::make(
                type:    ScanResult::TYPE_DISCOUNT,
                status:  ScanResult::STATUS_INVALID,
                model:   $discountCode,
                message: "Rabatten är inte giltig (inaktiv eller utanför giltighetstid).",
            );
        }

        return ScanResult::make(
            type:   ScanResult::TYPE_DISCOUNT,
            status: ScanResult::STATUS_AVAILABLE,
            model:  $discountCode,
        );
    }

    // -------------------------------------------------------------------------
    // CTA helpers — "what should happen after scanning this code?"
    // -------------------------------------------------------------------------

    /**
     * Return a human-readable label for what a discount targets.
     * Shown on the scan station result card.
     */
    public function targetLabel(Discount $discount): string
    {
        $typeLabels = [
            'single'  => 'Vuxen',
            'family'  => 'Familj',
            'youth'   => 'Ungdom',
            'child'   => 'Barn',
            'student' => 'Student',
            'senior'  => 'Senior',
        ];

        return match ($discount->applies_to) {
            Discount::APPLIES_MEMBERSHIP => $discount->membership_type
                ? ($typeLabels[$discount->membership_type] ?? $discount->membership_type) . 'medlemskap'
                : 'Medlemskap (alla typer)',
            Discount::APPLIES_EVENT => 'Evenemang', // Phase 3: will specify event name
            Discount::APPLIES_SHOP  => 'Webshop',   // Phase 5: will specify product name
            default                 => 'Alla kategorier',
        };
    }

    /**
     * Return the URL to start the purchase/registration for a discount.
     * Returns null when no specific flow exists yet (event, shop).
     */
    public function ctaUrl(Discount $discount, string $code): ?string
    {
        if (in_array($discount->applies_to, [Discount::APPLIES_MEMBERSHIP, Discount::APPLIES_ALL])) {
            $params = ['code' => $code];
            if ($discount->membership_type) {
                $params['type'] = $discount->membership_type;
            }
            return route('membership.apply') . '?' . http_build_query($params);
        }

        // Phase 3: event registration URL
        // if ($discount->applies_to === Discount::APPLIES_EVENT && $discount->event_id) {
        //     return route('events.register', $discount->event) . '?code=' . $code;
        // }

        // Phase 5: shop product URL
        // if ($discount->applies_to === Discount::APPLIES_SHOP && $discount->product_id) {
        //     return route('shop.product', $discount->product) . '?code=' . $code;
        // }

        return null;
    }

    /**
     * Label for the CTA button on the scan station.
     */
    public function ctaLabel(Discount $discount): string
    {
        return match ($discount->applies_to) {
            Discount::APPLIES_MEMBERSHIP => 'Starta anmälan →',
            Discount::APPLIES_EVENT      => 'Anmäl dig →',   // Phase 3
            Discount::APPLIES_SHOP       => 'Gå till shop →', // Phase 5
            default                      => 'Starta köp →',
        };
    }

    // -------------------------------------------------------------------------
    // Fulfillable item resolver (ITM-)
    // -------------------------------------------------------------------------

    private function resolveItem(string $code): ScanResult
    {
        $token = strtolower(substr($code, 4));

        // Try event add-on first
        $addon = EventRegistrationAddon::with([
            'addon',
            'registration.user',
            'registration.event',
        ])->where('item_token', $token)->first();

        if ($addon) {
            if ($addon->isRedeemed()) {
                return ScanResult::make(
                    type:    ScanResult::TYPE_ITEM_ADDON,
                    status:  ScanResult::STATUS_ALREADY_USED,
                    model:   $addon,
                    message: 'Inlöst ' . $addon->redeemed_at->format('d/m Y \k\l. H:i') . '.',
                );
            }
            return ScanResult::make(
                type:   ScanResult::TYPE_ITEM_ADDON,
                status: ScanResult::STATUS_AVAILABLE,
                model:  $addon,
            );
        }

        // Try webshop fulfillment
        $fulfillment = OrderFulfillment::with([
            'order.user',
            'orderLine',
        ])->where('item_token', $token)->first();

        if ($fulfillment) {
            if ($fulfillment->isPickedUp()) {
                return ScanResult::make(
                    type:    ScanResult::TYPE_ITEM_FULFILLMENT,
                    status:  ScanResult::STATUS_ALREADY_USED,
                    model:   $fulfillment,
                    message: 'Hämtad ' . $fulfillment->picked_up_at->format('d/m Y \k\l. H:i') . '.',
                );
            }
            return ScanResult::make(
                type:   ScanResult::TYPE_ITEM_FULFILLMENT,
                status: ScanResult::STATUS_AVAILABLE,
                model:  $fulfillment,
            );
        }

        return ScanResult::notFound($code);
    }

    // -------------------------------------------------------------------------
    // Event registration resolver
    // -------------------------------------------------------------------------

    private function resolveEventRegistration(string $code): ScanResult
    {
        // QR codes encode "REG-{UPPERCASE_TOKEN}" — strip the prefix to get the token
        $token = strtolower(substr($code, 4));

        $registration = EventRegistration::with(['event.occurrences', 'user', 'priceTier'])
            ->where('check_in_token', $token)
            ->first();

        if (! $registration) {
            return ScanResult::notFound($code);
        }

        if ($registration->status !== EventRegistration::STATUS_CONFIRMED) {
            return ScanResult::make(
                type:    ScanResult::TYPE_EVENT_REGISTRATION,
                status:  ScanResult::STATUS_INVALID,
                model:   $registration,
                message: 'Anmälan har status "' . $registration->status . '" och kan inte checkas in.',
            );
        }

        return ScanResult::make(
            type:   ScanResult::TYPE_EVENT_REGISTRATION,
            status: ScanResult::STATUS_AVAILABLE,
            model:  $registration,
        );
    }
}
