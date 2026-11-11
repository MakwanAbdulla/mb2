<?php

namespace App\Services\Accounting;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SumUp REST API client.
 *
 * Auth: static API key (Bearer token) — no OAuth2 flow needed.
 *
 * Config (.env):
 *   SUMUP_API_KEY=sup_sk_...
 *
 * Pagination: cursor-based via `oldest_ref` from the `links[rel=next]` href.
 */
class SumupClient
{
    private const BASE_URL = 'https://api.sumup.com/v0.1';

    public function __construct(
        private readonly string $apiKey,
    ) {}

    /**
     * Fetch a page of transactions in ascending order.
     *
     * Pass $oldestRef from the previous page's next-link to paginate.
     * Returns ['items' => [...], 'nextRef' => '...' | null]
     *
     * @return array{items: list<array>, nextRef: string|null}
     */
    public function fetchTransactions(?string $oldestRef = null, int $limit = 100): array
    {
        $params = [
            'limit' => $limit,
            'order' => 'ascending',
        ];

        if ($oldestRef) {
            $params['oldest_ref'] = $oldestRef;
        }

        $response = $this->get('/me/transactions/history', $params);
        $response->throw();

        $body  = $response->json();
        $items = $body['items'] ?? [];

        // Extract next cursor from links array
        $nextRef = null;
        foreach ($body['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'next') {
                parse_str($link['href'] ?? '', $parsed);
                $nextRef = $parsed['oldest_ref'] ?? null;
                break;
            }
        }

        // No next page if we got fewer items than the limit
        if (\count($items) < $limit) {
            $nextRef = null;
        }

        return ['items' => $items, 'nextRef' => $nextRef];
    }

    /**
     * Fetch payouts for a given month. Returns raw payout arrays.
     *
     * @return list<array>
     */
    public function fetchPayouts(string $startDate, string $endDate, int $limit = 1000): array
    {
        $response = $this->get('/me/financials/payouts', [
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'limit'      => $limit,
        ]);
        $response->throw();

        return $response->json() ?? [];
    }

    private function get(string $path, array $query = []): Response
    {
        return Http::withToken($this->apiKey)
            ->get(self::BASE_URL . $path, $query);
    }
}
