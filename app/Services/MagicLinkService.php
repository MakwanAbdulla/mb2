<?php

namespace App\Services;

use App\Mail\MagicLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MagicLinkService
{
    public const TTL_MINUTES = 15;

    public function send(User $user): void
    {
        // Invalidate any existing unused tokens for this user
        \DB::table('magic_link_tokens')
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();

        $token = Str::random(64);

        \DB::table('magic_link_tokens')->insert([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = route('magic-link.verify', ['token' => $token]);

        Mail::to($user->email)->send(new MagicLinkMail($user, $url));
    }

    public function verify(string $token): ?User
    {
        $row = \DB::table('magic_link_tokens')
            ->where('token', $token)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $row) {
            return null;
        }

        \DB::table('magic_link_tokens')
            ->where('id', $row->id)
            ->update(['used_at' => now(), 'updated_at' => now()]);

        return User::find($row->user_id);
    }
}
