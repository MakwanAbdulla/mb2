<?php

namespace App\Livewire\Member;

use App\Models\Meeting\Meeting;
use App\Models\Meeting\MeetingTemplate;
use App\Models\Organization\Section;
use App\Models\Submission;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SubmissionIndex extends Component
{
    public string $newType      = 'issue';
    public string $newTitle     = '';
    public string $newBody      = '';
    public string $newSectionId = '';
    public bool   $showForm     = false;

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function submit(): void
    {
        abort_unless(Auth::check(), 403);

        if ($this->newType === 'motion' && ! $this->motionsOpen()) {
            $this->addError('newType', 'Motionstiden har stängt. Det finns inga öppna möten att motionera till.');
            return;
        }

        $this->validate([
            'newType'      => ['required', 'in:issue,motion'],
            'newTitle'     => ['required', 'string', 'max:255'],
            'newBody'      => ['nullable', 'string', 'max:10000'],
            'newSectionId' => ['nullable', 'exists:sections,id'],
        ], [], [
            'newTitle' => 'rubrik',
            'newType'  => 'typ',
        ]);

        Submission::create([
            'type'       => $this->newType,
            'title'      => $this->newTitle,
            'body'       => $this->newBody ?: null,
            'section_id' => $this->newSectionId ?: null,
            'status'     => 'submitted',
            'created_by' => Auth::id(),
        ]);

        $this->showForm     = false;
        $this->newType      = 'issue';
        $this->newTitle     = '';
        $this->newBody      = '';
        $this->newSectionId = '';
    }

    private function motionsOpen(): bool
    {
        return Meeting::whereIn('meeting_type', MeetingTemplate::MOTION_TYPES)
            ->whereIn('status', ['draft', 'notice_sent'])
            ->where(function ($q) {
                $q->whereNull('motion_deadline')
                  ->orWhere('motion_deadline', '>=', now()->toDateString());
            })
            ->exists();
    }

    public function render()
    {
        $mySubmissions = Submission::where('created_by', Auth::id())
            ->with('section', 'agendaItem.meeting')
            ->orderBy('created_at', 'desc')
            ->get();

        $sections = Section::where('accepts_issues', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.member.submission-index', [
            'mySubmissions' => $mySubmissions,
            'sections'      => $sections,
            'motionsOpen'   => $this->motionsOpen(),
        ]);
    }
}
