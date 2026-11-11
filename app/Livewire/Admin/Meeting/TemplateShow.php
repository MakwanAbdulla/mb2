<?php

namespace App\Livewire\Admin\Meeting;

use App\Models\AuditLog;
use App\Models\Meeting\MeetingAttendee;
use App\Models\Meeting\MeetingTemplate;
use App\Models\Meeting\MeetingTemplateItem;
use App\Models\Meeting\MeetingTemplateRoleMapping;
use App\Models\Organization\Section;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TemplateShow extends Component
{
    public MeetingTemplate $template;

    public string $newItemTitle = '';
    public string $newItemType  = 'standard';

    public function mount(MeetingTemplate $template): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('meetings.manage'), 403);
        $this->template = $template;
    }

    public function saveField(string $field, mixed $value): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);
        abort_unless(\in_array($field, ['name', 'description', 'meeting_type', 'default_notice_days', 'include_absences_in_minutes', 'section_id']), 422);

        $this->template->update([$field => ($value === '' || $value === null) ? null : $value]);
        $this->template->refresh();
    }

    public function saveItemField(int $itemId, string $field, string $value): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);
        abort_unless(\in_array($field, ['title', 'description', 'minutes_template']), 422);

        $item = MeetingTemplateItem::findOrFail($itemId);
        abort_unless($item->template_id === $this->template->id, 403);
        $item->update([$field => $value ?: null]);
    }

    public function saveItemType(int $itemId, string $type): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);
        abort_unless(\array_key_exists($type, MeetingTemplateItem::ITEM_TYPES), 422);

        $item = MeetingTemplateItem::findOrFail($itemId);
        abort_unless($item->template_id === $this->template->id, 403);
        $item->update(['item_type' => $type]);
    }

    public function toggleItemRequired(int $itemId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $item = MeetingTemplateItem::findOrFail($itemId);
        abort_unless($item->template_id === $this->template->id, 403);
        $item->update(['is_required' => ! $item->is_required]);
        $this->template->refresh();
    }

    public function addItem(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $this->validate([
            'newItemTitle' => ['required', 'string', 'max:255'],
            'newItemType'  => ['required', 'in:standard,vote,election,info'],
        ], [], ['newItemTitle' => 'rubrik']);

        $lastOrder = $this->template->items()->max('sort_order') ?? -1;

        $this->template->items()->create([
            'sort_order'  => $lastOrder + 1,
            'title'       => $this->newItemTitle,
            'item_type'   => $this->newItemType,
            'is_required' => false,
        ]);

        AuditLog::record('meeting_template_item_added', 'meetings', $this->template->name . ': ' . $this->newItemTitle, $this->template);
        $this->newItemTitle = '';
        $this->newItemType  = 'standard';
        $this->template->refresh();
    }

    public function removeItem(int $itemId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $item = MeetingTemplateItem::findOrFail($itemId);
        abort_unless($item->template_id === $this->template->id, 403);

        if ($item->is_required) {
            return;
        }

        $itemTitle = $item->title;
        $item->delete();
        AuditLog::record('meeting_template_item_removed', 'meetings', $this->template->name . ': ' . $itemTitle, $this->template);
        $this->template->refresh();
    }

    public function moveItemUp(int $itemId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $items = $this->template->items()->orderBy('sort_order')->get();
        $index = $items->search(fn ($i) => $i->id === $itemId);

        if ($index < 1) {
            return;
        }

        $prev = $items[$index - 1];
        $curr = $items[$index];
        [$prev->sort_order, $curr->sort_order] = [$curr->sort_order, $prev->sort_order];
        $prev->save();
        $curr->save();
    }

    public function moveItemDown(int $itemId): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $items = $this->template->items()->orderBy('sort_order')->get();
        $index = $items->search(fn ($i) => $i->id === $itemId);

        if ($index === false || $index >= $items->count() - 1) {
            return;
        }

        $next = $items[$index + 1];
        $curr = $items[$index];
        [$curr->sort_order, $next->sort_order] = [$next->sort_order, $curr->sort_order];
        $curr->save();
        $next->save();
    }

    public function addRoleMapping(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $this->template->roleMappings()->create([
            'section_role_keyword' => '',
            'meeting_role'         => 'attendee',
        ]);
        AuditLog::record('meeting_template_role_mapping_added', 'meetings', $this->template->name, $this->template);
        $this->template->refresh();
    }

    public function removeRoleMapping(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);

        $mapping = MeetingTemplateRoleMapping::findOrFail($id);
        abort_unless($mapping->template_id === $this->template->id, 403);
        $keyword = $mapping->section_role_keyword;
        $mapping->delete();
        AuditLog::record('meeting_template_role_mapping_removed', 'meetings', $this->template->name . ': ' . $keyword, $this->template);
        $this->template->refresh();
    }

    public function saveRoleMappingField(int $id, string $field, string $value): void
    {
        abort_unless(Auth::user()->hasPermissionTo('meetings.manage'), 403);
        abort_unless(\in_array($field, ['section_role_keyword', 'meeting_role']), 422);

        $mapping = MeetingTemplateRoleMapping::findOrFail($id);
        abort_unless($mapping->template_id === $this->template->id, 403);
        $mapping->update([$field => $value]);
    }

    public function render()
    {
        $items        = $this->template->items()->orderBy('sort_order')->get();
        $roleMappings = $this->template->roleMappings()->get();
        $sections     = Section::where('is_active', true)->orderBy('name')->get();

        return view('livewire.admin.meeting.template-show', [
            'items'        => $items,
            'roleMappings' => $roleMappings,
            'sections'     => $sections,
        ]);
    }
}
