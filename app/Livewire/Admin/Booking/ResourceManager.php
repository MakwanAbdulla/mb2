<?php

namespace App\Livewire\Admin\Booking;

use App\Models\AuditLog;
use App\Models\Booking\BookingCategory;
use App\Models\Booking\BookingResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class ResourceManager extends Component
{
    public bool    $showForm         = false;
    public ?int    $editingId        = null;

    public string  $name             = '';
    public string  $categoryId       = '';
    public string  $location         = '';
    public string  $description      = '';
    public int     $minDuration      = 30;
    public string  $maxDuration      = '';
    public int     $capacity         = 1;
    public bool    $requiresApproval = false;
    public bool    $active           = true;
    public string  $color            = '#6366f1';

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
        $r = BookingResource::findOrFail($id);

        $this->editingId        = $id;
        $this->name             = $r->name;
        $this->categoryId       = (string) $r->category_id;
        $this->location         = $r->location ?? '';
        $this->description      = $r->description ?? '';
        $this->minDuration      = $r->min_duration;
        $this->maxDuration      = $r->max_duration !== null ? (string) $r->max_duration : '';
        $this->capacity         = $r->capacity;
        $this->requiresApproval = $r->requires_approval;
        $this->active           = $r->active;
        $this->color            = $r->color ?? '#6366f1';

        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.categories'), 403);

        $this->validate([
            'name'             => ['required', 'string', 'max:100'],
            'categoryId'       => ['nullable', 'exists:booking_categories,id'],
            'location'         => ['nullable', 'string', 'max:200'],
            'description'      => ['nullable', 'string', 'max:1000'],
            'minDuration'      => ['required', 'integer', 'min:5', 'max:1440'],
            'maxDuration'      => ['nullable', 'integer', 'min:5', 'max:1440', 'gte:minDuration'],
            'capacity'         => ['required', 'integer', 'min:1', 'max:100'],
            'requiresApproval' => ['boolean'],
            'active'           => ['boolean'],
            'color'            => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'name.required'   => 'Ange ett namn.',
            'maxDuration.gte' => 'Maximitid måste vara minst lika lång som minimitiden.',
        ]);

        $data = [
            'category_id'       => $this->categoryId ?: null,
            'name'              => $this->name,
            'location'          => $this->location ?: null,
            'description'       => $this->description ?: null,
            'min_duration'      => $this->minDuration,
            'max_duration'      => $this->maxDuration !== '' ? (int) $this->maxDuration : null,
            'capacity'          => $this->capacity,
            'requires_approval' => $this->requiresApproval,
            'active'            => $this->active,
            'color'             => $this->color ?: null,
        ];

        if ($this->editingId) {
            $resource = BookingResource::findOrFail($this->editingId);
            $resource->fill($data);
            $changes = [];
            foreach ($resource->getDirty() as $key => $new) {
                $changes[$key] = ['from' => (string) $resource->getOriginal($key), 'to' => (string) $new];
            }
            $resource->save();
            AuditLog::record('resource_updated', 'resources', $this->name, $resource, $changes ?: null);
        } else {
            $data['slug'] = $this->uniqueSlug($this->name);
            $resource = BookingResource::create($data);
            AuditLog::record('resource_created', 'resources', $this->name, $resource, [
                'location'          => $data['location'],
                'capacity'          => $data['capacity'],
                'requires_approval' => $data['requires_approval'],
                'active'            => $data['active'],
            ]);
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.categories'), 403);

        $resource = BookingResource::findOrFail($id);
        $name = $resource->name;
        $resource->delete();
        AuditLog::record('resource_deleted', 'resources', $name);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->editingId        = null;
        $this->name             = '';
        $this->categoryId       = (string) (BookingCategory::first()?->id ?? '');
        $this->location         = '';
        $this->description      = '';
        $this->minDuration      = 30;
        $this->maxDuration      = '';
        $this->capacity         = 1;
        $this->requiresApproval = false;
        $this->active           = true;
        $this->color            = '#6366f1';
        $this->resetErrorBag();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 2;

        while (BookingResource::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function render()
    {
        return view('livewire.admin.booking.resource-manager', [
            'resources'  => BookingResource::with('category')->orderBy('name')->get(),
            'categories' => BookingCategory::where('active', true)->orderBy('name')->get(),
        ]);
    }
}
