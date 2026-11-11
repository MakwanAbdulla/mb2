<?php

namespace App\Livewire\Booking;

use App\Models\Booking\BookingCategory;
use App\Models\Booking\BookingResource;
use Livewire\Component;
use Livewire\WithPagination;

class ResourceList extends Component
{
    use WithPagination;

    public string $search           = '';
    public string $selectedCategory = '';
    public string $date             = '';

    public function mount(): void
    {
        $this->date = now()->format('Y-m-d');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCategory(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = BookingResource::query()
            ->with('category')
            ->where('active', true)
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('description', 'like', "%{$this->search}%"))
            ->when($this->selectedCategory, fn ($q) => $q->where('category_id', $this->selectedCategory));

        return view('livewire.booking.resource-list', [
            'resources'  => $query->paginate(12),
            'categories' => BookingCategory::where('active', true)->get(),
        ]);
    }
}
