<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MagicLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class MagicLinkController extends Controller
{
    public function request(Request $request, MagicLinkService $service): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $key = 'magic-link:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->with('magic_link_status', 'För många försök. Vänta en stund och försök igen.');
        }

        RateLimiter::hit($key, 60);

        // Always show success message to prevent user enumeration
        $user = User::where('email', strtolower($request->input('email')))->first();

        if ($user) {
            $service->send($user);
        }

        return back()->with('magic_link_status', 'Om e-postadressen finns i vårt system har vi skickat en inloggningslänk. Länken gäller i 15 minuter.');
    }

    public function verify(string $token, MagicLinkService $service): RedirectResponse
    {
        $user = $service->verify($token);

        if (! $user) {
            return redirect()->route('login')
                ->with('status', 'Inloggningslänken är ogiltig eller har gått ut. Begär en ny.');
        }

        Auth::login($user, remember: true);

        request()->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}
