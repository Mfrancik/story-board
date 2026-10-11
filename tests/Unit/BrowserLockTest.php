<?php

/**
 * L-7: bin/browser-lock.sh, the one machine-wide lock every browser-test run goes through. Each test points the
 * script at its own lock file, so this never waits on (or blocks) a real browser run.
 */
beforeEach(function () {
    $this->lock = sys_get_temp_dir().'/browser-lock-test-'.getmypid().'-'.uniqid().'.lock';
    $this->run = function (string $command, array $env = []): array {
        $env = ['BROWSER_LOCK_FILE' => $this->lock, 'PATH' => getenv('PATH'), 'HOME' => getenv('HOME'), ...$env];
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

        return [proc_close($proc), $out];
    };
});

afterEach(function () {
    @unlink($this->lock);
});

it('runs the command with BROWSER_LOCK_HELD=1, which the Browser suite requires', function () {
    [$code, $out] = ($this->run)('bin/browser-lock.sh printenv BROWSER_LOCK_HELD');

    expect($code)->toBe(0)->and(trim($out))->toBe('1');
});

it('runs a nested call straight through instead of waiting on its own lock', function () {
    // Without the re-entry the inner lockf would wait forever on the outer one; a 10 s alarm turns that into a failure.
    [$code, $out] = ($this->run)('perl -e \'alarm 10; exec @ARGV\' bin/browser-lock.sh bin/browser-lock.sh echo nested-ok');

    expect($code)->toBe(0)->and($out)->toContain('nested-ok');
});

it('makes a second run wait while the first holds the lock', function () {
    $start = microtime(true);
    // The first holder sleeps 2 s; the second starts 0.5 s later and can only finish after the first releases.
    [$code] = ($this->run)('bin/browser-lock.sh sleep 2 & sleep 0.5; bin/browser-lock.sh true; wait');

    expect($code)->toBe(0)->and(microtime(true) - $start)->toBeGreaterThan(1.9);
});

it('refuses to run without a command', function () {
    [$code, $out] = ($this->run)('bin/browser-lock.sh');

    expect($code)->toBe(2)->and($out)->toContain('usage');
});
