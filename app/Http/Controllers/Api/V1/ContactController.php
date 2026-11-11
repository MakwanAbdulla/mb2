<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['contact.view', 'contact.manage']), 403);

        $messages = ContactMessage::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($messages);
    }

    public function show(Request $request, ContactMessage $contactMessage): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['contact.view', 'contact.manage']), 403);

        if ($contactMessage->status === ContactMessage::STATUS_NEW) {
            $contactMessage->update(['status' => ContactMessage::STATUS_READ]);
            AuditLog::record('contact_message_read', 'members', $contactMessage->subject, $contactMessage);
        }

        return response()->json(['data' => $contactMessage]);
    }

    public function update(Request $request, ContactMessage $contactMessage): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('contact.manage'), 403);

        $data = $request->validate([
            'status' => 'required|in:new,read,replied,spam',
            'notes'  => 'nullable|string|max:2000',
        ]);

        if ($data['status'] === ContactMessage::STATUS_REPLIED && ! $contactMessage->replied_at) {
            $data['replied_at'] = now();
            $data['replied_by'] = $request->user()->id;
        }

        $contactMessage->update($data);

        AuditLog::record('contact_message_updated', 'members', $contactMessage->subject, $contactMessage);

        return response()->json(['data' => $contactMessage]);
    }
}
