<?php

namespace App\Livewire\Member;

use App\Models\Submission;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SubmissionShow extends Component
{
    public Submission $submission;

    public function mount(Submission $submission): void
    {
        abort_unless(Auth::check(), 403);

        // Egna ärenden, eller om man är röstberättigad (ballot-rad finns på någon poll)
        $user = Auth::user();
        $isOwner = $submission->created_by === $user->id;
        $hasVote = $submission->polls()
            ->whereHas('ballots', fn ($q) => $q->where('voter_id', $user->id))
            ->exists();

        abort_unless($isOwner || $hasVote, 403);

        $this->submission = $submission;
    }

    public function render()
    {
        return view('livewire.member.submission-show')
            ->layout('components.layouts.app', ['title' => $this->submission->title]);
    }
}
