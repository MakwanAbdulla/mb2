<?php

namespace App\Livewire\Admin\Meeting;

use App\Models\Meeting\Meeting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MeetingIndex extends Component
{
    public string $filter = 'upcoming'; // upcoming|past|all

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasAnyPermission(['meetings.view', 'meetings.manage']), 403);
    }

    public function render()
    {
        $query = Meeting::with('section')
            ->withCount('attendees')
            ->orderBy('scheduled_at', $this->filter === 'past' ? 'desc' : 'asc');

        if ($this->filter === 'upcoming') {
            $query->whereDate('scheduled_at', '>=', today())->whereNotIn('status', ['cancelled']);
        } elseif ($this->filter === 'past') {
            $query->whereDate('scheduled_at', '<', today());
        }

        return view('livewire.admin.meeting.meeting-index', [
            'meetings' => $query->get(),
        ]);
    }
}
