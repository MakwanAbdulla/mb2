<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Membership\MemberTag;
use App\Models\Membership\TagYearPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RfidTagController extends Controller
{
    // GET /api/v1/admin/rfid-tags
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('tags.manage'), 403);

        $tags = MemberTag::with('member')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('member_id'), fn ($q, $v) => $q->where('member_id', $v))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        $data = $tags->map(fn ($t) => [
            'id'                 => $t->id,
            'member_id'          => $t->member_id,
            'member_name'        => $t->member?->full_name,
            'tag_uid'            => $t->tag_uid,
            'status'             => $t->status,
            'status_label'       => MemberTag::statusLabel($t->status),
            'price_ore'          => $t->price_ore,
            'issued_at'          => $t->issued_at?->toIso8601String(),
            'deactivated_at'     => $t->deactivated_at?->toIso8601String(),
            'paxton_sync_status' => $t->paxton_sync_status,
            'paxton_synced_at'   => $t->paxton_synced_at?->toIso8601String(),
            'notes'              => $t->notes,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $tags->currentPage(),
                'last_page'    => $tags->lastPage(),
                'per_page'     => $tags->perPage(),
                'total'        => $tags->total(),
            ],
        ]);
    }

    // GET /api/v1/admin/rfid-tags/{tag}
    public function show(Request $request, MemberTag $memberTag): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('tags.manage'), 403);

        return response()->json([
            'id'                 => $memberTag->id,
            'member_id'          => $memberTag->member_id,
            'tag_uid'            => $memberTag->tag_uid,
            'status'             => $memberTag->status,
            'status_label'       => MemberTag::statusLabel($memberTag->status),
            'price_ore'          => $memberTag->price_ore,
            'issued_at'          => $memberTag->issued_at?->toIso8601String(),
            'deactivated_at'     => $memberTag->deactivated_at?->toIso8601String(),
            'paxton_sync_status' => $memberTag->paxton_sync_status,
            'paxton_sync_error'  => $memberTag->paxton_sync_error,
            'paxton_synced_at'   => $memberTag->paxton_synced_at?->toIso8601String(),
            'notes'              => $memberTag->notes,
            'order_id'           => $memberTag->order_id,
        ]);
    }

    // POST /api/v1/admin/rfid-tags
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('tags.manage'), 403);

        $data = $request->validate([
            'member_id' => 'required|exists:members,id',
            'tag_uid'   => 'nullable|string|max:50',
            'status'    => 'in:' . implode(',', MemberTag::STATUSES),
            'price_ore' => 'integer|min:0',
            'notes'     => 'nullable|string|max:2000',
        ]);

        $tag = MemberTag::create($data);

        if ($tag->tag_uid && $tag->status === MemberTag::STATUS_ACTIVE) {
            $tag->queuePaxtonSync();
        }

        AuditLog::record('rfid_tag_issued', 'members', $tag->tag_uid ?? "tag#{$tag->id}", $tag);

        return response()->json(['id' => $tag->id], 201);
    }

    // PATCH /api/v1/admin/rfid-tags/{tag}
    public function update(Request $request, MemberTag $memberTag): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('tags.manage'), 403);

        $data = $request->validate([
            'tag_uid'        => 'nullable|string|max:50',
            'status'         => 'sometimes|in:' . implode(',', MemberTag::STATUSES),
            'price_ore'      => 'integer|min:0',
            'notes'          => 'nullable|string|max:2000',
            'issued_at'      => 'nullable|date',
            'deactivated_at' => 'nullable|date',
        ]);

        $memberTag->update($data);

        if ($memberTag->tag_uid && in_array($memberTag->status, [MemberTag::STATUS_ACTIVE, MemberTag::STATUS_DEACTIVATED])) {
            $memberTag->queuePaxtonSync();
        }

        AuditLog::record('rfid_tag_updated', 'members', $memberTag->tag_uid ?? "tag#{$memberTag->id}", $memberTag);

        return response()->json(['id' => $memberTag->id, 'status' => $memberTag->status]);
    }

    // GET /api/v1/admin/rfid-tags/prices
    public function prices(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('tags.manage'), 403);

        $prices = TagYearPrice::query()
            ->when($request->input('year'), fn ($q, $y) => $q->where('year', $y))
            ->orderByDesc('year')
            ->get(['id', 'year', 'price_ore', 'annual_fee_ore']);

        return response()->json(['data' => $prices]);
    }

    // POST /api/v1/admin/rfid-tags/prices
    public function storePrice(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('tags.manage'), 403);

        $data = $request->validate([
            'year'           => 'required|integer|min:2020|max:2100',
            'price_ore'      => 'required|integer|min:0',
            'annual_fee_ore' => 'nullable|integer|min:0',
        ]);

        $price = TagYearPrice::updateOrCreate(
            ['year' => $data['year']],
            array_filter($data, fn ($v) => $v !== null)
        );

        AuditLog::record('rfid_tag_price_created', 'system', "TagYearPrice {$data['year']}", $price);

        return response()->json(['data' => $price], 201);
    }

    // PATCH /api/v1/admin/rfid-tags/prices/{price}
    public function updatePrice(Request $request, TagYearPrice $price): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('tags.manage'), 403);

        $data = $request->validate([
            'year'           => 'sometimes|integer|min:2020|max:2100',
            'price_ore'      => 'sometimes|integer|min:0',
            'annual_fee_ore' => 'nullable|integer|min:0',
        ]);

        $price->update($data);

        AuditLog::record('rfid_tag_price_updated', 'system', "TagYearPrice {$price->year}", $price);

        return response()->json(['data' => $price->fresh()]);
    }
}
