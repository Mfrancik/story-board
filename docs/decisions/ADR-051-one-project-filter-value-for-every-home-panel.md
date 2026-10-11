# ADR-051 — One ProjectFilter value is handed to every home panel

Date: 2026-10-11 · Status: accepted · Story: RD-6

## Context

RD-6 lets the owner narrow the home overview to chosen projects through `?projects=a,b`. Every panel must follow
it: the headline figures, Needs me and Live now now, and RD-7's project tiles and Shipped this week later. The
story's codebase rule is "one filter value passed into each home action (no per-panel copies of the rule)". The
URL is free text, so it can name unknown projects, disabled ones, or the same name in a different case. Live now
polls every 30 s and re-renders the whole component.

## Decision

- **One value object, one rule.** `app/Services/ProjectFilter.php` (a `final readonly` value) is the only place the
  "which projects" rule lives. `projects()` returns the enabled projects it covers as a query, which callers run or
  use as a subquery. `includes()` answers for data matched in memory (live sessions). `Overview::render()` resolves
  the URL once per render through `app/Actions/Board/ResolveProjectFilter.php` and hands the same value to
  `ListNeedsMe::handle()`, `ListLiveSessions::overview()` and `ReadOverviewFigures::handle()`. RD-7's tiles and
  Shipped this week must take the same value. It lives in `Services/`, beside the Production value objects,
  because it is a value, not an action.
- **Exact names, compared in PHP.** The resolver loads the named projects, then matches names in PHP, not in MySQL,
  whose collation would let `Coins` match `coins`. Unknown and disabled names are dropped. A URL where every name
  is dropped covers every project. For each reason (`unknown`, `disabled`) the resolver logs one
  `board.overview_filter_ignored` warning, with at most 20 names, each cut to 60 characters. Its `log:` flag is
  false on a poll, so the 30 s re-render does not warn again.
- **Log the choice, not the poll.** `board.overview_filtered` (info, the resolved names) logs on a page load that
  names projects and on every change, including a clear, which logs `[]`. A poll never logs it. A private
  `$announce` property, which lasts one request, carries the reason.
- **The URL is not rewritten on load.** An ignored name stays in the URL until the next change, which rebuilds
  the list from the resolved names. Stripping it on load would need a replace-on-mount. The URL keeps names in
  the order they were picked, not alphabetical order.

Alternatives rejected:
- **Each action filters itself** from a raw name list. This keeps a copy of the rule in every panel, and RD-7 would
  add two more. A panel that disagreed would show figures that do not match its list.
- **Match names in the `whereIn`.** This is simpler, but it is case-insensitive under the default collation, so a
  mistyped case would pass silently.
- **Warn on every render.** A filtered tab left open would log two warnings a minute about the same bookmark.

## Consequences

- A new home panel gets the filter by accepting a `?ProjectFilter` (null means every enabled project), not by
  reading the URL.
- `projects()` reapplies "enabled" on every query, so a project switched off after the URL was resolved still drops
  out.
- An ignored name lingers in a bookmarked URL until the owner changes the filter.
