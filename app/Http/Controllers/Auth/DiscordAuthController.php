<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DiscordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class DiscordAuthController extends Controller
{
    // ── Login (unauthenticated) ───────────────────────────────────────────────

    public function loginRedirect(): RedirectResponse
    {
        return Socialite::driver('discord')
            ->scopes(['identify', 'email'])
            ->redirect();
    }

    public function loginCallback(Request $request, DiscordService $discord): RedirectResponse
    {
        try {
            $socialUser = Socialite::driver('discord')->user();
        } catch (\Exception) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Discord-inloggning misslyckades. Försök igen.']);
        }

        // 1. Matcha på kopplat discord_id
        $user = User::where('discord_id', $socialUser->getId())->first();

        // 2. Matcha på e-postadress — koppla automatiskt
        if (! $user && $socialUser->getEmail()) {
            $user = User::where('email', $socialUser->getEmail())->first();

            if ($user) {
                if (User::where('discord_id', $socialUser->getId())->where('id', '!=', $user->id)->exists()) {
                    return redirect()->route('login')
                        ->withErrors(['email' => 'Det Discord-kontot är redan kopplat till ett annat konto.']);
                }

                $user->update([
                    'discord_id'        => $socialUser->getId(),
                    'discord_username'  => $socialUser->getNickname() ?? $socialUser->getName(),
                    'discord_linked_at' => now(),
                ]);

                $discord->syncUser($user->fresh());
            }
        }

        if (! $user) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Inget Armagedon-konto hittades för det här Discord-kontot. Logga in på vanligt sätt och koppla Discord under Inställningar.']);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    // ── Link (authenticated) ──────────────────────────────────────────────────

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('discord')
            ->scopes(['identify'])
            ->redirect();
    }

    public function callback(Request $request, DiscordService $discord): RedirectResponse
    {
        try {
            $socialUser = Socialite::driver('discord')->user();
        } catch (\Exception) {
            return redirect()->route('dashboard', ['#installningar'])
                ->withErrors(['discord_link' => 'Discord-koppling misslyckades. Försök igen.']);
        }

        $user = $request->user();

        // Prevent stealing another user's linked Discord account
        if (User::where('discord_id', $socialUser->getId())->where('id', '!=', $user->id)->exists()) {
            return redirect()->route('dashboard', ['#installningar'])
                ->withErrors(['discord_link' => 'Det Discord-kontot är redan kopplat till ett annat Armagedon-konto.']);
        }

        $user->update([
            'discord_id'         => $socialUser->getId(),
            'discord_username'   => $socialUser->getNickname() ?? $socialUser->getName(),
            'discord_linked_at'  => now(),
        ]);

        $discord->syncUser($user->fresh());

        return redirect()->route('dashboard', ['#installningar'])
            ->with('flash_discord', 'Discord-kontot ' . ($socialUser->getNickname() ?? $socialUser->getName()) . ' kopplades till ditt konto.');
    }

    public function unlink(Request $request, DiscordService $discord): RedirectResponse
    {
        $user = $request->user();

        // Remove all managed Discord roles before unlinking
        $discord->syncUser($user); // will no-op if not configured

        $user->update([
            'discord_id'        => null,
            'discord_username'  => null,
            'discord_linked_at' => null,
        ]);

        return redirect()->route('dashboard', ['#installningar'])
            ->with('flash_discord', 'Discord-kontot kopplades bort.');
    }
}
