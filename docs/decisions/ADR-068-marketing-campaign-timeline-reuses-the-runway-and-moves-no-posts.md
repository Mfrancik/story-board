# ADR-068 — The campaign timeline reuses the runway's rules, and a reschedule moves no posts

Date: 2026-10-12 · Status: accepted · Story: MK-7

## Context

The campaign page shows the next 8 dates, each with a state. MK-1's `BuildCampaignRunway` already decides which
posts belong to a date (by local day, ADR-062) and what state each date is in (ADR-063). However, it did this in
private methods, and its output covers only 6 dates with no posts attached. The story's Do-NOT-touch list says to
reuse MK-1's runway rules, not fork them.

The story also says that changing a campaign's days or time leaves approved posts on their dates while open dates
follow the new rule. Something has to decide what to show for a date that holds posts but is no longer on the
schedule.

## Decision

- `BuildCampaignRunway::state()` and `::day()` go from private to public, with no change to their rules.
  `app/Actions/Marketing/ListCampaignOccurrences.php` builds the timeline from `CampaignSchedule` plus those two
  methods. The orchestrator accepted the visibility-only change as reuse, not a fork.
- **No post is ever moved or rewritten** by a schedule change. A date that still holds posts but is off the
  schedule becomes its own row (`on_schedule: false`, "Off schedule"). It is shown only while it is upcoming and no
  later than the 8th scheduled date, or while it is missed. So Tue→Wed shows 9 rows: the approved Tue 20 plus 8
  open Wednesdays.
- "Missed" on the timeline means an approved post past its time and not posted, which is the runway's own definition.
- The header status line uses `BuildCampaignRunway::handle()`'s 6-date numbers unchanged, so it matches the
  campaigns list. One posts query (`scopeRunwayWindow`) feeds both the status line and the timeline.

Alternatives rejected:
- **Copy the day/state logic into the new Action.** Two copies of the rules would drift, and the page would disagree
  with the list.
- **Widen `BuildCampaignRunway::handle()` to return 8 dates with posts.** That changes MK-1's output and its tests
  for one consumer.
- **Move or re-date approved posts when the schedule changes.** It silently rewrites what the owner approved, and the
  story forbids it.
- **Show every off-schedule date, past ones included.** Past posted posts already appear under Past posts. Showing
  them again would clutter the future view.

## Consequences

- Any change to `state()` or `day()` now changes the list, the tab count and the campaign page together, which is
  the point.
- The timeline can have more than 8 rows after a reschedule.
- An off-schedule date that passes without being missed (posted or skipped) leaves the timeline.
