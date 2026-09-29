<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Story;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story snapshot rows shaped like bin/story-index records.
 *
 * @extends Factory<Story>
 */
class StoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = 'FX-'.$this->faker->unique()->numberBetween(1, 99999);

        return [
            'project_id' => Project::factory(),
            'story_id' => $id,
            'title' => $this->faker->sentence(4),
            'status' => 'draft',
            'initiative' => 'demo',
            'journey' => 'none',
            'path' => "stories/demo/{$id}-story.md",
            'source' => 'factory',
            'depends_on' => [],
            'mockups' => ['dir' => null, 'options' => [], 'chosen' => null],
            'parse_errors' => [],
            'sha' => str_repeat('a', 40),
        ];
    }
}
