<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Models\AuditLog;
use App\Models\Meeting\Meeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class MeetingController extends Controller
{
    public function index(Request $request): ResourceCollection
    {
        abort_unless($request->user()->hasAnyPermission(['meetings.view', 'meetings.manage']), 403);

        $meetings = Meeting::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('meeting_type', $v))
            ->orderByDesc('scheduled_at')
            ->paginate($request->integer('per_page', 25));

        return MeetingResource::collection($meetings);
    }

    public function show(Request $request, Meeting $meeting): MeetingResource
    {
        abort_unless($request->user()->hasAnyPermission(['meetings.view', 'meetings.manage']), 403);

        $meeting->load('agendaItems', 'attendees');

        return new MeetingResource($meeting);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);

        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'meeting_type'   => 'required|string|max:50',
            'scheduled_at'   => 'required|date',
            'ends_at'        => 'nullable|date|after:scheduled_at',
            'location'       => 'nullable|string|max:255',
            'section_id'     => 'nullable|exists:sections,id',
            'motion_deadline' => 'nullable|date',
            'is_extraordinary' => 'boolean',
        ]);

        $meeting = Meeting::create([
            ...$data,
            'status'     => 'draft',
            'created_by' => $request->user()->id,
        ]);

        AuditLog::record('meeting_created', 'meetings', $meeting->title, $meeting);

        return (new MeetingResource($meeting))->response()->setStatusCode(201);
    }

    public function update(Request $request, Meeting $meeting): MeetingResource
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);

        $data = $request->validate([
            'title'           => 'sometimes|string|max:255',
            'meeting_type'    => 'sometimes|string|max:50',
            'status'          => 'sometimes|in:' . implode(',', array_keys(Meeting::STATUSES)),
            'scheduled_at'    => 'sometimes|date',
            'ends_at'         => 'nullable|date',
            'location'        => 'nullable|string|max:255',
            'section_id'      => 'nullable|exists:sections,id',
            'motion_deadline' => 'nullable|date',
            'is_extraordinary' => 'boolean',
        ]);

        $meeting->update($data);

        AuditLog::record('meeting_updated', 'meetings', $meeting->title, $meeting);

        return new MeetingResource($meeting);
    }

    public function checkIn(Request $request, Meeting $meeting): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('meetings.manage'), 403);

        $data = $request->validate([
            'member_id' => 'required|integer|exists:members,id',
            'token'     => 'required|string|size:64',
        ]);

        // Verify HMAC token
        if (! \App\Services\MemberCardService::verifyToken($data['member_id'], $data['token'], tolerance: 5)) {
            return response()->json(['error' => 'Ogiltig eller utgången QR-kod.'], 401);
        }

        $member = \App\Models\Membership\Member::with(['user', 'membershipPeriods'])->findOrFail($data['member_id']);

        $isActive = $member->status === 'active';

        $attendee = $meeting->attendees()->firstOrCreate(
            ['member_id' => $member->id],
            [
                'role'                   => 'attendee',
                'invited'                => false,
                'present'                => true,
                'voting_rights'          => $isActive,
                'membership_verified_at' => now(),
                'membership_valid'       => $isActive,
            ]
        );

        // If attendee already existed but wasn't present, mark them present now
        if (! $attendee->wasRecentlyCreated && ! $attendee->present) {
            $attendee->update([
                'present'                => true,
                'membership_verified_at' => now(),
                'membership_valid'       => $isActive,
                'voting_rights'          => $isActive,
            ]);
        }

        AuditLog::record('meeting_attendee_checked_in', 'meetings', $meeting->title . ' — ' . $member->full_name, $meeting);

        // Count totals
        $totals = $meeting->attendees()
            ->selectRaw('COUNT(*) as total, SUM(present) as present_count, SUM(voting_rights AND present) as voting_count')
            ->first();

        return response()->json([
            'data' => [
                'attendee' => [
                    'id'              => $attendee->id,
                    'already_present' => ! $attendee->wasRecentlyCreated && $attendee->present,
                    'present'         => true,
                    'voting_rights'   => $attendee->voting_rights,
                    'membership_valid' => $attendee->membership_valid,
                    'role'            => $attendee->role,
                ],
                'member' => [
                    'id'         => $member->id,
                    'name'       => $member->full_name,
                    'avatar_url' => $member->user?->avatarUrl(80),
                    'is_active'  => $isActive,
                ],
                'meeting_totals' => [
                    'total'   => (int) $totals->total,
                    'present' => (int) $totals->present_count,
                    'voting'  => (int) $totals->voting_count,
                ],
            ],
        ]);
    }
}
