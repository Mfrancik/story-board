<?php

namespace Database\Factories;

use App\Models\MarketingCampaign;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Marketing campaigns for tests: a weekly Tuesday 19:00 campaign in UTC, on, with
 * a photo per post, unless a test says otherwise.
 *
 * @extends Factory<MarketingCampaign>
 */
class MarketingCampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Campaign '.$this->faker->unique()->numberBetween(1, 999999);

        return [
            'project_id' => Project::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'days' => [2],
            'time' => '19:00:00',
            'timezone' => 'UTC',
            'is_enabled' => true,
            'topics' => ['ideas' => [], 'own' => []],
            'brief' => null,
            'photo_mode' => MarketingCampaign::PHOTO_PER_POST,
        ];
    }

    /**
     * A campaign switched off: listed under Paused, never counted as needing the owner.
     */
    public function paused(): static
    {
        return $this->state(['is_enabled' => false]);
    }

    /**
     * Named, with the slug that name gives.
     */
    public function named(string $name): static
    {
        return $this->state(['name' => $name, 'slug' => Str::slug($name)]);
    }

    /**
     * Posting on the given ISO weekdays at `$time` (HH:MM).
     *
     * @param  list<int>  $days
     */
    public function on(array $days, string $time): static
    {
        return $this->state(['days' => $days, 'time' => $time.':00']);
    }
}
