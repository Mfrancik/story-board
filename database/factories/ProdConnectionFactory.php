<?php

namespace Database\Factories;

use App\Models\ProdConnection;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Production connections for tests. The default points at a closed local port,
 * so a test that forgets to use ProductionFixture fails fast as "unreachable"
 * and never reaches a real server.
 *
 * @extends Factory<ProdConnection>
 */
class ProdConnectionFactory extends Factory
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
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'coins',
            'username' => 'board_ro',
            'password' => 'not-a-real-password',
            'use_ssl' => false,
            'verified_at' => now(),
        ];
    }
}
