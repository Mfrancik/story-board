<?php

namespace Database\Factories;

use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Marketing posts for tests: an AI draft for tomorrow, in no campaign, unless a
 * state says otherwise.
 *
 * @extends Factory<MarketingPost>
 */
class MarketingPostFactory extends Factory
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
            'campaign_id' => null,
            'scheduled_for' => now()->addDay(),
            'status' => MarketingPost::STATUS_DRAFT,
            'caption' => $this->faker->sentence(),
            'asset_id' => null,
            'source' => 'ai',
            'generation_meta' => null,
            'posted_at' => null,
        ];
    }

    /**
     * In `$campaign` (and its project), scheduled for `$at` read in the campaign's timezone.
     */
    public function forCampaign(MarketingCampaign $campaign, ?string $at = null): static
    {
        return $this->state(fn () => [
            'project_id' => $campaign->project_id,
            'campaign_id' => $campaign->id,
            ...($at === null ? [] : ['scheduled_for' => Carbon::parse($at, $campaign->timezone)->utc()]),
        ]);
    }

    /**
     * Picked and confirmed by the owner.
     */
    public function approved(): static
    {
        return $this->state(['status' => MarketingPost::STATUS_APPROVED]);
    }

    /**
     * Marked as posted at its scheduled time.
     */
    public function posted(): static
    {
        return $this->state(fn (array $attributes) => ['status' => MarketingPost::STATUS_POSTED, 'posted_at' => $attributes['scheduled_for'] ?? now()]);
    }

    /**
     * A date the owner chose not to post on.
     */
    public function skipped(): static
    {
        return $this->state(['status' => MarketingPost::STATUS_SKIPPED]);
    }

    /**
     * A candidate the owner threw away.
     */
    public function discarded(): static
    {
        return $this->state(['status' => MarketingPost::STATUS_DISCARDED]);
    }
}
