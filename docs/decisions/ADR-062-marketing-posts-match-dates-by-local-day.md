# ADR-062 — Marketing posts match a campaign's dates by local day, not exact time

Date: 2026-10-12 · Status: accepted · Story: MK-1

## Context

A campaign's dates are computed (`CampaignSchedule`) from its weekdays, wall-clock time and timezone. Posts store an
absolute `scheduled_for`. The runway has to decide which post belongs to which date. A campaign's time can change
(MK-7 lets the owner edit it), and a timezone west of UTC puts a Tue 19:00 post on Wednesday in UTC.

## Decision

`BuildCampaignRunway` groups posts by `scheduled_for` converted to the **campaign's timezone**, and takes the date
part (`Y-m-d`). A date's posts are every non-discarded post on that local day. `MarketingPost::scopeRunwayWindow()`
loads from one day before now, so posts on a local day that started earlier than UTC's are not cut off.

Alternatives rejected:
- **Exact datetime match** to the computed occurrence. Moving a campaign from 19:00 to 18:00 would orphan every
  already-approved post, and the runway would show its dates as open.
- **Matching by UTC date.** Wrong for any campaign whose local evening is the next day in UTC. A Los Angeles
  Tue 19:00 post is Wed 02:00 UTC, which `CampaignListTest` pins.

## Consequences

- A post keeps its date when the campaign's time changes. Changing the campaign's **days** still orphans posts on
  the old days.
- Two occurrences on the same local day are impossible by construction (`CampaignSchedule` yields at most one per day).
- Changing a campaign's timezone can move posts across a date boundary.
