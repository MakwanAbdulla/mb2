<?php

namespace Database\Factories\Content;

use App\Models\Content\MailingTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailingTemplate>
 */
class MailingTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'                => fake()->words(3, true),
            'description'         => fake()->optional()->sentence(),
            'subject'             => fake()->sentence(),
            'body_html'           => '<p>' . fake()->paragraph() . '</p>',
            'created_by'          => User::factory(),
            'permission_required' => null,
        ];
    }

    public function withPermission(string $permission): static
    {
        return $this->state(['permission_required' => $permission]);
    }
}
