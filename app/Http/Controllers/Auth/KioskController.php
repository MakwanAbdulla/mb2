<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class KioskController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check() && session('kiosk_mode')) {
            return redirect()->intended(route('dashboard'));
        }

        // Cookie signals kiosk origin to Fortify's login flow too
        return response()
            ->view('auth.kiosk-login')
            ->cookie('kiosk_origin', '1', 60, secure: true, httpOnly: true, sameSite: 'lax');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();
            $request->session()->put('kiosk_mode', true);

            return redirect()->intended(route('dashboard'));
        }

        return back()->withErrors(['email' => 'Felaktiga inloggningsuppgifter.']);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('kiosk.login');
    }
}
