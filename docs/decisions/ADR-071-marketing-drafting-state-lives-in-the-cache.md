# ADR-071 — A date's "Drafting…" / "Drafting failed" state lives in the cache

Date: 2026-10-12 · Status: accepted · Story: MK-8

## Context

Fill queues one job per campaign date. The campaign page has to show which dates are being drafted, and which
failed, until the drafts appear or the owner retries. Nothing else needs this state: once drafts exist, the posts
say what the date is.

## Decision

- `app/Services/Marketing/DraftingStatus.php` keeps one cache entry per date, keyed
  `marketing.drafting.{campaign}.{Y-m-d}`. The value is `drafting` (TTL 10 min, well past the 90 s HTTP timeout)
  or `failed` (TTL 24 h).
- `FillCampaign` sets `drafting` before it dispatches. The job clears the entry on success or refusal, and sets
  `failed` on an AI failure.
- The page reads every date's entry in one `Cache::many()`. It adds `wire:poll.2s` only while some date is
  `drafting`.

Alternatives rejected:
- **A status column on posts.** The posts do not exist until drafting succeeds, and failure creates none.
- **A drafting-jobs table.** It needs a schema and cleanup for state that is transient by nature.
- **Always polling, or a broadcast.** Polling all the time costs requests when nothing is happening. Broadcasting
  needs infrastructure the app does not run.

## Consequences

- The cache must be shared by the web process and the worker. That holds for `CACHE_STORE=database`, and tests
  use `array` with the sync queue.
- A lost or expired entry degrades safely: the date reads as open again. If no worker runs, "Drafting…" lasts
  at most 10 minutes. A failure is forgotten after a day.
- The state cannot be inspected historically. The logs (`marketing.ai.draft.*`) are the record.
