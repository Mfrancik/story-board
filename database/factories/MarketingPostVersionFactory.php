<?php

namespace Database\Factories;

use App\Models\MarketingPost;
use App\Models\MarketingPostVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Earlier post versions for tests: version 1 of a new draft, with no feedback, unless a test says otherwise.
 *
 * @extends Factory<MarketingPostVersion>
 */
class MarketingPostVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => MarketingPost::factory(),
            'version' => 1,
            'caption' => $this->faker->sentence(),
            'asset_id' => null,
            'feedback' => null,
            'chips' => null,
            'generation_meta' => null,
        ];
    }
}
