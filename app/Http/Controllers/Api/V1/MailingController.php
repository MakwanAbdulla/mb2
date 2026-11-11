<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MailingResource;
use App\Models\AuditLog;
use App\Models\Content\Mailing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class MailingController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['mailings.view', 'mailings.manage']), 403);

        $mailings = Mailing::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return MailingResource::collection($mailings);
    }

    public function show(Request $request, Mailing $mailing): MailingResource
    {
        abort_unless($request->user()->hasAnyPermission(['mailings.view', 'mailings.manage']), 403);

        $mailing->load('recipients');

        return new MailingResource($mailing);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'subject'     => 'required|string|max:255',
            'body_html'   => 'required|string',
            'category'    => 'nullable|string|max:50',
            'scheduled_at' => 'nullable|date',
        ]);

        $mailing = Mailing::create([
            ...$data,
            'status'     => Mailing::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record('mailing_created', 'system', $mailing->title, $mailing);

        return (new MailingResource($mailing))->response()->setStatusCode(201);
    }

    public function update(Request $request, Mailing $mailing): MailingResource
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);
        abort_unless($mailing->isEditable(), 422, 'Mailing cannot be edited in its current state.');

        $data = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'subject'      => 'sometimes|string|max:255',
            'body_html'    => 'sometimes|string',
            'category'     => 'nullable|string|max:50',
            'scheduled_at' => 'nullable|date',
        ]);

        $mailing->update($data);

        AuditLog::record('mailing_updated', 'system', $mailing->title, $mailing);

        return new MailingResource($mailing);
    }

    public function send(Request $request, Mailing $mailing): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);
        abort_unless($mailing->isDraft(), 422, 'Only draft mailings can be sent.');

        // Dispatch sending — actual send logic lives in jobs/services not yet built
        $mailing->update(['status' => Mailing::STATUS_SENDING]);

        AuditLog::record('mailing_sent', 'system', $mailing->title, $mailing);

        return response()->json(['message' => 'Mailing queued for sending.', 'status' => $mailing->status]);
    }

    public function cancel(Request $request, Mailing $mailing): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);
        abort_unless(in_array($mailing->status, [Mailing::STATUS_DRAFT, Mailing::STATUS_SCHEDULED]), 422, 'Mailing cannot be cancelled.');

        $mailing->update(['status' => Mailing::STATUS_CANCELLED]);

        AuditLog::record('mailing_cancelled', 'system', $mailing->title, $mailing);

        return response()->json(['message' => 'Mailing cancelled.', 'status' => $mailing->status]);
    }

    public function destroy(Request $request, Mailing $mailing): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);
        abort_unless($mailing->isDraft(), 422, 'Only draft mailings can be deleted.');

        $title = $mailing->title;
        $mailing->delete();

        AuditLog::record('mailing_deleted', 'system', $title, null);

        return response()->json(['message' => 'Mailing deleted.']);
    }
}
