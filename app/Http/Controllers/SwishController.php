<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Shop\Order;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Olssonm\Swish\Callback;
use Olssonm\Swish\Certificate;
use Olssonm\Swish\Client;
use Olssonm\Swish\Payment;
use Olssonm\Swish\QR;

class SwishController extends Controller
{
    /**
     * Swish's fixed, publicly documented MSS (Merchant Swish Simulator) test
     * payee alias. Never issued to a real merchant, so matching on it is a
     * safe signal to route Payment Request calls to the sandbox endpoint
     * instead of production — no separate "test mode" setting needed.
     */
    private const MSS_TEST_PAYEE_ALIAS = '1234679304';

    private function swishClient(): Client
    {
        $certPath = (string) SystemSetting::get('payment.swish_certificate_path', '');
        $certPass = (string) SystemSetting::get('payment.swish_certificate_passphrase', '') ?: null;

        abort_if(empty($certPath) || ! file_exists($certPath), 503, 'Swish Handel är inte konfigurerat.');

        $isSandbox = SystemSetting::get('payment.swish_number', '') === self::MSS_TEST_PAYEE_ALIAS;
        $endpoint  = $isSandbox ? Client::TEST_ENDPOINT : Client::PRODUCTION_ENDPOINT;

        // No custom root CA needed — Swish's sandbox TLS certificate chains
        // to the same publicly trusted root as production, verified against
        // the system's default CA bundle (Certificate's default rootPath=true).
        return new Client(new Certificate($certPath, $certPass), $endpoint);
    }

    /**
     * payment.swish_emulator_enabled — explicit opt-in only, never inferred
     * from APP_ENV (staging intentionally runs with APP_ENV=production).
     * Skips the real Swish API entirely so dev/staging can exercise the full
     * approve/decline flow without a merchant certificate.
     */
    private function isEmulatorEnabled(): bool
    {
        return (bool) SystemSetting::get('payment.swish_emulator_enabled', false);
    }

    /**
     * Resolve and authorize an order from the request.
     * Works for both authenticated users and session-based guests.
     */
    private function resolveOrder(Request $request): Order
    {
        $orderId = (int) $request->input('order_id');
        abort_if(! $orderId, 422, 'order_id saknas.');

        $order = Order::findOrFail($orderId);

        if (auth()->check()) {
            abort_unless(
                $order->user_id === null || $order->user_id === auth()->id(),
                403
            );
        } else {
            abort_unless(session('event_order_id') === $orderId, 403);
        }

        abort_if($order->isPaid(), 422, 'Ordern är redan betald.');

        return $order;
    }

    /**
     * Retrieve the current pending order from session.
     */
    private function sessionOrder(): ?Order
    {
        $orderId = session('pending_order_id');
        return $orderId ? Order::find($orderId) : null;
    }

    /**
     * Create a Swish M-Commerce payment request and redirect to the pending page.
     */
    public function checkout(Request $request): RedirectResponse
    {
        $order = $this->resolveOrder($request);

        $successUrl = $request->input('success_url', route('dashboard'));
        session([
            'pending_order_id'    => $order->id,
            'swish_success_url'   => $successUrl,
        ]);

        if ($this->isEmulatorEnabled()) {
            Log::warning('Swish emulator aktiv — ingen riktig betalning sker', ['order_id' => $order->id]);
            $order->update(['swish_payment_id' => (string) Str::uuid()]);

            return redirect()->route('payment.swish.emulator');
        }

        $swishNumber = preg_replace('/\D/', '', (string) SystemSetting::get('payment.swish_number', ''));

        $payment = new Payment([
            'payeeAlias'            => $swishNumber,
            'payeePaymentReference' => $order->payment_reference,
            'callbackUrl'           => route('swish.callback'),
            'amount'                => number_format($order->total_ore / 100, 2, '.', ''),
            'currency'              => 'SEK',
            'message'               => $order->payment_reference,
        ]);

        $result = $this->swishClient()->create($payment);

        $order->update(['swish_payment_id' => $payment->id]);

        session(['swish_payment_token' => $result->paymentRequestToken]);

        return redirect()->route('payment.swish.pending');
    }

    /**
     * Show the pending page while the user approves in the Swish app.
     */
    public function pending(Request $request): View
    {
        $token = session('swish_payment_token');
        $order = $this->sessionOrder();

        $qrDataUrl = null;
        if ($token) {
            try {
                $qrResult  = $this->swishClient()->create(new QR(['token' => $token, 'size' => 300]));
                $qrDataUrl = $qrResult->toBase64();
            } catch (\Throwable $e) {
                Log::warning('Swish QR generation failed', ['error' => $e->getMessage()]);
            }
        }

        return view('payment.swish-pending', [
            'token'      => $token,
            'qrDataUrl'  => $qrDataUrl,
            'order'      => $order,
            'successUrl' => session('swish_success_url', route('dashboard')),
        ]);
    }

    /**
     * Fake "Swish app" confirmation screen — approve/decline buttons that
     * directly call Order::markPaid()/markFailed(), bypassing the real API
     * and callback entirely. payment.swish_emulator_enabled only.
     */
    public function emulator(): View
    {
        abort_unless($this->isEmulatorEnabled(), 404);

        $order = $this->sessionOrder();
        abort_unless($order, 404);

        return view('payment.swish-emulator', [
            'order'      => $order,
            'successUrl' => session('swish_success_url', route('dashboard')),
        ]);
    }

    public function emulatorApprove(): RedirectResponse
    {
        abort_unless($this->isEmulatorEnabled(), 404);

        $order = $this->sessionOrder();
        abort_unless($order, 404);

        Log::warning('Swish emulator: betalning godkänd (fejk)', ['order_id' => $order->id]);
        $order->markPaid(Order::METHOD_SWISH);

        return redirect(session('swish_success_url', route('dashboard')));
    }

    public function emulatorDecline(): RedirectResponse
    {
        abort_unless($this->isEmulatorEnabled(), 404);

        $order = $this->sessionOrder();
        abort_unless($order, 404);

        Log::warning('Swish emulator: betalning nekad (fejk)', ['order_id' => $order->id]);
        $order->markFailed('swish_emulator_declined');

        return redirect()->route('membership.payment');
    }

    /**
     * AJAX endpoint: poll for the current payment status.
     */
    public function status(Request $request): JsonResponse
    {
        $order = $this->sessionOrder();

        if (! $order) {
            return response()->json(['status' => 'UNKNOWN']);
        }

        // Fast path: order already marked paid (callback fired)
        if ($order->isPaid()) {
            return response()->json([
                'status'      => 'PAID',
                'redirect_to' => session('swish_success_url', route('dashboard')),
            ]);
        }

        if (! $order->swish_payment_id) {
            return response()->json(['status' => 'UNKNOWN']);
        }

        try {
            $payment = $this->swishClient()->get(new Payment(['id' => $order->swish_payment_id]));
            return response()->json(['status' => $payment->status]);
        } catch (\Throwable $e) {
            Log::warning('Swish status check failed', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'ERROR']);
        }
    }

    /**
     * Generate a pre-filled Swish QR code for a pending order.
     * Used for private Swish — no certificate required.
     */
    public function qr(Request $request): Response
    {
        $orderId = (int) $request->query('order_id', session('pending_order_id', 0));
        $order   = $orderId ? Order::find($orderId) : null;

        abort_if(! $order, 404);

        $payee = preg_replace('/\D/', '', (string) SystemSetting::get('payment.swish_number', ''));

        try {
            $res = Http::timeout(5)->post('https://mpc.getswish.net/qrg-swish/api/v1/prefilled', [
                'format'  => 'png',
                'size'    => 300,
                'payee'   => ['value' => $payee,                            'editable' => false],
                'amount'  => ['value' => round($order->total_ore / 100, 2), 'editable' => false],
                'message' => ['value' => $order->payment_reference,          'editable' => false],
            ]);

            if ($res->successful()) {
                return response($res->body(), 200, ['Content-Type' => 'image/png']);
            }

            Log::warning('Swish pre-filled QR API error', ['status' => $res->status()]);
        } catch (\Throwable $e) {
            Log::warning('Swish pre-filled QR request failed', ['error' => $e->getMessage()]);
        }

        abort(503, 'Swish QR-generering misslyckades.');
    }

    /**
     * Swish server callback (excluded from CSRF).
     */
    public function callback(Request $request): JsonResponse
    {
        try {
            $result = Callback::parse($request->getContent());
        } catch (\Throwable $e) {
            Log::error('Swish callback parse failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        if (! ($result instanceof Payment)) {
            return response()->json(['status' => 'ok']);
        }

        if ($result->status === 'PAID') {
            $this->handleSwishPaid($result);
        } elseif (in_array($result->status, ['DECLINED', 'ERROR'], true)) {
            $this->handleSwishFailed($result);
        } else {
            // e.g. CREATED — payment still in progress, nothing to do yet
            Log::info('Swish callback: non-terminal status', ['id' => $result->id, 'status' => $result->status]);
        }

        return response()->json(['status' => 'ok']);
    }

    private function handleSwishFailed(Payment $payment): void
    {
        $order = Order::where('swish_payment_id', $payment->id)->first();

        if (! $order) {
            Log::warning('Swish callback: order not found for failed payment', ['swish_id' => $payment->id]);
            return;
        }

        $order->markFailed('swish_'.strtolower($payment->status));

        Log::info('Swish payment failed', [
            'order_id' => $order->id,
            'swish_id' => $payment->id,
            'status'   => $payment->status,
        ]);
    }

    private function handleSwishPaid(Payment $payment): void
    {
        $order = Order::where('swish_payment_id', $payment->id)->first();

        if (! $order) {
            Log::warning('Swish callback: order not found', ['swish_id' => $payment->id]);
            return;
        }

        if ($order->isPaid()) {
            return;
        }

        $order->markPaid(Order::METHOD_SWISH_HANDEL);

        AuditLog::record('payment_recorded', 'payments', "Order {$order->payment_reference} – Swish", $order, ['method' => Order::METHOD_SWISH_HANDEL]);

        Log::info('Swish payment confirmed', [
            'order_id' => $order->id,
            'swish_id' => $payment->id,
        ]);
    }
}
