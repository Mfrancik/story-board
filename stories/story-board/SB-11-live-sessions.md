# SB-11 — Live sessions
Status: approved         Journey: none
Source: owner 2026-09-29 (/story): *"active sessions (if possible) … visually see what is being worked on as
we speak."* Owner answer: metadata only, *"and description of story being built. if theres mockups that are
approved id like to see them when i look in this"*.

## Story
As the owner, I want to see which Claude Code sessions are active right now, in which project, checkout
and branch, and the story each is building with its approved mockup, so that I can see what's being
worked on as it happens.

## Why
The board shows what's decided and merged. What's happening right now lives only in open terminals. Each
session leaves a transcript file whose metadata says where it runs, which is enough to answer "what's
being worked on" without reading a single message.

## In scope
- A `SessionReader` service reads session files under the transcripts root (default
  `~/.claude/projects`, set in config). It reads only files whose mtime is within
  `SessionReader::LIVE_MINUTES` (10). From each it reads the last 400 KB and keeps only `cwd`,
  `gitBranch`, `timestamp`, `sessionId` and `version`. Message content is never stored, logged or
  rendered.
- A session belongs to a project when its `cwd` equals the project's path, or sits under it (worktrees
  at `<path>/.claude/worktrees/*`), or under one of the project's `project_locations`. Sessions in
  unregistered or disabled projects are ignored.
- Story link, in order:
  1. Every token in the branch name matching `Story::ID_PATTERN` (case-sensitive, uppercase) that exists
     as a story in that project, on the ref or off main.
  2. Otherwise, the off-main rows whose `branch` equals the session's branch.
  3. Otherwise, "No story linked".
- A linked story shows its ID, title, status, a one-line description (the first sentence of its `## Story`
  section, read with `RenderStory`'s git read at the row's SHA) and, when it has a chosen mockup, that
  option's thumbnail via `Story::mockupUrl()`. Clicking it opens the SB-8 modal.
- A "Live now" panel on `/` (SB-9's slot) and `/p/{project}` (SB-10's slot). Each session card shows
  project, checkout (main checkout or worktree name), branch, "active N min ago" and the story link. The
  panel re-reads every 30 seconds (`wire:poll.30s` on the panel only). Results are cached for 20 seconds,
  so two open tabs cost one scan.
- The sidebar (SB-7) shows a live badge with the count next to each project that has a live session.
- Degraded states:
  - Root folder missing: the panel says "No Claude Code sessions folder found".
  - A file that fails to parse is skipped and logged once.
  - Every candidate file fails: the panel says "Sessions unavailable". The rest of the page is unaffected.

## Out of scope (do NOT build)
- Reading, storing or showing any message text, prompt, title or tool output from a transcript.
- Detecting whether the Claude process is still running. This story uses mtime. A process check can be
  a follow-up if mtime proves too coarse.
- Sending anything to a session (SB-13 and the kit hook).
- Sessions for projects not registered on the board, including story-board itself.

## Acceptance criteria (executable — these become the Pest test names)
- Given a fixture root with a coins session file modified 2 minutes ago, `cwd`
  `<coins>/.claude/worktrees/set42` and branch `feat/SET-42-lock-a-coin-in-its-slot`, and SET-42 approved
  on the ref with chosen mockup b, when `/` loads, then Live now shows a coins card for worktree `set42`,
  that branch, "active 2 min ago", SET-42 with its one-line description, and the option-b thumbnail.
- Given a session file last modified 11 minutes ago, then it is not shown.
- Given a session on branch `integrate/bullion-phone-1` with no ID in its name and one off-main row on
  that branch, then the card links that row's story.
- Given a session on `docs/PRF-browser-flake-stories` (`PRF` has no number) or a detached `HEAD`, then the
  card reads "No story linked".
- Given a branch named `integrate/bul-65-66-mob-46` (lower case), then no story is linked (IDs are
  matched upper case only, per SB-3's ID rule).
- Given a session whose `cwd` is in no registered project, or in a disabled one, then it is not shown.
- Given a session file with a truncated last line and a valid earlier line, then the card uses the last
  valid line.
- Given a file with no parseable line, then it is skipped, `board.session_unreadable` is logged once with
  the file name (not its content), and other sessions still show.
- Given every live file is unreadable, then the panel shows "Sessions unavailable" and the page still
  returns 200.
- Given the transcripts root does not exist, then the panel shows "No Claude Code sessions folder found"
  and `board.sessions_root_missing` is logged.
- Given any session renders, then the response contains no text from the file other than `cwd`,
  `gitBranch` and the times (a fixture message body with a sentinel string never appears).
- Given the panel polls twice within 20 seconds, then the files are read once.

## Applicable standards
- Design: design-A "Live now" cards and the sidebar badge (SB-7 gate). The poll lives on the panel only,
  never the page. A card reserves its thumbnail space, so polling causes no layout shift.
- Codebase/DB: no schema change; nothing about sessions is stored. `SessionReader` reads files only,
  never writes, never follows a path outside the root (paths are `realpath`-checked), and runs no git.
  The transcript root is a config value (`config/board.php` `sessions_path`, env `BOARD_SESSIONS_PATH`).
  Owner confirmation is needed before touching `config/`. L-5: every skip and refusal gets a test and
  a log line.
- Logging: `board.sessions_read` (debug; `files`, `live`, `ms`), `board.session_unreadable` (warning;
  `file`, `reason`), `board.sessions_root_missing` (warning; `path`).

## Design mockup gate
- Mockups: covered by docs/mockups/SB-7/option-a.html, "Live now" in views 1 and 2.
- Chosen option: a (SB-7's gate)
- Why I chose it: "A is the best design" (owner, 2026-09-29).

## Do NOT touch
- `~/.claude/` (read only), any registered project's files, `app/Services/GitReader.php`.

## Data & interfaces
- Schema/migrations: none.
- Service: `app/Services/SessionReader.php`. Livewire: a `LiveSessions` component embedded by the home
  and project pages. Sidebar badge. Config key `board.sessions_path`. ADR: the board reads Claude Code
  session metadata (an undocumented format, read defensively).

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build. The fixtures are
  hand-written JSONL files in a temp root, never the real `~/.claude`.
- Journey test: none.
- Browser check: with a Claude session running in coins, `/` shows it under Live now within 30 seconds,
  with the right branch and worktree. `/p/asset-track` shows the empty state "No live sessions".
- Checked against real data (2026-09-29):
  - Matching `cwd` against registered paths maps 365 of 365 session files (coins 359,
    client-dashboard 6). Worktree sessions are filed under the main project's folder, with the worktree
    in `cwd`.
  - Live in the last 10 minutes: coins 3, others 0.
  - Branch recovered from the last 200 lines for 110 of 111 files from the last 7 days.
  - Distinct branches from the last 7 days: 28. 16 carry an uppercase ID. 1 more
    (`integrate/bullion-phone-1`) links through an off-main row. 11 link nothing (`docs/*-stories`,
    `integrate/*`, `HEAD`, `main`). Two Claude Code versions write these files today (2.1.283, 2.1.284).

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-7, SB-8, SB-9, SB-10
