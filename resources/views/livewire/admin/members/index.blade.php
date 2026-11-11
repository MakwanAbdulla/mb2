<?php

use App\Models\Membership\Member;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public string $type = '';

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('members.view'), 403);
    }

    #[Computed]
    public function members()
    {
        return Member::query()
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('first_name', 'like', "%{$this->search}%")
                    ->orWhere('last_name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            }))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->type, fn ($q) => $q->where('membership_type', $this->type))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25);
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }
    public function updatedType(): void { $this->resetPage(); }

    public function statusLabel(string $status): string
    {
        return match($status) {
            Member::STATUS_PENDING  => 'Väntar',
            Member::STATUS_ACTIVE   => 'Aktiv',
            Member::STATUS_INACTIVE => 'Inaktiv',
            Member::STATUS_EXPIRED  => 'Utgången',
            Member::STATUS_DECEASED => 'Avliden',
            default                 => $status,
        };
    }

    public function statusClasses(string $status): string
    {
        return match($status) {
            Member::STATUS_PENDING  => 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
            Member::STATUS_ACTIVE   => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            Member::STATUS_INACTIVE => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
            Member::STATUS_EXPIRED  => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            Member::STATUS_DECEASED => 'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400',
            default                 => 'bg-zinc-100 text-zinc-600',
        };
    }

    public function typeLabel(string $type): string
    {
        return match($type) {
            Member::TYPE_SINGLE   => 'Vuxen',
            Member::TYPE_FAMILY   => 'Familj',
            Member::TYPE_YOUTH    => 'Ungdom',
            Member::TYPE_CHILD    => 'Barn',
            Member::TYPE_STUDENT  => 'Student',
            Member::TYPE_SENIOR   => 'Senior',
            Member::TYPE_HONORARY => 'Hedersmedlem',
            default               => $type,
        };
    }
}; ?>

<div>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">

        <div class="flex justify-end">
            @can('members.manage')
            <a href="{{ route('admin.members.create') }}" wire:navigate
               class="inline-flex items-center gap-1.5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-dark hover:bg-brand-saffron">
                + Registrera ny medlem
            </a>
            @endcan
        </div>

        {{-- Search & filters --}}
        <div class="flex flex-col gap-3 sm:flex-row">
            <div class="relative flex-1">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <x-bootstrap-icon name="search" class="size-4 text-zinc-400" />
                </div>
                <input wire:model.live.debounce.300ms="search"
                       type="search"
                       placeholder="Sök på namn eller e-post…"
                       class="w-full rounded-lg border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 placeholder-zinc-400 focus:border-brand-saffron focus:outline-none focus:ring-2 focus:ring-brand-saffron/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder-zinc-500" />
            </div>

            <select wire:model.live="status"
                    class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                <option value="">Alla statusar</option>
                <option value="{{ Member::STATUS_PENDING }}">Väntar</option>
                <option value="{{ Member::STATUS_ACTIVE }}">Aktiv</option>
                <option value="{{ Member::STATUS_INACTIVE }}">Inaktiv</option>
                <option value="{{ Member::STATUS_EXPIRED }}">Utgången</option>
                <option value="{{ Member::STATUS_DECEASED }}">Avliden</option>
            </select>

            <select wire:model.live="type"
                    class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                <option value="">Alla typer</option>
                <option value="{{ Member::TYPE_SINGLE }}">Vuxen</option>
                <option value="{{ Member::TYPE_FAMILY }}">Familj</option>
                <option value="{{ Member::TYPE_YOUTH }}">Ungdom</option>
                <option value="{{ Member::TYPE_STUDENT }}">Student</option>
                <option value="{{ Member::TYPE_SENIOR }}">Senior</option>
                <option value="{{ Member::TYPE_HONORARY }}">Hedersmedlem</option>
            </select>
        </div>

        {{-- Table --}}
        @if($this->members->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-8 text-center text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400">
                @if($search || $status || $type)
                    Inga medlemmar matchar sökningen.
                @else
                    Inga medlemmar registrerade ännu.
                @endif
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Namn</th>
                            <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 sm:table-cell">E-post</th>
                            <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 md:table-cell">Typ</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                            <th class="relative px-4 py-3"><span class="sr-only">Åtgärder</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                        @foreach($this->members as $member)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.members.show', $member) }}" wire:navigate
                                       class="font-medium text-zinc-900 hover:text-complement dark:text-white dark:hover:text-complement-light">
                                        {{ $member->full_name }}
                                    </a>
                                    @if($member->phone)
                                        <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $member->phone }}</div>
                                    @endif
                                </td>
                                <td class="hidden px-4 py-3 text-zinc-600 dark:text-zinc-400 sm:table-cell">
                                    {{ $member->email }}
                                </td>
                                <td class="hidden px-4 py-3 text-zinc-600 dark:text-zinc-400 md:table-cell">
                                    {{ $this->typeLabel($member->membership_type) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $this->statusClasses($member->status) }}">
                                        {{ $this->statusLabel($member->status) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('admin.members.show', $member) }}" wire:navigate
                                           class="text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Visa</a>
                                        <a href="{{ route('admin.members.edit', $member) }}" wire:navigate
                                           class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Redigera</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination + count --}}
            <div class="flex items-center justify-between text-sm text-zinc-500 dark:text-zinc-400">
                <span>{{ $this->members->total() }} {{ $this->members->total() === 1 ? 'medlem' : 'medlemmar' }}</span>
                <div>{{ $this->members->links() }}</div>
            </div>
        @endif
    </div>
</div>
