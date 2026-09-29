<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Registered projects for tests; the path points nowhere unless a test sets one.
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->slug(2),
            'path' => '/nonexistent/'.$this->faker->unique()->slug(2),
            'ref' => 'origin/main',
            'is_enabled' => true,
            'state' => Project::STATE_PENDING,
        ];
    }

    /**
     * A project taken out of refreshes and off the board.
     */
    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }
}
