<?php

namespace App\Http\Controllers;

use App\Models\Content\MailLog;
use Illuminate\Http\Response;

class MailTrackController extends Controller
{
    /**
     * Record an email open and return a 1×1 transparent GIF.
     * URL is intentionally short and doesn't require auth.
     */
    public function open(string $token): Response
    {
        $log = MailLog::where('tracking_token', $token)->first();

        if ($log) {
            $log->recordOpen();
        }

        // 1×1 transparent GIF (43 bytes)
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($pixel, 200, [
            'Content-Type'  => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }
}
