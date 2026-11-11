<?php

namespace App\Concerns;

use App\Models\Reaction;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

trait HasReactions
{
    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }

    /**
     * Returns reactions grouped by emoji: ['👍' => ['count' => 3, 'mine' => true], ...]
     *
     * @return Collection<string, array{count: int, mine: bool}>
     */
    public function reactionSummary(): Collection
    {
        $userId = Auth::id();

        $reactions = $this->relationLoaded('reactions')
            ? $this->getRelation('reactions')
            : $this->reactions()->get();

        return $reactions
            ->groupBy('emoji')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'mine'  => $userId && $group->contains('user_id', $userId),
            ]);
    }

    public function toggleReaction(string $emoji): void
    {
        if (! in_array($emoji, Reaction::allCurated(), true)) {
            return;
        }

        $userId = Auth::id();

        /** @var Reaction|null $existing */
        $existing = $this->reactions()
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            $this->reactions()->create(['user_id' => $userId, 'emoji' => $emoji]);
        }

        $this->unsetRelation('reactions');
    }
}
