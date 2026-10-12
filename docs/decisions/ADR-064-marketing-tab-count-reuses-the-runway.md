# ADR-064 — The Marketing tab's count reuses the runway and is handed down by the Marketing page

Date: 2026-10-12 · Status: accepted · Story: MK-1

## Context

The story asks for a count dot on the Marketing tab, which `board/project-tabs` shows on every project page. The
same number appears in the campaigns list's banner and, per campaign, as row badges. A separate count query (for
example, draft posts with `scheduled_for >= now`) would be cheaper, but it would count candidates rather than
dates and would ignore precedence (an approved date with leftover drafts).

## Decision

- `app/Actions/Marketing/CountPostsToApprove.php` sums `BuildCampaignRunway::handle()['to_approve']` over the
  project's enabled campaigns, so the tab, the banner and the badges use one rule. A campaign whose schedule
  fails is logged (`marketing.runway.failed`, `source: tab_count`) and left out.
- `board/project-tabs` computes the count itself when no `marketingCount` prop is given. `CampaignList` already has
  the total, so it passes it through `board/project-header`'s `marketingCount`. The Marketing page then does not
  run the runway twice or log a failure twice.

Alternatives rejected:
- **A cheap SQL count of drafts.** It disagrees with the banner whenever a date has more than one candidate or
  already has an approved post.
- **Making every project page pass the count in.** Every project page component would need to know about marketing.
  Computing the count in the tabs component keeps them unchanged.

## Consequences

- Every project page render runs two extra queries (campaigns, posts in the window) and the runway in PHP.
- A campaign with a broken timezone logs one warning per render of any of the project's pages, polls included,
  until it is fixed.
