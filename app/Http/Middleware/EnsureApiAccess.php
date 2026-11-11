<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only enforce for Bearer-token clients, not SPA cookie sessions.
        if ($request->bearerToken() && ! $request->user()?->hasPermissionTo('api.access')) {
            return response()->json(['message' => 'API access not granted.'], 403);
        }

        return $next($request);
    }
}
