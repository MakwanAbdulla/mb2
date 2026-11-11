<?php

namespace App\Http\Controllers;

use App\Models\InboxMailbox;
use App\Services\InboxReaderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InboxAttachmentController extends Controller
{
    public function download(Request $request, int $mailboxId, string $messageId, string $attachmentId)
    {
        abort_unless($request->hasValidSignature(), 403);

        $mailbox = InboxMailbox::findOrFail($mailboxId);

        $user    = Auth::user();
        $allowed = $user?->hasPermissionTo('workspace.manage')
            || InboxMailbox::forOwner($user)?->id === $mailboxId;
        abort_unless($allowed, 403);

        $data = (new InboxReaderService($mailbox))->getAttachment($messageId, $attachmentId);
        abort_if($data === null, 404);

        $filename = preg_replace('/[^\w.\- ]/', '_', $data['filename']);

        return response($data['data'], 200, [
            'Content-Type'        => $data['mime_type'],
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => strlen($data['data']),
        ]);
    }
}
