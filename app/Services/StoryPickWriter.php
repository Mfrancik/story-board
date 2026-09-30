<?php

namespace App\Services;

use App\Exceptions\GitReaderException;
use App\Exceptions\StoryPickRefusedException;
use App\Models\Project;
use App\Models\Story;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The only code in the board that writes to a registered project (SB-21,
 * ADR-032): it records a mockup pick by rewriting a story file's
 * `Chosen option:` and `Why I chose it:` lines and committing that one file on
 * the checkout's current branch. It never pushes, never stages anything else,
 * and refuses — writing nothing — whenever the checkout is not in a state
 * where a one-file commit is plainly safe. Every read goes through GitReader.
 */
class StoryPickWriter
{
    /** Statuses whose mockups are still picked; a built or cancelled story's unpicked set is history (as ListWhatNeedsMe). */
    public const OPEN_STATUSES = ['draft', 'approved'];

    /** The longest reason written; the rest is cut so the gate line stays one readable line. */
    public const REASON_MAX = 200;

    /**
     * Same environment as GitReader: never wait on a prompt. Commit cannot run
     * through GitReader (its allow-list is reads only), so this is the one other
     * place that spawns git, and only `commit`.
     */
    private const ENV = [
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes -o ConnectTimeout=10',
    ];

    /** Files git leaves in the git dir while an operation is half done, and what to call it. */
    private const IN_PROGRESS = [
        'rebase-merge' => 'a rebase', 'rebase-apply' => 'a rebase', 'MERGE_HEAD' => 'a merge',
        'CHERRY_PICK_HEAD' => 'a cherry-pick', 'REVERT_HEAD' => 'a revert', 'BISECT_LOG' => 'a bisect',
    ];

    /** A gate line: `- Chosen option: …` / `- **Why I chose it:** …`, bullet and optional bold kept as written. */
    private const LINE = '/^(?<prefix>[ \t]*[-*][ \t]+(?:\*\*)?%s:(?:\*\*)?[ \t]*)(?<value>.*)$/mi';

    /**
     * @param  GitReader  $git  every read of the checkout (branch, status, git dir)
     */
    public function __construct(private readonly GitReader $git) {}

    /**
     * Record `$option` as `$story`'s pick with the owner's `$reason`, and commit
     * the story file alone as `docs(<ID>): record mockup pick <x>`.
     *
     * Side effects: rewrites two lines of one file in the project's checkout and
     * makes one commit on its current branch (never pushed); logs
     * `board.mockup_picked`, or `board.mockup_pick_refused` (warning) and throws.
     *
     * @param  list<string>  $options  the set's option letters; `$option` must be one of them
     * @return string the new commit's SHA
     *
     * @throws StoryPickRefusedException with a one-line reason; nothing was written.
     */
    public function pick(Project $project, Story $story, array $options, string $option, string $reason): string
    {
        $id = (string) $story->story_id;
        $refuse = function (string $why) use ($project, $id): StoryPickRefusedException {
            Log::warning('board.mockup_pick_refused', ['project' => $project->name, 'story' => $id, 'reason' => $why]);

            return new StoryPickRefusedException($why);
        };

        if (! $project->is_enabled) {
            throw $refuse('the project is not on the board');
        }
        if (! in_array($option, $options, true)) {
            throw $refuse("there is no option {$option} for {$id}");
        }
        // The ref already records a letter: the checkout may simply be behind, and a second pick would diverge from it.
        if (($letter = $story->mockups['chosen'] ?? null) !== null) {
            throw $refuse("this story already has a pick: {$letter}");
        }
        if (! in_array($story->status, self::OPEN_STATUSES, true)) {
            throw $refuse("this story is {$story->status}; only draft or approved stories take a pick");
        }
        $file = $this->storyPath($project, $story) ?? throw $refuse('the story file is not in the checkout');

        try {
            $this->assertCheckoutReady($project);
            // Porcelain lists the file when it is modified, staged or untracked: any of them is the owner's unsaved work.
            $dirty = trim($this->git->run($project->path, ['status', '--porcelain', '--', $story->path], config: ['core.fsmonitor=false']));
        } catch (StoryPickRefusedException $e) {
            throw $refuse($e->getMessage());
        } catch (GitReaderException $e) {
            throw $refuse('git could not read the checkout: '.$e->getMessage());
        }
        if ($dirty !== '') {
            throw $refuse('this story has unsaved changes in the checkout');
        }

        $original = File::get($file);
        $why = $this->refusalFor($original);
        if ($why !== null) {
            throw $refuse($why);
        }

        File::put($file, $this->rewrite($original, $option, $this->reasonLine($reason)));
        try {
            $this->commit($project->path, $story->path, "docs({$id}): record mockup pick {$option}");
            $sha = trim($this->git->run($project->path, ['rev-parse', 'HEAD']));
        } catch (GitReaderException $e) {
            // The file was rewritten but not committed: put the owner's bytes back so the refusal writes nothing.
            File::put($file, $original);
            throw $refuse('git could not commit the pick: '.$e->getMessage());
        }

        Log::info('board.mockup_picked', ['project' => $project->name, 'story' => $id, 'option' => $option, 'commit' => $sha]);

        return $sha;
    }

    /**
     * Why a story's text cannot take a pick from the board, or null when it can:
     * it has a `## Design mockup gate` section with a `Chosen option:` line that
     * still holds the template's placeholder. Pure, so the gallery decides
     * "awaiting pick" by the very rule the writer enforces.
     */
    public function refusalFor(string $markdown): ?string
    {
        $section = $this->section($markdown);
        if ($section === null) {
            return 'this story has no Design mockup gate section';
        }
        if (preg_match('/^\s*n\/a\b/i', $section[0])) {
            return 'this story is marked non-visual (n/a) in its mockup gate';
        }
        if (! preg_match(sprintf(self::LINE, 'Chosen option'), $section[0], $m)) {
            return 'this story has no “Chosen option:” line in its mockup gate';
        }
        if (! $this->isPlaceholder($m['value'])) {
            return 'this story already has a pick: '.Str::limit($this->plain($m['value']), 60);
        }

        return null;
    }

    /**
     * The option letter a story's gate records as a single letter — what this
     * writer leaves behind — or null. Used for the checkout's copy of a story,
     * which the kit parser has not indexed.
     */
    public function pickedLetter(string $markdown): ?string
    {
        $section = $this->section($markdown);
        if ($section === null || ! preg_match(sprintf(self::LINE, 'Chosen option'), $section[0], $m)) {
            return null;
        }

        return preg_match('/^[a-z]$/i', $this->plain($m['value'])) ? strtolower($this->plain($m['value'])) : null;
    }

    /**
     * The branch the board reads for `$project`: its ref without the remote
     * (`origin/main` → `main`), or the ref itself when it names a local branch.
     *
     * @throws GitReaderException when the checkout cannot be read.
     */
    public function boardBranch(Project $project): string
    {
        $remotes = preg_split('/\s+/', trim($this->git->run($project->path, ['remote']))) ?: [];
        [$first, $rest] = array_pad(explode('/', $project->ref, 2), 2, null);

        return $rest !== null && in_array($first, $remotes, true) ? $rest : $project->ref;
    }

    /**
     * The reason as the line will read: `owner pick <date> (board): <reason>`,
     * one line, whitespace collapsed, cut to REASON_MAX characters.
     */
    public function reasonLine(string $reason): string
    {
        $reason = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $reason)), 0, self::REASON_MAX);
        $stamp = 'owner pick '.now()->toDateString().' (board)';

        return $reason === '' ? "{$stamp}, no reason given." : "{$stamp}: {$reason}";
    }

    /**
     * The absolute path of the story file in the checkout, or null when it is not
     * a plain file inside it. The path comes from the snapshot, never from input,
     * but it is still held to `stories/**.md` and resolved before anything is written.
     */
    private function storyPath(Project $project, Story $story): ?string
    {
        if (! preg_match('#^stories/(?:[A-Za-z0-9_][A-Za-z0-9._-]*/)*[A-Za-z0-9_][A-Za-z0-9._-]*\.md$#D', $story->path)) {
            return null;
        }
        $file = $project->path.'/'.$story->path;
        $real = realpath($file);
        $root = realpath($project->path);

        // A symlink could point the write anywhere; only a real file under the checkout is written.
        return $real !== false && $root !== false && ! is_link($file) && is_file($real) && str_starts_with($real, $root.'/') ? $real : null;
    }

    /**
     * Refuse while git is part-way through another operation, or when the
     * checkout is on a different branch from the one the board reads.
     *
     * @throws StoryPickRefusedException
     * @throws GitReaderException
     */
    private function assertCheckoutReady(Project $project): void
    {
        $gitDir = trim($this->git->run($project->path, ['rev-parse', '--absolute-git-dir']));
        foreach (self::IN_PROGRESS as $marker => $what) {
            if (file_exists("{$gitDir}/{$marker}")) {
                throw new StoryPickRefusedException("{$what} is in progress in the checkout; finish it, then pick again");
            }
        }

        $current = trim($this->git->run($project->path, ['rev-parse', '--abbrev-ref', 'HEAD']));
        $board = $this->boardBranch($project);
        if ($current !== $board) {
            $current = $current === 'HEAD' ? 'a detached HEAD' : $current;
            throw new StoryPickRefusedException("the checkout is on {$current}, but the board reads {$board} ({$project->ref})");
        }
    }

    /**
     * Commit `$path` alone. `--only` commits that path's working-tree content and
     * leaves every other staged change staged and out of the commit.
     * `--no-verify`: the board runs git, never a project's own hooks (the same
     * rule as GitReader's fsmonitor setting).
     *
     * @throws GitReaderException when git refuses or times out.
     */
    private function commit(string $checkout, string $path, string $message): void
    {
        try {
            $result = Process::env(self::ENV)->timeout(30)->run([
                'git', '-C', $checkout, '-c', 'core.fsmonitor=false', 'commit', '--quiet', '--no-verify', '-m', $message, '--only', '--', $path,
            ]);
        } catch (ProcessTimedOutException $e) {
            throw new GitReaderException('git commit timed out after 30s', previous: $e);
        }

        if (! $result->successful()) {
            throw new GitReaderException(strtok(trim($result->errorOutput()), "\n") ?: 'git commit failed');
        }
    }

    /**
     * `$markdown` with the gate's Chosen line set to `$option` and its Why line
     * set to `$why` (added under Chosen when missing). Continuation lines of the
     * old values go with them; every other byte is kept as it was.
     */
    private function rewrite(string $markdown, string $option, string $why): string
    {
        [$original, $offset] = $this->section($markdown) ?? throw new StoryPickRefusedException('this story has no Design mockup gate section');
        $section = $original;

        $chosen = sprintf(self::LINE, 'Chosen option');
        $section = (string) preg_replace_callback($this->withContinuation($chosen), fn ($m) => $m['prefix'].$option, $section, 1);

        $whyLine = sprintf(self::LINE, 'Why I chose it');
        if (preg_match($whyLine, $section)) {
            $section = (string) preg_replace_callback($this->withContinuation($whyLine), fn ($m) => $m['prefix'].$why, $section, 1);
        } else {
            // No Why line to fill: add one directly under Chosen, with its indentation and bullet.
            $section = (string) preg_replace_callback($this->withContinuation($chosen), function ($m) use ($why) {
                // LINE's prefix always starts with a bullet, so this matches; `- ` is only a fallback.
                $bullet = preg_match('/^[ \t]*[-*][ \t]+/', $m['prefix'], $b) ? $b[0] : '- ';

                return $m[0]."\n".$bullet.'Why I chose it: '.$why;
            }, $section, 1);
        }

        return substr($markdown, 0, $offset).$section.substr($markdown, $offset + strlen($original));
    }

    /**
     * A LINE pattern that also swallows the value's indented continuation lines.
     */
    private function withContinuation(string $pattern): string
    {
        return str_replace('(?<value>.*)$/mi', '(?<value>.*(?:\n[ \t]+\S.*)*)$/mi', $pattern);
    }

    /**
     * The gate section's body and its byte offset in `$markdown`, or null.
     *
     * @return array{0: string, 1: int}|null
     */
    private function section(string $markdown): ?array
    {
        if (! preg_match('/^##\s+Design mockup gate[^\n]*\n(.*?)(?=^##\s|\z)/ms', $markdown, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [$m[1][0], $m[1][1]];
    }

    /**
     * Whether a Chosen value is the template's "not picked yet": empty,
     * `_pending_`, a dash, TBD, or the template's `_(filled AFTER …)_` hint.
     */
    private function isPlaceholder(string $value): bool
    {
        $plain = $this->plain($value);

        return in_array(strtolower($plain), ['', 'pending', '-', '—', '–', 'tbd'], true)
            || (str_starts_with($plain, '(') && str_ends_with($plain, ')'));
    }

    /**
     * A gate value without emphasis markers, backticks or surrounding space.
     */
    private function plain(string $value): string
    {
        return trim(str_replace(['**', '`'], '', $value), " \t*_");
    }
}
