<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ScannerRelayStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScannerRelayController extends Controller
{
    public function scan(Request $request, ScannerRelayStore $store, string $session): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $data = $request->validate([
            'code' => 'required|string|max:512',
        ]);

        $result = $store->push($session, $data['code']);

        if ($result === null) {
            return response()->json(['message' => 'Okänd eller utgången parkoppling.'], 404);
        }

        return response()->json($result);
    }

    public function heartbeat(Request $request, ScannerRelayStore $store, string $session): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('events.manage'), 403);

        $result = $store->heartbeat($session);

        if ($result === null) {
            return response()->json(['message' => 'Okänd eller utgången parkoppling.'], 404);
        }

        return response()->json($result);
    }
}
