<?php

namespace App\Concerns;

use App\Models\Poll;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPolls
{
    public function polls(): MorphMany
    {
        return $this->morphMany(Poll::class, 'voteable');
    }

    public function activePoll(): ?Poll
    {
        return $this->polls()->where('status', 'open')->latest()->first();
    }
}
