<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Content\MailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailLogController extends Controller
{
    // GET /api/v1/admin/mail-log
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);

        $logs = MailLog::query()
            ->when($request->input('mail_type'), fn ($q, $v) => $q->where('mail_type', $v))
            ->when($request->input('member_id'), fn ($q, $v) => $q->where('member_id', $v))
            ->when($request->input('from'), fn ($q, $v) => $q->where('sent_at', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->where('sent_at', '<=', $v))
            ->orderByDesc('sent_at')
            ->paginate($request->integer('per_page', 50));

        $data = $logs->map(fn ($l) => [
            'id'               => $l->id,
            'mail_type'        => $l->mail_type,
            'recipient_email'  => $l->recipient_email,
            'recipient_name'   => $l->recipient_name,
            'subject'          => $l->subject,
            'member_id'        => $l->member_id,
            'mailing_id'       => $l->mailing_id,
            'sent_at'          => $l->sent_at?->toIso8601String(),
            'delivery_status'  => $l->delivery_status,
            'delivery_error'   => $l->delivery_error,
            'first_opened_at'  => $l->first_opened_at?->toIso8601String(),
            'open_count'       => $l->open_count,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }

    // GET /api/v1/admin/mail-log/{mailLog}
    public function show(Request $request, MailLog $mailLog): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);

        return response()->json([
            'id'               => $mailLog->id,
            'mail_type'        => $mailLog->mail_type,
            'mailable_class'   => $mailLog->mailable_class,
            'recipient_email'  => $mailLog->recipient_email,
            'recipient_name'   => $mailLog->recipient_name,
            'subject'          => $mailLog->subject,
            'body_html'        => $mailLog->body_html,
            'member_id'        => $mailLog->member_id,
            'mailing_id'       => $mailLog->mailing_id,
            'sent_at'          => $mailLog->sent_at?->toIso8601String(),
            'delivery_status'  => $mailLog->delivery_status,
            'delivery_error'   => $mailLog->delivery_error,
            'first_opened_at'  => $mailLog->first_opened_at?->toIso8601String(),
            'open_count'       => $mailLog->open_count,
            'tracking_token'   => $mailLog->tracking_token,
        ]);
    }
}
