<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Mail\MembershipApplied;
use App\Mail\NewMemberAdminMail;
use App\Models\AuditLog;
use App\Models\Membership\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Mail;

class MemberController extends Controller
{
    /**
     * List all members (paginated, filterable).
     */
    public function index(Request $request): ResourceCollection
    {
        $members = Member::query()
            ->when($request->search, fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('first_name', 'like', "%{$request->search}%")
                    ->orWhere('last_name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->type, fn ($q) => $q->where('membership_type', $request->type))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($request->integer('per_page', 25));

        return MemberResource::collection($members);
    }

    /**
     * Show a single member.
     */
    public function show(Member $member): MemberResource
    {
        $member->load([
            'user',
            'membershipPeriods' => fn ($q) => $q->orderByDesc('year'),
            'sections' => fn ($q) => $q->wherePivotNull('left_at'),
            'sectionRoles' => fn ($q) => $q->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now())),
        ]);

        return new MemberResource($member);
    }

    /**
     * Create a new member (admin back-office).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'email'           => 'required|email|max:255|unique:members,email',
            'phone'           => 'nullable|string|max:30',
            'address'         => 'nullable|string|max:255',
            'postal_code'     => 'nullable|string|max:10',
            'city'            => 'nullable|string|max:100',
            'date_of_birth'   => 'nullable|date|before:today',
            'membership_type' => 'required|in:single,family,student,honorary',
            'status'          => 'required|in:pending,active,inactive,expired,deceased',
            'notes'           => 'nullable|string|max:2000',
        ]);

        $member = Member::create($data);

        AuditLog::record('member_created', 'members', $member->full_name, $member);

        if ($member->email) {
            Mail::to($member->notificationEmail())->queue(new MembershipApplied($member));
        }

        $adminEmail = config('mail.contact_address', config('mail.from.address'));
        Mail::to($adminEmail)->queue(new NewMemberAdminMail($member));

        return (new MemberResource($member))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a member.
     */
    public function update(Request $request, Member $member): MemberResource
    {
        $data = $request->validate([
            'first_name'      => 'sometimes|required|string|max:100',
            'last_name'       => 'sometimes|required|string|max:100',
            'email'           => "sometimes|required|email|max:255|unique:members,email,{$member->id}",
            'phone'           => 'nullable|string|max:30',
            'address'         => 'nullable|string|max:255',
            'postal_code'     => 'nullable|string|max:10',
            'city'            => 'nullable|string|max:100',
            'date_of_birth'   => 'nullable|date|before:today',
            'membership_type' => 'sometimes|required|in:single,family,student,honorary',
            'status'          => 'sometimes|required|in:pending,active,inactive,expired,deceased',
            'notes'           => 'nullable|string|max:2000',
        ]);

        $changes = [];
        foreach ($member->getDirty() as $key => $new) {
            $changes[$key] = ['from' => (string) $member->getOriginal($key), 'to' => (string) $new];
        }
        $member->update($data);

        AuditLog::record('member_updated', 'members', $member->full_name, $member, $changes ?: null);

        return new MemberResource($member->fresh());
    }

    /**
     * Soft-delete a member.
     */
    public function destroy(Member $member): JsonResponse
    {
        $name = $member->full_name;
        $member->delete();

        AuditLog::record('member_deleted', 'members', $name);

        return response()->json(['message' => 'Member deleted.']);
    }
}
