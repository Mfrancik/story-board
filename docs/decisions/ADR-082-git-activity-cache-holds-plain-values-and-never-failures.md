# ADR-082 — The git activity cache holds plain values only and never caches a failure

Date: 2026-10-12 · Status: accepted · Story: RD-12 (feat `9b0addd`)

## Context

The project overview renders on every Livewire request, polls included. Running four git commands per render would
make it slow, so `ReadGitActivity` caches each read. `config/cache.php` sets `serializable_classes` to false. In the
database store, a cached `CarbonImmutable` comes back as `__PHP_Incomplete_Class`, while the default array store
used in tests hands back the original object. A naive cache would therefore pass its tests and break in the app.

## Decision

`app/Actions/Board/ReadGitActivity.php:read()` wraps each of the four reads in `Cache::remember()` for
`CACHE_SECONDS` (300, the same cadence as Shipped this week):

- **Only scalars and arrays are cached.** `commitStats()` timestamps become ints before caching.
  `commitsPerDay()` already returns `Y-m-d => int`. Dates are rebuilt as `CarbonImmutable` after the read.
- **The key is project id, read name, and `sha1(path|ref|stored sha|day)`.** The ref only moves on a refresh, and
  that changes the stored sha, so a refresh invalidates the reads naturally. The two date-windowed reads (`commits`,
  `files`) also carry today's date, so the window rolls at midnight.
- **Failures are not cached.** A `GitReaderException` escapes `remember()`, so nothing is stored. The read returns
  null, which is that tile's error state, and logs `board.git_activity_failed`. The next render tries git again.

Alternatives rejected:
- **Turn on `serializable_classes` for Carbon.** That changes `config/`, which is on the Do-NOT-touch list. It
  would also widen what the cache unserialises for every caller.
- **Cache the whole activity array once.** One failing read would then decide the fate of all four, and the dates
  inside it would hit the same serialisation problem.
- **Cache failures briefly.** A transient git error would hide a tile until the cache expires, and the log would
  undercount the failures.

## Consequences

- The uncommitted count reads the working copy, which can change at any time. It can be up to `CACHE_SECONDS`
  stale.
- While git keeps failing, every render retries it and logs a warning per failed read. That costs log volume and
  keeps the failure visible.
- `tests/Feature/Board/ProjectGitActivityTest.php` ("caches the activity as plain values…") turns on
  `cache.stores.array.serialize`. The array store then serialises like the database store, so a Carbon put back into
  the cache fails that test. The default array store would hide it.
