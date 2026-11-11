<?php

namespace App\Livewire\Admin\Meeting;

use App\Models\Organization\Section;
use App\Models\Submission;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SubmissionIndex extends Component
{
    public string $typeFilter    = 'all';
    public string $statusFilter  = 'open';
    public string $sectionFilter = 'all';
    public string $search        = '';

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasAnyPermission(['submissions.view', 'submissions.manage']), 403);
    }

    public function render()
    {
        $query = Submission::with('createdBy', 'section', 'agendaItem.meeting')
            ->orderBy('created_at', 'desc');

        if ($this->typeFilter !== 'all') {
            $query->where('type', $this->typeFilter);
        }

        if ($this->statusFilter === 'open') {
            $query->whereIn('status', ['submitted', 'under_review']);
        } elseif ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->sectionFilter === 'board') {
            $query->whereNull('section_id');
        } elseif ($this->sectionFilter !== 'all') {
            $query->where('section_id', $this->sectionFilter);
        }

        $submissions = $query->get();

        if ($this->search !== '') {
            $term = mb_strtolower($this->search);
            $submissions = $submissions->filter(
                fn ($s) => str_contains(mb_strtolower($s->title), $term)
            );
        }

        return view('livewire.admin.meeting.submission-index', [
            'submissions' => $submissions,
            'sections'    => Section::where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
