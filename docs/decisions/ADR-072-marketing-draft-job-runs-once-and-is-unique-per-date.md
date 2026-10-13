# ADR-072 — The draft job runs once and is unique per campaign date

Date: 2026-10-12 · Status: accepted · Story: MK-8

## Context

Each `DraftOccurrenceJob` makes one paid Claude call. Laravel's default retry behaviour, or a double click on
Fill, would repeat that call without the owner seeing it.

## Decision

- `app/Jobs/DraftOccurrenceJob.php` has `$tries = 1`. A failure marks the date "Drafting failed · Retry", and the
  owner's Retry is the only retry.
- The job is `ShouldBeUnique` on `campaign_id|date`, with `uniqueFor` 600 s, so a double click queues one job.
  `FillCampaign` also skips any date already marked drafting.
- One job per date, not one per Fill. Each date fills or fails on its own.

Alternatives rejected:
- **Automatic retries with backoff.** They would bill repeated calls silently, and a refusal or a bad reply is
  likely to repeat anyway.
- **One job for all of "Fill N open dates".** One bad date would fail or delay the rest, and progress could not
  be shown per date.

## Consequences

- A transient error (an overload or a timeout) needs a click to recover from.
- An unexpected exception is logged as `marketing.ai.draft.failed` (`reason: exception`), marks the date failed,
  and is rethrown so the queue's failed-jobs record still has it.
