<?php

namespace App\Livewire\Admin\Booking;

use App\Models\AuditLog;
use App\Models\Booking\BookingCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class CategoryManager extends Component
{
    public bool   $showForm    = false;
    public ?int   $editingId   = null;

    public string $name        = '';
    public string $description = '';
    public string $color       = '#6366f1';
    public bool   $active      = true;

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
        $cat = BookingCategory::findOrFail($id);

        $this->editingId   = $id;
        $this->name        = $cat->name;
        $this->description = $cat->description ?? '';
        $this->color       = $cat->color;
        $this->active      = $cat->active;

        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.categories'), 403);

        $this->validate([
            'name'        => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'color'       => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'active'      => ['boolean'],
        ], [
            'name.required' => 'Ange ett namn.',
            'color.regex'   => 'Ange en giltig hexfärg, t.ex. #6366f1.',
        ]);

        $data = [
            'name'        => $this->name,
            'description' => $this->description ?: null,
            'color'       => $this->color,
            'active'      => $this->active,
        ];

        if ($this->editingId) {
            $cat = BookingCategory::findOrFail($this->editingId);
            $cat->update($data);
            AuditLog::record('booking_category_updated', 'booking_categories', $cat->name, $cat);
        } else {
            $data['slug'] = $this->uniqueSlug($this->name);
            $cat = BookingCategory::create($data);
            AuditLog::record('booking_category_created', 'booking_categories', $cat->name, $cat);
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->hasPermissionTo('bookings.categories'), 403);

        $cat = BookingCategory::findOrFail($id);
        $name = $cat->name;
        $cat->delete();
        AuditLog::record('booking_category_deleted', 'booking_categories', $name);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->editingId   = null;
        $this->name        = '';
        $this->description = '';
        $this->color       = '#6366f1';
        $this->active      = true;
        $this->resetErrorBag();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 2;

        while (BookingCategory::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function render()
    {
        return view('livewire.admin.booking.category-manager', [
            'categories' => BookingCategory::withCount('resources')->orderBy('name')->get(),
        ]);
    }
}
