<?php

namespace App\Services\Signing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Inleed DocSign REST API client (https://docsign.se/api).
 *
 * Observed behaviour (tested 2026-10-23, see issue #134):
 * - Errors come back with HTTP 200 and `"success": false` — always check `success`.
 * - Ids are integers in GET responses and sometimes strings in POST responses;
 *   everything is normalized to string here.
 * - `signed_pdf_url` is "" (not null) until signed; normalized to null.
 *
 * Config (.env): DOCSIGN_API_KEY
 */
class DocsignClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://docsign.se/api',
        private readonly int $timeout = 30,
    ) {}

    public static function fromConfig(): self
    {
        $key = config('services.docsign.api_key');

        if (! $key) {
            throw new DocsignException('DOCSIGN_API_KEY saknas.');
        }

        return new self($key, config('services.docsign.base_url'), config('services.docsign.timeout'));
    }

    /** @return array{email: string|null, balance_ore: int} */
    public function account(): array
    {
        $body = $this->decode($this->http()->get('/account', $this->auth()));

        return ['email' => $body['email'] ?? null, 'balance_ore' => (int) ($body['balance'] ?? 0)];
    }

    /** @return list<array<string, mixed>> */
    public function listParties(): array
    {
        return array_map($this->normalizeParty(...), $this->decode($this->http()->get('/parties', $this->auth())));
    }

    /**
     * @param  array{name: string, email?: string|null, phone_number?: string|null, sign_method: string, company?: string|null, external_id?: string|int|null}  $party
     * @return string DocSign's party id
     */
    public function createParty(array $party, bool $debug = false): ?string
    {
        $body = $this->decode($this->http()->post('/parties', $this->auth() + $this->withoutNulls($party) + ['debug' => $debug]));

        return isset($body['party_id']) ? (string) $body['party_id'] : null;
    }

    /** @return list<array<string, mixed>> */
    public function listDocuments(?string $state = null): array
    {
        $params = $this->auth() + ($state ? ['state' => $state] : []);

        return array_map($this->normalizeDocument(...), $this->decode($this->http()->get('/documents', $params)));
    }

    public function findDocument(string $documentId): ?array
    {
        foreach ($this->listDocuments() as $document) {
            if ($document['id'] === $documentId) {
                return $document;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document  name, comment, parties (ids), attachments, callback_url, callback_sign_url, is_demo, send_*
     * @return string DocSign's document id
     */
    public function createDocument(array $document, bool $debug = false): ?string
    {
        $body = $this->decode($this->http()->post('/documents', $this->auth() + $this->withoutNulls($document) + ['debug' => $debug]));

        return isset($body['document_id']) ? (string) $body['document_id'] : null;
    }

    /**
     * pause (pending only), resume (paused only), delete (paused or completed — pause
     * a pending document first), archive/unarchive (completed only; only visible in
     * DocSign's web UI). An unknown action returns success without doing anything,
     * hence the check here. Tested 2026-10-27.
     */
    public function editDocument(string $documentId, string $action): void
    {
        if (! in_array($action, ['pause', 'resume', 'delete', 'archive', 'unarchive'], true)) {
            throw new \InvalidArgumentException("Unknown DocSign document action: {$action}");
        }

        $this->decode($this->http()->post('/document/edit', $this->auth() + ['document_id' => $documentId, 'action' => $action]));
    }

    /**
     * Delete a party. Allowed even while it is on a pending document; documents keep
     * their copy of the party. Returns false if DocSign no longer has it (HTTP 404).
     */
    public function deleteParty(string $partyId): bool
    {
        try {
            $this->decode($this->http()->post('/party/edit', $this->auth() + ['party_id' => $partyId, 'action' => 'delete']));
        } catch (DocsignException $e) {
            if ($e->status === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->acceptJson()->asJson()->timeout($this->timeout);
    }

    /** @return array{api_key: string} */
    private function auth(): array
    {
        return ['api_key' => $this->apiKey];
    }

    private function decode(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new DocsignException("Oväntat svar från DocSign (HTTP {$response->status()}).", status: $response->status());
        }

        if ($response->failed()) {
            throw new DocsignException($body['message'] ?? "HTTP {$response->status()}", status: $response->status());
        }

        if (array_key_exists('success', $body) && $body['success'] !== true) {
            throw new DocsignException($body['message'] ?? 'Okänt fel', $body['data'] ?? [], $response->status());
        }

        return $body;
    }

    private function normalizeParty(array $party): array
    {
        $party['id'] = (string) $party['id'];
        $party['external_id'] = isset($party['external_id']) ? (string) $party['external_id'] : null;

        return $party;
    }

    private function normalizeDocument(array $document): array
    {
        $document['id'] = (string) $document['id'];
        foreach (['original_pdf_url', 'signed_pdf_url'] as $key) {
            $document[$key] = ($document[$key] ?? '') === '' ? null : $document[$key];
        }
        $document['parties'] = array_map($this->normalizeParty(...), $document['parties'] ?? []);

        return $document;
    }

    private function withoutNulls(array $data): array
    {
        return array_filter($data, fn ($value) => $value !== null);
    }
}
