# Live sessions
Status: active   ·   Last updated: 2026-10-11   ·   Stories: SB-11, SB-25, RD-5, RD-9

## Overview
The home overview's "Live now" panel shows which Claude Code sessions are active right now: in which
project and branch, the story each branch is building, what it is doing and how full its context is. The board and git show what has been decided
and merged. Live sessions show what is happening in the open terminals. Only metadata is read from a
session: no message, prompt or tool output is kept, logged or rendered. Design A, views 1 and 2
(`docs/mockups/SB-7/option-a.html`).

SB-11 built a `LiveSessions` panel for `/` and `/p/{project}` and a live badge in the old sidebar. RD-5 moved `/`
to the [home overview](home-overview.md), whose panel uses `ListLiveSessions::overview()` (slim cards: story IDs
only, plus a doing line and context bar from `SessionReader`'s `started`, `doing`, `tokens` and `model` fields,
ADR-048). **RD-9 deleted the old panel, the badge and `counts()`** with the pages they sat on
([retired pages](retired-pages.md)). The scan, matching and story-link rules below are unchanged.

## How it works
**1. Scan (cached 20 s).** `app/Services/SessionReader.php:live()` wraps `scan()` in
`Cache::remember('board.sessions', CACHE_SECONDS)`, so every panel, sidebar and tab within 20 s shares one
scan. `scan()`:
- resolves `config('board.sessions_path')` with `realpath`. If it is missing, it logs
  `board.sessions_root_missing` and returns status `MISSING`.
- globs `<root>/*/*.jsonl`, one folder deep. Subagent transcripts one level further down are not
  sessions.
- keeps only files whose **mtime** is within `LIVE_MINUTES`. mtime is also what "active N min ago" shows,
  so the filter and the label agree.
- `readFile()` re-checks each file's `realpath` against the root, so a symlink cannot lead outside it
  (logged as unreadable, reason `outside_root`). It reads only the last `TAIL_BYTES`, dropping the first
  fragment line when the read starts mid-file.
- `lastValidLine()` walks the lines from the end to the newest one that decodes to an object with a
  non-empty string `cwd`. A truncated last line (a write in progress) falls through to the line before.
  The decoded line is cut down straight away to `cwd`, `gitBranch`, `timestamp`, `sessionId` and
  `version`, so the message never outlives the call.
- sorts sessions newest first and logs `board.sessions_read`. If live files existed and none could be
  read, the status is `UNAVAILABLE`, not an empty `OK`.

Only the file scan is cached. Everything below runs on every request, so a project switched off in
Manage projects (SB-12) drops out of the panel on the next poll.

**2. Match and link.** `app/Actions/Board/ListLiveSessions.php`:
- `matched()` builds anchors from every project's `path` and its `project_locations`. Disabled projects
  are loaded too, but only so the skip can log why. A session belongs to the **deepest** anchor its `cwd`
  equals or sits under, so a registered worktree location beats the main checkout above it. A session
  with no anchor, or with a disabled project, logs `board.session_ignored` at debug. Story-board's own
  sessions land here on every poll.
- `checkout()` names the card: `main checkout`, `worktree <name>` for `<path>/.claude/worktrees/<name>/…`,
  or the location's folder name (`worktree ` prefixed for a `worktree` kind).
- `links()` chooses the stories, in this order:
  1. Every token in the branch matching `[A-Z]{2,}-[0-9]+[a-z]?` that also passes `Story::ID_PATTERN`
     and resolves through `FindStoryVersion` (ref version first, the same as the story page). This can link
     several stories, one button per ID. Matching is case-sensitive, so `bul-65` links nothing.
  2. Otherwise, `branchRows()`: off-main rows whose `branch` is the branch or `origin/<branch>`.
     `IndexOffMain` stores remote-only branches under their remote name. Rows without a page are dropped,
     and each ID appears once. Two rules then narrow it (SB-25), because `IndexOffMain` tags an untracked
     file with the branch of the checkout it sits in, so without them every session on `main`, in any
     folder, claimed every untracked story in the main checkout:
     - **Rule 1, default branch.** If the branch equals `defaultBranch()` (`projects.ref` with a leading
       `origin/` stripped; no other remote prefix is handled), step 2 returns nothing. The default branch
       collects every checkout's leftovers; it is not a unit of work. Step 1 still runs first, so an
       ID-bearing default branch still links.
     - **Rule 2, own checkout.** A row of kind `untracked` links only if its root (parsed by
       `untrackedRoot()` from `location` = `untracked in <root>`; any other shape never links) equals the
       session's own checkout root. `checkoutRoot()` picks the **deepest** of the project path, its
       registered locations and `<path>/.claude/worktrees/<name>` (derived from the cwd, so a worktree
       works before a refresh registers it) that contains the cwd, on whole path segments (`/a/b` never
       claims `/a/bc`). `samePath()` compares case-sensitively, trailing slashes trimmed, both as typed
       and via `realpath` because `IndexOffMain` stores the root resolved (macOS `/var` is
       `/private/var`). Branch and worktree rows are committed to the branch and still link by branch alone.
       Deliberately stricter than "cwd sits under the root": see ADR-034.
  3. Otherwise, nothing: "No story linked". A null branch or a detached `HEAD` stops at this step.
- For each linked row, `firstSentence()` takes the first sentence of the `## Story` section, read through
  `RenderStory::read()` at the row's SHA and stripped of `*`/`` ` ``. It is cached for a day under the
  project, SHA, path and location kind. A failed git read on a tracked row is **not** cached, so the next
  poll retries. `firstSentence()` and `links()` serve only `handle()`, which no page calls since RD-9; the
  overview uses `storyRows()` directly and shows IDs only, so it never reads git for a card.

**3. Render.** `app/Livewire/Board/Overview.php:render()` calls `overview($filter)` inside its per-panel
`attempt()` (ADR-049) and `resources/views/livewire/board/overview.blade.php` draws the cards
(`data-live-now`, `data-live-card="<project>"`, `data-live-story`). `wire:poll.30s` sits on the Live now section, the page's only poll. See
[home-overview.md](home-overview.md) for the card layout.

## Data model
None. No migration and nothing stored. The only persisted state is cache:
- `board.sessions` (the scan, 20 s)
- `board.session_unreadable:<sha1(file|mtime)>` (the "logged once" marker, 10 min)
- `board.story_line:<sha1(project|sha|path|kind)>` (description line, 1 day; `''` means "no line")

## Interfaces
- `SessionReader::live(): array{status, sessions}`. Status is `SessionReader::OK | MISSING | UNAVAILABLE`.
  Each session is `file` (basename), `cwd`, `branch`, `timestamp`, `session_id`, `version`, `mtime`.
  Constants: `LIVE_MINUTES`, `TAIL_BYTES`, `CACHE_SECONDS`.
- `ListLiveSessions::overview(?ProjectFilter $filter): array{status, sessions}`. Each card is `key` (12 hex
  chars of `sha1(file)`), `project`, `branch`, `stories` (IDs), `started_at`, `active_at`, `doing`, `tokens`,
  `window`. This is the one caller-facing method in use.
- `ListLiveSessions::handle(?string $project)` still exists (cards with `checkout`, and `stories` of `story`,
  `line`, `chosen`) but has no caller since RD-9 (backlog F-15).

`session_id` and `version` are read but never rendered. Card keys hash the file name, whose stem is the
session ID, so neither reaches the HTML.

## Configuration
`config/board.php` (new in this story, created with owner approval):
`sessions_path` = env `BOARD_SESSIONS_PATH`, default `$HOME/.claude/projects`. The "No Claude Code
sessions folder found" empty state names the path and this variable. SB-14 added `kit_path` and
`kit_ref` to the same file.

**Tests:** `tests/TestCase.php:setUp()` points `board.sessions_path` at an empty temp folder for every
test. A test that needs sessions sets it to a `Tests\Support\SessionFixture` root (see RUNBOOK).

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.sessions_read` | debug | `SessionReader::scan()` | `files` (all transcripts), `live` (within 10 min), `ms` |
| `board.session_unreadable` | warning | `SessionReader::unreadable()` | `file` (basename only), `reason` (`outside_root` \| `unopenable` \| `no_valid_line`) |
| `board.sessions_root_missing` | warning | `SessionReader::scan()` | `path` |
| `board.session_ignored` | debug | `ListLiveSessions::matched()` | `cwd`, `reason` (`unregistered` \| `disabled`), `project` |
| `board.session_links_filtered` | debug | `ListLiveSessions::logFiltered()` | `project`, `branch`, `rule` (`default_branch` \| `untracked_checkout`), `dropped` (distinct story IDs). Only when `dropped > 0`; never IDs or text (SB-11 privacy rule) |

`board.session_unreadable` is logged once per file per mtime, for 10 minutes. Without that, the 30 s poll
would repeat the warning. Each line carries `request_id`.
- **Healthy:** one `board.sessions_read` per 20 s at most, however many tabs are open, with `live` equal to
  the number of active terminals.
- **Panel says "Sessions unavailable":** look for `board.session_unreadable` lines. `no_valid_line` on
  every file usually means Claude Code changed its transcript format (see ADR-023).
- **A session is missing:** look for `board.session_ignored` with its `cwd`. `unregistered` means no
  project path or location contains it. The fix is `board:project alias`, or registering the project.
- **Wrong or no story:** the branch has no upper-case ID, and no off-main row carries that branch yet
  (the off-main index updates only on refresh). If rows exist but the card is empty, look for
  `board.session_links_filtered`: `default_branch` means the session is on the project's default branch;
  `untracked_checkout` means the untracked files sit in a different checkout from the session's cwd.

## Testing & verification
- `tests/Feature/Board/LiveSessionLinksTest.php` (RD-9; ported from the deleted `LiveSessionsTest`): the
  scan, matching and linking rules, through `ListLiveSessions::overview()`, the path the home uses. It covers
  the 10-minute window, branch and off-main linking, lower-case and ID-less branches, unregistered and
  disabled cwds, registered locations, truncated and unreadable files, the missing root, the 20 s cache,
  `board.sessions_read`, the symlink `outside_root` skip and multi-ID linking.
- The page-level checks (cards, never the message, the poll) are in `tests/Feature/Board/HomeOverviewTest.php`
  and `tests/Browser/HomeOverviewTest.php`.
- SB-25 adds one `it()` per acceptance criterion (rule 1 in the main checkout and a worktree, rule 2 in
  and across checkouts, branch rows unchanged, `origin/main` ref, step 1 unchanged, the log), plus two
  extras: step 1 still links on the default branch, and no `board.session_links_filtered` when nothing
  is dropped.
- Fixtures are hand-written JSONL in a temp root (`tests/Support/SessionFixture.php`), never the real
  `~/.claude`.
- **Pending (owner-side):** the story's browser check against a real, running coins session: `/` shows it
  within 30 s with the right branch and worktree. This has not been done yet.
- **Pending (owner-side, SB-25):** with a coins session on `main` and untracked stories in `~/Code/coins`,
  the coins card on `/` links no story instead of the ADMIN/BRAND list.

## Key decisions & tradeoffs
- The board reads Claude Code's undocumented transcript files defensively, rather than having the kit's
  hook post session state → [ADR-023](../decisions/ADR-023-live-sessions-read-transcript-metadata-defensively.md).
- Liveness is file mtime, not a process check. A session idle for more than 10 minutes drops off, and a
  closed terminal stays on for up to 10 minutes.
- Only the scan is cached, so switching a project off hides its sessions at the next poll, with no
  cache flush.
- The fallback never runs on the default branch, and an untracked row belongs only to the checkout it
  sits in, not to a worktree nested under it →
  [ADR-034](../decisions/ADR-034-live-cards-link-untracked-stories-only-from-their-own-checkout.md).
- Case-sensitive IDs follow SB-3's ID rule, even though some real branches use lower case
  (`integrate/bul-65-66-mob-46`).

## Known limitations & gotchas
- The format is undocumented. If a Claude Code release renames `cwd` or `gitBranch`, or moves the files,
  the panel degrades to "Sessions unavailable" or "no branch". It does not break the page.
- No sidebar shows live sessions since RD-9 deleted the old badge; the shell sidebar has none.
- A panel can be up to 20 s (cache) plus 30 s (poll) behind a session's first activity.
- A branch pushed or created after the last refresh links through step 2 only after the next off-main
  refresh indexes it.
- Rule 1 strips only `origin/`. A project whose ref is `upstream/main` (or any other remote) is not
  recognised as on its default branch, and a `main` session there borrows untracked rows again.
- A session on the default branch never shows its own untracked stories, even in the checkout that holds
  them. Put the work on a branch (or name the ID in it) to get a link.
- Only `~/.claude/projects/<folder>/<id>.jsonl` is scanned. Subagent transcripts are ignored by design.
- Every test inherits the empty sessions root from `TestCase`. A new board test that expects sessions
  must set `board.sessions_path` itself.

## Change history
2026-09-29 — Live now panel on `/` and `/p/{project}`, sidebar live badge, `SessionReader`, `ListLiveSessions`, `config/board.php` (SB-11, `76ec1eb`)
2026-09-30 — Fallback skips the default branch; untracked rows link only to a session in their own checkout; `board.session_links_filtered` (SB-25, `f747e88`)
2026-10-11 — `SessionReader` reads `started`, `doing`, `tokens`, `model`; `ListLiveSessions::overview()` and `storyRows()` for the home overview; the old panel remains on `/p/{project}` only (RD-5, `6d6f3de`)
2026-10-11 — Old `LiveSessions` panel, sidebar badge, `counts()` and the `thumb` field deleted; `matched()` always logs; tests ported to `LiveSessionLinksTest` (RD-9, `2669db7`)
