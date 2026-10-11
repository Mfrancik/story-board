<?php

use App\Services\StoryStatusOrder;

/**
 * RD-1: the one copy of the status vocabulary's order and of Progress by
 * initiative's rollup, shared by the stories list and the pick writer.
 */
it('orders counts by the kit vocabulary, then any other status A–Z', function () {
    $ordered = StoryStatusOrder::ordered(['zeta' => 1, 'built' => 2, '(none)' => 3, 'draft' => 4, 'cancelled' => 5, 'approved' => 6]);

    expect(array_keys($ordered))->toBe(['draft', 'approved', 'built', 'cancelled', '(none)', 'zeta']);
});

it('counts draft and approved as open', function () {
    expect(StoryStatusOrder::OPEN)->toBe(['draft', 'approved'])
        ->and(StoryStatusOrder::isOpen('draft'))->toBeTrue()
        ->and(StoryStatusOrder::isOpen('built'))->toBeFalse()
        ->and(StoryStatusOrder::isOpen(null))->toBeFalse();
});

it('rolls (initiative, status) rows up into one row per initiative, most open work first', function () {
    $rows = [
        (object) ['initiative' => 'beta', 'status' => 'built', 'n' => '3', 'parked' => 0],
        (object) ['initiative' => 'alpha', 'status' => 'draft', 'n' => '2', 'parked' => 0],
        (object) ['initiative' => 'alpha', 'status' => 'built', 'n' => 1, 'parked' => 0],
        (object) ['initiative' => null, 'status' => 'approved', 'n' => 2, 'parked' => 0],
        (object) ['initiative' => 'gamma', 'status' => 'approved', 'n' => 2, 'parked' => 1],
        // A null status and a literal "(none)" are two rows with one key: summed, never dropped.
        (object) ['initiative' => 'beta', 'status' => '(none)', 'n' => 1, 'parked' => 0],
        (object) ['initiative' => 'beta', 'status' => '(none)', 'n' => 1, 'parked' => 0],
    ];

    $rollup = StoryStatusOrder::rollup($rows);

    expect(array_column($rollup, 'name'))->toBe(['alpha', 'gamma', null, 'beta'])
        ->and($rollup[0])->toBe(['name' => 'alpha', 'counts' => ['draft' => 2, 'built' => 1], 'total' => 3, 'built' => 1, 'open' => 2, 'parked' => false])
        ->and($rollup[1]['parked'])->toBeTrue()
        ->and($rollup[3]['counts'])->toBe(['built' => 3, '(none)' => 2]);
});
