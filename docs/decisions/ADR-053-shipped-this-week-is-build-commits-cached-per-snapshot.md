# ADR-053 — Shipped this week is build commits, cached per snapshot

Date: 2026-10-11 · Status: accepted · Story: RD-7

## Context

The home overview's Shipped this week panel lists what got built in the last 7 days. "Built" already has a
definition: CLAUDE.md step 6 says `feat|fix|test(<ID>)` commits flip a story to built, and `docs|chore|refactor`
never do. The overview polls every 30 s and re-renders every panel (ADR-049), so a direct `git log` per project
would run twice a minute per open tab. One project's missing checkout or bad ref must not blank the others.

## Decision

- **One row per build commit.** `ListShippedThisWeek::BUILD_COMMIT` matches `^(feat|fix|test)\(<ID>\):` with the
  ID pattern from `Story::ID_ROUTE`. A story with a `feat` and a `fix` this week appears twice. Rows are newest
  first; ties sort by project, then ID.
- **Titles come from the snapshot.** One query fetches the on-ref title for each `(project, ID)`. A story not in
  the snapshot (renamed, or never written) shows the commit's subject and is not linked.
- **Cached per project for `CACHE_SECONDS` (300), keyed on ref and stored `sha`.** The ref only moves on a
  refresh, which changes the stored sha, so a poll between refreshes never needs git. `board.shipped_read`
  therefore logs once per real read, not per poll.
- **A failed project fails alone.** A `GitReaderException` for one project logs `board.shipped_unreadable` and
  names it in the panel; the other projects are still listed.

Alternatives rejected:
- **One row per story** (deduplicate feat + fix). Hides a follow-up fix, which is shipping too.
- **Every commit with a story ID.** `docs(<ID>)` lands after the build; listing it would show the same story as
  shipped twice and contradict the Status rule.
- **No cache.** Correct, but runs git on every poll and floods `board.shipped_read`.

## Consequences

- A cached read can be up to 5 minutes old, so its window can reach that far past 7 days.
- A commit whose subject breaks the convention (no ID, a malformed ID) never appears.
