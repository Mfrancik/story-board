<?php

namespace Database\Factories;

use App\Models\ProdMetric;
use App\Models\ProdSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Daily production snapshots for tests: today's value of a Total users metric.
 *
 * @extends Factory<ProdSnapshot>
 */
class ProdSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prod_metric_id' => ProdMetric::factory(),
            'project_id' => fn (array $attributes) => ProdMetric::whereKey($attributes['prod_metric_id'])->value('project_id'),
            'day' => now()->toDateString(),
            'value' => $this->faker->numberBetween(0, 500),
            'read_at' => now(),
        ];
    }
}
