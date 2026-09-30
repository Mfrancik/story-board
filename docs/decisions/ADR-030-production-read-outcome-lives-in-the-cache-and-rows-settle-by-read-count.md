# ADR-030 — Production read outcome lives in the cache; rows settle by read count

Date: 2026-09-29 · Status: accepted · Story: SB-18

## Context

`/prod` queues one `ReadProductionMetrics` job per project (ADR-006's pattern) and shows a skeleton per row
until that project's read is back. Two things have to cross from the worker to the page:

1. **What happened on the last read**: ok, unreachable, refused, or which metrics failed and why. Values
   are already persisted as `prod_snapshots` rows, so only the outcome is missing.
2. **Whether the read the page asked for has finished**, so the row can stop loading.

## Decision

**The outcome is a cache entry, not a table.** `ReadProductionProject` stores
`board:prod-read:{project_id}` forever: `seq`, `at`, `ok`, `status`, `reason`, `message`, and a
per-metric map of failures. It is a plain array because the cache store forbids objects
(`serializable_classes` false). `ReadProductionProject::outcome()` rebuilds it field by field and returns
null for anything malformed, so an entry left by an older build cannot break the page. Losing the cache
loses only the error banner: last good values, their read times and the trend all come from snapshots.

**Rows settle by read count, not clock.** Each read sets `seq` to the previous value + 1. When the page
queues a read it records the project's current `seq` in `$pending`. A row stops loading once the cached
`seq` is higher. After `STALL_SECONDS` (60) with no new read the row gives up, shows "No answer from the
queue worker…" and logs `board.prod_read_stalled`. The component polls only while `$pending` is not empty.

## Alternatives rejected

- **A `prod_reads` table (or columns on `prod_connections`).** A migration and a write per read for data
  that is disposable. Nothing needs the outcome history, and the error banner is the only thing lost
  without it.
- **Settle by timestamp** (row done when `outcome.at >= asked_at`). Failed under frozen test time (`travel`)
  where every read has the same second. It could also clear a row with an older read's result when a read
  finished in the same second as the request. A count is exact.
- **Settle by job ID.** `ShouldBeUnique` drops a second dispatch while one is queued, so the page's job may
  never exist. A count still settles, because the job already in the queue increments it.

## Consequences

- `cache:clear` blanks every row's error state until the next read. Values are unaffected.
- The cache store must be shared between the web process and the worker (it is: `CACHE_STORE=database`).
- Anything else that wants "the last production read" should read `ReadProductionProject::outcome()`, not
  the cache key directly.
