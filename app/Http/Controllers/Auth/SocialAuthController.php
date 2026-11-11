<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Membership\Member;
use App\Models\User;
use App\Models\WorkspaceAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function linkRedirect(): RedirectResponse
    {
        session(['google_link_intent' => true]);
        return Socialite::driver('google')->redirect();
    }

    public function unlinkPersonal(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->notification_email === $user->google_personal_email) {
            $user->update(['notification_email' => null]);
        }
        $user->update(['google_personal_email' => null]);

        return redirect()->route('dashboard')->with('flash_google', 'Personligt Google-konto kopplades bort.');
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $socialUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect()->route('login')->withErrors(['email' => 'Inloggning med Google misslyckades. Försök igen.']);
        }

        $email = $socialUser->getEmail();

        if (! $email) {
            return redirect()->route('login')->withErrors(['email' => 'Ingen e-postadress tillhandahölls av Google.']);
        }

        // ── Link mode: authenticated user is connecting a personal account ────
        if (session()->pull('google_link_intent')) {
            return $this->handleLink($request, $email);
        }

        // ── Login mode ────────────────────────────────────────────────────────
        $user = User::where('email', $email)
            ->orWhere('google_id', $socialUser->getId())
            ->orWhere('google_personal_email', $email)
            ->first();

        // Fall back to matching via workspace account on the member profile
        if (! $user) {
            $account = WorkspaceAccount::where('email', $email)
                ->where('accountable_type', 'member')
                ->with('accountable.user')
                ->first();
            $user = $account?->accountable?->user;
        }

        if ($user) {
            $user->update([
                'google_id' => $socialUser->getId(),
                'name'      => $socialUser->getName() ?: $user->name,
            ]);
        } else {
            $user = User::create([
                'name'              => $socialUser->getName() ?: $email,
                'email'             => $email,
                'email_verified_at' => now(),
                'password'          => Hash::make(Str::random(32)),
                'google_id'         => $socialUser->getId(),
            ]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        if ($request->cookie('kiosk_origin')) {
            $request->session()->put('kiosk_mode', true);
            \Illuminate\Support\Facades\Cookie::queue(\Illuminate\Support\Facades\Cookie::forget('kiosk_origin'));
        }

        return redirect()->intended(route('dashboard'));
    }

    private function handleLink(Request $request, string $email): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (strtolower($email) === strtolower($user->email)) {
            return redirect()->route('dashboard')
                ->withErrors(['google_link' => 'Det är redan din primära e-postadress.']);
        }

        $conflict = User::where('email', $email)
            ->orWhere('google_personal_email', $email)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($conflict) {
            return redirect()->route('dashboard')
                ->withErrors(['google_link' => 'Den e-postadressen är redan kopplad till ett annat konto.']);
        }

        $user->update(['google_personal_email' => $email]);

        return redirect()->route('dashboard')->with('flash_google', 'Personligt Google-konto kopplat: ' . $email);
    }
}
