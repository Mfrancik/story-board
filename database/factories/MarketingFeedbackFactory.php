<?php

namespace Database\Factories;

use App\Models\MarketingFeedback;
use App\Models\MarketingPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The owner's feedback for tests: a written note on a new post, unless a state makes it a like or a dislike.
 *
 * @extends Factory<MarketingFeedback>
 */
class MarketingFeedbackFactory extends Factory
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
            'project_id' => fn (array $attributes) => MarketingPost::whereKey($attributes['post_id'])->firstOrFail()->project_id,
            'campaign_id' => fn (array $attributes) => MarketingPost::whereKey($attributes['post_id'])->firstOrFail()->campaign_id,
            'vote' => null,
            'reasons' => null,
            'note' => $this->faker->sentence(),
        ];
    }

    /**
     * About `$post`, in its project and campaign.
     */
    public function on(MarketingPost $post): static
    {
        return $this->state(['post_id' => $post->id, 'project_id' => $post->project_id, 'campaign_id' => $post->campaign_id]);
    }

    /**
     * A like, no note.
     */
    public function liked(): static
    {
        return $this->state(['vote' => MarketingFeedback::LIKED, 'note' => null]);
    }

    /**
     * A dislike with `$reasons`, no note.
     *
     * @param  list<string>  $reasons
     */
    public function disliked(array $reasons = ['Too salesy']): static
    {
        return $this->state(['vote' => MarketingFeedback::DISLIKED, 'reasons' => $reasons, 'note' => null]);
    }
}
