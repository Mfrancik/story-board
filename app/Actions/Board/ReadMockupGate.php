<?php

namespace App\Actions\Board;

use App\Services\MockupGateParser;

/**
 * Reads the human text of a story's "Design mockup gate" section for display on
 * the story page (SB-4): whether the story is visual at all, and the words
 * around the pick. The option letter itself comes from the kit parser
 * (stories.mockups.chosen) — this never decides anything, it only quotes.
 */
class ReadMockupGate
{
    /**
     * @param  MockupGateParser  $parser  finds the section and reads it as non-visual by the writer's own rule
     */
    public function __construct(private readonly MockupGateParser $parser) {}

    /**
     * The gate's words: whether the story is visual, and its Chosen and Why values as written.
     *
     * @return array{visual: bool, chosen: string|null, why: string|null}
     */
    public function handle(string $markdown): array
    {
        $found = $this->parser->section($markdown);
        $section = $found === null ? null : trim($found[0]);

        return [
            // The /story template writes `n/a — non-visual` (or just `n/a`) for stories with no gate.
            'visual' => $section === null || ! $this->parser->isNonVisual($section),
            'chosen' => $this->field($section, 'Chosen option'),
            'why' => $this->field($section, 'Why I chose it'),
        ];
    }

    /**
     * A `Field: value` line's value (joined with its indented continuation lines),
     * with emphasis markers stripped; null when missing or still `_pending_`.
     */
    private function field(?string $section, string $name): ?string
    {
        if ($section === null || ! preg_match('/'.preg_quote($name, '/').':\s*(.+(?:\n[ \t]+\S.*)*)/i', $section, $m)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/', ' ', str_replace(['**', '__', '`'], '', $m[1])) ?? '');
        $value = trim($value, " *_\t");

        return $value === '' || strcasecmp($value, 'pending') === 0 ? null : $value;
    }
}
