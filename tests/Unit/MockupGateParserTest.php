<?php

use App\Services\MockupGateParser;

/**
 * RD-1: the one reader of a story's `## Design mockup gate`, pure — no git, no
 * database — so the gallery, the viewer and the writer share one rule.
 */
function rdGate(string $chosen): string
{
    return "# AB-1 — Story\n\n## Design mockup gate\n- Mockups: docs/mockups/AB-1/\n- Chosen option: {$chosen}\n- Why I chose it: _pending_\n\n## Links\n";
}

it('finds a pick written in bold, plain or in backticks as its lower-case letter', function (string $chosen) {
    expect((new MockupGateParser)->pickedLetter(rdGate($chosen)))->toBe('b');
})->with(['**B**', 'B', 'b', '`b`', '**b**']);

it('finds no pick in a placeholder, a hybrid or a missing gate', function (string $markdown) {
    expect((new MockupGateParser)->pickedLetter($markdown))->toBeNull();
})->with([
    'placeholder' => rdGate('_(filled AFTER the owner picks; no build before this)_'),
    'pending' => rdGate('_pending_'),
    'hybrid' => rdGate('D (revised hybrid)'),
    'no gate' => "# AB-1\n\n## Story\nNo gate.\n",
]);

it('says why a gate cannot take a pick, or null when it can', function (string $markdown, ?string $why) {
    expect((new MockupGateParser)->refusalFor($markdown))->toBe($why);
})->with([
    'placeholder' => [rdGate('_(filled AFTER the owner picks; no build before this)_'), null],
    'tbd' => [rdGate('TBD'), null],
    'bold pick' => [rdGate('**B**'), 'this story already has a pick: B'],
    'no section' => ["# AB-1\n\n## Story\nx\n", 'this story has no Design mockup gate section'],
    'non-visual' => ["## Design mockup gate\nn/a — non-visual\n", 'this story is marked non-visual (n/a) in its mockup gate'],
    'no chosen line' => ["## Design mockup gate\n- Mockups: x\n", 'this story has no “Chosen option:” line in its mockup gate'],
]);

it('returns the gate section body and its byte offset', function () {
    $markdown = rdGate('a');
    [$body, $offset] = (new MockupGateParser)->section($markdown);

    expect(substr($markdown, $offset, strlen($body)))->toBe($body)
        ->and($body)->toStartWith('- Mockups:')
        ->and($body)->not->toContain('## Links');
});

it('names the board branch: the ref without a known remote, else the ref itself', function (string $ref, array $remotes, string $branch) {
    expect((new MockupGateParser)->boardBranch($ref, $remotes))->toBe($branch);
})->with([
    'remote ref' => ['origin/main', ['origin'], 'main'],
    'nested branch' => ['origin/feature/x', ['origin', 'upstream'], 'feature/x'],
    'local branch with a slash' => ['feature/x', ['origin'], 'feature/x'],
    'local branch' => ['main', ['origin'], 'main'],
    'no remotes' => ['origin/main', [], 'origin/main'],
]);
