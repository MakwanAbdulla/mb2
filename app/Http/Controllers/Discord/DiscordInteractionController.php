<?php

namespace App\Http\Controllers\Discord;

use App\Http\Controllers\Controller;
use App\Services\DiscordCommandHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SodiumException;

class DiscordInteractionController extends Controller
{
    private const TYPE_PING = 1;

    private const TYPE_APPLICATION_COMMAND = 2;

    private const RESPONSE_PONG = 1;

    private const RESPONSE_CHANNEL_MESSAGE = 4;

    /**
     * Requests signed more than five minutes from now are rejected, so a
     * captured request cannot be sent again later.
     */
    private const MAX_SIGNATURE_AGE = 300;

    public function __invoke(Request $request, DiscordCommandHandler $commands): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            return response()->json(['error' => 'invalid request signature'], 401);
        }

        return match ($request->json('type')) {
            self::TYPE_PING => response()->json(['type' => self::RESPONSE_PONG]),
            self::TYPE_APPLICATION_COMMAND => response()->json([
                'type' => self::RESPONSE_CHANNEL_MESSAGE,
                'data' => $commands->handle((array) $request->json('data', []), $this->discordUserId($request)),
            ]),
            default => response()->json(['error' => 'unsupported interaction type'], 400),
        };
    }

    /**
     * Discord signs every request with the application's key. Anything that
     * fails the check is rejected, as Discord's documentation requires.
     */
    private function hasValidSignature(Request $request): bool
    {
        $publicKey = (string) config('services.discord.public_key');
        $signature = (string) $request->header('X-Signature-Ed25519');
        $timestamp = (string) $request->header('X-Signature-Timestamp');

        if (! $this->isHex($publicKey, 64) || ! $this->isHex($signature, 128) || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAX_SIGNATURE_AGE) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                hex2bin($signature),
                $timestamp.$request->getContent(),
                hex2bin($publicKey),
            );
        } catch (SodiumException) {
            return false;
        }
    }

    /**
     * In a server the user is sent under member.user, in a direct message under user.
     */
    private function discordUserId(Request $request): ?string
    {
        $id = $request->json('member.user.id') ?? $request->json('user.id');

        return $id === null ? null : (string) $id;
    }

    private function isHex(string $value, int $length): bool
    {
        return strlen($value) === $length && ctype_xdigit($value);
    }
}
