<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Shop\DiscountCode;
use App\Models\Shop\Order;
use App\Models\SystemSetting;
use App\Services\CartService;
use App\Services\ShopCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class ShopCheckoutController extends Controller
{
    public function __construct(
        private CartService $cart,
        private ShopCheckoutService $checkout,
    ) {}

    /**
     * POST /api/v1/shop/checkout
     *
     * Convert cart to order and initiate payment.
     *
     * Body:
     *   payment_method  string   required — stripe|bankgiro
     *   guest_name      string   required if unauthenticated
     *   guest_email     string   required if unauthenticated
     *   discount_code   string   optional
     *   success_url     string   optional — redirect URL after Stripe payment (stripe only)
     *   cancel_url      string   optional — redirect URL on Stripe cancellation (stripe only)
     *
     * Response:
     *   stripe:    { order_id, payment_method, payment_url }
     *   bankgiro:  { order_id, payment_method, bankgiro, ocr_number, amount_ore, amount_formatted }
     */
    public function store(Request $request): JsonResponse
    {
        $cart = $this->cart->get();

        if (! $cart || $cart->isEmpty()) {
            return response()->json(['message' => 'Cart is empty.'], 422);
        }

        $stockErrors = $this->cart->validateStock();
        if (! empty($stockErrors)) {
            return response()->json(['message' => 'Stock unavailable.', 'errors' => $stockErrors], 422);
        }

        $isGuest = ! auth()->check();

        $data = $request->validate([
            'payment_method' => ['required', 'string', 'in:stripe,bankgiro'],
            'guest_name'     => [$isGuest ? 'required' : 'nullable', 'string', 'max:255'],
            'guest_email'    => [$isGuest ? 'required' : 'nullable', 'email', 'max:255'],
            'discount_code'  => ['nullable', 'string', 'max:100'],
            'success_url'    => ['nullable', 'url'],
            'cancel_url'     => ['nullable', 'url'],
        ]);

        $user        = auth()->user();
        $member      = $user?->member;
        $discountCode = null;

        if ($data['discount_code'] ?? null) {
            $discountCode = DiscountCode::with('discount')
                ->where('code', $data['discount_code'])
                ->first();

            if (! $discountCode || ! $discountCode->isAvailable()) {
                return response()->json(['message' => 'Invalid or expired discount code.'], 422);
            }
        }

        $order = DB::transaction(fn () => $this->checkout->createOrderFromCart(
            cart:         $cart,
            userId:       $user?->id,
            guestName:    $isGuest ? $data['guest_name'] : null,
            guestEmail:   $isGuest ? $data['guest_email'] : null,
            discountCode: $discountCode,
            member:       $member,
        ));

        $this->cart->destroy();

        AuditLog::record('shop_order_placed', 'shop', "Order #{$order->id}", $order);

        return match ($data['payment_method']) {
            'stripe'    => $this->stripeResponse($request, $order),
            'bankgiro'  => $this->bankgiroResponse($order),
        };
    }

    private function stripeResponse(Request $request, Order $order): JsonResponse
    {
        $secretKey = (string) SystemSetting::get('payment.stripe_secret_key', '');

        if (empty($secretKey)) {
            return response()->json(['message' => 'Stripe is not configured.'], 503);
        }

        $successUrl = $request->input('success_url', route('dashboard'));
        $cancelUrl  = $request->input('cancel_url', route('shop.checkout'));

        $stripe = new StripeClient($secretKey);

        $lineItems = $order->lines->map(fn ($line) => [
            'price_data' => [
                'currency'     => 'sek',
                'unit_amount'  => $line->unit_price_ore,
                'product_data' => ['name' => $line->description ?? 'Betalning'],
            ],
            'quantity' => $line->quantity ?? 1,
        ])->values()->toArray();

        $session = $stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'mode'                 => 'payment',
            'line_items'           => $lineItems,
            'metadata'             => [
                'order_id'  => $order->id,
                'reference' => $order->payment_reference,
            ],
            'customer_email' => $order->user?->email ?? $order->guest_email ?? null,
            'success_url'    => route('payment.stripe.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'     => $cancelUrl,
        ]);

        $order->update(['stripe_session_id' => $session->id]);

        return response()->json([
            'order_id'       => $order->id,
            'payment_method' => 'stripe',
            'payment_url'    => $session->url,
        ]);
    }

    private function bankgiroResponse(Order $order): JsonResponse
    {
        $bankgiro = SystemSetting::get('payment.bankgiro_number', '');

        return response()->json([
            'order_id'         => $order->id,
            'payment_method'   => 'bankgiro',
            'bankgiro'         => $bankgiro,
            'ocr_number'       => $order->ocr_number,
            'amount_ore'       => $order->total_ore,
            'amount_formatted' => number_format($order->total_ore / 100, 2, ',', ' ') . ' kr',
        ]);
    }
}
