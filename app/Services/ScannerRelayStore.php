<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ephemeral pairing between a browser-based scanner kiosk tab and a
 * physical device: the kiosk creates a session and shows it as a QR code,
 * the device scans it and starts POSTing raw codes tagged with that
 * session, and the kiosk polls for them. Pure message relay — no
 * check-in/scan-resolution logic lives here, that stays in ScannerService
 * on the kiosk side.
 */
class ScannerRelayStore
{
    private const PAIRING_TTL = 1800;   // 30 min to complete pairing
    private const ACTIVE_TTL  = 7200;   // 2h once in use, refreshed on every push/poll
    private const MAX_QUEUED  = 20;
    private const CONNECTED_WINDOW = 45; // seconds of silence before a side is "disconnected"

    public function createSession(): string
    {
        $sessionId = Str::upper(Str::random(6));

        Cache::put($this->key($sessionId), [
            'codes'          => [],
            'cursor'         => 0,
            'device_seen_at' => null,
            'browser_seen_at' => now()->timestamp,
        ], self::PAIRING_TTL);

        return $sessionId;
    }

    /** Device → server: a scanned code. Null if the session is unknown/expired. */
    public function push(string $sessionId, string $code): ?array
    {
        $session = Cache::get($this->key($sessionId));
        if ($session === null) {
            return null;
        }

        $session['cursor']++;
        $session['codes'][] = ['id' => $session['cursor'], 'code' => $code];
        $session['codes'] = array_slice($session['codes'], -self::MAX_QUEUED);
        $session['device_seen_at'] = now()->timestamp;

        Cache::put($this->key($sessionId), $session, self::ACTIVE_TTL);

        return $this->status($session);
    }

    /** Device → server: "still here", sent when nothing has been scanned recently. */
    public function heartbeat(string $sessionId): ?array
    {
        $session = Cache::get($this->key($sessionId));
        if ($session === null) {
            return null;
        }

        $session['device_seen_at'] = now()->timestamp;
        Cache::put($this->key($sessionId), $session, self::ACTIVE_TTL);

        return $this->status($session);
    }

    /** Browser → server: fetch codes queued since $after, and refresh presence. */
    public function poll(string $sessionId, int $after): ?array
    {
        $session = Cache::get($this->key($sessionId));
        if ($session === null) {
            return null;
        }

        $session['browser_seen_at'] = now()->timestamp;
        Cache::put($this->key($sessionId), $session, self::ACTIVE_TTL);

        $newCodes = array_values(array_filter(
            $session['codes'],
            fn (array $entry) => $entry['id'] > $after,
        ));

        return [
            'device_connected' => $this->isRecent($session['device_seen_at']),
            'codes'            => $newCodes,
            'cursor'           => $session['cursor'],
        ];
    }

    private function status(array $session): array
    {
        return [
            'ok'                => true,
            'browser_connected' => $this->isRecent($session['browser_seen_at']),
        ];
    }

    private function isRecent(?int $timestamp): bool
    {
        return $timestamp !== null && (now()->timestamp - $timestamp) <= self::CONNECTED_WINDOW;
    }

    private function key(string $sessionId): string
    {
        return "scanner_relay:{$sessionId}";
    }
}
