<?php

use App\Models\Project;
use App\Models\Story;

it('shows each registered project with its story count', function () {
    $project = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'state' => Project::STATE_OK]);
    Story::factory()->count(4)->for($project)->create();

    visit('/')
        ->assertSee('coins')
        ->assertSeeIn('[data-project="coins"] [data-story-count]', '4')
        ->assertNoJavaScriptErrors();
});
