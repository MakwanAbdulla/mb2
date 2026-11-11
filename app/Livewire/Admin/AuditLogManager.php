<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AuditLogManager extends Component
{
    use WithPagination;

    #[Url(as: 'omrade')]
    public string $area = '';

    #[Url(as: 'atgard')]
    public string $action = '';

    #[Url(as: 'anvandare')]
    public string $userId = '';

    #[Url(as: 'fran')]
    public string $dateFrom = '';

    #[Url(as: 'till')]
    public string $dateTo = '';

    #[Url(as: 'rader')]
    public int $perPage = 20;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('audit.view'), 403);

        if (! $this->dateFrom && ! $this->dateTo) {
            $this->dateFrom = now()->subWeek()->format('Y-m-d');
            $this->dateTo   = now()->format('Y-m-d');
        }
    }

    public function updatedArea(): void     { $this->resetPage(); }
    public function updatedAction(): void   { $this->resetPage(); }
    public function updatedUserId(): void   { $this->resetPage(); }
    public function updatedDateFrom(): void { $this->resetPage(); }
    public function updatedDateTo(): void   { $this->resetPage(); }
    public function updatedPerPage(): void  { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->area     = '';
        $this->action   = '';
        $this->userId   = '';
        $this->dateFrom = now()->subWeek()->format('Y-m-d');
        $this->dateTo   = now()->format('Y-m-d');
        $this->perPage  = 20;
        $this->resetPage();
    }

    public function render()
    {
        $perPage = in_array($this->perPage, [20, 50, 100]) ? $this->perPage : 20;

        $query = AuditLog::with(['user', 'auditable'])
            ->when($this->area,     fn ($q) => $q->where('area', $this->area))
            ->when($this->action,   fn ($q) => $q->where('action', $this->action))
            ->when($this->userId,   fn ($q) => $q->where('user_id', $this->userId))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderByDesc('created_at');

        return view('livewire.admin.audit-log-manager', [
            'logs'         => $query->paginate($perPage),
            'users'        => User::orderBy('name')->get(['id', 'name']),
            'areaLabels'   => AuditLog::AREA_LABELS,
            'actionLabels' => AuditLog::ACTION_LABELS,
        ]);
    }
}
