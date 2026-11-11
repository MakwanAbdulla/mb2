<?php

namespace App\Services;

class MemberCardService
{
    public static function generateToken(int $memberId): string
    {
        $ts = (int) floor(time());
        return hash_hmac('sha256', $memberId . '.' . $ts, config('app.key'));
    }

    public static function verifyToken(int $memberId, string $token, int $tolerance = 15): bool
    {
        $now = (int) floor(time());
        for ($offset = -$tolerance; $offset <= $tolerance; $offset++) {
            $expected = hash_hmac('sha256', $memberId . '.' . ($now + $offset), config('app.key'));
            if (hash_equals($expected, $token)) {
                return true;
            }
        }
        return false;
    }
}
