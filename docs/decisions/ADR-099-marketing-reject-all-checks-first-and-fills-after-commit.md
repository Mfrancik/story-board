# ADR-099 — Reject all checks before writing, commits votes and discards together, then fills

Date: 2026-10-13 · Status: accepted · Story: MK-20

## Context

"Reject all 3" (`RejectDateDrafts`) does three things: it dislikes each draft (`RecordFeedback`), discards the drafts,
and redrafts the date through MK-8's single-date Fill (`FillCampaign`). Fill refuses a date that is not open, and on
the sync queue (tests, and a dev setup without a worker) its `DraftOccurrenceJob` runs inline, which means a Claude
call of several seconds.

## Decision

- **Check first.** If there is no API key or the campaign is paused, refuse before writing anything
  (`marketing.review.refused`; the message says nothing was rejected). Fill checks both again itself.
- **One transaction for the owner's verdict.** The dislikes and the discard commit together. If `RecordFeedback`
  refuses an unknown reason, both roll back.
- **Fill after commit**, with `$campaign->withoutRelations()`, so Fill sees the date as open.

Alternatives rejected:
- **Fill inside the transaction.** On the sync queue this would hold a transaction (and its locks) open across a
  Claude call.
- **Dispatch the job before commit.** A worker could pick up the job before the discard is visible, find the date not
  open, and refuse it.
- **Fill first, then discard.** Fill refuses the date while its drafts still exist.

## Consequences

- If Fill refuses for a reason not checked in advance (for example a date off the campaign's schedule), the drafts
  are already discarded and the date is left open. The owner fills it from the campaign page. This is accepted as
  rare: the inbox only offers Reject all on dates from the schedule.
