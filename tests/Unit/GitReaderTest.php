<?php

use App\Exceptions\GitReaderException;
use App\Services\GitReader;

it('refuses any git subcommand that could write to a project', function (string $subcommand) {
    (new GitReader)->run('/tmp', [$subcommand]);
})->with(['checkout', 'pull', 'commit', 'reset', 'clean', 'stash', 'merge', 'push', 'add', 'rm', 'config', '-c'])
    ->throws(GitReaderException::class, 'not allowed');
