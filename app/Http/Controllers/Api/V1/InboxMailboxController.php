<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InboxMailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxMailboxController extends Controller
{
    private function formatMailbox(InboxMailbox $m): array
    {
        return [
            'id'              => $m->id,
            'name'            => $m->name,
            'purpose'         => $m->purpose,
            'owner_type'      => $m->owner_type,
            'owner_id'        => $m->owner_id,
            'driver'          => $m->driver,
            'mailbox'         => $m->mailbox,
            'imap_host'       => $m->imap_host,
            'imap_port'       => $m->imap_port,
            'imap_encryption' => $m->imap_encryption,
            'imap_username'   => $m->imap_username,
            'imap_folder'     => $m->imap_folder,
            'smtp_host'       => $m->smtp_host,
            'smtp_port'       => $m->smtp_port,
            'smtp_username'   => $m->smtp_username,
            'is_enabled'      => $m->is_enabled,
            'created_at'      => $m->created_at->toIso8601String(),
            'updated_at'      => $m->updated_at->toIso8601String(),
        ];
    }

    // GET /api/v1/admin/inbox-mailboxes
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('settings.manage'), 403);

        $mailboxes = InboxMailbox::orderBy('name')->get()
            ->map(fn ($m) => $this->formatMailbox($m));

        return response()->json(['data' => $mailboxes]);
    }

    // POST /api/v1/admin/inbox-mailboxes
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('settings.manage'), 403);

        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'purpose'         => 'nullable|string|max:100',
            'owner_type'      => 'nullable|string|max:255',
            'owner_id'        => 'nullable|integer',
            'driver'          => 'required|string|in:google_workspace,imap',
            'mailbox'         => 'nullable|string|max:255',
            'imap_host'       => 'nullable|string|max:255',
            'imap_port'       => 'nullable|integer|min:1|max:65535',
            'imap_encryption' => 'nullable|string|in:ssl,tls,none',
            'imap_username'   => 'nullable|string|max:255',
            'imap_password'   => 'nullable|string|max:1000',
            'imap_folder'     => 'nullable|string|max:255',
            'smtp_host'       => 'nullable|string|max:255',
            'smtp_port'       => 'nullable|integer|min:1|max:65535',
            'smtp_username'   => 'nullable|string|max:255',
            'smtp_password'   => 'nullable|string|max:1000',
            'is_enabled'      => 'boolean',
        ]);

        $mailbox = InboxMailbox::create($data);

        AuditLog::record('inbox_mailbox_created', 'system', $mailbox->name, $mailbox);

        return response()->json($this->formatMailbox($mailbox), 201);
    }

    // GET /api/v1/admin/inbox-mailboxes/{mailbox}
    public function show(Request $request, InboxMailbox $mailbox): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('settings.manage'), 403);

        return response()->json($this->formatMailbox($mailbox));
    }

    // PATCH /api/v1/admin/inbox-mailboxes/{mailbox}
    public function update(Request $request, InboxMailbox $mailbox): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('settings.manage'), 403);

        $data = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'purpose'         => 'sometimes|nullable|string|max:100',
            'owner_type'      => 'sometimes|nullable|string|max:255',
            'owner_id'        => 'sometimes|nullable|integer',
            'driver'          => 'sometimes|string|in:google_workspace,imap',
            'mailbox'         => 'sometimes|nullable|string|max:255',
            'imap_host'       => 'sometimes|nullable|string|max:255',
            'imap_port'       => 'sometimes|nullable|integer|min:1|max:65535',
            'imap_encryption' => 'sometimes|nullable|string|in:ssl,tls,none',
            'imap_username'   => 'sometimes|nullable|string|max:255',
            'imap_password'   => 'sometimes|string|max:1000',
            'imap_folder'     => 'sometimes|nullable|string|max:255',
            'smtp_host'       => 'sometimes|nullable|string|max:255',
            'smtp_port'       => 'sometimes|nullable|integer|min:1|max:65535',
            'smtp_username'   => 'sometimes|nullable|string|max:255',
            'smtp_password'   => 'sometimes|string|max:1000',
            'is_enabled'      => 'sometimes|boolean',
        ]);

        // Only update password fields when explicitly provided (present in request)
        if (! $request->has('imap_password')) {
            unset($data['imap_password']);
        }
        if (! $request->has('smtp_password')) {
            unset($data['smtp_password']);
        }

        $mailbox->update($data);

        AuditLog::record('inbox_mailbox_updated', 'system', $mailbox->name, $mailbox);

        return response()->json($this->formatMailbox($mailbox));
    }

    // DELETE /api/v1/admin/inbox-mailboxes/{mailbox}
    public function destroy(Request $request, InboxMailbox $mailbox): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('settings.manage'), 403);

        $name = $mailbox->name;

        AuditLog::record('inbox_mailbox_deleted', 'system', $name, $mailbox);

        $mailbox->delete();

        return response()->json(null, 204);
    }
}
