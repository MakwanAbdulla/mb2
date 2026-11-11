<?php

namespace App\Livewire\Meeting;

use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingAttendee;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MeetingView extends Component
{
    public Meeting $meeting;

    public function mount(Meeting $meeting): void
    {
        // Draft + cancelled: bara admin
        if (in_array($meeting->status, ['draft', 'cancelled'])) {
            abort_unless(Auth::user()?->hasAnyPermission(['meetings.view', 'meetings.manage']), 403);
        }

        $this->meeting = $meeting;
    }

    public function render()
    {
        $user   = Auth::user();
        $member = $user?->member;

        $agendaItems = $this->meeting->agendaItems()->with('minutesItem', 'documents', 'polls')->get();

        $attendee = $member
            ? MeetingAttendee::where('meeting_id', $this->meeting->id)
                ->where('member_id', $member->id)
                ->first()
            : null;

        $isPublished = $this->meeting->status === 'published';

        // Protokoll är synligt om mötet är publicerat, eller om användaren har meetings.view
        $showMinutes = $isPublished
            || $user?->hasAnyPermission(['meetings.view', 'meetings.manage']);

        $presentAttendees = $isPublished
            ? $this->meeting->attendees()->with('member')->where('present', true)->get()
            : collect();

        $absentAttendees = ($isPublished && $this->meeting->include_absences_in_minutes)
            ? $this->meeting->attendees()->with('member')->where('present', false)->get()
            : collect();

        return view('livewire.meeting.meeting-view', [
            'agendaItems'      => $agendaItems,
            'attendee'         => $attendee,
            'showMinutes'      => $showMinutes,
            'presentAttendees' => $presentAttendees,
            'absentAttendees'  => $absentAttendees,
        ])->layout('components.layouts.app', ['title' => $this->meeting->title]);
    }
}
