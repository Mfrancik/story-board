<?php

use App\Models\Project;
use Tests\Support\GitFixture;

it('runs a mockup\'s scripts in an opaque origin that cannot reach the board', function () {
    $fixture = new GitFixture;
    $fixture->story('FX-1', 'approved')
        ->write('docs/mockups/FX-1/option-a.html', '<html><body><p id="out">not run</p><script>
            let cookies; try { cookies = document.cookie; } catch (e) { cookies = "blocked"; }
            document.getElementById("out").textContent = "origin=" + window.origin + " cookies=" + cookies;
        </script></body></html>')
        ->commitAndPush();
    Project::factory()->create(['name' => 'fx', 'path' => $fixture->project, 'indexed_at' => now()]);
    $this->artisan('board:refresh')->assertSuccessful();

    visit('/p/fx/m/FX-1/option-a.html')
        ->assertSeeIn('#out', 'origin=null')
        ->assertSeeIn('#out', 'cookies=blocked');

    $fixture->destroy();
});
