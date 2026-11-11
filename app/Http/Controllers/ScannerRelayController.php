<?php

namespace App\Http\Controllers;

use App\Services\ScannerRelayStore;
use App\Support\QrPng;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ScannerRelayController extends Controller
{
    public function qr(string $session): Response
    {
        $png = QrPng::render('PAIR-' . $session);

        return response($png, 200, [
            'Content-Type'   => 'image/png',
            'Content-Length' => strlen($png),
            'Cache-Control'  => 'no-store',
        ]);
    }

    public function poll(Request $request, ScannerRelayStore $store, string $session): JsonResponse
    {
        $after = (int) $request->query('after', 0);
        $result = $store->poll($session, $after);

        if ($result === null) {
            return response()->json(['message' => 'Parkopplingen har gått ut.'], 404);
        }

        return response()->json($result);
    }
}
