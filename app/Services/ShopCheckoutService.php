<?php

namespace App\Services;

use App\Models\Accounting\Customer;
use App\Models\Events\EventRegistration;
use App\Models\Events\EventRegistrationAddon;
use App\Models\Events\EventRegistrationOccurrence;
use App\Models\Events\EventPriceTier;
use App\Models\Membership\Member;
use App\Models\Shop\Cart;
use App\Models\Shop\CartItem;
use App\Models\Shop\DiscountCode;
use App\Models\Shop\DiscountUse;
use App\Models\Shop\Order;
use App\Models\Shop\OrderFulfillment;
use App\Models\Shop\OrderLine;
use App\Models\Shop\ProductVariant;
use Illuminate\Support\Str;

class ShopCheckoutService
{
    /**
     * Create an Order from a Cart.
     *
     * @param  Cart         $cart
     * @param  int|null     $userId
     * @param  string|null  $guestName
     * @param  string|null  $guestEmail
     * @param  DiscountCode|null  $discountCode
     * @param  Member|null  $member  — used for discount eligibility check
     */
    public function createOrderFromCart(
        Cart $cart,
        ?int $userId,
        ?string $guestName,
        ?string $guestEmail,
        ?DiscountCode $discountCode = null,
        ?Member $member = null,
    ): Order {
        $cart->loadMissing('items.cartable');

        $subtotal = 0;
        foreach ($cart->items as $item) {
            $subtotal += $item->lineTotalOre();
        }

        $discountOre = 0;
        if ($discountCode && $discountCode->discount->eligibleFor($member)) {
            $discountOre = $discountCode->discount->calculate($subtotal);
        }

        $total = max(0, $subtotal - $discountOre);

        $customerId = null;
        if (! $userId && $guestEmail) {
            $customerId = Customer::where('email', $guestEmail)->value('id');
        }

        $order = Order::create([
            'user_id'      => $userId,
            'customer_id'  => $customerId,
            'guest_name'   => $userId ? null : $guestName,
            'guest_email'  => $userId ? null : $guestEmail,
            'status'       => Order::STATUS_PENDING,
            'total_ore'    => $total,
            'discount_ore' => $discountOre,
        ]);

        foreach ($cart->items as $item) {
            if ($item->isProduct()) {
                $this->createProductLine($order, $item);
            } elseif ($item->isEventTicket()) {
                $this->createEventTicketLine($order, $item, $userId, $guestName, $guestEmail);
            }
        }

        if ($discountCode && $discountOre > 0) {
            DiscountUse::create([
                'discount_id'       => $discountCode->discount_id,
                'discount_code_id'  => $discountCode->id,
                'member_id'         => $member?->id,
                'discountable_type' => Order::class,
                'discountable_id'   => $order->id,
                'original_amount'   => $subtotal,
                'discount_amount'   => $discountOre,
            ]);

            $discountCode->redeem($member);
        }

        return $order;
    }

    private function createProductLine(Order $order, CartItem $item): void
    {
        /** @var ProductVariant $variant */
        $variant = $item->cartable;
        $product = $variant->product;

        $line = $order->lines()->create([
            'category'       => OrderLine::CATEGORY_SHOP,
            'orderable_type' => ProductVariant::class,
            'orderable_id'   => $variant->id,
            'description'    => $variant->option_value
                ? "{$product->name} ({$variant->option_value})"
                : $product->name,
            'unit_price_ore' => $item->unit_price_ore,
            'quantity'       => $item->quantity,
            'discount_ore'   => 0,
            'line_total_ore' => $item->lineTotalOre(),
        ]);

        if ($product->track_stock) {
            $variant->decrementStock($item->quantity, $line->id);
        }

        if ($product->isPhysical()) {
            OrderFulfillment::create([
                'order_id'      => $order->id,
                'order_line_id' => $line->id,
                'status'        => OrderFulfillment::STATUS_PENDING,
            ]);
        }
    }

    private function createEventTicketLine(
        Order $order,
        CartItem $item,
        ?int $userId,
        ?string $guestName,
        ?string $guestEmail,
    ): void {
        /** @var EventPriceTier $tier */
        $tier  = $item->cartable;
        $event = $tier->event;
        $meta  = $item->meta ?? [];

        $registration = EventRegistration::create([
            'event_id'       => $event->id,
            'user_id'        => $userId,
            'guest_name'     => $userId ? null : ($meta['attendee_name'] ?? $guestName),
            'guest_email'    => $userId ? null : ($meta['attendee_email'] ?? $guestEmail),
            'price_tier_id'  => $tier->id,
            'status'         => EventRegistration::STATUS_PENDING_PAYMENT,
            'order_id'       => $order->id,
            'check_in_token' => Str::uuid(),
        ]);

        $occurrenceIds = $meta['occurrence_ids'] ?? [];
        if (empty($occurrenceIds)) {
            $occurrenceIds = $event->occurrences->pluck('id')->toArray();
        }

        foreach ($occurrenceIds as $occId) {
            EventRegistrationOccurrence::create([
                'registration_id' => $registration->id,
                'occurrence_id'   => $occId,
            ]);
        }

        foreach ($meta['addon_selections'] ?? [] as $sel) {
            EventRegistrationAddon::create([
                'registration_id' => $registration->id,
                'addon_id'        => $sel['addon_id'],
                'option_value'    => $sel['option_value'] ?? null,
                'quantity'        => $sel['quantity'] ?? 1,
            ]);
        }

        $order->lines()->create([
            'category'       => OrderLine::CATEGORY_EVENT,
            'orderable_type' => EventRegistration::class,
            'orderable_id'   => $registration->id,
            'description'    => "{$event->title} — {$tier->label}",
            'unit_price_ore' => $item->unit_price_ore,
            'quantity'       => 1,
            'discount_ore'   => 0,
            'line_total_ore' => $item->lineTotalOre(),
        ]);
    }
}
