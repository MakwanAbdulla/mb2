<?php

namespace App\Livewire\Admin\Meeting;

use App\Models\AuditLog;
use App\Models\Meeting\MeetingTemplate;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TemplateIndex extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('meetings.manage'), 403);
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $template = MeetingTemplate::withCount('meetings')->findOrFail($id);

        abort_if($template->meetings_count > 0, 422, 'Mallen används av befintliga möten och kan inte tas bort.');

        $name = $template->name;
        $template->delete();
        AuditLog::record('meeting_template_deleted', 'meeting_templates', $name);
    }

    public function render()
    {
        $templates = MeetingTemplate::with('section')
            ->withCount(['items', 'meetings'])
            ->orderBy('name')
            ->get();

        return view('livewire.admin.meeting.template-index', ['templates' => $templates]);
    }
}
