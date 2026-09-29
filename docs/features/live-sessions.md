# Live sessions
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-11

## Overview
A "Live now" panel on `/` and `/p/{project}`, and a live badge in the sidebar, show which Claude Code
sessions are active right now: in which project, checkout and branch, and the story each branch is
building, with its one-line description and chosen mockup. The board and git show what has been decided
and merged. Live sessions show what is happening in the open terminals. Only metadata is read from a
session: no message, prompt or tool output is kept, logged or rendered. Design A, views 1 and 2
(`docs/mockups/SB-7/option-a.html`).

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
     and resolves through `FindStoryVersion` (ref version first, the same as the SB-8 modal). This can link
     several stories, one button per ID. Matching is case-sensitive, so `bul-65` links nothing.
  2. Otherwise, off-main rows whose `branch` is the branch or `origin/<branch>`. `IndexOffMain` stores
     remote-only branches under their remote name. Rows without a page are dropped, and each ID appears once.
  3. Otherwise, nothing: "No story linked". A null branch or a detached `HEAD` stops at this step.
- For each linked row, `firstSentence()` takes the first sentence of the `## Story` section, read through
  `RenderStory::read()` at the row's SHA and stripped of `*`/`` ` ``. It is cached for a day under the
  project, SHA, path and location kind. A failed git read on a tracked row is **not** cached, so the next
  poll retries. The thumbnail is `Story::mockupUrl("option-<chosen>.html")`, used only when `chosen` is one
  of the parsed `options`.
- `counts()` powers the sidebar badge from the same matching, with no story links and no git.

**3. Render.** `app/Livewire/Board/LiveSessions.php` (`#[Locked] ?string $project`) renders
`resources/views/livewire/board/live-sessions.blade.php`. `wire:poll.30s` sits on the panel's
`<section>` and nowhere else on the page. Home embeds `<livewire:board.live-sessions />`. The project page
embeds it with `:project` so it shows only that project's sessions. Each card shows project · checkout, an
"active N min ago" pill ("just now" under a minute), the branch ("detached HEAD", "no branch"), and one
button per linked story. Clicking a button dispatches `board-story` with `<project>/<ID>`, which opens the
SB-8 modal. Every story button reserves a `mockup-thumb-box`. With no chosen mockup the box is dashed and
empty, so a poll that changes a card does not move the page. The pulsing dot is `.live-pulse` in
`resources/css/app.css` and does not animate under `prefers-reduced-motion`.

**Sidebar badge.** `app/View/Components/Board/Sidebar.php:render()` passes
`app(ListLiveSessions::class)->counts()`. `resources/views/components/board/sidebar.blade.php` renders
`<span data-live-count="N">` beside each project that has a live session. The sidebar does not poll, so
the badge updates on page loads only.

## Data model
None. No migration and nothing stored. The only persisted state is cache:
- `board.sessions` (the scan, 20 s)
- `board.session_unreadable:<sha1(file|mtime)>` (the "logged once" marker, 10 min)
- `board.story_line:<sha1(project|sha|path|kind)>` (description line, 1 day; `''` means "no line")

## Interfaces
- `SessionReader::live(): array{status, sessions}`. Status is `SessionReader::OK | MISSING | UNAVAILABLE`.
  Each session is `file` (basename), `cwd`, `branch`, `timestamp`, `session_id`, `version`, `mtime`.
  Constants: `LIVE_MINUTES`, `TAIL_BYTES`, `CACHE_SECONDS`.
- `ListLiveSessions::handle(?string $project): array{status, sessions}`. Each card is `key` (12 hex chars
  of `sha1(file)`), `project`, `checkout`, `branch`, `active_at` (Carbon from mtime), and `stories` (a list
  of `story`, `line`, `thumb`, `chosen`).
- `ListLiveSessions::counts(): array<project name, int>`.
- Livewire `board.live-sessions`, prop `project` (locked).
- Test hooks: `data-live-panel="<status>"`, `data-live-session`, `data-live-project`, `data-live-story`,
  `data-live-line`, `data-live-thumb`, `data-live-count` (sidebar).

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

`board.session_unreadable` is logged once per file per mtime, for 10 minutes. Without that, the 30 s poll
would repeat the warning. Each line carries `request_id`.
- **Healthy:** one `board.sessions_read` per 20 s at most, however many tabs are open, with `live` equal to
  the number of active terminals.
- **Panel says "Sessions unavailable":** look for `board.session_unreadable` lines. `no_valid_line` on
  every file usually means Claude Code changed its transcript format (see ADR-023).
- **A session is missing:** look for `board.session_ignored` with its `cwd`. `unregistered` means no
  project path or location contains it. The fix is `board:project alias`, or registering the project.
- **Wrong or no story:** the branch has no upper-case ID, and no off-main row carries that branch yet
  (the off-main index updates only on refresh).

## Testing & verification
- `tests/Feature/Board/LiveSessionsTest.php`: one `it()` per acceptance criterion, plus extras: matching
  a registered location, `board.sessions_read` context, the single `wire:poll.30s`, the symlink
  `outside_root` skip, multi-ID linking, the project-page scope with "No live sessions", the sidebar
  badge, and opening the modal. The leakage test writes sentinel strings into the message, version,
  request ID, slug and session ID, then asserts that none appear in the page.
- `tests/Browser/LiveSessionsTest.php`: a fixture coins worktree session shows with its badge and
  option-b thumbnail. A forced `$refresh` leaves the card height unchanged, and clicking the story opens
  the modal. `/p/asset-track` at 375 px shows "No live sessions" without sideways scroll.
- Fixtures are hand-written JSONL in a temp root (`tests/Support/SessionFixture.php`), never the real
  `~/.claude`.
- The two older "does not render Live now until SB-11" tests in `AllProjectsDashboardTest` and
  `ProjectPageTest` are now order checks (Live now sits between What needs me and In flight).
- **Pending (owner-side):** the story's browser check against a real, running coins session: `/` shows it
  within 30 s with the right branch and worktree. This has not been done yet.

## Key decisions & tradeoffs
- The board reads Claude Code's undocumented transcript files defensively, rather than having the kit's
  hook post session state → [ADR-023](../decisions/ADR-023-live-sessions-read-transcript-metadata-defensively.md).
- Liveness is file mtime, not a process check. A session idle for more than 10 minutes drops off, and a
  closed terminal stays on for up to 10 minutes.
- Only the scan is cached, so switching a project off hides its sessions at the next poll, with no
  cache flush.
- Case-sensitive IDs follow SB-3's ID rule, even though some real branches use lower case
  (`integrate/bul-65-66-mob-46`).

## Known limitations & gotchas
- The format is undocumented. If a Claude Code release renames `cwd` or `gitBranch`, or moves the files,
  the panel degrades to "Sessions unavailable" or "no branch". It does not break the page.
- The sidebar badge does not poll. It reflects the last page load, while the panel refreshes every 30 s.
- A panel can be up to 20 s (cache) plus 30 s (poll) behind a session's first activity.
- A branch pushed or created after the last refresh links through step 2 only after the next off-main
  refresh indexes it.
- Only `~/.claude/projects/<folder>/<id>.jsonl` is scanned. Subagent transcripts are ignored by design.
- Every test inherits the empty sessions root from `TestCase`. A new board test that expects sessions
  must set `board.sessions_path` itself.

## Change history
2026-09-29 — Live now panel on `/` and `/p/{project}`, sidebar live badge, `SessionReader`, `ListLiveSessions`, `config/board.php` (SB-11, `76ec1eb`)
