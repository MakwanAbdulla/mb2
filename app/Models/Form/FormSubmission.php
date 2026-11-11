<?php

namespace App\Models\Form;

use App\Models\Survey\SurveyInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int         $id
 * @property int         $form_id
 * @property string|null $submittable_type
 * @property int|null    $submittable_id
 * @property int|null    $invitation_id
 * @property int|null    $user_id
 * @property string|null $ip_address
 */
class FormSubmission extends Model
{
    protected $fillable = [
        'form_id',
        'submittable_type',
        'submittable_id',
        'invitation_id',
        'user_id',
        'ip_address',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** The thing that prompted this submission — Survey, EventRegistration, etc. */
    public function submittable(): MorphTo
    {
        return $this->morphTo();
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(SurveyInvitation::class, 'invitation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class, 'submission_id');
    }
}
