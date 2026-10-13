<?php

namespace Database\Factories;

use App\Models\MarketingPost;
use App\Models\MarketingPostNetwork;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A post's Facebook row for tests: posted, with a link, on a new posted post.
 *
 * @extends Factory<MarketingPostNetwork>
 */
class MarketingPostNetworkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => MarketingPost::factory()->posted(),
            'network' => MarketingPostNetwork::FACEBOOK,
            'status' => MarketingPostNetwork::STATUS_POSTED,
            'url' => 'https://www.facebook.com/StackerMint/posts/'.$this->faker->numerify('##########'),
        ];
    }
}
