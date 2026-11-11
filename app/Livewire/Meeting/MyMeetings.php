<?php

namespace App\Livewire\Meeting;

use App\Models\Meeting\Meeting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MyMeetings extends Component
{
    public function render()
    {
        $user = Auth::user();

        $meetings = Meeting::whereHas('attendees', fn ($q) => $q->where('member_id', $user->member?->id))
            ->orWhereNull('section_id') // föreningsnivå-möten syns för alla
            ->with('section')
            ->orderBy('scheduled_at', 'desc')
            ->get()
            ->filter(fn ($m) => $m->status !== 'cancelled' && $m->status !== 'draft');

        return view('livewire.meeting.my-meetings', ['meetings' => $meetings])
            ->layout('components.layouts.app', ['title' => 'Mina möten']);
    }
}
