<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * The one reader of a story's `## Design mockup gate` section: where it is, which
 * option letter it records, whether the board may write a pick into it, and which
 * branch a board pick lands on. Pure — it never reads git or the database — so the
 * gallery, the story page and the pick writer decide by the very same rule.
 */
class MockupGateParser
{
    /**
     * A gate line: `- Chosen option: …` / `- **Why I chose it:** …`, bullet and optional
     * bold kept as written. `%s` is the field name; `prefix` and `value` are named groups.
     */
    public const LINE = '/^(?<prefix>[ \t]*[-*][ \t]+(?:\*\*)?%s:(?:\*\*)?[ \t]*)(?<value>.*)$/mi';

    /**
     * The gate section's body and its byte offset in `$markdown`, or null when the
     * story has no `## Design mockup gate…` heading. The body runs to the next `## `.
     *
     * @return array{0: string, 1: int}|null
     */
    public function section(string $markdown): ?array
    {
        if (! preg_match('/^##\s+Design mockup gate[^\n]*\n(.*?)(?=^##\s|\z)/ms', $markdown, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [$m[1][0], $m[1][1]];
    }

    /**
     * Whether the gate says the story is non-visual: its body opens with `n/a`
     * (the /story template's `n/a — non-visual`).
     */
    public function isNonVisual(string $section): bool
    {
        return preg_match('/^\s*n\/a\b/i', $section) === 1;
    }

    /**
     * Why a story's text cannot take a pick from the board, or null when it can:
     * it has a gate section with a `Chosen option:` line that still holds the
     * template's placeholder.
     */
    public function refusalFor(string $markdown): ?string
    {
        $section = $this->section($markdown);
        if ($section === null) {
            return 'this story has no Design mockup gate section';
        }
        if ($this->isNonVisual($section[0])) {
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
     * The option letter a story's gate records as a single letter, lower-cased —
     * `b`, `**B**` and `` `b` `` all read as `b` — or null for anything else.
     * Used for a checkout's copy of a story, which the kit parser has not indexed.
     */
    public function pickedLetter(string $markdown): ?string
    {
        $section = $this->section($markdown);
        if ($section === null || ! preg_match(sprintf(self::LINE, 'Chosen option'), $section[0], $m)) {
            return null;
        }
        $plain = $this->plain($m['value']);

        return preg_match('/^[a-z]$/i', $plain) ? strtolower($plain) : null;
    }

    /**
     * The branch the board reads for a project: its ref without the remote
     * (`origin/main` → `main`), or the ref itself when it names a local branch.
     *
     * @param  list<string>  $remotes  the checkout's remote names (`git remote`)
     */
    public function boardBranch(string $ref, array $remotes): string
    {
        [$first, $rest] = array_pad(explode('/', $ref, 2), 2, null);

        return $rest !== null && in_array($first, $remotes, true) ? $rest : $ref;
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
