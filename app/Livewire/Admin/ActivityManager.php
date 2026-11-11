<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Booking\BookingActivity;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ActivityManager extends Component
{
    public bool   $showForm  = false;
    public ?int   $editingId = null;

    public string $name      = '';
    public int    $sortOrder = 0;
    public bool   $active    = true;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('bookings.categories'), 403);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $act = BookingActivity::findOrFail($id);

        $this->editingId = $id;
        $this->name      = $act->name;
        $this->sortOrder = $act->sort_order;
        $this->active    = $act->active;

        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.categories'), 403);

        $uniqueRule = $this->editingId
            ? 'unique:booking_activities,name,' . $this->editingId
            : 'unique:booking_activities,name';

        $this->validate([
            'name'      => ['required', 'string', 'max:100', $uniqueRule],
            'sortOrder' => ['integer', 'min:0'],
            'active'    => ['boolean'],
        ], [
            'name.required' => 'Ange ett namn.',
            'name.unique'   => 'Det finns redan en aktivitet med det namnet.',
        ]);

        $data = [
            'name'       => $this->name,
            'sort_order' => $this->sortOrder,
            'active'     => $this->active,
        ];

        if ($this->editingId) {
            $act = BookingActivity::findOrFail($this->editingId);
            $act->fill($data);
            $changes = [];
            foreach ($act->getDirty() as $key => $new) {
                $changes[$key] = ['from' => (string) $act->getOriginal($key), 'to' => (string) $new];
            }
            $act->save();
            AuditLog::record('activity_updated', 'activities', $this->name, $act, $changes ?: null);
        } else {
            $act = BookingActivity::create($data);
            AuditLog::record('activity_created', 'activities', $this->name, $act, [
                'sort_order' => $data['sort_order'],
                'active'     => $data['active'],
            ]);
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.categories'), 403);

        $act  = BookingActivity::findOrFail($id);
        $name = $act->name;
        $act->delete();
        AuditLog::record('activity_deleted', 'activities', $name);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name      = '';
        $this->sortOrder = BookingActivity::max('sort_order') + 1 ?? 0;
        $this->active    = true;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.admin.activity-manager', [
            'activities' => BookingActivity::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }
}
