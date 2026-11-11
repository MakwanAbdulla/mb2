<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int             $id
 * @property int             $poll_id
 * @property int             $voter_id
 * @property int             $option_id
 * @property \Carbon\Carbon  $voted_at
 */
class PollBallot extends Model
{
    public $timestamps = false;

    protected $fillable = ['poll_id', 'voter_id', 'option_id', 'voted_at'];

    protected $casts = ['voted_at' => 'datetime'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function voter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voter_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'option_id');
    }
}
