<?php

use App\Models\AuditLog;
use App\Models\Membership\Member;
use App\Models\Membership\MemberTag;
use App\Models\Shop\Order;
use App\Models\Membership\TagYearPrice;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, title};

layout('components.layouts.public');
title('RFID-taggar');

new class extends Component {

    public string  $search       = '';
    public string  $filterStatus = '';

    // Assign / issue form
    public bool    $showIssueForm  = false;
    public string  $issueSearch    = '';
    public ?int    $issueMemberId  = null;
    public string  $issueUid       = '';

    // Edit UID on existing tag
    public ?int    $editTagId  = null;
    public string  $editUid    = '';

    // Flash
    public ?string $flash      = null;
    public bool    $flashError = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPermissionTo('tags.manage'), 403);
    }

    // ── Computed ───────────────────────────────────────────────────────────────

    #[Computed]
    public function tags()
    {
        return MemberTag::with(['member'])
            ->when($this->search !== '', function ($q) {
                $q->where(function ($inner) {
                    $inner->whereHas('member', fn ($m) => $m->where('first_name', 'like', '%' . $this->search . '%')
                                                            ->orWhere('last_name', 'like', '%' . $this->search . '%')
                                                            ->orWhere('membership_number', 'like', '%' . $this->search . '%'))
                          ->orWhere('tag_uid', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            ->orderByDesc('created_at')
            ->paginate(30);
    }

    #[Computed]
    public function memberSuggestions()
    {
        if (strlen($this->issueSearch) < 2) {
            return collect();
        }
        $term = mb_strtolower($this->issueSearch);
        return Member::get()
            ->filter(fn ($m) =>
                str_contains(mb_strtolower($m->first_name . ' ' . $m->last_name), $term)
                || str_contains(mb_strtolower($m->membership_number ?? ''), $term)
            )
            ->take(10)
            ->values();
    }

    #[Computed]
    public function tagPrice(): int
    {
        return TagYearPrice::forYear(now()->year);
    }

    // ── Actions ────────────────────────────────────────────────────────────────

    public function selectMember(int $memberId): void
    {
        $member = Member::find($memberId);
        $this->issueMemberId = $memberId;
        $this->issueSearch   = $member?->full_name ?? '';
        unset($this->memberSuggestions);
    }

    public function issueTag(): void
    {
        $this->validate([
            'issueMemberId' => 'required|exists:members,id',
            'issueUid'      => ['required', 'string', 'max:64', 'regex:/^[0-9A-Fa-f]+$/'],
        ], [
            'issueMemberId.required' => 'Välj en medlem.',
            'issueUid.required'      => 'Ange tagg-UID.',
            'issueUid.regex'         => 'UID får bara innehålla hexadecimala tecken (0–9, A–F).',
        ]);

        // Normalise UID to uppercase
        $uid = strtoupper(trim($this->issueUid));

        if (MemberTag::where('tag_uid', $uid)->exists()) {
            $this->addError('issueUid', 'UID:et är redan registrerat.');
            return;
        }

        $member = Member::findOrFail($this->issueMemberId);

        $tag = MemberTag::create([
            'member_id'          => $member->id,
            'tag_uid'            => $uid,
            'status'             => MemberTag::STATUS_ACTIVE,
            'price_ore'          => $this->tagPrice,
            'issued_at'          => now(),
            'paxton_sync_status' => MemberTag::PAXTON_PENDING,
        ]);

        $existing = Order::where('user_id', $member->user?->id)
            ->where('status', Order::STATUS_PENDING)
            ->whereDate('created_at', today())
            ->first();

        if ($existing) {
            $tag->addLineToOrder($existing);
        } else {
            $tag->createOrder();
        }

        $this->reset(['showIssueForm', 'issueSearch', 'issueMemberId', 'issueUid']);
        unset($this->tags, $this->memberSuggestions);

        AuditLog::record('rfid_tag_issued', 'members', $member->full_name, $tag);

        $this->flash      = 'Tagg utfärdad och order skapad.';
        $this->flashError = false;
    }

    public function startEditUid(int $tagId, string $currentUid): void
    {
        $this->editTagId = $tagId;
        $this->editUid   = $currentUid;
    }

    public function saveUid(): void
    {
        $this->validate([
            'editUid' => ['required', 'string', 'max:64', 'regex:/^[0-9A-Fa-f]+$/'],
        ], [
            'editUid.required' => 'Ange tagg-UID.',
            'editUid.regex'    => 'UID får bara innehålla hexadecimala tecken (0–9, A–F).',
        ]);

        $uid = strtoupper(trim($this->editUid));

        if (MemberTag::where('tag_uid', $uid)->where('id', '!=', $this->editTagId)->exists()) {
            $this->addError('editUid', 'UID:et är redan registrerat.');
            return;
        }

        $tag = MemberTag::findOrFail($this->editTagId);
        $tag->update([
            'tag_uid'            => $uid,
            'paxton_sync_status' => MemberTag::PAXTON_PENDING,
        ]);
        AuditLog::record('rfid_tag_uid_updated', 'members', $tag->member?->full_name ?? $uid, $tag);

        $this->reset(['editTagId', 'editUid']);
        unset($this->tags);

        $this->flash      = 'Tagg-UID uppdaterat.';
        $this->flashError = false;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editTagId', 'editUid']);
    }

    public function deactivate(int $tagId, string $reason): void
    {
        abort_unless(in_array($reason, [MemberTag::STATUS_LOST, MemberTag::STATUS_BROKEN, MemberTag::STATUS_DEACTIVATED]), 422);

        $tag = MemberTag::findOrFail($tagId);
        $tag->update([
            'status'             => $reason,
            'deactivated_at'     => now(),
            'paxton_sync_status' => MemberTag::PAXTON_PENDING,
        ]);
        AuditLog::record('rfid_tag_deactivated', 'members', $tag->member?->full_name ?? $tag->tag_uid, $tag);

        unset($this->tags);
        $this->flash      = 'Tagg avaktiverad (' . MemberTag::statusLabel($reason) . ').';
        $this->flashError = false;
    }

    public function reactivate(int $tagId): void
    {
        $tag = MemberTag::findOrFail($tagId);
        $tag->update([
            'status'             => MemberTag::STATUS_ACTIVE,
            'deactivated_at'     => null,
            'paxton_sync_status' => MemberTag::PAXTON_PENDING,
        ]);
        AuditLog::record('rfid_tag_reactivated', 'members', $tag->member?->full_name ?? $tag->tag_uid, $tag);

        unset($this->tags);
        $this->flash      = 'Tagg återaktiverad.';
        $this->flashError = false;
    }
}; ?>

<div class="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">RFID-taggar</h1>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                Pris: {{ number_format($this->tagPrice / 100, 2, ',', ' ') }} kr
            </p>
        </div>
        <button wire:click="$set('showIssueForm', true)"
                class="btn btn-primary">
            <x-bootstrap-icon name="plus-lg" class="size-4" />
            Tilldela ny tagg
        </button>
    </div>

    {{-- Flash --}}
    @if($flash)
        <div class="flex items-center gap-3 rounded-xl border px-4 py-3
                    {{ $flashError
                        ? 'border-red-200 bg-red-50 dark:border-red-900/40 dark:bg-red-900/20'
                        : 'border-green-200 bg-green-50 dark:border-green-900/40 dark:bg-green-900/20' }}"
             x-data x-init="setTimeout(() => $wire.set('flash', null), 4000)">
            <x-bootstrap-icon name="{{ $flashError ? 'exclamation-circle-fill' : 'check-circle-fill' }}"
                class="size-4 shrink-0 {{ $flashError ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}" />
            <span class="text-sm {{ $flashError ? 'text-red-800 dark:text-red-300' : 'text-green-800 dark:text-green-300' }}">
                {{ $flash }}
            </span>
        </div>
    @endif

    {{-- Issue form modal-ish card --}}
    @if($showIssueForm)
        <div class="overflow-hidden rounded-xl border border-brand bg-white shadow-sm dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Tilldela ny RFID-tagg</span>
            </div>
            <div class="space-y-4 px-5 py-4">

                {{-- Member search --}}
                <div>
                    <label class="lbl">Sök medlem</label>
                    <x-member-search wire:model.live.debounce.300ms="issueSearch"
                                     :results="$this->memberSuggestions"
                                     action="selectMember"
                                     :selected-name="$issueMemberId ? Member::find($issueMemberId)?->full_name : null" />
                    @error('issueMemberId') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Tag UID --}}
                <div>
                    <label class="lbl">Tagg-UID <span class="font-normal text-zinc-400">(Paxton-format, utan de 2 första siffrorna)</span></label>
                    <input wire:model="issueUid"
                           type="text"
                           placeholder="t.ex. A1B2C3D4"
                           class="inp w-full font-mono uppercase" />
                    @error('issueUid') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center justify-between">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        En order om {{ number_format($this->tagPrice / 100, 2, ',', ' ') }} kr skapas automatiskt.
                    </p>
                    <div class="flex gap-2">
                        <button wire:click="$set('showIssueForm', false)"
                                class="btn btn-ghost btn-sm">Avbryt</button>
                        <button wire:click="issueTag"
                                class="btn btn-primary btn-sm">Tilldela tagg</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3">
        <input wire:model.live.debounce.300ms="search"
               type="search"
               placeholder="Sök medlem eller UID…"
               class="inp w-64" />
        <select wire:model.live="filterStatus" class="inp">
            <option value="">Alla statusar</option>
            @foreach(\App\Models\Membership\MemberTag::STATUSES as $s)
                <option value="{{ $s }}">{{ \App\Models\Membership\MemberTag::statusLabel($s) }}</option>
            @endforeach
        </select>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        @if($this->tags->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-zinc-400 dark:text-zinc-500">Inga taggar hittades.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Medlem</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Tagg-UID</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Status</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Paxton</th>
                        <th class="px-4 py-3 text-left font-semibold text-zinc-600 dark:text-zinc-300">Utfärdad</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @foreach($this->tags as $tag)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">

                            {{-- Member --}}
                            <td class="px-4 py-3">
                                @if($tag->member)
                                    <a href="{{ route('admin.members.show', $tag->member) }}"
                                       class="font-medium text-zinc-900 hover:underline dark:text-white">
                                        {{ $tag->member->full_name }}
                                    </a>
                                    <span class="block text-xs text-zinc-400">#{{ $tag->member->membership_number }}</span>
                                @else
                                    <span class="text-zinc-400">–</span>
                                @endif
                            </td>

                            {{-- UID --}}
                            <td class="px-4 py-3 font-mono">
                                @if($editTagId === $tag->id)
                                    <div class="flex items-center gap-2">
                                        <input wire:model="editUid"
                                               type="text"
                                               class="inp w-36 font-mono uppercase text-sm"
                                               placeholder="UID…" />
                                        <button wire:click="saveUid"
                                                class="btn btn-primary btn-sm">OK</button>
                                        <button wire:click="cancelEdit"
                                                class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">Avbryt</button>
                                    </div>
                                    @error('editUid') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                @else
                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $tag->tag_uid ?? '–' }}</span>
                                @endif
                            </td>

                            {{-- Status badge --}}
                            <td class="px-4 py-3">
                                @php
                                    $statusColors = [
                                        'ordered'     => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
                                        'active'      => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                                        'lost'        => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                        'broken'      => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
                                        'deactivated' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                                    ];
                                    $statusIcons = [
                                        'ordered'     => 'hourglass-split',
                                        'active'      => 'check-circle-fill',
                                        'lost'        => 'question-circle-fill',
                                        'broken'      => 'tools',
                                        'deactivated' => 'x-circle-fill',
                                    ];
                                @endphp
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium
                                             {{ $statusColors[$tag->status] ?? 'bg-zinc-100 text-zinc-500' }}">
                                    <x-bootstrap-icon name="{{ $statusIcons[$tag->status] ?? 'circle' }}" class="size-3 shrink-0" />
                                    {{ \App\Models\Membership\MemberTag::statusLabel($tag->status) }}
                                </span>
                            </td>

                            {{-- Paxton sync --}}
                            <td class="px-4 py-3">
                                @if($tag->paxton_sync_status === 'synced')
                                    <span class="inline-flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                                        <x-bootstrap-icon name="cloud-check-fill" class="size-3" />
                                        Synkad
                                    </span>
                                @elseif($tag->paxton_sync_status === 'failed')
                                    <span class="inline-flex items-center gap-1 text-xs text-red-600 dark:text-red-400"
                                          title="{{ $tag->paxton_sync_error }}">
                                        <x-bootstrap-icon name="cloud-slash-fill" class="size-3" />
                                        Misslyckades
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-xs text-zinc-400">
                                        <x-bootstrap-icon name="cloud-upload" class="size-3" />
                                        Väntar
                                    </span>
                                @endif
                            </td>

                            {{-- Issued --}}
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                                {{ $tag->issued_at?->isoFormat('D MMM YYYY') ?? '–' }}
                            </td>

                            {{-- Actions --}}
                            <td class="px-4 py-3 text-right">
                                @if($editTagId !== $tag->id)
                                    <div class="flex items-center justify-end gap-3">
                                        <button wire:click="startEditUid({{ $tag->id }}, '{{ $tag->tag_uid }}')"
                                                class="text-xs text-complement dark:text-complement-light hover:underline">
                                            Ändra UID
                                        </button>

                                        @if($tag->status === \App\Models\Membership\MemberTag::STATUS_ACTIVE)
                                            <div x-data="{ open: false }" class="relative">
                                                <button @click="open = !open"
                                                        class="text-xs text-zinc-500 hover:text-red-500 dark:text-zinc-400 dark:hover:text-red-400">
                                                    Avaktivera ▾
                                                </button>
                                                <div x-show="open" @click.outside="open = false" x-cloak
                                                     class="absolute right-0 z-10 mt-1 w-40 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                                                    <button wire:click="deactivate({{ $tag->id }}, 'lost')"
                                                            wire:confirm="Markera taggen som borttappad?"
                                                            @click="open = false"
                                                            class="block w-full px-4 py-2 text-left text-xs hover:bg-zinc-50 dark:hover:bg-zinc-700">
                                                        Borttappad
                                                    </button>
                                                    <button wire:click="deactivate({{ $tag->id }}, 'broken')"
                                                            wire:confirm="Markera taggen som trasig?"
                                                            @click="open = false"
                                                            class="block w-full px-4 py-2 text-left text-xs hover:bg-zinc-50 dark:hover:bg-zinc-700">
                                                        Trasig
                                                    </button>
                                                    <button wire:click="deactivate({{ $tag->id }}, 'deactivated')"
                                                            wire:confirm="Avaktivera taggen permanent?"
                                                            @click="open = false"
                                                            class="block w-full px-4 py-2 text-left text-xs text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                                                        Avaktivera
                                                    </button>
                                                </div>
                                            </div>
                                        @elseif(in_array($tag->status, [\App\Models\Membership\MemberTag::STATUS_LOST, \App\Models\Membership\MemberTag::STATUS_BROKEN]))
                                            <button wire:click="reactivate({{ $tag->id }})"
                                                    wire:confirm="Återaktivera taggen?"
                                                    class="text-xs text-green-600 dark:text-green-400 hover:underline">
                                                Återaktivera
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Pagination --}}
            @if($this->tags->hasPages())
                <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
                    {{ $this->tags->links() }}
                </div>
            @endif
        @endif
    </div>

</div>
