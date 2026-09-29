<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * Registers the owner's kit projects. Idempotent: re-running updates nothing it already has.
 */
class ProjectSeeder extends Seeder
{
    /** The projects that used the dev-standards kit when the board was built. */
    private const PROJECTS = ['coins', 'client-dashboard', 'asset-track', 'rent-track'];

    /**
     * Seed the four kit projects under ~/Code.
     */
    public function run(): void
    {
        // $HOME rather than a hardcoded /Users/<name>, so the seeder is not tied to one machine.
        $home = rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME')), '/');

        foreach (self::PROJECTS as $name) {
            Project::firstOrCreate(['name' => $name], ['path' => "{$home}/Code/{$name}"]);
        }
    }
}
