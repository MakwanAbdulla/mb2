<?php

namespace Database\Factories\Wiki;

use App\Models\User;
use App\Models\Wiki\WikiPage;
use App\Models\Wiki\WikiSpace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WikiPage>
 */
class WikiPageFactory extends Factory
{
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->words(3, true));
        $user = User::factory();

        return [
            'wiki_space_id' => WikiSpace::factory(),
            'parent_id' => null,
            'title' => $title,
            'slug' => Str::slug($title),
            'content' => '<div>'.fake()->paragraph().'</div>',
            'created_by' => $user,
            'updated_by' => $user,
            'sort_order' => 0,
        ];
    }

    /** A child page in the same space as its parent. */
    public function childOf(WikiPage $parent): static
    {
        return $this->state([
            'wiki_space_id' => $parent->wiki_space_id,
            'parent_id' => $parent->id,
        ]);
    }
}
