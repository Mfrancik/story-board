<?php

namespace App\Actions\Board;

use Illuminate\Support\Facades\Log;

/**
 * The short story page's reading of a story file (RD-3): one summary line, the
 * acceptance criteria and the Do NOT touch list, so the page can lead with what
 * the story is and fold the rest. It reads only the markdown it is given; the
 * caller reads the file from git (RenderStory::read()).
 */
class ReadStoryOutline
{
    /** The longest summary shown, ellipsis included. */
    public const SUMMARY_MAX = 200;

    /**
     * The story's summary line, acceptance criteria and Do NOT touch items.
     *
     * The summary is the story's own `Summary:` line when it has one, else the
     * first sentence of its `## Story` section, else null — cut at a word to
     * SUMMARY_MAX characters with "…". Side effect: logs
     * `board.story_summary_fallback` (debug) when the first sentence is used.
     *
     * @param  array<string, mixed>  $context  log context (project, story)
     * @return array{summary: string|null, criteria: list<string>, untouchable: list<string>}
     */
    public function handle(string $markdown, array $context = []): array
    {
        $summary = null;
        if (preg_match('/^Summary:[ \t]*(\S.*)$/mi', $markdown, $m)) {
            $summary = $this->plain($m[1]);
        } elseif (($first = $this->firstSentence($this->section($markdown, 'Story'))) !== null) {
            $summary = $first;
            // Debug, not info: most stories have no Summary: line until the kit writes one (backlog), so this is the norm.
            Log::debug('board.story_summary_fallback', $context);
        }

        return [
            'summary' => $summary === null || $summary === '' ? null : $this->cut($summary),
            'criteria' => $this->items($this->section($markdown, 'Acceptance criteria')),
            'untouchable' => $this->items($this->section($markdown, 'Do NOT touch')),
        ];
    }

    /**
     * The body of the first `## <heading>` section (a heading that starts with
     * those words, any case, so "Acceptance criteria (executable …)" matches),
     * up to the next `##` heading; null when the story has no such section.
     */
    private function section(string $markdown, string $heading): ?string
    {
        $pattern = '/^##[ \t]+'.preg_quote($heading, '/').'\b[^\n]*\n(.*?)(?=^##[ \t]|\z)/msi';

        return preg_match($pattern, $markdown, $m) ? $m[1] : null;
    }

    /**
     * The first sentence of a section's first paragraph, as plain text on one line.
     */
    private function firstSentence(?string $body): ?string
    {
        $paragraph = preg_split('/\n[ \t]*\n/', trim((string) $body))[0] ?? '';
        $text = $this->plain($paragraph);
        if ($text === '') {
            return null;
        }

        // A sentence ends at . ! or ? followed by a space or the end; without one the paragraph is the sentence.
        return preg_match('/^(.+?[.!?])(?=\s|$)/u', $text, $m) ? $m[1] : $text;
    }

    /**
     * Top-level list items of a section (`- `, `* ` or `1. `), each on one line:
     * indented continuation lines join the item they wrap, and a `[ ]` checkbox is dropped.
     *
     * @return list<string>
     */
    private function items(?string $body): array
    {
        $items = [];
        foreach (preg_split('/\n/', (string) $body) ?: [] as $line) {
            if (preg_match('/^(?:[-*]|\d+\.)[ \t]+(?:\[[ xX]\][ \t]+)?(.*)$/', $line, $m)) {
                $items[] = trim($m[1]);
            } elseif ($items !== [] && preg_match('/^[ \t]+(\S.*)$/', $line, $m)) {
                $items[array_key_last($items)] .= ' '.trim($m[1]);
            }
        }

        return array_values(array_filter($items, fn (string $item) => $item !== ''));
    }

    /**
     * Markdown to plain text for a one-line summary: links keep their words, and
     * emphasis and code marks go; whitespace collapses to single spaces.
     */
    private function plain(string $markdown): string
    {
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $markdown);
        $text = str_replace(['**', '__', '`'], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * At most SUMMARY_MAX characters: a longer text is cut back to the last whole
     * word that fits and ends in "…".
     */
    private function cut(string $text): string
    {
        if (mb_strlen($text) <= self::SUMMARY_MAX) {
            return $text;
        }

        // One character more than fits, so a word ending exactly at the limit is followed by its space and kept.
        $window = mb_substr($text, 0, self::SUMMARY_MAX);
        $space = mb_strrpos($window, ' ');
        // Back to the last space, unless the text is one unbroken word, which is cut where it stands.
        $head = $space !== false && $space > 0 ? mb_substr($window, 0, $space) : mb_substr($window, 0, self::SUMMARY_MAX - 1);

        return rtrim($head, " \t,;:.-—").'…';
    }
}
