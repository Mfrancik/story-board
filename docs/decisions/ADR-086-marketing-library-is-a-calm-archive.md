# ADR-086 — The library is a calm archive: no drafts, and no missed posts in Coming up

Date: 2026-10-12 · Status: accepted · Story: MK-10

## Context

The owner's round-5 feedback on the library was "library needs organized better, not just a mix of all sorts of
crap". The story settles on two sections, Coming up (approved) and Posted, with drafts living on campaign pages and
in the studio. It does not say where an approved post whose time has passed without being marked posted (a missed
post) belongs.

## Decision

`app/Actions/Marketing/ListLibraryPosts.php` returns only:

- **Coming up**: approved, not posted, and `scheduled_for >= now`. Grouped by Monday-first week in the owner's
  timezone.
- **Posted**: `posted` status, or a `posted_at` on any post that isn't discarded (`MarketingPost::isPosted()` as a
  query). Newest first, grouped by month; only the current month starts open.

Drafts, skipped and discarded posts never appear. **A missed post is left out of both sections**: it is not "coming
up", and it has not been posted. The calendar (red chip) and the campaign page already flag it, and once marked
posted it joins Posted.

Alternatives rejected:
- **Missed posts in Coming up**, at the top. Mislabels them and turns the archive into a to-do list.
- **A third "Missed" section.** Duplicates the calendar and the campaigns list's Missed badge.

## Consequences

- A missed post is findable on the calendar and its campaign page, not in the library, until it is marked posted or
  rescheduled.
