<?php

namespace Database\Factories;

use App\Models\ProdMetric;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Production metrics for tests: the Total users preset unless a state says otherwise.
 *
 * @extends Factory<ProdMetric>
 */
class ProdMetricFactory extends Factory
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
            'key' => 'total_users',
            'kind' => ProdMetric::KIND_PRESET,
            'label' => 'Total users',
            'config' => ['table' => 'users'],
            'sql' => null,
            'position' => 0,
            'is_enabled' => true,
        ];
    }

    /**
     * A custom single-SELECT metric.
     */
    public function custom(string $sql = 'SELECT count(*) FROM users'): static
    {
        return $this->state(fn () => [
            'key' => 'custom-'.$this->faker->unique()->lexify('??????'),
            'kind' => ProdMetric::KIND_CUSTOM,
            'label' => 'Custom',
            'config' => null,
            'sql' => $sql,
        ]);
    }
}
