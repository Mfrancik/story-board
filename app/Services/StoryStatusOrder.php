<?php

namespace App\Services;

use stdClass;

/**
 * The one place the board orders story statuses and decides which count as open
 * work: the kit's vocabulary first, in its lifecycle order, then anything a
 * project made up, A–Z. Pure — no database, no git — so every list that ranks
 * or rolls up statuses agrees without reaching into another page's action.
 */
class StoryStatusOrder
{
    /** The kit's vocabulary in lifecycle order; anything else (a project's own or a typo) follows, A–Z. */
    public const ORDER = ['draft', 'approved', 'built', 'cancelled'];

    /** The statuses that are still open work: counted as open, and the only ones whose mockups are still picked. */
    public const OPEN = ['draft', 'approved'];

    /**
     * Whether `$status` is open work.
     */
    public static function isOpen(?string $status): bool
    {
        return in_array($status, self::OPEN, true);
    }

    /**
     * Counts keyed by status, in ORDER and then alphabetically.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public static function ordered(array $counts): array
    {
        $rank = fn (string $s) => [array_search($s, self::ORDER, true) === false ? 1 : 0, (int) array_search($s, self::ORDER, true), $s];
        uksort($counts, fn ($a, $b) => $rank((string) $a) <=> $rank((string) $b));

        return $counts;
    }

    /**
     * Roll status counts up into one row per initiative, most open work first.
     *
     * Each row is a plain object — a toBase() aggregate or one built in PHP — with
     * `initiative` (null for none), `status` (a missing one as `(none)`), `n` (its
     * count, a string from MySQL) and `parked`.
     *
     * @param  iterable<stdClass>  $rows  one row per (initiative, status); only read, never kept
     * @return list<array{name: string|null, counts: array<string, int>, total: int, built: int, open: int, parked: bool}>
     */
    public static function rollup(iterable $rows): array
    {
        $initiatives = collect($rows)->groupBy(fn ($row) => (string) $row->initiative)->map(function ($group) {
            // Summed, not keyed: a null status and a literal "(none)" are two SQL rows
            // with one key, and mapWithKeys would drop one of them.
            $counts = $group->groupBy('status')->map(fn ($rows) => $rows->sum(fn ($row) => (int) $row->n))->all();

            return [
                'name' => $group->first()->initiative,
                'counts' => self::ordered($counts),
                'total' => array_sum($counts),
                'built' => $counts['built'] ?? 0,
                'open' => array_sum(array_intersect_key($counts, array_flip(self::OPEN))),
                'parked' => $group->contains(fn ($row) => (bool) $row->parked),
            ];
        })->values()->all();

        // Most open work first; a tie reads A–Z, with the unnamed row after the named ones.
        usort($initiatives, fn ($a, $b) => [$b['open'], $a['name'] === null, $a['name']] <=> [$a['open'], $b['name'] === null, $b['name']]);

        return $initiatives;
    }
}
