<?php

namespace App\Models;

use App\Models\Meeting\Meeting;
use App\Models\Organization\OrganizationalRoleMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int         $id
 * @property string      $voteable_type
 * @property int         $voteable_id
 * @property string      $question
 * @property string      $type
 * @property string      $status
 * @property int         $eligible_count
 * @property string|null $result
 * @property int         $result_yes
 * @property int         $result_no
 * @property int         $result_abstain
 * @property string|null $result_note
 * @property \Carbon\Carbon|null $deadline
 * @property \Carbon\Carbon|null $opened_at
 * @property \Carbon\Carbon|null $closed_at
 * @property int         $created_by
 */
class Poll extends Model
{
    protected $fillable = [
        'voteable_type', 'voteable_id', 'question', 'type', 'status',
        'eligible_count', 'result', 'result_yes', 'result_no', 'result_abstain',
        'result_note', 'deadline', 'opened_at', 'closed_at', 'created_by',
    ];

    protected $casts = [
        'deadline'   => 'datetime',
        'opened_at'  => 'datetime',
        'closed_at'  => 'datetime',
    ];

    public const TYPES = [
        'yes_no_abstain' => 'Ja / Nej / Avstår',
        'yes_no'         => 'Ja / Nej',
        'free'           => 'Fri omröstning',
    ];

    public const STATUSES = [
        'pending' => 'Förberedd',
        'open'    => 'Öppen',
        'closed'  => 'Stängd',
        'manual'  => 'Manuellt registrerad',
    ];

    public const RESULTS = [
        'approved'  => 'Bifallen',
        'rejected'  => 'Avslagen',
        'tied'      => 'Lika röstetal',
        'no_quorum' => 'Beslutsoförmögen',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function voteable(): MorphTo
    {
        return $this->morphTo();
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('sort_order');
    }

    public function ballots(): HasMany
    {
        return $this->hasMany(PollBallot::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return \in_array($this->status, ['closed', 'manual']);
    }

    /** Number of actual votes cast (option_id set). */
    public function votesCast(): int
    {
        if ($this->isClosed()) {
            return $this->result_yes + $this->result_no + $this->result_abstain;
        }
        return $this->ballots()->whereNotNull('option_id')->count();
    }

    public function hasVoted(int $userId): bool
    {
        return $this->ballots()
            ->where('voter_id', $userId)
            ->whereNotNull('option_id')
            ->exists();
    }

    public function resultLabel(): ?string
    {
        return $this->result ? (self::RESULTS[$this->result] ?? $this->result) : null;
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Open the poll and seed options + pre-populate ballots for eligible voters.
     * For meeting polls pass the meeting; for per capsulam pass explicit userIds.
     *
     * @param array<int> $voterIds  User IDs for per capsulam polls (ignored for meeting polls).
     */
    public function open(?Meeting $meeting = null, array $voterIds = []): void
    {
        if ($meeting) {
            $eligible = $meeting->votingList()->count();
            // Meeting polls do not pre-populate ballots — eligibility checked via MeetingAttendee
        } else {
            if (empty($voterIds)) {
                $voterIds = self::eligibleBoardCandidates()->pluck('user_id')->all();
            }
            $this->seedBallots($voterIds);
            $eligible = count($voterIds);
        }

        if ($this->options()->count() === 0) {
            $this->seedDefaultOptions();
        }

        $this->update([
            'status'         => 'open',
            'eligible_count' => $eligible,
            'opened_at'      => now(),
        ]);
    }

    /**
     * Close the poll and compute the result from recorded ballots.
     */
    public function close(): void
    {
        $yes     = $this->ballots()->whereHas('option', fn ($q) => $q->where('label', 'Ja'))->count();
        $no      = $this->ballots()->whereHas('option', fn ($q) => $q->where('label', 'Nej'))->count();
        $abstain = $this->ballots()->whereHas('option', fn ($q) => $q->where('label', 'Avstår'))->count();

        $this->update([
            'status'         => 'closed',
            'result_yes'     => $yes,
            'result_no'      => $no,
            'result_abstain' => $abstain,
            'result'         => $this->computeResult($yes, $no),
            'closed_at'      => now(),
        ]);
    }

    /**
     * Record result manually (oral vote, acclamation, etc.).
     */
    public function closeManual(int $yes, int $no, int $abstain, ?string $note = null): void
    {
        $this->update([
            'status'         => 'manual',
            'result_yes'     => $yes,
            'result_no'      => $no,
            'result_abstain' => $abstain,
            'result_note'    => $note,
            'result'         => $this->computeResult($yes, $no),
            'closed_at'      => now(),
        ]);
    }

    /**
     * Cast a vote. Updates the pre-populated ballot row for this voter.
     * Per capsulam polls auto-close when the result is mathematically determined.
     */
    public function castVote(int $userId, int $optionId): void
    {
        $this->ballots()->updateOrCreate(
            ['voter_id' => $userId],
            ['option_id' => $optionId, 'voted_at' => now()],
        );

        if ($this->deadline && $this->isResultDetermined()) {
            $this->close();
        }
    }

    public function isResultDetermined(): bool
    {
        $yes       = $this->ballots()->whereHas('option', fn ($q) => $q->where('label', 'Ja'))->count();
        $no        = $this->ballots()->whereHas('option', fn ($q) => $q->where('label', 'Nej'))->count();
        $remaining = $this->ballots()->whereNull('option_id')->count();

        return $yes > $no + $remaining
            || $no  > $yes + $remaining;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function seedBallots(array $userIds): void
    {
        $existing = $this->ballots()->pluck('voter_id')->all();
        foreach ($userIds as $userId) {
            if (! \in_array($userId, $existing)) {
                $this->ballots()->create(['voter_id' => $userId]);
            }
        }
    }

    private function seedDefaultOptions(): void
    {
        $options = match ($this->type) {
            'yes_no'         => ['Ja', 'Nej'],
            'yes_no_abstain' => ['Ja', 'Nej', 'Avstår'],
            default          => [],
        };

        foreach ($options as $i => $label) {
            $this->options()->create(['label' => $label, 'sort_order' => $i]);
        }
    }

    private function computeResult(int $yes, int $no): string
    {
        if ($yes > $no) {
            return 'approved';
        }
        if ($no > $yes) {
            return 'rejected';
        }
        return 'tied';
    }

    /**
     * Returns active styrelse members with user accounts, for voter selection.
     *
     * @return \Illuminate\Support\Collection<int, object{user_id: int, name: string, title: string|null}>
     */
    public static function eligibleBoardCandidates(): \Illuminate\Support\Collection
    {
        return OrganizationalRoleMember::with(['member.user', 'period.organizationalRole'])
            ->where('transition_role', OrganizationalRoleMember::TRANSITION_CURRENT)
            ->whereHas('period', fn ($q) =>
                $q->where('status', 'active')
                  ->whereHas('organizationalRole', fn ($q) => $q->where('slug', 'styrelsen'))
            )
            ->whereHas('member', fn ($q) => $q->whereNotNull('user_id'))
            ->get()
            ->map(fn ($rm) => (object) [
                'user_id' => $rm->member->user_id,
                'name'    => $rm->member->user->name ?? $rm->member->full_name,
                'title'   => $rm->title,
            ])
            ->sortBy('name')
            ->values();
    }
}
