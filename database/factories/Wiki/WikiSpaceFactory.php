<?php

namespace Database\Factories\Wiki;

use App\Enums\WikiReadAccess;
use App\Models\Organization\Section;
use App\Models\Wiki\WikiSpace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Association-wide by default; use forGroup() for a group's space.
 *
 * @extends Factory<WikiSpace>
 */
class WikiSpaceFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'group_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(4),
            'description' => fake()->optional()->sentence(),
            'read_access' => WikiReadAccess::Members,
        ];
    }

    public function shared(): static
    {
        return $this->state(['group_id' => null]);
    }

    /**
     * A group's space. Every group gets an empty space when it is created
     * (SectionObserver); this one replaces it, so tests can set it up freely.
     */
    // TODO(#168): take a Group once sections become groups.
    public function forGroup(?Section $group = null): static
    {
        return $this->state(function () use ($group) {
            $group ??= Section::factory()->create();
            WikiSpace::where('group_id', $group->id)->whereDoesntHave('pages', fn ($q) => $q->withTrashed())->delete();

            return ['group_id' => $group->id];
        });
    }

    public function readableBy(WikiReadAccess $access): static
    {
        return $this->state(['read_access' => $access]);
    }
}
