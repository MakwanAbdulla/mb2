<?php

namespace App\Services\Accounting;

use App\Models\SystemSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Zettle (PayPal POS) REST API client.
 *
 * Auth: JWT Bearer Token flow (assertion provided by Zettle Developer Portal).
 *
 * Config (.env):
 *   ZETTLE_CLIENT_ID=019da0e6-...
 *   ZETTLE_ASSERTION=eyJ...   (long-lived JWT from Zettle Developer Portal)
 *
 * The assertion is exchanged for a short-lived access_token (7200 s).
 * We cache the token in system_settings and refresh it 5 min before expiry.
 */
class ZettleClient
{
    private const TOKEN_URL    = 'https://oauth.zettle.com/token';
    private const PURCHASE_URL = 'https://purchase.izettle.com/purchases/v2';
    private const FINANCE_URL  = 'https://finance.izettle.com';

    public function __construct(
        private readonly string $clientId,
        private readonly string $assertion,
    ) {}

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    public function getAccessToken(): string
    {
        $cached    = SystemSetting::get('zettle.access_token');
        $expiresAt = (int) SystemSetting::get('zettle.token_expires_at', 0);

        if ($cached && $expiresAt > now()->addMinutes(5)->timestamp) {
            return $cached;
        }

        return $this->fetchNewToken();
    }

    private function fetchNewToken(): string
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'client_id'  => $this->clientId,
            'assertion'  => $this->assertion,
        ]);

        $response->throw();

        $data      = $response->json();
        $token     = $data['access_token'];
        $expiresIn = $data['expires_in'] ?? 7200;

        SystemSetting::set('zettle.access_token', $token);
        SystemSetting::set('zettle.token_expires_at', (string) (now()->timestamp + $expiresIn));

        return $token;
    }

    // -------------------------------------------------------------------------
    // Purchases
    // -------------------------------------------------------------------------

    /**
     * Fetch a page of purchases.
     *
     * Zettle paginates via `lastPurchaseHash` (a composite token from the response,
     * not purchaseUUID). Pass null for the first page.
     *
     * Returns ['purchases' => [...], 'lastPurchaseHash' => '...']
     * `lastPurchaseHash` is null when there are no more pages.
     *
     * @return array{purchases: list<array>, lastPurchaseHash: string|null}
     */
    public function fetchPurchases(?string $lastPurchaseHash = null, int $limit = 200): array
    {
        $params = ['limit' => $limit];
        if ($lastPurchaseHash) {
            $params['lastPurchaseHash'] = $lastPurchaseHash;
        }

        $response = $this->get(self::PURCHASE_URL, $params);
        $response->throw();

        $body = $response->json();

        return [
            'purchases'        => $body['purchases'] ?? [],
            'lastPurchaseHash' => ! empty($body['purchases']) ? ($body['lastPurchaseHash'] ?? null) : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Finance / payouts
    // -------------------------------------------------------------------------

    /**
     * Fetch PAYOUT entries from the LIQUID account for a date range.
     *
     * Each entry represents one bank transfer (negative amount in LIQUID = positive
     * deposit to the merchant's bank account). We return abs(amount) as amount_ore.
     *
     * Zettle paginates via `lastTransactionOffset`; pass null for the first page.
     *
     * @return array{data: list<array>, cursor: string|null}
     */
    /**
     * Fetch PAYOUT entries from the LIQUID account for a date range.
     *
     * The Finance API returns a flat JSON array (no pagination envelope).
     * PAYOUT entries have negative `amount` in LIQUID (money leaving to bank).
     *
     * @return list<array>
     */
    public function fetchLiquidPayouts(string $startDate, string $endDate): array
    {
        $response = $this->get(self::FINANCE_URL . '/v2/accounts/LIQUID/transactions', [
            'start'                  => $startDate . 'T00:00:00.000Z',
            'end'                    => $endDate . 'T23:59:59.999Z',
            'includeTransactionType' => 'PAYOUT',
        ]);

        $response->throw();

        return $response->json() ?? [];
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    private function get(string $url, array $query = []): Response
    {
        return Http::withToken($this->getAccessToken())
            ->get($url, $query);
    }
}
