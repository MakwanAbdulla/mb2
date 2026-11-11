<?php

use App\Models\Membership\AccountDeletionRequest;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('Raderingsbegäranden');

new class extends Component {
    use WithPagination;

    public function mount(): void
    {
        abort_unless(Auth::check() && Auth::user()->hasPermissionTo('members.manage'), 403);
    }

    public string $filterStatus = 'pending';

    // Rejection modal state
    public ?int   $rejectingId  = null;
    public string $adminNote    = '';

    public function updatedFilterStatus(): void { $this->resetPage(); }

    #[Computed]
    public function requests()
    {
        return AccountDeletionRequest::with(['member', 'user', 'actionedBy'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->latest()
            ->paginate(25);
    }

    #[Computed]
    public function pendingCount(): int
    {
        return AccountDeletionRequest::where('status', AccountDeletionRequest::STATUS_PENDING)->count();
    }

    public function approve(int $id): void
    {
        $request = AccountDeletionRequest::with('member.user')->findOrFail($id);

        if (! $request->isPending()) {
            return;
        }

        $member = $request->member;

        // Capture before approve() wipes the data via anonymize()
        $memberName   = $member->full_name;
        $memberNumber = $member->membership_number;

        $request->approve(auth()->user());

        AuditLog::record(
            action: 'membership_deactivated',
            area: 'members',
            subject: "GDPR-radering godkänd: {$memberName} (#{$memberNumber})",
            auditable: $member,
            changes: [
                'reason'       => 'gdpr_erasure',
                'reason_label' => AuditLog::DEACTIVATION_REASON_LABELS['gdpr_erasure'],
                'previous_status' => 'active',
                'new_status'      => 'deleted',
                'request_id'   => $id,
            ],
        );

        $this->unset('requests');
    }

    public function openReject(int $id): void
    {
        $this->rejectingId = $id;
        $this->adminNote   = '';
    }

    public function confirmReject(): void
    {
        if (! $this->rejectingId) {
            return;
        }

        $this->validate(['adminNote' => 'required|string|max:500'], [
            'adminNote.required' => 'Ange en anledning till avslaget.',
        ]);

        $request = AccountDeletionRequest::findOrFail($this->rejectingId);

        if (! $request->isPending()) {
            $this->rejectingId = null;
            return;
        }

        $request->reject(auth()->user(), $this->adminNote);

        AuditLog::record(
            action: 'gdpr_request_rejected',
            area: 'members',
            subject: "GDPR-begäran nekad: {$request->member?->full_name}",
            auditable: $request->member,
            changes: ['admin_note' => $this->adminNote, 'request_id' => $this->rejectingId],
        );

        $this->rejectingId = null;
        $this->adminNote   = '';
        $this->unset('requests');
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->adminNote   = '';
    }
};
?>

<x-slot:header>
    <x-page-header permission="members.view" title="Raderingsbegäranden (GDPR)">
        @if($this->pendingCount > 0)
            <x-slot:actions>
                <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-800 dark:bg-red-900/30 dark:text-red-300">
                    {{ $this->pendingCount }} väntande
                </span>
            </x-slot:actions>
        @endif
    </x-page-header>
</x-slot:header>

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- Rejection modal --}}
    @if($rejectingId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
             x-data x-on:keydown.escape.window="$wire.cancelReject()">
            <div class="w-full max-w-md rounded-xl bg-white shadow-xl dark:bg-zinc-800">
                <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">Neka raderingsbegäran</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Anledning till avslag <span class="text-red-500">*</span>
                        </label>
                        <textarea wire:model="adminNote" rows="3"
                                  placeholder="T.ex. utestående betalning, pågående ärende…"
                                  class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"></textarea>
                        @error('adminNote') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        Anledningen visas inte för medlemmen automatiskt — du behöver kontakta dem separat om nödvändigt.
                    </p>
                </div>
                <div class="flex justify-end gap-2 border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <button type="button" wire:click="cancelReject"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-700">
                        Avbryt
                    </button>
                    <button type="button" wire:click="confirmReject"
                            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                        Neka begäran
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Filter tabs --}}
    <div class="mb-6 flex gap-1 border-b border-zinc-200 dark:border-zinc-700">
        @foreach([
            AccountDeletionRequest::STATUS_PENDING  => 'Väntande',
            AccountDeletionRequest::STATUS_APPROVED => 'Godkända',
            AccountDeletionRequest::STATUS_REJECTED => 'Nekade',
            '' => 'Alla',
        ] as $value => $label)
            <button type="button"
                    wire:click="$set('filterStatus', '{{ $value }}')"
                    class="-mb-px px-4 py-2 text-sm font-medium transition-colors
                        {{ $filterStatus === $value
                            ? 'border-b-2 border-brand-dark text-zinc-900 dark:border-brand dark:text-zinc-100'
                            : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if($this->requests->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <x-bootstrap-icon name="shield-check" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Inga begäranden att visa.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($this->requests as $req)
                <div class="overflow-hidden rounded-xl border
                    {{ $req->status === 'pending'  ? 'border-amber-200 dark:border-amber-800/50' : '' }}
                    {{ $req->status === 'approved' ? 'border-red-200 dark:border-red-900/50'    : '' }}
                    {{ $req->status === 'rejected' ? 'border-zinc-200 dark:border-zinc-700'     : '' }}
                    bg-white dark:bg-zinc-800">
                    <div class="flex flex-wrap items-start justify-between gap-4 p-5">
                        <div class="min-w-0 flex-1">
                            {{-- Member info --}}
                            @if($req->member && $req->member->status !== 'deleted')
                                <a href="{{ route('admin.members.show', $req->member) }}" wire:navigate
                                   class="font-semibold text-complement-light hover:underline dark:text-complement-light">
                                    {{ $req->member->full_name }}
                                </a>
                                <div class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                                    #{{ $req->member->membership_number }} · {{ $req->user->email ?? '–' }}
                                </div>
                            @else
                                <span class="font-semibold italic text-zinc-400 dark:text-zinc-500">Raderad användare</span>
                                @if($req->user)
                                    <div class="mt-0.5 text-xs text-zinc-400">{{ $req->user->email ?? '–' }}</div>
                                @endif
                            @endif

                            {{-- Request date + reason --}}
                            <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                Inskickad {{ $req->created_at->format('d/m Y H:i') }}
                            </div>
                            @if($req->reason)
                                <blockquote class="mt-2 border-l-2 border-zinc-300 pl-3 text-sm italic text-zinc-600 dark:border-zinc-600 dark:text-zinc-400">
                                    {{ $req->reason }}
                                </blockquote>
                            @endif
                        </div>

                        {{-- Status badge + actions --}}
                        <div class="flex flex-col items-end gap-2">
                            @if($req->status === 'pending')
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                    <x-bootstrap-icon name="hourglass-split" class="size-3" />
                                    Väntande
                                </span>
                                <div class="flex gap-2">
                                    <button type="button"
                                            wire:click="approve({{ $req->id }})"
                                            wire:confirm="Godkänn radering och anonymisera personuppgifter? Åtgärden kan inte ångras."
                                            class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">
                                        Godkänn radering
                                    </button>
                                    <button type="button"
                                            wire:click="openReject({{ $req->id }})"
                                            class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-700">
                                        Neka
                                    </button>
                                </div>
                            @elseif($req->status === 'approved')
                                <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-800 dark:bg-red-900/30 dark:text-red-300">
                                    <x-bootstrap-icon name="check-circle" class="size-3" />
                                    Godkänd
                                </span>
                                <div class="text-xs text-zinc-400">
                                    av {{ $req->actionedBy?->name ?? '–' }} · {{ $req->actioned_at?->format('d/m Y') }}
                                </div>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-semibold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400">
                                    <x-bootstrap-icon name="x-circle" class="size-3" />
                                    Nekad
                                </span>
                                @if($req->admin_note)
                                    <p class="max-w-xs text-right text-xs text-zinc-500 dark:text-zinc-400">{{ $req->admin_note }}</p>
                                @endif
                                <div class="text-xs text-zinc-400">
                                    av {{ $req->actionedBy?->name ?? '–' }} · {{ $req->actioned_at?->format('d/m Y') }}
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $this->requests->links() }}
        </div>
    @endif
</div>
