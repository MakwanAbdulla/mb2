<?php

namespace App\Models\Accounting;

use App\Models\Accounting\AccountingDocument;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\FiscalYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class JournalEntry extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'series',
        'number',
        'date',
        'description',
        'source_type',
        'source_id',
        'reverses_journal_entry_id',
        'created_by',
        'is_locked',
        'requires_receipt',
        'edited_at',
        'edit_note',
        'edited_by',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'date'      => 'date',
        'number'    => 'integer',
        'is_locked'        => 'boolean',
        'requires_receipt' => 'boolean',
        'edited_at'   => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** All lines including superseded (for full audit trail). */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Only current (active) lines — use for accounting calculations. */
    public function currentLines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->whereNull('superseded_at');
    }

    /** Only superseded (historical) lines — use for change display. */
    public function supersededLines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->whereNotNull('superseded_at')->orderBy('superseded_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AccountingDocument::class)->latest();
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** The entry this one reverses (storno), if any. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reverses_journal_entry_id');
    }

    /** The entry that reverses this one, if any. */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'reverses_journal_entry_id');
    }

    /**
     * Replace all current lines with new ones, preserving the old ones as
     * superseded. Called when editing an unlocked journal entry.
     *
     * @param  array  $newLines  Array of ['account_id', 'cost_center_id', 'debit', 'credit', 'description']
     * @param  int    $userId    Who is making the edit
     * @param  string $note      Mandatory edit reason
     */
    public function updateLines(array $newLines, int $userId, string $note): void
    {
        DB::transaction(function () use ($newLines, $userId, $note) {
            $now = Carbon::now();

            // Supersede all existing current lines
            $this->currentLines()->update([
                'superseded_at' => $now,
                'superseded_by' => $userId,
            ]);

            // Create the new lines
            foreach ($newLines as $line) {
                $this->lines()->create([
                    'account_id'     => $line['account_id'],
                    'cost_center_id' => $line['cost_center_id'] ?? null,
                    'debit'          => $line['debit'] ?? 0,
                    'credit'         => $line['credit'] ?? 0,
                    'description'    => $line['description'] ?? null,
                ]);
            }

            // Record edit metadata on the entry
            $this->update([
                'edited_at' => $now,
                'edited_by' => $userId,
                'edit_note' => $note,
            ]);
        });
    }

    /**
     * Creates a new journal entry that reverses this one — every current line's
     * debit/credit swapped — and links it via reverses_journal_entry_id.
     *
     * Booked into whichever fiscal year is open for $date (default: today), not
     * backdated into this entry's own period — the point of a reversal is to
     * correct a (possibly already closed/reported) entry without touching it,
     * so this entry itself is never modified.
     *
     * @throws \RuntimeException  When this entry has already been reversed, or
     *                             no open fiscal year covers $date.
     */
    public function reverse(int $userId, string $note, ?Carbon $date = null): JournalEntry
    {
        if ($this->reversedBy()->exists()) {
            throw new \RuntimeException('Verifikationen är redan vänd.');
        }

        $date ??= Carbon::now();

        return DB::transaction(function () use ($date, $userId, $note) {
            $fiscalYear = FiscalYear::where('starts_at', '<=', $date)
                ->where('ends_at', '>=', $date)
                ->where('is_locked', false)
                ->first();

            if (! $fiscalYear) {
                throw new \RuntimeException("Inget öppet räkenskapsår täcker datumet {$date->toDateString()}.");
            }

            $nextNumber = static::where('fiscal_year_id', $fiscalYear->id)
                ->where('series', $this->series)
                ->lockForUpdate()
                ->max('number') + 1;

            $description = 'Vändning av ' . $this->series . $this->number . ($note !== '' ? ': ' . $note : '');
            if (strlen($description) > 500) {
                $description = substr($description, 0, 497) . '…';
            }

            $reversal = static::create([
                'fiscal_year_id'            => $fiscalYear->id,
                'series'                    => $this->series,
                'number'                    => $nextNumber,
                'date'                      => $date,
                'description'               => $description,
                'reverses_journal_entry_id' => $this->id,
                'created_by'                => $userId,
                'is_locked'                 => false,
                'requires_receipt'          => false,
            ]);

            foreach ($this->currentLines as $line) {
                $reversal->lines()->create([
                    'account_id'     => $line->account_id,
                    'cost_center_id' => $line->cost_center_id,
                    'debit'          => $line->credit,
                    'credit'         => $line->debit,
                    'description'    => $line->description,
                ]);
            }

            return $reversal;
        });
    }

    /** Returns true when current debits == current credits (balanced entry). */
    public function isBalanced(): bool
    {
        $lines  = $this->currentLines;
        $debit  = $lines->sum('debit');
        $credit = $lines->sum('credit');
        return $debit === $credit && $debit > 0;
    }

    public function hasBeenEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    public function hasReviewNote(): bool
    {
        return ! empty($this->review_note);
    }

    public function scopeUnlocked($query)
    {
        return $query->where('is_locked', false);
    }

    public function scopeForSeries($query, string $series)
    {
        return $query->where('series', $series);
    }
}
