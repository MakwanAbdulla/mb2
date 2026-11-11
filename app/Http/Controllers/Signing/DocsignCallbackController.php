<?php

namespace App\Http\Controllers\Signing;

use App\Enums\SigningEventType;
use App\Http\Controllers\Controller;
use App\Models\Signing\SigningRequest;
use App\Services\Signing\SigningService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Receives DocSign callbacks (`callback_url` → completed, `callback_sign_url` → signed).
 *
 * DocSign's callbacks are not authenticated, so they must come from one of Inleed's
 * addresses (services.docsign.callback_ips) and carry the request's own secret
 * token in the URL, stored only as a hash. What DocSign sends is logged, then the
 * request is synced from the API — status is never taken from the callback itself.
 *
 * Observed format (2026-10-23): GET with `document_id` (and `party_id` for signed)
 * in the query string, empty body.
 */
class DocsignCallbackController extends Controller
{
    /** Headers worth keeping to learn the format; everything else is dropped. */
    private const KEPT_HEADERS = ['user-agent', 'content-type', 'content-length', 'accept'];

    public function __invoke(Request $request, SigningRequest $signingRequest, string $token, string $event): Response
    {
        $allowedIps = config('services.docsign.callback_ips', []);
        if ($allowedIps !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowedIps)) {
            Log::warning('DocSign callback from unknown address', ['signing_request_id' => $signingRequest->id, 'ip' => $request->ip()]);
            abort(404);
        }

        if (! $signingRequest->callbackTokenMatches($token)) {
            Log::warning('DocSign callback with invalid token', ['signing_request_id' => $signingRequest->id, 'ip' => $request->ip()]);
            abort(404);
        }

        $headers = collect($request->headers->all())
            ->filter(fn ($value, string $name) => in_array($name, self::KEPT_HEADERS, true) || str_starts_with($name, 'x-'))
            ->map(fn (array $values) => implode(', ', $values))
            ->all();

        $body = $request->isJson() ? $request->json()->all() : $request->post();

        $signingRequest->recordEvent(SigningEventType::CallbackReceived, [
            'event' => $event,
            'method' => $request->method(),
            'query' => $request->query(),
            'body' => $body,
            // Only when Laravel couldn't parse it. Key-based scrubbing can't reach
            // free text, so anything shaped like a personnummer is masked here.
            'raw_body' => $body === [] && $request->getContent() !== ''
                ? self::maskPersonnummer(mb_substr($request->getContent(), 0, 2000))
                : null,
            'headers' => $headers,
            'ip' => $request->ip(),
        ]);

        // Status is never taken from the callback — fetch it from the API. Always answer
        // 200: a failure is logged and the scheduled signing:sync retries.
        try {
            app(SigningService::class)->sync($signingRequest);
        } catch (Throwable $e) {
            $signingRequest->recordEvent(SigningEventType::Error, ['stage' => 'callback_sync', 'message' => $e->getMessage()]);
        }

        return response('OK', 200);
    }

    public static function maskPersonnummer(string $text): string
    {
        return preg_replace('/\b(\d{2})?\d{6}[-+]?\d{4}\b/', '[personnummer]', $text);
    }
}
