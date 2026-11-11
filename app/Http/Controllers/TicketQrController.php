<?php

namespace App\Http\Controllers;

use App\Models\Events\EventRegistration;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Response;

class TicketQrController extends Controller
{
    /**
     * Serve a QR code SVG for the given check-in token.
     *
     * The route is public (no auth) because the token itself is the secret.
     * The QR encodes "REG-{token}" which the scanner station resolves.
     */
    public function show(string $token): Response
    {
        $registration = EventRegistration::where('check_in_token', $token)->first();

        if ($registration === null || $registration->status !== EventRegistration::STATUS_CONFIRMED) {
            return response($this->invalidSvg(), 200, [
                'Content-Type'  => 'image/svg+xml',
                'Cache-Control' => 'no-store',
            ]);
        }

        $renderer = new ImageRenderer(
            new RendererStyle(200, 0),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $svg    = $writer->writeString('REG-' . strtoupper($token));

        return response($svg, 200, [
            'Content-Type'  => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function invalidSvg(): string
    {
        return <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
            <rect width="200" height="200" rx="8" fill="#fef2f2"/>
            <rect width="200" height="200" rx="8" fill="none" stroke="#fca5a5" stroke-width="2"/>
            <line x1="60" y1="60" x2="140" y2="140" stroke="#ef4444" stroke-width="10" stroke-linecap="round"/>
            <line x1="140" y1="60" x2="60" y2="140" stroke="#ef4444" stroke-width="10" stroke-linecap="round"/>
            <text x="100" y="172" font-family="system-ui, sans-serif" font-size="13" font-weight="600"
                  fill="#dc2626" text-anchor="middle">Ogiltig biljett</text>
        </svg>
        SVG;
    }
}
