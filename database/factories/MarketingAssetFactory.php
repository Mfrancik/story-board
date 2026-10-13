<?php

namespace Database\Factories;

use App\Models\MarketingAsset;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Marketing assets for tests: a favourite JPEG photo row. Tests that serve it put the bytes at `path` themselves.
 *
 * @extends Factory<MarketingAsset>
 */
class MarketingAssetFactory extends Factory
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
            'purpose' => MarketingAsset::PURPOSE_PHOTO,
            'is_favourite' => true,
            'path' => 'marketing/test/photos/'.$this->faker->uuid().'.jpg',
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'width' => 8,
            'height' => 8,
            'ai_notes' => null,
        ];
    }

    /**
     * An inspiration image (MK-18), read by the AI: `$notes` override the default read notes.
     *
     * @param  array<string, mixed>  $notes
     */
    public function inspiration(array $notes = []): static
    {
        return $this->state(fn () => [
            'purpose' => MarketingAsset::PURPOSE_INSPIRATION,
            'is_favourite' => false,
            'path' => 'marketing/test/inspirations/'.$this->faker->uuid().'.jpg',
            'original_name' => 'ad.jpg',
            'ai_notes' => [
                'status' => MarketingAsset::READ_DONE, 'source' => 'upload',
                'headline' => 'A headline', 'style' => ['short headline'], 'topic' => 'coins', ...$notes,
            ],
        ]);
    }
}
