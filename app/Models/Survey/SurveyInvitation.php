<?php

namespace App\Models\Survey;

use App\Models\Form\FormSubmission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int              $id
 * @property int              $survey_id
 * @property string           $email
 * @property string           $token
 * @property \Carbon\Carbon|null $sent_at
 * @property \Carbon\Carbon|null $responded_at
 */
class SurveyInvitation extends Model
{
    protected $fillable = [
        'survey_id',
        'email',
        'token',
        'sent_at',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at'      => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function submission(): HasOne
    {
        return $this->hasOne(FormSubmission::class, 'invitation_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function hasResponded(): bool
    {
        return $this->responded_at !== null;
    }
}
