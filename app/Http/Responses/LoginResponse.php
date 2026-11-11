<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Cookie;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): mixed
    {
        if ($request->cookie('kiosk_origin')) {
            $request->session()->put('kiosk_mode', true);
            Cookie::queue(Cookie::forget('kiosk_origin'));
        }

        return redirect()->intended(Fortify::redirects('login', '/'));
    }
}
