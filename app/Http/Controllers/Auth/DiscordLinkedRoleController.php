<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DiscordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DiscordLinkedRoleController extends Controller
{
    private const TOKEN_URL = 'https://discord.com/api/v10/oauth2/token';
    private const AUTH_URL  = 'https://discord.com/oauth2/authorize';
    private const USER_URL  = 'https://discord.com/api/v10/users/@me';

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('discord_linked_role_state', $state);

        $params = http_build_query([
            'client_id'     => config('services.discord.client_id'),
            'redirect_uri'  => route('discord.linked-role.callback'),
            'response_type' => 'code',
            'scope'         => 'identify email role_connections.write',
            'state'         => $state,
            'prompt'        => 'consent',
        ]);

        return redirect(self::AUTH_URL . '?' . $params);
    }

    public function callback(Request $request, DiscordService $discord): RedirectResponse
    {
        if ($request->input('state') !== $request->session()->pull('discord_linked_role_state')) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Ogiltig state-parameter. Försök igen.']);
        }

        if (! $request->has('code')) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Discord-auktorisering avbröts.']);
        }

        // Exchange code for tokens
        $tokenResponse = Http::asForm()->post(self::TOKEN_URL, [
            'client_id'     => config('services.discord.client_id'),
            'client_secret' => config('services.discord.client_secret'),
            'grant_type'    => 'authorization_code',
            'code'          => $request->input('code'),
            'redirect_uri'  => route('discord.linked-role.callback'),
        ]);

        if (! $tokenResponse->successful()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Kunde inte hämta Discord-token. Försök igen.']);
        }

        $tokens = $tokenResponse->json();

        // Get Discord user info
        $userResponse = Http::withToken($tokens['access_token'])->get(self::USER_URL);

        if (! $userResponse->successful()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Kunde inte hämta Discord-användarinfo. Försök igen.']);
        }

        $discordUser = $userResponse->json();
        $discordId   = $discordUser['id'];
        $username    = $discordUser['global_name'] ?? $discordUser['username'];
        $email       = $discordUser['email'] ?? null;
        $expiresAt   = now()->addSeconds((int) $tokens['expires_in']);

        // Find or match user account
        $user = User::where('discord_id', $discordId)->first()
            ?? ($email ? User::where('email', $email)->first() : null);

        if (! $user) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Inget Armagedon-konto hittades för det här Discord-kontot. Logga in på vanligt sätt och koppla Discord under Inställningar.']);
        }

        // Store tokens and link account
        $user->update([
            'discord_id'                => $discordId,
            'discord_username'          => $username,
            'discord_linked_at'         => $user->discord_linked_at ?? now(),
            'discord_access_token'      => $tokens['access_token'],
            'discord_refresh_token'     => $tokens['refresh_token'] ?? null,
            'discord_token_expires_at'  => $expiresAt,
        ]);

        // Push membership metadata to Discord
        $discord->pushMetadata($user->fresh());

        // Log the user in
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('dashboard')
            ->with('flash_discord', 'Discord-kontot kopplades och din medlemsstatus verifierades.');
    }
}
