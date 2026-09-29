<?php

use App\Actions\Board\ListWhatNeedsMe;
use App\Models\Project;
use App\Models\Story;

/**
 * SB-3's three groups, at the data level. The page renders exactly what this returns.
 */
function needs(array $filters = []): array
{
    return app(ListWhatNeedsMe::class)->handle(...$filters);
}

function ids($stories): array
{
    return collect($stories)->pluck('story_id')->all();
}

beforeEach(function () {
    $this->coins = Project::factory()->create(['name' => 'coins']);
    $this->cd = Project::factory()->create(['name' => 'client-dashboard']);
});

it('lists drafts awaiting approval but not the ones in a parked draft group', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'AUC-17', 'status' => 'draft']);
    Story::factory()->for($this->coins)->create(['story_id' => 'IMP-1', 'status' => 'draft', 'initiative' => 'import', 'is_parked' => true]);

    $groups = needs();

    expect(ids($groups['approval']))->toBe(['AUC-17'])
        ->and($groups['parked'])->toBe(1);
});

it('lists stories with mockup options and no chosen option as awaiting a pick', function () {
    $mockups = fn ($chosen) => ['dir' => 'docs/mockups/X', 'options' => ['a', 'b'], 'chosen' => $chosen];
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-65', 'status' => 'approved', 'mockups' => $mockups(null)]);
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-56', 'status' => 'approved', 'mockups' => $mockups('a')]);
    // Built and cancelled stories are history, not a pending decision, even without a parseable pick.
    Story::factory()->for($this->coins)->create(['story_id' => 'OLD-1', 'status' => 'built', 'mockups' => $mockups(null)]);
    Story::factory()->for($this->coins)->create(['story_id' => 'OLD-2', 'status' => 'cancelled', 'mockups' => $mockups(null)]);
    // A mockup directory with no option files has nothing to pick from.
    Story::factory()->for($this->coins)->create(['story_id' => 'DIR-1', 'status' => 'draft', 'mockups' => ['dir' => 'docs/mockups/DIR-1', 'options' => [], 'chosen' => null]]);

    expect(ids(needs()['pick']))->toBe(['MOB-65']);
});

it('lists approved stories across projects as ready to build, oldest first', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'PAY-20', 'status' => 'approved', 'dated_on' => '2026-09-20']);
    Story::factory()->for($this->cd)->create(['story_id' => 'SS-6', 'status' => 'approved', 'dated_on' => '2026-07-02']);
    Story::factory()->for($this->coins)->create(['story_id' => 'LEGAL-1', 'status' => 'approved', 'dated_on' => null]);
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-1', 'status' => 'built']);

    expect(ids(needs()['build']))->toBe(['SS-6', 'PAY-20', 'LEGAL-1']);
});

it('filters every group by project', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'AUC-17', 'status' => 'draft']);
    Story::factory()->for($this->cd)->create(['story_id' => 'SS-15', 'status' => 'draft']);
    Story::factory()->for($this->cd)->create(['story_id' => 'SS-6', 'status' => 'approved']);

    $groups = needs(['project' => 'coins']);

    expect(ids($groups['approval']))->toBe(['AUC-17'])
        ->and($groups['build'])->toBeEmpty();
});

it('filters every group by initiative', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-65', 'status' => 'approved', 'initiative' => 'mobile']);
    Story::factory()->for($this->coins)->create(['story_id' => 'PAY-4', 'status' => 'approved', 'initiative' => 'payments']);

    expect(ids(needs(['initiative' => 'mobile'])['build']))->toBe(['MOB-65']);
});

it('finds a story by exact ID without matching its neighbours', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-65', 'status' => 'approved']);
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-6', 'status' => 'approved']);
    Story::factory()->for($this->coins)->create(['story_id' => 'MOB-650', 'status' => 'draft']);

    $groups = needs(['search' => 'mob-65']);

    expect(ids($groups['build']))->toBe(['MOB-65'])
        ->and($groups['approval'])->toBeEmpty();
});

it('searches titles as well as IDs', function () {
    Story::factory()->for($this->coins)->create(['story_id' => 'LEGAL-1', 'status' => 'approved', 'title' => 'Real Terms of Service']);
    Story::factory()->for($this->coins)->create(['story_id' => 'PAY-4', 'status' => 'approved', 'title' => 'Stripe is bound']);

    expect(ids(needs(['search' => 'terms'])['build']))->toBe(['LEGAL-1']);
});

it('ignores disabled projects', function () {
    $off = Project::factory()->disabled()->create();
    Story::factory()->for($off)->create(['status' => 'draft']);

    expect(needs()['approval'])->toBeEmpty();
});

it('summarises each project with counts by raw status and its parse-error count', function () {
    Story::factory()->for($this->coins)->count(2)->create(['status' => 'built']);
    Story::factory()->for($this->coins)->create(['status' => 'done', 'parse_errors' => ["status 'done' is not in stories/README.md §Status"]]);

    $card = collect(needs()['projects'])->firstWhere('name', 'coins');

    expect($card['counts'])->toBe(['built' => 2, 'done' => 1])
        ->and($card['parse_errors'])->toBe(1);
});
