# ADR-084 — The calendar composes the runway's rules in a fixed number of queries

Date: 2026-10-12 · Status: accepted · Story: MK-10

## Context

The marketing calendar shows every campaign's posts and open dates on one grid. A date's state (approved, drafts to
approve, posted, skipped, missed), which local day a post belongs to, and which dates a campaign's schedule produces
are already decided by MK-1/MK-7's `BuildCampaignRunway` and `ListCampaignOccurrences`. The story's codebase standard
says "no second schedule implementation" and "no N+1".

## Decision

`app/Actions/Marketing/BuildMarketingCalendar.php` reads, and only composes:

- **Ghosts** are `ListCampaignOccurrences::handle()` rows in state `open`, from today on, for enabled campaigns.
- **A chip's state** is `BuildCampaignRunway::state()` over a campaign date's posts, with `approved` promoted to
  `missed` by `MarketingPost::isMissed()`. **A post's day** is `BuildCampaignRunway::day()` in its campaign's
  timezone.
- **One-off posts** (no campaign, so no campaign timezone) are placed on their day in the owner's timezone,
  `config('board.timezone')`, the one `CreateOneOffPost` uses. "Today" and the grid's days are the owner's too.
- **Queries are fixed**: one for the campaigns with their runway-window posts, one for the range's posts with their
  photos. Chips reuse the tray's campaign instances (`setRelation`), so nothing reloads per chip.

Alternatives rejected:
- **Per-campaign post queries.** Simpler to write, but the query count grows with campaigns. A test pins the count.
- **Expanding the schedule in the calendar.** A second copy of the weekday/time/timezone rules would drift from the
  campaign page and the list the first time either changes.

## Consequences

- The calendar and the campaign page cannot disagree about a date's state or day.
- A campaign whose schedule can't be read still shows its posts (placed by the owner's timezone) but no ghosts, with
  the reason in the tray and a `marketing.runway.failed` (`source: calendar`) warning.
- Skipped dates make no chip and paused campaigns make no ghosts, by design.
- Changing `board.timezone` can move a one-off to a neighbouring day.
