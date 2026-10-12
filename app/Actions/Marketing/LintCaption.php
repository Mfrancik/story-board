<?php

namespace App\Actions\Marketing;

/**
 * The copy-rules lint (MK-8): checks one caption against the brand's copy rules and returns warnings. Pure (no AI,
 * no database, no log) and warn-only: a post is never blocked by it, the owner decides. Run on every AI caption and
 * saved with the post as `lint`.
 *
 * Warnings: `too_long` (more than MAX_LINES lines), `filler` (a filler phrase from the copy rules), `banned_claim`
 * (a banned phrase from the copy rules, or any promise to look a coin up from its cert — the app cannot do that
 * yet, however it is worded) and `no_price_source` (a value comparison that does not say where the price came from).
 */
class LintCaption
{
    /** The brief: one or two lines. */
    public const MAX_LINES = 2;

    /**
     * A cert-lookup claim in any wording: "type a cert and we pull the coin", "we'll look up your certificate".
     * The copy rules list the common phrasings; this catches the rest, because the claim is what is banned.
     */
    private const CERT_CLAIM = '/\b(type|enter|paste|drop|send|give)\b[^.!?\n]{0,30}\bcert(ificate)?s?\b|\bcert(ificate)?s?\b[^.!?\n]{0,40}\b(pull|look(s|ed)?\s*up|lookup|find|fetch)\b|\b(pull|look\s*up|find|fetch)\b[^.!?\n]{0,30}\bcert(ificate)?s?\b/i';

    /** A comparison of value: what needs a price source. */
    private const COMPARISON = '/\b(vs\.?|versus|compared (to|with)|(worth|costs?|sells? for|sold for|priced?|goes for|went for) (more|less) than|cheaper than|pricier than|same (price|value) as|worth as much as)(?=\W|$)/i';

    /** Where a price came from: a named guide or market, or "according to …". */
    private const PRICE_SOURCE = '/\b(greysheet|grey sheet|pcgs|ngc|price guide|red book|ebay sold|sold listings?|auction|according to|source)\b/i';

    /**
     * The warnings for `$caption`, in the order the rules are listed above; an empty list for a clean caption.
     *
     * @param  array{filler?: list<string>, banned?: list<string>}  $copyRules  the brand's copy rules
     * @return list<array{code: string, match: string}>
     */
    public function handle(string $caption, array $copyRules): array
    {
        $warnings = [];
        $lower = mb_strtolower($caption);

        $lines = array_filter(preg_split('/\R/', trim($caption)) ?: [], fn (string $l) => trim($l) !== '');
        if (count($lines) > self::MAX_LINES) {
            $warnings[] = ['code' => 'too_long', 'match' => count($lines).' lines'];
        }

        foreach ($copyRules['filler'] ?? [] as $phrase) {
            if ($this->contains($lower, $phrase)) {
                $warnings[] = ['code' => 'filler', 'match' => mb_strtolower(trim($phrase))];
            }
        }

        $banned = false;
        foreach ($copyRules['banned'] ?? [] as $phrase) {
            if ($this->contains($lower, $phrase)) {
                $warnings[] = ['code' => 'banned_claim', 'match' => mb_strtolower(trim($phrase))];
                $banned = true;
            }
        }
        // One warning per claim: the pattern only speaks when no listed phrase already caught it.
        if (! $banned && preg_match(self::CERT_CLAIM, $caption, $m)) {
            $warnings[] = ['code' => 'banned_claim', 'match' => mb_strtolower($m[0])];
        }

        if (preg_match(self::COMPARISON, $caption, $m) && ! preg_match(self::PRICE_SOURCE, $caption)) {
            $warnings[] = ['code' => 'no_price_source', 'match' => mb_strtolower($m[0])];
        }

        return $warnings;
    }

    /**
     * Whether lower-cased `$haystack` contains `$phrase`, ignoring case and blank phrases.
     */
    private function contains(string $haystack, string $phrase): bool
    {
        $phrase = mb_strtolower(trim($phrase));

        return $phrase !== '' && str_contains($haystack, $phrase);
    }
}
