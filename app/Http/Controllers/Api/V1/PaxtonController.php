<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Membership\MemberTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API endpoints consumed by the Paxton Net2 sync agent.
 * Authentication: Sanctum bearer token with `tags.manage` permission.
 */
class PaxtonController extends Controller
{
    /**
     * GET /api/v1/paxton/sync-queue
     *
     * Returns all tags with paxton_sync_status = pending.
     * The agent polls this endpoint and processes each entry.
     *
     * Response format:
     * [
     *   {
     *     "tag_id": 1,
     *     "action": "add|update|deactivate",
     *     "tag_uid": "A1B2C3",
     *     "member_name": "Anna Andersson",
     *     "member_number": "42"
     *   }
     * ]
     */
    public function syncQueue(): JsonResponse
    {
        $tags = MemberTag::where('paxton_sync_status', MemberTag::PAXTON_PENDING)
            ->with('member')
            ->get();

        $items = $tags->map(function (MemberTag $tag) {
            $action = match ($tag->status) {
                MemberTag::STATUS_ACTIVE      => 'add',
                MemberTag::STATUS_LOST,
                MemberTag::STATUS_BROKEN      => 'deactivate_card',
                MemberTag::STATUS_DEACTIVATED => 'deactivate_all',
                default                       => 'add',
            };

            return [
                'tag_id'        => $tag->id,
                'action'        => $action,
                'tag_uid'       => $tag->tag_uid,
                'member_name'   => $tag->member?->full_name ?? '',
                'member_number' => $tag->member?->membership_number ?? '',
            ];
        });

        return response()->json($items);
    }

    /**
     * POST /api/v1/paxton/sync-confirm
     *
     * Called by the agent after processing a tag.
     *
     * Body: { "tag_id": 1, "status": "synced"|"failed", "error": "optional message" }
     */
    public function syncConfirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tag_id' => 'required|integer|exists:member_tags,id',
            'status' => 'required|in:synced,failed',
            'error'  => 'nullable|string|max:1000',
        ]);

        $tag = MemberTag::findOrFail($validated['tag_id']);

        if ($validated['status'] === 'synced') {
            $tag->update([
                'paxton_sync_status' => MemberTag::PAXTON_SYNCED,
                'paxton_sync_error'  => null,
                'paxton_synced_at'   => now(),
            ]);
        } else {
            $tag->update([
                'paxton_sync_status' => MemberTag::PAXTON_FAILED,
                'paxton_sync_error'  => $validated['error'] ?? 'Unknown error',
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
