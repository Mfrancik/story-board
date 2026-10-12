<?php

use App\Models\MarketingAsset;
use App\Models\MarketingBrand;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitFixture;

/**
 * MK-2 acceptance criteria for `marketing:seed-kit {project} {kitPath}`: StackerMint's brand, copy rules, idea menu
 * and five starter campaigns from a kit folder, idempotent. Uses the trimmed fixture kit under
 * tests/Fixtures/marketing-kit (3 tiny PNGs + a captions file); the real kit is never read by tests.
 */
beforeEach(function () {
    Queue::fake();
    $this->kit = base_path('tests/Fixtures/marketing-kit');
    $this->coins = Project::factory()->create(['name' => 'coins', 'indexed_at' => now(), 'refresh_attempted_at' => now(), 'state' => Project::STATE_OK]);
});

it('seeds exactly 5 campaigns and one brand when run twice, and turns no kit file into a post', function () {
    Log::spy();
    $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => $this->kit])->assertSuccessful();
    $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => $this->kit])->assertSuccessful();

    expect(MarketingCampaign::count())->toBe(5)
        ->and(MarketingBrand::count())->toBe(1)
        ->and(MarketingPost::count())->toBe(0)
        ->and(MarketingAsset::count())->toBe(0);
    Log::shouldHaveReceived('info')->with('marketing.kit_seed.succeeded', Mockery::on(fn ($c) => $c['project'] === 'coins' && $c['campaigns_created'] === 5))->once();
    Log::shouldHaveReceived('info')->with('marketing.kit_seed.succeeded', Mockery::on(fn ($c) => $c['campaigns_created'] === 0))->once();
});

it('seeds the StackerMint brand, copy rules and idea menu from the kit', function () {
    $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => $this->kit])->assertSuccessful();

    $brand = MarketingBrand::sole();
    expect($brand->values)->toMatchArray([
        'name' => 'StackerMint', 'accent' => '#B79F82', 'ink' => '#222222', 'surface' => '#FAFAFA',
        'font' => 'Instrument Sans', 'radius' => 5, 'shadow' => 'halo',
    ])
        ->and($brand->voice)->toContain('Short and human')
        ->and($brand->copy_rules['filler'])->toContain('tell us below')->toContain('we read every one')
        ->and($brand->copy_rules['banned'])->toContain('cert lookup')
        // In the kit's order: MySQL would re-sort a category-keyed JSON object.
        ->and(array_column($brand->idea_menu, 'category'))->toBe(['Sets', 'Toned & TrueView', 'Show & tell', 'Did you know', 'Ask the community'])
        ->and($brand->idea_menu[0]['ideas'][1])->toEqual(['title' => 'Slot filled', 'text' => 'Another slot filled. [coin] joins the [set] set. [x] of [y] done.'])
        ->and($brand->idea_menu[2]['ideas'][0])->toEqual(['title' => 'Coin of the week', 'text' => 'Coin of the week: [coin], [grade]. What do you see first, the strike or the color?']);
});

it('seeds the prototype\'s starter campaigns with Did you know off and topics from the idea menu', function () {
    $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => $this->kit])->assertSuccessful();

    $c = MarketingCampaign::orderBy('id')->get()->keyBy('slug');
    expect($c->keys()->all())->toBe(['coin-of-the-week', 'trueview-tuesday', 'set-spotlight', 'ask-the-community', 'did-you-know'])
        ->and($c['coin-of-the-week']->scheduleLabel())->toBe('Tue 10:00 weekly')
        ->and($c['trueview-tuesday']->scheduleLabel())->toBe('Tue 19:00 weekly')
        ->and($c['set-spotlight']->scheduleLabel())->toBe('Fri 18:00 weekly')
        ->and($c['ask-the-community']->scheduleLabel())->toBe('Sun 12:00 weekly')
        ->and($c['did-you-know']->is_enabled)->toBeFalse()
        ->and($c['trueview-tuesday']->is_enabled)->toBeTrue()
        ->and($c['trueview-tuesday']->photo_mode)->toBe(MarketingCampaign::PHOTO_PER_POST)
        // Only ideas the kit's menu actually has: the trimmed fixture lacks "Album toning" and "Rate the rainbow".
        ->and($c['trueview-tuesday']->topics['ideas'])->toBe(['Toned & TrueView|TrueView Tuesday', 'Toned & TrueView|Toning 101'])
        ->and($c['did-you-know']->topics['ideas'])->toContain('Did you know|What "BN, RB, RD" means');
});

it('leaves an existing campaign of the same slug and an edited brand as they are', function () {
    $mine = MarketingCampaign::factory()->for($this->coins)->named('TrueView Tuesday')->on([3], '08:00')->create();
    MarketingBrand::factory()->for($this->coins)->create(['values' => ['name' => 'Mine'] + MarketingBrand::DEFAULTS]);

    $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => $this->kit])->assertSuccessful();

    expect(MarketingCampaign::count())->toBe(5)
        ->and($mine->fresh()->scheduleLabel())->toBe('Wed 08:00 weekly')
        ->and(MarketingBrand::sole()->values['name'])->toBe('Mine')
        // The idea menu is the kit's, not the owner's: it is always read from the kit.
        ->and(MarketingBrand::sole()->idea_menu[0]['category'])->toBe('Sets');
});

it('reads the logo from the project repo when asked', function () {
    $repo = new GitFixture;
    $repo->write('resources/views/components/app-mark.blade.php', '<svg viewBox="0 0 23 23"><path d="M1 1h2"/></svg>')->commitAndPush();
    $repo->git($repo->project, 'fetch', '--quiet');
    $this->coins->update(['path' => $repo->project]);

    $this->artisan('marketing:seed-kit', [
        'project' => 'coins', 'kitPath' => $this->kit,
        '--logo-path' => 'resources/views/components/app-mark.blade.php',
    ])->assertSuccessful();

    $brand = MarketingBrand::sole();
    expect($brand->logo_svg)->toContain('xmlns="http://www.w3.org/2000/svg"')
        ->and($brand->logo_source)->toContain('resources/views/components/app-mark.blade.php @ origin/main');
    $repo->destroy();
});

it('fails with a clear message and logs marketing.kit_seed.failed for a missing kit path', function () {
    Log::spy();
    $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => '/nowhere/kit'])
        ->expectsOutputToContain('No marketing kit at /nowhere/kit')
        ->assertFailed();

    expect(MarketingBrand::count())->toBe(0)->and(MarketingCampaign::count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('marketing.kit_seed.failed', Mockery::on(fn ($c) => $c['project'] === 'coins' && $c['reason'] === 'missing_kit'));
});

it('fails for a kit with no captions file and for an unknown project, and logs why', function () {
    $empty = sys_get_temp_dir().'/mk2-empty-kit-'.bin2hex(random_bytes(4));
    mkdir($empty);

    Log::spy();
    $this->artisan('marketing:seed-kit', ['project' => 'coins', 'kitPath' => $empty])
        ->expectsOutputToContain('captions-and-bios.txt')->assertFailed();
    $this->artisan('marketing:seed-kit', ['project' => 'nope', 'kitPath' => $this->kit])
        ->expectsOutputToContain('No project named "nope"')->assertFailed();
    rmdir($empty);

    expect(MarketingCampaign::count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('marketing.kit_seed.failed', Mockery::on(fn ($c) => $c['reason'] === 'missing_captions'));
    Log::shouldHaveReceived('warning')->with('marketing.kit_seed.failed', Mockery::on(fn ($c) => $c['reason'] === 'unknown_project'));
});
