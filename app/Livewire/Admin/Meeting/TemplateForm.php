<?php

namespace App\Livewire\Admin\Meeting;

use App\Models\AuditLog;
use App\Models\Meeting\MeetingTemplate;
use App\Models\Meeting\MeetingTemplateRoleMapping;
use App\Models\Organization\Section;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

class TemplateForm extends Component
{
    public ?MeetingTemplate $template = null;

    public string $name                     = '';
    public string $sectionId               = '';
    public string $meetingType             = 'board_meeting';
    public int    $defaultNoticeDays       = 14;
    public bool   $includeAbsencesInMinutes = true;
    public bool   $isExtraordinary          = false;
    public string $description             = '';

    /** @var array<int, array{title: string, description: string, minutes_template: string, item_type: string, is_required: bool, _key: string}> */
    public array $items = [];

    /** @var array<int, array{section_role_keyword: string, meeting_role: string, _key: string}> */
    public array $roleMappings = [];

    public function mount(?MeetingTemplate $template = null): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('meetings.manage'), 403);

        if ($template?->exists) {
            $this->template                 = $template;
            $this->name                     = $template->name;
            $this->sectionId               = (string) ($template->section_id ?? '');
            $this->meetingType             = $template->meeting_type;
            $this->defaultNoticeDays       = $template->default_notice_days;
            $this->includeAbsencesInMinutes = $template->include_absences_in_minutes;
            $this->isExtraordinary          = $template->is_extraordinary;
            $this->description             = $template->description ?? '';
            $this->roleMappings = $template->roleMappings->map(fn ($m) => [
                'section_role_keyword' => $m->section_role_keyword,
                'meeting_role'         => $m->meeting_role,
                '_key'                 => uniqid(),
            ])->values()->toArray();

            $this->items = $template->items->map(fn ($item) => [
                'title'            => $item->title,
                'description'      => $item->description ?? '',
                'minutes_template' => $item->minutes_template ?? '',
                'item_type'        => $item->item_type,
                'is_required'      => $item->is_required,
                '_key'             => uniqid(),
            ])->values()->toArray();
        }

        if (empty($this->items)) {
            $this->addItem();
        }
    }

    public function addItem(): void
    {
        $this->items[] = [
            'title'            => '',
            'description'      => '',
            'minutes_template' => '',
            'item_type'        => 'standard',
            'is_required'      => false,
            '_key'             => uniqid(),
        ];
    }

    public function removeItem(int $index): void
    {
        if ($this->items[$index]['is_required'] ?? false) {
            return;
        }
        array_splice($this->items, $index, 1);
    }

    public function moveUp(int $index): void
    {
        if ($index === 0) {
            return;
        }
        [$this->items[$index - 1], $this->items[$index]] = [$this->items[$index], $this->items[$index - 1]];
    }

    public function moveDown(int $index): void
    {
        if ($index >= count($this->items) - 1) {
            return;
        }
        [$this->items[$index], $this->items[$index + 1]] = [$this->items[$index + 1], $this->items[$index]];
    }

    public function addRoleMapping(): void
    {
        $this->roleMappings[] = [
            'section_role_keyword' => '',
            'meeting_role'         => 'chair',
            '_key'                 => uniqid(),
        ];
    }

    public function removeRoleMapping(int $index): void
    {
        array_splice($this->roleMappings, $index, 1);
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $this->validate([
            'name'                     => ['required', 'string', 'max:255'],
            'sectionId'               => ['nullable', 'exists:sections,id'],
            'meetingType'             => ['required', 'in:board_meeting,members_meeting,annual_general,section,other'],
            'defaultNoticeDays'       => ['required', 'integer', 'min:1', 'max:365'],
            'includeAbsencesInMinutes' => ['boolean'],
            'isExtraordinary'         => ['boolean'],
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.title'           => ['required', 'string', 'max:255'],
            'items.*.item_type'       => ['required', 'in:standard,vote,election,info'],
        ], [], [
            'name'              => 'namn',
            'defaultNoticeDays' => 'kallelsetid',
            'items.*.title'     => 'agendapunktstitel',
        ]);

        $attributes = [
            'name'                      => $this->name,
            'section_id'               => $this->sectionId ?: null,
            'meeting_type'             => $this->meetingType,
            'default_notice_days'      => $this->defaultNoticeDays,
            'include_absences_in_minutes' => $this->includeAbsencesInMinutes,
            'is_extraordinary'         => $this->isExtraordinary,
            'description'              => $this->description ?: null,
            'created_by'               => Auth::id(),
        ];

        if ($this->template) {
            $this->template->update($attributes);
            $this->template->items()->delete();
            $this->template->roleMappings()->delete();
        } else {
            $this->template = MeetingTemplate::create($attributes);
        }

        foreach ($this->roleMappings as $mapping) {
            if (! trim($mapping['section_role_keyword'])) {
                continue;
            }
            $this->template->roleMappings()->create([
                'section_role_keyword' => trim($mapping['section_role_keyword']),
                'meeting_role'         => $mapping['meeting_role'],
            ]);
        }

        foreach ($this->items as $i => $item) {
            $this->template->items()->create([
                'sort_order'       => $i,
                'title'            => $item['title'],
                'description'      => $item['description'] ?: null,
                'minutes_template' => $item['minutes_template'] ?: null,
                'item_type'        => $item['item_type'],
                'is_required'      => $item['is_required'],
            ]);
        }

        AuditLog::record('meeting_template_saved', 'meeting_templates', $this->template->name, $this->template);

        $this->redirect(route('admin.meetings.templates.show', $this->template), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.meeting.template-form', [
            'sections'     => Section::where('is_active', true)->orderBy('name')->get(),
            'meetingTypes' => MeetingTemplate::TYPES,
        ]);
    }
}
