<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Builds a throwaway project the way a real registered project looks: a bare
 * "origin", an author clone that pushes to it, and the registered clone the
 * board reads from — which only ever learns about new commits through fetch.
 */
class GitFixture
{
    /** Root directory holding every repo of this fixture. */
    public readonly string $root;

    /** The bare repository that plays the role of the GitHub remote. */
    public readonly string $origin;

    /** The clone the tests commit and push from (the "other machine"). */
    public readonly string $author;

    /** The clone registered on the board, as ~/Code/<project> would be. */
    public readonly string $project;

    /**
     * Create the three repos and push an initial commit carrying the kit's
     * stories/README.md, so story-index has a §Status vocabulary to check against.
     */
    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/story-board-fixtures/'.bin2hex(random_bytes(6));
        $this->origin = $this->root.'/origin.git';
        $this->author = $this->root.'/author';
        $this->project = $this->root.'/project';

        File::ensureDirectoryExists($this->root);
        $this->git($this->root, 'init', '--quiet', '--bare', '-b', 'main', $this->origin);
        $this->git($this->root, 'clone', '--quiet', $this->origin, $this->author);
        $this->git($this->author, 'checkout', '--quiet', '-b', 'main');

        $this->write('stories/README.md', "# Stories\n\n## Status\n\n- `draft`\n- `approved`\n- `built`\n");
        $this->commitAndPush('chore: kit');

        $this->git($this->root, 'clone', '--quiet', $this->origin, $this->project);
    }

    /**
     * Write a story file in the author clone (not committed yet).
     */
    public function story(string $id, string $status, string $initiative = 'demo', string $body = ''): self
    {
        $text = "# {$id} — Story {$id}\nStatus: {$status}          Journey: none\n"
            ."Source: fixture\n\n## Story\nAs a tester.\n{$body}\n## Links\nJourney: none · Depends on: none\n";

        return $this->write("stories/{$initiative}/{$id}-story.md", $text);
    }

    /**
     * Write any file in the author clone (not committed yet).
     */
    public function write(string $path, string $contents): self
    {
        File::ensureDirectoryExists(dirname($this->author.'/'.$path));
        File::put($this->author.'/'.$path, $contents);

        return $this;
    }

    /**
     * Commit everything in the author clone and push it to origin.
     */
    public function commitAndPush(string $message = 'test: fixture'): self
    {
        $this->git($this->author, 'add', '-A');
        $this->git($this->author, 'commit', '--quiet', '-m', $message);
        $this->git($this->author, 'push', '--quiet', 'origin', 'HEAD:main');

        return $this;
    }

    /**
     * Pull origin into the registered clone, so its working tree matches main.
     */
    public function syncProject(): self
    {
        $this->git($this->project, 'pull', '--quiet', 'origin', 'main');

        return $this;
    }

    /**
     * Commit the author clone's changes on a new branch and push it to origin,
     * leaving the author back on main. The branch is unmerged until merged.
     */
    public function pushBranch(string $branch, string $message = 'docs: branch work'): self
    {
        $this->git($this->author, 'checkout', '--quiet', '-b', $branch);
        $this->git($this->author, 'add', '-A');
        $this->git($this->author, 'commit', '--quiet', '-m', $message);
        $this->git($this->author, 'push', '--quiet', 'origin', $branch);
        $this->git($this->author, 'checkout', '--quiet', 'main');

        return $this;
    }

    /**
     * Merge `$branch` into main in the author clone and push main.
     */
    public function mergeBranch(string $branch): self
    {
        $this->git($this->author, 'merge', '--quiet', '--no-ff', '-m', "merge {$branch}", $branch);
        $this->git($this->author, 'push', '--quiet', 'origin', 'main');

        return $this;
    }

    /**
     * Add a worktree of the registered clone on a new local branch, the way the
     * owner's `coins-*` checkouts are made. Returns the worktree's path.
     */
    public function addWorktree(string $name, string $branch): string
    {
        $path = $this->root.'/'.$name;
        // The clone only knows the origin/main it was cloned at; branch from the current one.
        $this->git($this->project, 'fetch', '--quiet');
        $this->git($this->project, 'worktree', 'add', '--quiet', '-b', $branch, $path, 'origin/main');

        return $path;
    }

    /**
     * Write a file into any checkout without staging it (an untracked file).
     */
    public function untracked(string $checkout, string $path, string $contents): self
    {
        File::ensureDirectoryExists(dirname($checkout.'/'.$path));
        File::put($checkout.'/'.$path, $contents);

        return $this;
    }

    /**
     * The commit SHA that origin's main points at right now.
     */
    public function originSha(): string
    {
        return trim($this->git($this->origin, 'rev-parse', 'main'));
    }

    /**
     * Run git in `$dir` and return stdout; a failure fails the test loudly.
     */
    public function git(string $dir, string ...$args): string
    {
        $process = new Process(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', ...$args], $dir);
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * Remove every repo this fixture created.
     */
    public function destroy(): void
    {
        File::deleteDirectory($this->root);
    }
}
