# ADR-076 — "To approve" is one rule, shared by the campaigns list and the studio queue

Date: 2026-10-12 · Status: accepted · Story: MK-9

## Context

The campaigns list shows "N to approve" per campaign, and the Marketing tab shows a total (ADR-064). The studio shows
"1 of N to approve" and "Approve & next (N left)". If the two counted differently, the owner would approve
everything the studio offered and still see a badge.

## Decision

- The rule moved from inline code in `BuildCampaignRunway::handle()` into a private `toApprove()`: an upcoming date
  whose kept posts are only drafts, counted once however many drafts it has. `handle()` uses it for `to_approve`,
  and the new public `datesToApprove()` uses it to list the dates themselves.
- `app/Actions/Marketing/ListDatesToApprove.php` builds the studio's queue from `datesToApprove()`, sorted by time and
  then campaign id. A campaign with an unreadable schedule is logged and left out.
- Scope: "Start approving" (opened with nothing) queues every **enabled** campaign of the project. A campaign's badge
  or a timeline row queues **that campaign only**.

Alternatives rejected:
- **A second query in the studio.** This is the drift the decision prevents.
- **Always queue the whole project.** Approving from one campaign's page or badge would then jump to another
  campaign's dates, which is not what the owner asked for.

## Consequences

- Any change to what "to approve" means is made in one place and moves the badge, the tab count and the studio
  together.
- One-off posts have no campaign, so they are in neither the count nor the queue.
