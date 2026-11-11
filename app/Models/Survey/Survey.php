<?php

namespace App\Models\Survey;

use App\Enums\SurveyStatus;
use App\Models\Form\Form;
use App\Models\Form\FormSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int              $id
 * @property int|null         $form_id
 * @property string           $title
 * @property string|null      $description
 * @property SurveyStatus     $status
 * @property bool             $is_anonymous
 * @property bool             $is_open
 * @property bool             $notify_on_response
 * @property int              $created_by
 * @property string|null      $surveyable_type
 * @property int|null         $surveyable_id
 * @property string|null      $role
 * @property \Carbon\Carbon|null $opens_at
 * @property \Carbon\Carbon|null $closes_at
 * @property \Carbon\Carbon|null $send_invitations_at
 */
class Survey extends Model
{
    public const ROLE_REGISTRATION = 'registration';
    public const ROLE_FOLLOWUP     = 'followup';

    protected $fillable = [
        'form_id',
        'title',
        'description',
        'status',
        'is_anonymous',
        'is_open',
        'notify_on_response',
        'created_by',
        'opens_at',
        'closes_at',
        'send_invitations_at',
        'surveyable_type',
        'surveyable_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'status'              => SurveyStatus::class,
            'is_anonymous'        => 'boolean',
            'is_open'             => 'boolean',
            'notify_on_response'  => 'boolean',
            'opens_at'            => 'datetime',
            'closes_at'           => 'datetime',
            'send_invitations_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** Questions via the linked Form */
    public function questions(): HasManyThrough
    {
        return $this->hasManyThrough(
            \App\Models\Form\FormQuestion::class,
            Form::class,
            'id',       // forms.id
            'form_id',  // form_questions.form_id
            'form_id',  // surveys.form_id
            'id',       // forms.id
        )->orderBy('sort_order');
    }

    /** Submissions for this survey (via polymorphic submittable) */
    public function submissions(): MorphMany
    {
        return $this->morphMany(FormSubmission::class, 'submittable');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(SurveyInvitation::class);
    }

    public function surveyable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function isStandalone(): bool
    {
        return $this->surveyable_id === null;
    }

    public function isRegistration(): bool
    {
        return $this->role === self::ROLE_REGISTRATION;
    }

    public function isFollowup(): bool
    {
        return $this->role === self::ROLE_FOLLOWUP;
    }

    public function isActive(): bool
    {
        return $this->status === SurveyStatus::Active;
    }
}
