<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Content\MailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailTemplateController extends Controller
{
    // GET /api/v1/admin/mail-templates
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);

        $templates = MailTemplate::orderBy('label')->get()
            ->map(fn ($t) => [
                'id'          => $t->id,
                'key'         => $t->key,
                'label'       => $t->label,
                'description' => $t->description,
                'subject'     => $t->subject,
                'body'        => $t->body,
                'is_active'   => $t->is_active,
                'updated_at'  => $t->updated_at->toIso8601String(),
            ]);

        return response()->json(['data' => $templates]);
    }

    // GET /api/v1/admin/mail-templates/{mailTemplate}
    public function show(Request $request, MailTemplate $mailTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);

        return response()->json([
            'id'          => $mailTemplate->id,
            'key'         => $mailTemplate->key,
            'label'       => $mailTemplate->label,
            'description' => $mailTemplate->description,
            'subject'     => $mailTemplate->subject,
            'body'        => $mailTemplate->body,
            'is_active'   => $mailTemplate->is_active,
            'updated_at'  => $mailTemplate->updated_at->toIso8601String(),
        ]);
    }

    // PATCH /api/v1/admin/mail-templates/{mailTemplate}
    public function update(Request $request, MailTemplate $mailTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);

        $data = $request->validate([
            'subject'   => 'sometimes|string|max:255',
            'body'      => 'sometimes|string',
            'is_active' => 'boolean',
        ]);

        $mailTemplate->update($data);

        AuditLog::record('mail_template_updated', 'system', $mailTemplate->label, $mailTemplate);

        return response()->json(['id' => $mailTemplate->id, 'key' => $mailTemplate->key]);
    }
}
