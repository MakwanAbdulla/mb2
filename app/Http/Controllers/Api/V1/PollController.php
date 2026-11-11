<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PollResource;
use App\Models\AuditLog;
use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingAgendaItem;
use App\Models\Meeting\MeetingAttendee;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Auth;

class PollController extends Controller
{
    public function pending(Request $request): ResourceCollection
    {
        $user = $request->user();
        $userId = $user->id;

        $polls = Poll::where('status', 'open')
            ->where(function ($q) use ($userId) {
                // Per-capsulam polls: pre-populated ballot exists for this user
                $q->whereHas('ballots', fn ($b) => $b->where('voter_id', $userId)->whereNull('option_id'));
            })
            ->with('options', 'voteable')
            ->get();

        return PollResource::collection($polls);
    }

    public function show(Poll $poll): PollResource
    {
        abort_unless(Auth::check(), 401);
        $poll->load('options');
        return new PollResource($poll);
    }

    public function vote(Request $request, Poll $poll): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        abort_unless($poll->isOpen(), 422, 'Poll is not open.');

        $data = $request->validate([
            'option_id' => 'required|integer|exists:poll_options,id',
        ]);

        $optionBelongsToPoll = $poll->options()->where('id', $data['option_id'])->exists();
        abort_unless($optionBelongsToPoll, 422, 'Invalid option for this poll.');

        $userId = Auth::id();
        $isEligible = false;

        if ($poll->voteable_type === \App\Models\Submission::class) {
            $isEligible = $poll->ballots()->where('voter_id', $userId)->exists();
        } elseif ($poll->voteable_type === \App\Models\Meeting\MeetingAgendaItem::class) {
            $meetingId = $poll->voteable->meeting_id;
            $isEligible = MeetingAttendee::where('meeting_id', $meetingId)
                ->whereHas('member', fn ($q) => $q->where('user_id', $userId))
                ->where('present', true)
                ->where('voting_rights', true)
                ->exists();
        }

        abort_unless($isEligible, 403, 'Not eligible to vote on this poll.');

        if ($poll->hasVoted($userId)) {
            return response()->json(['message' => 'Already voted.'], 422);
        }

        $poll->castVote($userId, $data['option_id']);

        AuditLog::record('poll_voted', 'meetings', $poll->question, $poll);

        return response()->json(['message' => 'Vote recorded.']);
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['meetings.manage', 'submissions.manage']), 403);

        $data = $request->validate([
            'voteable_type' => 'required|in:submission,agenda_item',
            'voteable_id'   => 'required|integer',
            'question'      => 'required|string|max:500',
            'type'          => 'required|in:yes_no_abstain,yes_no,free',
            'deadline'      => 'nullable|date',
            'options'       => 'nullable|array',
            'options.*'     => 'string|max:100',
        ]);

        $voteableClass = match ($data['voteable_type']) {
            'submission'   => Submission::class,
            'agenda_item'  => MeetingAgendaItem::class,
        };

        abort_unless(class_exists($voteableClass) && $voteableClass::find($data['voteable_id']), 422, 'Voteable not found.');

        $poll = Poll::create([
            'voteable_type' => $voteableClass,
            'voteable_id'   => $data['voteable_id'],
            'question'      => $data['question'],
            'type'          => $data['type'],
            'status'        => 'pending',
            'deadline'      => $data['deadline'] ?? null,
            'created_by'    => $request->user()->id,
            'eligible_count' => 0,
        ]);

        if (! empty($data['options'])) {
            foreach ($data['options'] as $i => $label) {
                $poll->options()->create(['label' => $label, 'sort_order' => $i]);
            }
        }

        AuditLog::record('poll_created', 'meetings', $poll->question, $poll);

        return (new PollResource($poll->load('options')))->response()->setStatusCode(201);
    }

    public function open(Request $request, Poll $poll): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['meetings.manage', 'submissions.manage']), 403);
        abort_unless($poll->status === 'pending', 422, 'Poll is not in pending state.');

        $voterIds = $request->input('voter_ids', []);
        $meeting  = null;

        if ($poll->voteable_type === MeetingAgendaItem::class) {
            $meeting = $poll->voteable?->meeting;
        }

        $poll->open($meeting, $voterIds);

        AuditLog::record('poll_opened', 'meetings', $poll->question, $poll);

        return response()->json(['message' => 'Poll opened.', 'status' => $poll->status]);
    }

    public function close(Request $request, Poll $poll): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['meetings.manage', 'submissions.manage']), 403);
        abort_unless($poll->isOpen(), 422, 'Poll is not open.');

        $poll->close();

        AuditLog::record('poll_closed', 'meetings', $poll->question, $poll);

        return response()->json(['message' => 'Poll closed.', 'status' => $poll->status, 'result' => $poll->result]);
    }

    public function closeManual(Request $request, Poll $poll): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['meetings.manage', 'submissions.manage']), 403);

        $data = $request->validate([
            'result_yes'     => 'required|integer|min:0',
            'result_no'      => 'required|integer|min:0',
            'result_abstain' => 'required|integer|min:0',
            'result_note'    => 'nullable|string|max:500',
        ]);

        $poll->closeManual($data['result_yes'], $data['result_no'], $data['result_abstain'], $data['result_note'] ?? null);

        AuditLog::record('poll_closed_manual', 'meetings', $poll->question, $poll);

        return response()->json([
            'message'        => 'Manual result recorded.',
            'status'         => $poll->status,
            'result'         => $poll->result,
            'result_yes'     => $poll->result_yes,
            'result_no'      => $poll->result_no,
            'result_abstain' => $poll->result_abstain,
        ]);
    }
}
