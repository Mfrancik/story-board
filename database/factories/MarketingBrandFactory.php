<?php

namespace Database\Factories;

use App\Models\MarketingBrand;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Marketing brands for tests: the neutral defaults Settings shows, no logo, no rules.
 *
 * @extends Factory<MarketingBrand>
 */
class MarketingBrandFactory extends Factory
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
            'values' => ['name' => 'Brand'] + MarketingBrand::DEFAULTS,
            'logo_svg' => null,
            'logo_source' => null,
            'voice' => null,
            'copy_rules' => ['filler' => [], 'banned' => []],
            'idea_menu' => [],
        ];
    }
}
