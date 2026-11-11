<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Events\EventPriceTier;
use App\Models\Membership\MembershipPeriod;
use App\Models\Shop\Order;
use App\Models\Shop\OrderLine;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Stripe\Webhook;

class StripeController extends Controller
{
    private function stripeClient(): StripeClient
    {
        $secretKey = (string) SystemSetting::get('payment.stripe_secret_key', '');
        abort_if(empty($secretKey), 503, 'Stripe är inte konfigurerat.');
        return new StripeClient($secretKey);
    }

    /**
     * Resolve and authorize an order from the request.
     * Works for both authenticated users and session-based guests.
     */
    private function resolveOrder(Request $request): Order
    {
        $orderId = (int) $request->input('order_id');
        abort_if(! $orderId, 422, 'order_id saknas.');

        $order = Order::with('lines.orderable', 'lines.discountUse', 'user')->findOrFail($orderId);

        if (auth()->check()) {
            abort_unless(
                $order->user_id === null || $order->user_id === auth()->id(),
                403
            );
        } else {
            // Guest: order must be the one stored in their session
            abort_unless(session('event_order_id') === $orderId, 403);
        }

        abort_if($order->isPaid(), 422, 'Ordern är redan betald.');

        return $order;
    }

    /**
     * Build Stripe line items from an order's lines.
     */
    private function buildLineItems(Order $order): array
    {
        $items = [];

        foreach ($order->lines as $line) {
            $name = match (true) {
                $line->orderable instanceof MembershipPeriod => $this->membershipLabel($line->orderable),
                $line->orderable instanceof EventPriceTier   => $line->orderable->label,
                default                                      => $line->description ?? 'Betalning',
            };

            // Apply discount if any
            $unitAmount = $line->unit_price_ore;
            if ($line->discountUse) {
                $unitAmount = max(0, $unitAmount - $line->discountUse->discount_amount);
            }

            $items[] = [
                'price_data' => [
                    'currency'     => 'sek',
                    'unit_amount'  => $unitAmount,
                    'product_data' => ['name' => $name],
                ],
                'quantity' => $line->quantity ?? 1,
            ];
        }

        // Fallback: single line item from order total
        if (empty($items)) {
            $items[] = [
                'price_data' => [
                    'currency'     => 'sek',
                    'unit_amount'  => $order->total_ore,
                    'product_data' => ['name' => 'Betalning'],
                ],
                'quantity' => 1,
            ];
        }

        return $items;
    }

    private function membershipLabel(MembershipPeriod $period): string
    {
        $typeLabels = [
            'single'   => 'Vuxenmedlemskap',
            'family'   => 'Familjemedlemskap',
            'youth'    => 'Ungdomsmedlemskap',
            'child'    => 'Barnmedlemskap',
            'student'  => 'Studentmedlemskap',
            'senior'   => 'Seniormedlemskap',
            'honorary' => 'Hedersmedlemskap',
        ];

        return ($typeLabels[$period->membership_type] ?? 'Medlemskap') . ' ' . $period->year;
    }

    /**
     * Create a Stripe Checkout Session and redirect to Stripe.
     */
    public function checkout(Request $request)
    {
        $order = $this->resolveOrder($request);

        $successUrl = $request->input('success_url', route('dashboard'));
        $cancelUrl  = $request->input('cancel_url', url()->previous());

        // Store so success() can redirect to the right place
        session(["stripe_success_url_{$order->id}" => $successUrl]);

        $customerEmail = $order->user?->email ?? $order->guest_email;

        $stripe  = $this->stripeClient();
        $session = $stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'mode'                 => 'payment',
            'line_items'           => $this->buildLineItems($order),
            'metadata'             => [
                'order_id'  => $order->id,
                'reference' => $order->payment_reference,
            ],
            'customer_email' => $customerEmail ?: null,
            'success_url'    => route('payment.stripe.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'     => $cancelUrl,
        ]);

        $order->update(['stripe_session_id' => $session->id]);

        return redirect($session->url);
    }

    /**
     * User returns from Stripe. The webhook is authoritative — we just redirect.
     */
    public function success(Request $request)
    {
        $sessionId = $request->query('session_id');

        $order = $sessionId
            ? Order::where('stripe_session_id', $sessionId)->first()
            : null;

        if ($order) {
            $redirectUrl = session("stripe_success_url_{$order->id}", route('dashboard'));
            session()->forget("stripe_success_url_{$order->id}");

            if ($order->isPaid()) {
                return redirect($redirectUrl)->with('success', 'Betalning bekräftad!');
            }

            // Webhook hasn't fired yet — show pending view
            return view('payment.stripe-pending', [
                'sessionId'   => $sessionId,
                'redirectUrl' => $redirectUrl,
            ]);
        }

        return redirect(route('dashboard'));
    }

    /**
     * Stripe webhook endpoint (excluded from CSRF).
     */
    public function webhook(Request $request)
    {
        $webhookSecret = (string) SystemSetting::get('payment.stripe_webhook_secret', '');
        $payload       = $request->getContent();
        $sigHeader     = $request->header('Stripe-Signature', '');

        try {
            // Without a webhook secret, Event::constructFrom() skips the SDK's
            // type-specific object mapping, so $event->data->object comes back
            // as a generic StripeObject rather than Checkout\Session — that's
            // why the handlers below type-hint StripeObject, not Session.
            $event = $webhookSecret
                ? Webhook::constructEvent($payload, $sigHeader, $webhookSecret)
                : \Stripe\Event::constructFrom(json_decode($payload, true));
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature mismatch', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $this->handleCheckoutCompleted($event->data->object);
        } elseif ($event->type === 'checkout.session.expired') {
            $this->handleCheckoutExpired($event->data->object);
        }

        return response()->json(['status' => 'ok']);
    }

    private function handleCheckoutCompleted(StripeObject $session): void
    {
        $order = Order::where('stripe_session_id', $session->id)->first();

        if (! $order) {
            Log::warning('Stripe webhook: order not found', ['session_id' => $session->id]);
            return;
        }

        if ($order->isPaid()) {
            return;
        }

        $order->markPaid(Order::METHOD_STRIPE);

        AuditLog::record('payment_recorded', 'payments', "Order {$order->payment_reference} – Stripe", $order, ['method' => Order::METHOD_STRIPE]);

        Log::info('Stripe payment confirmed', [
            'order_id'   => $order->id,
            'session_id' => $session->id,
        ]);
    }

    /**
     * Fires when a Checkout Session's 24h window lapses without payment —
     * the customer abandoned checkout or the card was declined and they
     * never retried. Must also be enabled in the Stripe Dashboard webhook
     * config (not sent by default unless "all events" is selected).
     */
    private function handleCheckoutExpired(StripeObject $session): void
    {
        $order = Order::where('stripe_session_id', $session->id)->first();

        if (! $order) {
            Log::warning('Stripe webhook: order not found for expired session', ['session_id' => $session->id]);
            return;
        }

        $order->markFailed('stripe_checkout_expired');

        Log::info('Stripe checkout expired', [
            'order_id'   => $order->id,
            'session_id' => $session->id,
        ]);
    }
}
