<?php

namespace App\Actions\Board;

use App\Exceptions\GitReaderException;
use App\Models\Project;
use App\Services\GitReader;
use Illuminate\Support\Facades\Log;

/**
 * A project's handbook (SB-14): its rules, lessons, standards, runbook, decisions
 * and skills, read from git at the project's snapshot SHA, with each standards
 * file and kit skill badged against the dev-standards kit. Nothing is stored;
 * every section is read when its tab is first opened. Markdown goes through
 * RenderStory's safe renderer, since these files are written in other repos.
 *
 * Every method reading the project throws GitReaderException when git fails for
 * a reason other than a missing file (a bad SHA, a vanished checkout); a missing
 * file is null, which the page shows as a designed empty state.
 */
class ReadHandbook
{
    public const RULES = 'CLAUDE.md';

    public const LESSONS = 'docs/LESSONS.md';

    public const RUNBOOK = 'docs/RUNBOOK.md';

    public const STANDARDS = 'docs/standards/';

    public const DECISIONS = 'docs/decisions/';

    public const SKILLS = '.claude/skills/';

    /** Badge: the project's file is byte-for-byte the kit's (the same blob). */
    public const SAME = 'same';

    /** Badge: both have the file and the bytes differ. */
    public const CHANGED = 'changed';

    /** Badge: the kit has it, the project does not. */
    public const MISSING = 'missing';

    /** Badge: the project has it, the kit does not. */
    public const PROJECT_ONLY = 'project';

    /**
     * @param  GitReader  $git  the only way the board touches a repository
     * @param  RenderStory  $markdown  the board's one safe markdown renderer
     */
    public function __construct(private GitReader $git, private RenderStory $markdown) {}

    /**
     * Whether a decision file name may be handed to git. Checked before any git
     * call: `..` could climb out of docs/decisions/, a leading `-` could be read
     * as an option, and a `/` would reach files the Decisions list never shows.
     */
    public static function isSafeDecisionName(string $name): bool
    {
        return $name !== ''
            && ! str_contains($name, '..')
            && ! str_starts_with($name, '-')
            && ! str_contains($name, '/')
            && ! str_contains($name, '\\')
            && str_ends_with($name, '.md');
    }

    /**
     * A file rendered to safe HTML, or null when the project has no such file.
     */
    public function page(Project $project, string $file): ?string
    {
        $markdown = $this->read($project->path, (string) $project->sha, $file);

        return $markdown === null ? null : $this->markdown->toHtml($markdown);
    }

    /**
     * The project's lessons, newest first, or null when it has no LESSONS.md.
     *
     * @return list<array{number: int, date: string|null, name: string, scope: string|null, html: string}>|null
     */
    public function lessons(Project $project): ?array
    {
        $markdown = $this->read($project->path, (string) $project->sha, self::LESSONS);

        return $markdown === null ? null : $this->parseLessons($markdown);
    }

    /**
     * Split a LESSONS.md into its `## L-<n>` entries, newest (highest number) first.
     * Headings inside HTML comments and code fences are not entries: the kit's
     * ledger carries its entry format as a commented-out `## L-<n>` template, and
     * counting it is what made the story's first real counts one too high.
     *
     * @return list<array{number: int, date: string|null, name: string, scope: string|null, html: string}>
     */
    public function parseLessons(string $markdown): array
    {
        $text = (string) preg_replace('/<!--.*?-->/s', '', $markdown);

        $entries = [];
        $current = null;
        $fence = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if ($fence === null && preg_match('/^\s*(```|~~~)/', $line, $m)) {
                $fence = $m[1];
            } elseif ($fence !== null && str_starts_with(ltrim($line), $fence)) {
                $fence = null;
                if ($current !== null) {
                    $current['body'][] = $line;
                }

                continue;
            }

            // Outside a fence, any level-1 or level-2 heading ends the entry before it.
            $heading = $fence === null && preg_match('/^#{1,2}\s/', $line);
            if ($heading) {
                if ($current !== null) {
                    $entries[] = $current;
                    $current = null;
                }
                if (preg_match('/^## L-(\d+)\b\s*[—–-]*\s*(.*)$/u', $line, $m)) {
                    $current = ['number' => (int) $m[1], 'rest' => trim($m[2]), 'body' => []];
                }

                continue;
            }

            if ($current !== null) {
                $current['body'][] = $line;
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }

        $lessons = array_map(function (array $entry) {
            $body = trim(implode("\n", $entry['body']));
            // `<date> — <name>`; a heading without a date keeps its whole text as the name.
            [$date, $name] = preg_match('/^(\d{4}-\d{2}-\d{2})\s*[—–-]+\s*(.+)$/u', $entry['rest'], $m)
                ? [$m[1], trim($m[2])]
                : [null, $entry['rest']];

            return [
                'number' => $entry['number'],
                'date' => $date,
                'name' => $name,
                'scope' => preg_match('/^Scope:\s*(.+)$/mi', $body, $s) ? trim($s[1]) : null,
                'html' => $this->markdown->toHtml($body),
            ];
        }, $entries);

        usort($lessons, fn ($a, $b) => $b['number'] <=> $a['number']);

        return $lessons;
    }

    /**
     * Every standards file in the project or the kit, each with its badge (null
     * when there is no kit to compare with) and both texts rendered. `present`
     * says whether the project has any standards file at all.
     *
     * @param  array{path: string, sha: string}|null  $kit
     * @return array{present: bool, kit: bool, files: list<array{file: string, badge: string|null, html: string|null, kit_html: string|null}>}
     */
    public function standards(Project $project, ?array $kit): array
    {
        $mine = $this->directFiles($project->path, (string) $project->sha, self::STANDARDS);
        // The kit's texts are read in one guarded step, so a kit failing halfway hides every badge, not some.
        $kitTexts = $this->guardKit($kit, fn (array $k) => array_map(
            fn (string $file) => $this->git->show($k['path'], $k['sha'], $file),
            $this->directFiles($k['path'], $k['sha'], self::STANDARDS),
        ));

        $names = array_values(array_unique([...array_keys($mine), ...array_keys($kitTexts ?? [])]));
        sort($names);

        $files = [];
        foreach ($names as $name) {
            $bytes = isset($mine[$name]) ? $this->git->show($project->path, (string) $project->sha, $mine[$name]) : null;
            $kitBytes = $kitTexts[$name] ?? null;

            $files[] = [
                'file' => $name,
                'badge' => $kitTexts === null ? null : $this->badge($bytes, $kitBytes),
                'html' => $bytes === null ? null : $this->markdown->toHtml($bytes),
                'kit_html' => $kitBytes === null ? null : $this->markdown->toHtml($kitBytes),
            ];
        }

        return ['present' => $mine !== [], 'kit' => $kitTexts !== null, 'files' => $files];
    }

    /**
     * The file names directly in docs/decisions/, newest name first, or null when
     * the project has no such folder.
     *
     * @return list<string>|null
     */
    public function decisions(Project $project): ?array
    {
        $names = array_keys($this->directFiles($project->path, (string) $project->sha, self::DECISIONS));
        rsort($names);

        return $names === [] ? null : $names;
    }

    /**
     * One decision file rendered, or null when there is no such file. The caller
     * has already checked the name with isSafeDecisionName().
     */
    public function decision(Project $project, string $name): ?string
    {
        return $this->page($project, self::DECISIONS.$name);
    }

    /**
     * Every skill folder in the project or the kit: whether it comes from the kit
     * (`kit`), is the project's own (`project`), or cannot be told without a kit
     * (null), and its SKILL.md's badge.
     *
     * @param  array{path: string, sha: string}|null  $kit
     * @return array{kit: bool, skills: list<array{name: string, from: string|null, badge: string|null}>}
     */
    public function skills(Project $project, ?array $kit): array
    {
        $mine = $this->skillFolders($project->path, (string) $project->sha);
        // Each kit skill's SKILL.md text (null when the folder has none), read in one guarded step.
        $kitTexts = $this->guardKit($kit, function (array $k) {
            $texts = [];
            foreach ($this->skillFolders($k['path'], $k['sha']) as $name => $has) {
                $texts[$name] = $has ? $this->git->show($k['path'], $k['sha'], self::SKILLS.$name.'/SKILL.md') : null;
            }

            return $texts;
        });

        $names = array_values(array_unique(array_map('strval', [...array_keys($mine), ...array_keys($kitTexts ?? [])])));
        sort($names);

        $skills = [];
        foreach ($names as $name) {
            $skills[] = match (true) {
                $kitTexts === null => ['name' => $name, 'from' => null, 'badge' => null],
                ! array_key_exists($name, $kitTexts) => ['name' => $name, 'from' => 'project', 'badge' => self::PROJECT_ONLY],
                ! isset($mine[$name]) => ['name' => $name, 'from' => 'kit', 'badge' => self::MISSING],
                default => ['name' => $name, 'from' => 'kit', 'badge' => $this->skillBadge($project, $name, $mine[$name], $kitTexts[$name])],
            };
        }

        return ['kit' => $kitTexts !== null, 'skills' => $skills];
    }

    /**
     * A kit skill the project also has: the same when its SKILL.md is the kit's
     * bytes. A folder in both whose SKILL.md is gone on one side has still been
     * changed, not removed.
     */
    private function skillBadge(Project $project, string $name, bool $hasSkillFile, ?string $kitText): string
    {
        $text = $hasSkillFile ? $this->git->show($project->path, (string) $project->sha, self::SKILLS.$name.'/SKILL.md') : null;

        return $text !== null && $text === $kitText ? self::SAME : self::CHANGED;
    }

    /**
     * The badge for one file. Equal bytes are the same blob (git names a blob by
     * a hash of its bytes), so comparing the two texts is comparing the blobs
     * without a GitReader method that lists blob SHAs outside stories/.
     */
    private function badge(?string $mine, ?string $kit): string
    {
        return match (true) {
            $mine === null => self::MISSING,
            $kit === null => self::PROJECT_ONLY,
            $mine === $kit => self::SAME,
            default => self::CHANGED,
        };
    }

    /**
     * Run one read against the kit. A kit that fails mid-read (moved, deleted,
     * its ref gone since the page loaded) is logged as unreachable and the
     * section carries on without badges, rather than failing the whole section.
     *
     * @template T
     *
     * @param  array{path: string, sha: string}|null  $kit
     * @param  callable(array{path: string, sha: string}): T  $read
     * @return T|null null when there is no kit or it failed
     */
    private function guardKit(?array $kit, callable $read): mixed
    {
        if ($kit === null) {
            return null;
        }

        try {
            return $read($kit);
        } catch (GitReaderException $e) {
            Log::warning('board.kit_unreachable', ['path' => $kit['path'], 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The raw bytes of `$file`, or null when it is not in the tree at `$sha`.
     * Listed first so a missing file (an empty state) is told apart from a
     * failing repository (an error), which `show` alone reports the same way.
     */
    private function read(string $path, string $sha, string $file): ?string
    {
        if (! in_array($file, $this->git->listFiles($path, $sha, $file), true)) {
            return null;
        }

        return $this->git->show($path, $sha, $file);
    }

    /**
     * The `.md` files directly inside `$dir` (not in its subfolders), as base
     * name => path.
     *
     * @return array<string, string>
     */
    private function directFiles(string $path, string $sha, string $dir): array
    {
        $files = [];
        foreach ($this->git->listFiles($path, $sha, $dir) as $file) {
            $name = substr($file, strlen($dir));
            if (! str_contains($name, '/') && str_ends_with($name, '.md')) {
                $files[$name] = $file;
            }
        }

        return $files;
    }

    /**
     * The skill folders under `.claude/skills/`, as name => whether it has a SKILL.md.
     * A folder named like a number comes back as an int key (PHP array keys), so
     * callers cast names back to strings.
     *
     * @return array<int|string, bool>
     */
    private function skillFolders(string $path, string $sha): array
    {
        $folders = [];
        foreach ($this->git->listFiles($path, $sha, self::SKILLS) as $file) {
            $rest = substr($file, strlen(self::SKILLS));
            if (! str_contains($rest, '/')) {
                continue;
            }
            $name = strstr($rest, '/', true);
            $folders[$name] = ($folders[$name] ?? false) || $rest === $name.'/SKILL.md';
        }

        return $folders;
    }
}
