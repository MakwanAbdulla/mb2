<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SigningRequestResource;
use App\Models\AuditLog;
use App\Models\Signing\SigningRequest;
use App\Services\Signing\DocsignException;
use App\Services\Signing\SigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Throwable;

class SigningRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('signing.view') || SigningRequest::canBeCreatedBy($request->user()), 403);

        $requests = SigningRequest::query()
            ->visibleTo($request->user())
            ->with('signers.signer')
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return SigningRequestResource::collection($requests);
    }

    public function show(Request $request, SigningRequest $signingRequest): SigningRequestResource
    {
        abort_unless($signingRequest->isVisibleTo($request->user()), 403);

        return new SigningRequestResource($signingRequest->load(['files', 'signers.signer']));
    }

    /**
     * Create and send a request. Files are PDFs as base64. If sending fails the
     * request is kept (status failed, last_error set) and returned with 502.
     */
    public function store(Request $request, SigningService $service): JsonResponse
    {
        abort_unless(SigningRequest::canBeCreatedBy($request->user()), 403);
        abort_unless(config('services.docsign.api_key'), 503, 'DocSign är inte konfigurerat.');

        $data = $request->validate([
            'name' => 'required|string|max:200',
            'comment' => 'nullable|string|max:2000',
            'send_reminders' => 'boolean',
            'send_receipt' => 'boolean',
            'files' => 'required|array|min:1|max:5',
            'files.*.name' => 'required|string|max:200',
            'files.*.content_base64' => 'required|string',
            'signers' => 'required|array|min:1|max:20',
            'signers.*.name' => 'required|string|max:200',
            'signers.*.email' => 'nullable|required_without:signers.*.phone|email|max:200',
            'signers.*.phone' => 'nullable|required_without:signers.*.email|string|max:30',
            'signers.*.sign_method' => ['required', Rule::in(['bankid', 'email'])],
            'signers.*.role_label' => 'nullable|string|max:100',
        ]);

        $files = [];
        foreach ($data['files'] as $i => $file) {
            $contents = base64_decode($file['content_base64'], true);
            if ($contents === false || ! str_starts_with($contents, '%PDF')) {
                return response()->json(['message' => 'Filen måste vara en PDF i base64.', 'errors' => ["files.$i.content_base64" => ['Filen måste vara en PDF i base64.']]], 422);
            }
            if (strlen($contents) > 20 * 1024 * 1024) {
                return response()->json(['message' => 'En fil får vara högst 20 MB.', 'errors' => ["files.$i.content_base64" => ['En fil får vara högst 20 MB.']]], 422);
            }
            $files[] = ['name' => $file['name'], 'contents' => $contents];
        }

        $signingRequest = $service->createDraft(
            name: $data['name'],
            comment: $data['comment'] ?? null,
            signers: $data['signers'],
            files: $files,
            createdBy: $request->user()->id,
            sendReminders: $data['send_reminders'] ?? true,
            sendReceipt: $data['send_receipt'] ?? true,
        );
        AuditLog::record('signing_request_created', 'signing', $signingRequest->name, $signingRequest);

        try {
            $service->send($signingRequest);
            $status = 201;
        } catch (Throwable) {
            $status = 502;
        }

        return (new SigningRequestResource($signingRequest->fresh(['files', 'signers.signer'])))
            ->response()
            ->setStatusCode($status);
    }

    public function pause(Request $request, SigningRequest $signingRequest, SigningService $service): JsonResponse
    {
        return $this->changeState($request, $signingRequest, fn () => $service->pause($signingRequest), 'signing_request_paused');
    }

    public function resume(Request $request, SigningRequest $signingRequest, SigningService $service): JsonResponse
    {
        return $this->changeState($request, $signingRequest, fn () => $service->resume($signingRequest), 'signing_request_resumed');
    }

    /** Deletes the document at DocSign (pausing it first if needed); our copy is kept. */
    public function cancel(Request $request, SigningRequest $signingRequest, SigningService $service): JsonResponse
    {
        return $this->changeState($request, $signingRequest, fn () => $service->cancel($signingRequest), 'signing_request_cancelled');
    }

    /** 409 when the request has the wrong status (ours or DocSign's), 502 when DocSign fails. */
    private function changeState(Request $request, SigningRequest $signingRequest, callable $action, string $auditAction): JsonResponse
    {
        abort_unless($signingRequest->isManageableBy($request->user()), 403);

        try {
            $action();
        } catch (DocsignException $e) {
            // Our status check (no HTTP status) or DocSign's "does not have state …" (HTTP 200)
            return response()->json(['message' => $e->summary()], ($e->status ?? 200) < 300 ? 409 : 502);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        AuditLog::record($auditAction, 'signing', $signingRequest->name, $signingRequest);

        return (new SigningRequestResource($signingRequest->fresh(['files', 'signers.signer'])))->response();
    }
}
