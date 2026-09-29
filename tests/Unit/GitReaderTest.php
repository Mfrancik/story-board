<?php

use App\Exceptions\GitReaderException;
use App\Services\GitReader;

it('refuses any git subcommand that could write to a project', function (string $subcommand) {
    (new GitReader)->run('/tmp', [$subcommand]);
})->with(['checkout', 'pull', 'commit', 'reset', 'clean', 'stash', 'merge', 'push', 'add', 'rm', 'config', '-c'])
    ->throws(GitReaderException::class, 'not allowed');

it('refuses argument forms of allowed subcommands that write', function (array $args) {
    (new GitReader)->run('/tmp', $args);
})->with([
    'remote add' => [['remote', 'add', 'evil', '/tmp/x']],
    'remote set-url' => [['remote', 'set-url', 'origin', '/tmp/x']],
    'show --output' => [['show', '--output=/tmp/x', 'HEAD']],
    'show -o' => [['show', '-o/tmp/x', 'HEAD']],
])->throws(GitReaderException::class, 'not allowed');

it('refuses a ref git would read as an option or a range', function (string $ref) {
    (new GitReader)->show('/tmp', $ref, 'stories/README.md');
})->with(['--output=/tmp/x', '-p', 'main..evil', 'main with space', ''])
    ->throws(GitReaderException::class, 'invalid ref');

it('accepts ordinary refs', function (string $ref) {
    expect(GitReader::isValidRef($ref))->toBeTrue();
})->with(['origin/main', 'main', 'docs/MOB-56-pick', '5e48518e7bc665f5aff02360b259a4597bca423b', 'release-1.2']);
