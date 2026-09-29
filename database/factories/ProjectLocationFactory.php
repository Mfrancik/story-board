<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Extra checkouts of a project for tests.
 *
 * @extends Factory<ProjectLocation>
 */
class ProjectLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'kind' => ProjectLocation::KIND_ALIAS,
            'path' => '/nonexistent/'.$this->faker->unique()->slug(2),
            'branch' => null,
        ];
    }
}
