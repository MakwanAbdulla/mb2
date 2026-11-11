<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Content\MailingTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailingTemplateController extends Controller
{
    private function toArray(MailingTemplate $t): array
    {
        return [
            'id'                  => $t->id,
            'name'                => $t->name,
            'category'            => $t->category,
            'description'         => $t->description,
            'subject'             => $t->subject,
            'body_html'           => $t->body_html,
            'permission_required' => $t->permission_required,
            'created_by'          => $t->created_by,
            'created_at'          => $t->created_at->toIso8601String(),
            'updated_at'          => $t->updated_at->toIso8601String(),
        ];
    }

    // GET /api/v1/admin/mailing-templates
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);

        $templates = MailingTemplate::orderBy('name')->get();

        return response()->json(['data' => $templates->map(fn ($t) => $this->toArray($t))]);
    }

    // GET /api/v1/admin/mailing-templates/{template}
    public function show(Request $request, MailingTemplate $mailingTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);

        return response()->json($this->toArray($mailingTemplate));
    }

    // POST /api/v1/admin/mailing-templates
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);

        $data = $request->validate([
            'name'                => 'required|string|max:255',
            'category'            => 'nullable|string|max:100',
            'description'         => 'nullable|string|max:2000',
            'subject'             => 'required|string|max:255',
            'body_html'           => 'required|string',
            'permission_required' => 'nullable|string|max:100',
        ]);

        $data['created_by'] = $request->user()->id;

        $template = MailingTemplate::create($data);

        AuditLog::record('mailing_template_created', 'system', $template->name, $template);

        return response()->json($this->toArray($template), 201);
    }

    // PATCH /api/v1/admin/mailing-templates/{template}
    public function update(Request $request, MailingTemplate $mailingTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);

        $data = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'category'            => 'nullable|string|max:100',
            'description'         => 'nullable|string|max:2000',
            'subject'             => 'sometimes|string|max:255',
            'body_html'           => 'sometimes|string',
            'permission_required' => 'nullable|string|max:100',
        ]);

        $mailingTemplate->update($data);

        AuditLog::record('mailing_template_updated', 'system', $mailingTemplate->name, $mailingTemplate);

        return response()->json($this->toArray($mailingTemplate));
    }

    // DELETE /api/v1/admin/mailing-templates/{template}
    public function destroy(Request $request, MailingTemplate $mailingTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('mailings.manage'), 403);
        abort_if($mailingTemplate->mailings()->exists(), 422, 'Template is in use by mailings.');

        $name = $mailingTemplate->name;
        $mailingTemplate->delete();

        AuditLog::record('mailing_template_deleted', 'system', $name, null);

        return response()->json(null, 204);
    }
}
