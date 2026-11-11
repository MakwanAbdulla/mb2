<?php

use App\Models\AuditLog;
use App\Models\Membership\MemberTag;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {

    public ?string $flash      = null;
    public bool    $flashError = false;

    // Report form
    public bool   $showReportForm = false;
    public string $reportReason   = '';
    public string $reportNotes    = '';

    #[Computed]
    public function member()
    {
        return Auth::user()->member;
    }

    #[Computed]
    public function tags()
    {
        return $this->member?->tags()->orderByDesc('created_at')->get() ?? collect();
    }

    #[Computed]
    public function activeTag(): ?MemberTag
    {
        return $this->tags->firstWhere('status', MemberTag::STATUS_ACTIVE);
    }

    #[Computed]
    public function tagPrice(): int
    {
        return (int) SystemSetting::get('tags.price_ore', 7500);
    }

    public function reportTag(int $tagId): void
    {
        $this->validate([
            'reportReason' => 'required|in:lost,broken',
            'reportNotes'  => 'nullable|string|max:500',
        ], [
            'reportReason.required' => 'Välj orsak.',
            'reportReason.in'       => 'Ogiltig orsak.',
        ]);

        $tag = MemberTag::where('id', $tagId)
            ->where('member_id', $this->member->id)
            ->where('status', MemberTag::STATUS_ACTIVE)
            ->firstOrFail();

        $tag->update([
            'status'             => $this->reportReason,
            'deactivated_at'     => now(),
            'notes'              => trim(($tag->notes ?? '') . "\n" . now()->format('Y-m-d') . ': ' . $this->reportNotes),
            'paxton_sync_status' => MemberTag::PAXTON_PENDING,
        ]);

        AuditLog::record('rfid_tag_reported', 'members', ($tag->tag_uid ?? 'okänd UID') . ' — ' . $this->reportReason, $tag);
        $this->reset(['showReportForm', 'reportReason', 'reportNotes']);
        unset($this->tags, $this->activeTag);

        $this->flash      = 'Tack! Din tagg är nu markerad som ' . MemberTag::statusLabel($this->reportReason) . '. Kontakta styrelsen för att beställa en ny.';
        $this->flashError = false;
    }
}; ?>

<div class="space-y-6">

    {{-- Flash --}}
    @if($flash)
        <div class="flex items-center gap-3 rounded-xl border px-4 py-3
                    {{ $flashError
                        ? 'border-red-200 bg-red-50 dark:border-red-900/40 dark:bg-red-900/20'
                        : 'border-green-200 bg-green-50 dark:border-green-900/40 dark:bg-green-900/20' }}"
             x-data x-init="setTimeout(() => $wire.set('flash', null), 8000)">
            <x-bootstrap-icon name="{{ $flashError ? 'exclamation-circle-fill' : 'check-circle-fill' }}"
                class="size-4 shrink-0 {{ $flashError ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}" />
            <span class="text-sm {{ $flashError ? 'text-red-800 dark:text-red-300' : 'text-green-800 dark:text-green-300' }}">
                {{ $flash }}
            </span>
        </div>
    @endif

    {{-- Active tag --}}
    @if($this->activeTag)
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Din aktiva tagg</span>
            </div>
            <div class="flex items-center justify-between px-5 py-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                        <x-bootstrap-icon name="broadcast" class="size-5 text-green-600 dark:text-green-400" />
                    </div>
                    <div>
                        <p class="font-medium text-zinc-900 dark:text-white">
                            UID: <span class="font-mono">{{ $this->activeTag->tag_uid ?? 'Ej tilldelad ännu' }}</span>
                        </p>
                        @if($this->activeTag->issued_at)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Utfärdad {{ $this->activeTag->issued_at->isoFormat('D MMMM YYYY') }}
                            </p>
                        @endif
                    </div>
                </div>

                @if(! $showReportForm)
                    <button wire:click="$set('showReportForm', true)"
                            class="btn btn-sm btn-ghost text-zinc-500 hover:text-red-500 dark:text-zinc-400 dark:hover:text-red-400">
                        Rapportera problem
                    </button>
                @endif
            </div>

            {{-- Report form --}}
            @if($showReportForm)
                <div class="border-t border-zinc-100 bg-zinc-50 px-5 py-4 dark:border-zinc-800 dark:bg-zinc-800/50">
                    <p class="mb-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">Rapportera problem med taggen</p>
                    <div class="space-y-3">
                        <div class="flex gap-4">
                            <label class="flex items-center gap-2 text-sm">
                                <input wire:model="reportReason" type="radio" value="lost" class="accent-brand" />
                                <span class="text-zinc-700 dark:text-zinc-300">Borttappad</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <input wire:model="reportReason" type="radio" value="broken" class="accent-brand" />
                                <span class="text-zinc-700 dark:text-zinc-300">Trasig</span>
                            </label>
                        </div>
                        @error('reportReason') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                        <div>
                            <textarea wire:model="reportNotes"
                                      rows="2"
                                      placeholder="Valfri kommentar…"
                                      class="inp w-full resize-none text-sm"></textarea>
                        </div>

                        <div class="flex items-center gap-2">
                            <button wire:click="reportTag({{ $this->activeTag->id }})"
                                    wire:confirm="Rapportera taggen som {{ $reportReason === 'lost' ? 'borttappad' : 'trasig' }}? Du kan sedan kontakta styrelsen för en ny tagg."
                                    class="btn btn-sm btn-danger">
                                Rapportera
                            </button>
                            <button wire:click="$set('showReportForm', false)"
                                    class="btn btn-sm btn-ghost">Avbryt</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>

    @else
        {{-- No active tag --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="px-5 py-6 text-center">
                <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <x-bootstrap-icon name="broadcast-pin" class="size-6 text-zinc-400" />
                </div>
                <p class="font-medium text-zinc-900 dark:text-white">Du har ingen aktiv RFID-tagg</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    En RFID-tagg ger dig tillgång till föreningens lokal.
                    Priset är {{ number_format($this->tagPrice / 100, 2, ',', ' ') }} kr.
                </p>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    Kontakta styrelsen för att köpa en tagg.
                </p>
            </div>
        </div>
    @endif

    {{-- Tag history --}}
    @php $oldTags = $this->tags->where('status', '!=', \App\Models\Membership\MemberTag::STATUS_ACTIVE); @endphp
    @if($oldTags->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800">
                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Tidigare taggar</span>
            </div>
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($oldTags as $tag)
                    <li class="flex items-center justify-between px-5 py-3">
                        <div>
                            <p class="text-sm font-medium text-zinc-900 dark:text-white font-mono">
                                {{ $tag->tag_uid ?? '–' }}
                            </p>
                            @if($tag->deactivated_at)
                                <p class="text-xs text-zinc-400">
                                    Avaktiverad {{ $tag->deactivated_at->isoFormat('D MMMM YYYY') }}
                                </p>
                            @endif
                        </div>
                        @php
                            $badgeClass = match($tag->status) {
                                'lost'        => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                'broken'      => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
                                'deactivated' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                                default       => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
                            };
                        @endphp
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badgeClass }}">
                            {{ \App\Models\Membership\MemberTag::statusLabel($tag->status) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

</div>
