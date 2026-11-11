<?php

namespace App\Models\Form;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $submission_id
 * @property int         $question_id
 * @property int|null    $option_id
 * @property string|null $answer_text
 */
class FormAnswer extends Model
{
    protected $fillable = [
        'submission_id',
        'question_id',
        'option_id',
        'answer_text',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'submission_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(FormQuestion::class, 'question_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(FormQuestionOption::class, 'option_id');
    }
}
