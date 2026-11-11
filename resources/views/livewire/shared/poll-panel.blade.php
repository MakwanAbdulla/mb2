<?php

use App\Models\AuditLog;
use App\Models\Meeting\MeetingAgendaItem;
use App\Models\Meeting\MeetingAttendee;
use App\Models\Poll;
use App\Models\Submission;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {

    #[Locked] public string $voteableType; // 'submission' | 'agenda_item'
    #[Locked] public int    $voteableId;
    #[Locked] public ?int   $meetingId = null;

    // Create form
    public bool   $showCreateForm = false;
    public string $newQuestion    = '';
    public string $newType        = 'yes_no_abstain';
    public string $newDeadline    = '';

    // Voter selection form (per capsulam only)
    public bool  $showVoterForm  = false;
    public array $selectedVoters = [];

    // Manual result form
    public bool   $showManualForm  = false;
    public int    $manualYes       = 0;
    public int    $manualNo        = 0;
    public int    $manualAbstain   = 0;
    public string $manualNote      = '';

    public function mount(string $voteableType, int $voteableId, ?int $meetingId = null): void
    {
        $this->voteableType = $voteableType;
        $this->voteableId   = $voteableId;
        $this->meetingId    = $meetingId;
    }

    #[Computed]
    public function poll(): ?Poll
    {
        return Poll::where('voteable_type', $this->morphClass())
            ->where('voteable_id', $this->voteableId)
            ->latest()
            ->first();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::check() && Auth::user()->can(
            $this->voteableType === 'submission' ? 'submissions.manage' : 'meetings.manage'
        );
    }

    #[Computed]
    public function isEligible(): bool
    {
        if (! Auth::check()) {
            return false;
        }

        if ($this->voteableType === 'agenda_item' && $this->meetingId) {
            return MeetingAttendee::where('meeting_id', $this->meetingId)
                ->whereHas('member', fn ($q) => $q->where('user_id', Auth::id()))
                ->where('present', true)
                ->where('voting_rights', true)
                ->exists();
        }

        // Per capsulam: eligible = pre-populated ballot row exists for this user
        $poll = $this->poll;
        if ($poll?->isOpen()) {
            return $poll->ballots()->where('voter_id', Auth::id())->exists();
        }

        return false;
    }

    #[Computed]
    public function userBallot(): ?int
    {
        if (! $this->poll || ! Auth::check()) {
            return null;
        }
        $ballot = $this->poll->ballots()->where('voter_id', Auth::id())->first();
        return $ballot?->option_id;
    }

    #[Computed]
    public function boardCandidates(): \Illuminate\Support\Collection
    {
        return Poll::eligibleBoardCandidates();
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function openCreateForm(): void
    {
        $this->newQuestion = $this->voteableType === 'submission'
            ? (Submission::find($this->voteableId)?->title ?? '')
            : (MeetingAgendaItem::find($this->voteableId)?->title ?? '');
        $this->showCreateForm = true;
    }

    public function createPoll(): void
    {
        abort_unless($this->canManage, 403);
        $this->validate([
            'newQuestion' => 'required|string|max:500',
            'newType'     => 'required|in:yes_no_abstain,yes_no,free',
            'newDeadline' => $this->voteableType === 'submission' ? 'required|date|after:now' : 'nullable|date',
        ], [], ['newQuestion' => 'fråga', 'newDeadline' => 'deadline']);

        Poll::create([
            'voteable_type' => $this->morphClass(),
            'voteable_id'   => $this->voteableId,
            'question'      => $this->newQuestion,
            'type'          => $this->newType,
            'status'        => 'pending',
            'deadline'      => $this->newDeadline ?: null,
            'created_by'    => Auth::id(),
        ]);

        AuditLog::record('poll_created', 'meetings', $this->newQuestion, null);
        $this->showCreateForm = false;
        unset($this->poll);
    }

    // ── Open / Close ──────────────────────────────────────────────────────────

    public function openVoterForm(): void
    {
        abort_unless($this->canManage && $this->poll, 403);
        // Pre-select all candidates
        $this->selectedVoters = $this->boardCandidates->pluck('user_id')->map(fn ($id) => (string) $id)->all();
        $this->showVoterForm  = true;
    }

    public function openPoll(): void
    {
        abort_unless($this->canManage && $this->poll, 403);

        if ($this->voteableType === 'submission') {
            // Validate at least one voter selected
            if (empty($this->selectedVoters)) {
                $this->addError('selectedVoters', 'Välj minst en röstberättigad.');
                return;
            }
            $meeting = null;
            $voterIds = array_map('intval', $this->selectedVoters);
        } else {
            $meeting  = $this->meetingId ? \App\Models\Meeting\Meeting::find($this->meetingId) : null;
            $voterIds = [];
        }

        $this->poll->open($meeting, $voterIds);
        AuditLog::record('poll_opened', 'meetings', $this->poll->question, $this->poll);
        $this->showVoterForm = false;
        unset($this->poll);
    }

    public function closePoll(): void
    {
        abort_unless($this->canManage && $this->poll, 403);
        $question = $this->poll->question;
        $this->poll->close();
        AuditLog::record('poll_closed', 'meetings', $question, null);
        unset($this->poll, $this->userBallot);
    }

    public function openManualForm(): void
    {
        abort_unless($this->canManage, 403);
        $this->manualYes      = 0;
        $this->manualNo       = 0;
        $this->manualAbstain  = 0;
        $this->manualNote     = '';
        $this->showManualForm = true;
    }

    public function saveManual(): void
    {
        abort_unless($this->canManage && $this->poll, 403);
        $this->validate([
            'manualYes'     => 'required|integer|min:0',
            'manualNo'      => 'required|integer|min:0',
            'manualAbstain' => 'required|integer|min:0',
            'manualNote'    => 'nullable|string|max:200',
        ]);
        $question = $this->poll->question;
        $this->poll->closeManual($this->manualYes, $this->manualNo, $this->manualAbstain, $this->manualNote ?: null);
        AuditLog::record('poll_closed_manual', 'meetings', $question . " ({$this->manualYes}J/{$this->manualNo}N/{$this->manualAbstain}A)", null);
        $this->showManualForm = false;
        unset($this->poll, $this->userBallot);
    }

    public function deletePoll(): void
    {
        abort_unless($this->canManage && $this->poll && $this->poll->status === 'pending', 403);
        $question = $this->poll->question;
        $this->poll->delete();
        AuditLog::record('poll_deleted', 'meetings', $question, null);
        unset($this->poll);
    }

    // ── Vote ──────────────────────────────────────────────────────────────────

    public function vote(int $optionId): void
    {
        abort_unless($this->isEligible && $this->poll?->isOpen(), 403);
        $this->poll->castVote(Auth::id(), $optionId);
        AuditLog::record('poll_voted', 'meetings', $this->poll->question, $this->poll);
        unset($this->poll, $this->userBallot);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function morphClass(): string
    {
        return match ($this->voteableType) {
            'submission'  => Submission::class,
            'agenda_item' => MeetingAgendaItem::class,
            default       => throw new \InvalidArgumentException("Unknown voteable type: {$this->voteableType}"),
        };
    }
}; ?>

@php
    $poll      = $this->poll;
    $canManage = $this->canManage;
    $eligible  = $this->isEligible;
    $myBallot  = $this->userBallot;

    $resultColors = [
        'approved'  => 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
        'rejected'  => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300',
        'tied'      => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
        'no_quorum' => 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400',
    ];
@endphp

<div class="border-t border-zinc-100 dark:border-zinc-800">

    @if(! $poll && $canManage && ! $showCreateForm)
        {{-- Trigger --}}
        <div class="px-4 py-2">
            <button wire:click="openCreateForm"
                    class="text-xs text-zinc-400 hover:text-complement dark:hover:text-complement-light transition-colors">
                <x-bootstrap-icon name="bar-chart-line" class="inline size-3.5 mr-1" />
                Skapa omröstning
            </button>
        </div>

    @elseif(! $poll && $showCreateForm)
        {{-- Create form --}}
        <div class="bg-zinc-50/70 dark:bg-zinc-800/40 px-4 py-4 space-y-3">
            <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">Ny omröstning</p>
            <div>
                <label class="lbl text-xs">Fråga / motionstext</label>
                <input wire:model="newQuestion" type="text" class="inp mt-1 w-full text-sm" />
                @error('newQuestion') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-3">
                <div class="flex-1">
                    <label class="lbl text-xs">Typ</label>
                    <select wire:model="newType" class="inp mt-1 w-full text-sm">
                        @foreach(\App\Models\Poll::TYPES as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if($voteableType === 'submission')
                <div class="flex-1">
                    <label class="lbl text-xs">Deadline (per capsulam)</label>
                    <input wire:model="newDeadline" type="datetime-local" class="inp mt-1 w-full text-sm" />
                    @error('newDeadline') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                @endif
            </div>
            <div class="flex gap-2">
                <button wire:click="createPoll" class="btn btn-primary btn-sm">Skapa</button>
                <button wire:click="$set('showCreateForm', false)" class="btn btn-ghost btn-sm">Avbryt</button>
            </div>
        </div>

    @elseif($poll)
        {{-- Poll exists --}}
        <div class="bg-zinc-50/70 dark:bg-zinc-800/40 px-4 py-4 space-y-3">

            {{-- Header --}}
            <div class="flex items-start justify-between gap-2">
                <div>
                    <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">Omröstning</p>
                    <p class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $poll->question }}</p>
                    @if($poll->deadline)
                        <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                            Deadline: {{ $poll->deadline->isoFormat('D MMM YYYY, HH:mm') }}
                        </p>
                    @endif
                </div>
                <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium
                    {{ $poll->status === 'open'    ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300' : '' }}
                    {{ $poll->status === 'pending' ? 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400' : '' }}
                    {{ in_array($poll->status, ['closed','manual']) ? ($resultColors[$poll->result] ?? 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500') : '' }}">
                    @if(in_array($poll->status, ['closed','manual']))
                        {{ $poll->resultLabel() ?? $poll->status }}
                    @else
                        {{ \App\Models\Poll::STATUSES[$poll->status] }}
                    @endif
                </span>
            </div>

            @if($poll->isOpen())
                {{-- Progress bar --}}
                @php $pct = $poll->eligible_count > 0 ? round(($poll->votesCast() / $poll->eligible_count) * 100) : 0; @endphp
                <div>
                    <div class="mb-1 flex justify-between text-xs text-zinc-400 dark:text-zinc-500">
                        <span>{{ $poll->votesCast() }} av {{ $poll->eligible_count }} har röstat</span>
                        <span>{{ $pct }}%</span>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                        <div class="h-full bg-complement dark:bg-complement-light transition-all" style="width: {{ $pct }}%"></div>
                    </div>
                </div>

                {{-- Vote buttons (eligible, not yet voted) --}}
                @if($eligible && ! $myBallot)
                    <div class="flex flex-wrap gap-2">
                        @foreach($poll->options as $opt)
                            <button wire:click="vote({{ $opt->id }})"
                                    class="rounded-lg border border-zinc-300 dark:border-zinc-600 px-4 py-1.5 text-sm font-medium
                                           hover:border-complement hover:text-complement dark:hover:border-complement-light dark:hover:text-complement-light transition-colors">
                                {{ $opt->label }}
                            </button>
                        @endforeach
                    </div>
                @elseif($eligible && $myBallot)
                    <p class="text-xs text-zinc-400 dark:text-zinc-500">
                        <x-bootstrap-icon name="check-circle-fill" class="inline size-3.5 text-green-500 mr-1" />
                        Du har röstat: <span class="font-medium text-zinc-600 dark:text-zinc-300">{{ $poll->options->firstWhere('id', $myBallot)?->label }}</span>
                    </p>
                @endif

                {{-- Admin: close actions --}}
                @if($canManage)
                    <div class="flex flex-wrap gap-2 border-t border-zinc-200 dark:border-zinc-700 pt-3">
                        <button wire:click="closePoll"
                                wire:confirm="Stäng omröstningen och beräkna utfall från registrerade röster?"
                                class="btn btn-ghost btn-sm">
                            <x-bootstrap-icon name="lock" class="size-3.5" />
                            Stäng
                        </button>
                        <button wire:click="openManualForm" class="btn btn-ghost btn-sm">
                            <x-bootstrap-icon name="pencil" class="size-3.5" />
                            Ange manuellt
                        </button>
                    </div>
                @endif

            @elseif($poll->status === 'pending' && $canManage)

                @if($showVoterForm && $voteableType === 'submission')
                    {{-- Voter selection form --}}
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-3 space-y-3">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">Välj röstberättigade</p>
                            <div class="flex gap-3">
                                <button wire:click="$set('selectedVoters', {{ json_encode($this->boardCandidates->pluck('user_id')->map(fn($id) => (string)$id)->all()) }})"
                                        class="text-xs text-complement dark:text-complement-light hover:underline">
                                    Välj alla
                                </button>
                                <button wire:click="$set('selectedVoters', [])"
                                        class="text-xs text-zinc-400 hover:underline">
                                    Rensa
                                </button>
                            </div>
                        </div>

                        @if($this->boardCandidates->isEmpty())
                            <p class="text-xs text-zinc-400 dark:text-zinc-500 italic">
                                Inga aktiva styrelseledamöter med användarkonton hittades.
                            </p>
                        @else
                            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($this->boardCandidates as $candidate)
                                    <label class="flex items-center gap-3 py-2 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/40 -mx-3 px-3 transition-colors">
                                        <input type="checkbox"
                                               wire:model="selectedVoters"
                                               value="{{ $candidate->user_id }}"
                                               class="rounded border-zinc-300 dark:border-zinc-600 text-complement focus:ring-complement" />
                                        <span class="flex-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $candidate->name }}</span>
                                        @if($candidate->title)
                                            <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $candidate->title }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        @endif

                        @error('selectedVoters')
                            <p class="text-xs text-red-500">{{ $message }}</p>
                        @enderror

                        <div class="flex gap-2 pt-1">
                            <button wire:click="openPoll" class="btn btn-primary btn-sm">
                                <x-bootstrap-icon name="play-fill" class="size-3.5" />
                                Öppna ({{ count($selectedVoters) }} röstberättigade)
                            </button>
                            <button wire:click="$set('showVoterForm', false)" class="btn btn-ghost btn-sm">Avbryt</button>
                        </div>
                    </div>

                @else
                    {{-- Open / delete buttons --}}
                    <div class="flex gap-2">
                        @if($voteableType === 'submission')
                            <button wire:click="openVoterForm" class="btn btn-primary btn-sm">
                                <x-bootstrap-icon name="play-fill" class="size-3.5" />
                                Öppna omröstning
                            </button>
                        @else
                            <button wire:click="openPoll" class="btn btn-primary btn-sm">
                                <x-bootstrap-icon name="play-fill" class="size-3.5" />
                                Öppna omröstning
                            </button>
                        @endif
                        <button wire:click="openManualForm" class="btn btn-ghost btn-sm">
                            <x-bootstrap-icon name="pencil" class="size-3.5" />
                            Ange manuellt
                        </button>
                        <button wire:click="deletePoll"
                                wire:confirm="Ta bort denna omröstning?"
                                class="btn btn-ghost btn-sm text-red-500 hover:text-red-700">
                            <x-bootstrap-icon name="trash" class="size-3.5" />
                        </button>
                    </div>
                @endif

            @elseif($poll->isClosed())
                {{-- Results --}}
                @php $total = max($poll->votesCast(), 1); @endphp
                <div class="space-y-1.5">
                    @foreach([['label'=>'Ja','count'=>$poll->result_yes,'color'=>'bg-green-500'],['label'=>'Nej','count'=>$poll->result_no,'color'=>'bg-red-500'],['label'=>'Avstår','count'=>$poll->result_abstain,'color'=>'bg-zinc-400']] as $row)
                    @if($row['count'] > 0 || $poll->type !== 'yes_no')
                    <div class="flex items-center gap-2 text-xs">
                        <span class="w-12 shrink-0 text-zinc-600 dark:text-zinc-300">{{ $row['label'] }}</span>
                        <div class="flex-1 h-2 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                            <div class="h-full {{ $row['color'] }} rounded-full"
                                 style="width: {{ round($row['count'] / $total * 100) }}%"></div>
                        </div>
                        <span class="w-6 text-right text-zinc-500 dark:text-zinc-400">{{ $row['count'] }}</span>
                    </div>
                    @endif
                    @endforeach
                </div>
                @if($poll->result_note)
                    <p class="text-xs text-zinc-400 dark:text-zinc-500 italic">{{ $poll->result_note }}</p>
                @endif
                <p class="text-xs text-zinc-400 dark:text-zinc-500">
                    {{ $poll->votesCast() }} av {{ $poll->eligible_count }} röstberättigade röstade
                    @if($poll->status === 'manual') · manuellt registrerat @endif
                </p>
            @endif

            {{-- Manual form (overlaps open/pending states) --}}
            @if($showManualForm)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-3 space-y-3">
                    <p class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">Registrera utfall manuellt</p>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="lbl text-xs">Ja</label>
                            <input wire:model.number="manualYes" type="number" min="0" class="inp mt-1 w-full text-sm" />
                        </div>
                        <div>
                            <label class="lbl text-xs">Nej</label>
                            <input wire:model.number="manualNo" type="number" min="0" class="inp mt-1 w-full text-sm" />
                        </div>
                        <div>
                            <label class="lbl text-xs">Avstår</label>
                            <input wire:model.number="manualAbstain" type="number" min="0" class="inp mt-1 w-full text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="lbl text-xs">Anteckning <span class="font-normal text-zinc-400">(valfri)</span></label>
                        <input wire:model="manualNote" type="text" placeholder="t.ex. acklamation, enhälligt…" class="inp mt-1 w-full text-sm" />
                    </div>
                    <div class="flex gap-2">
                        <button wire:click="saveManual" class="btn btn-primary btn-sm">Spara utfall</button>
                        <button wire:click="$set('showManualForm', false)" class="btn btn-ghost btn-sm">Avbryt</button>
                    </div>
                </div>
            @endif

        </div>
    @endif

</div>
