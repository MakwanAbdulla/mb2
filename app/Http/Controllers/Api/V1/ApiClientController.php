<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiClientController extends Controller
{
    /**
     * Exchange client credentials for a Sanctum Bearer token.
     *
     * POST /api/v1/auth/client-tokens
     */
    public function token(Request $request): JsonResponse
    {
        $request->validate([
            'client_id'     => 'required|uuid',
            'client_secret' => 'required|string',
            'token_name'    => 'nullable|string|max:100',
        ]);

        $client = ApiClient::where('client_id', $request->client_id)
            ->where('enabled', true)
            ->first();

        if (! $client || ! $client->verifySecret($request->client_secret)) {
            return response()->json(['message' => 'Invalid client credentials.'], 401);
        }

        $client->updateQuietly(['last_used_at' => now()]);

        $abilities  = $client->scopes ?? ['*'];
        $tokenName  = $request->token_name ?? $client->name;
        $token      = $client->user->createToken($tokenName, $abilities)->plainTextToken;

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'client'     => $client->name,
            'scopes'     => $client->scopes,
        ], 201);
    }
}
