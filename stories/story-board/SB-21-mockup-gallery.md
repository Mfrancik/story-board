# SB-21 — Mockup gallery
Status: built            Journey: none
Source: owner 2026-09-29 (/story): *"for any mockup, i have a mockup gallery button i go to, and that gives me a full
screen view with a small title and description, tells me where, shows me a current version (if that exists, then i
can see the two mockups side by side in a single screen."* The "current version" pane is SB-23; this story is the
gallery, the viewer, option-vs-option compare, and **picking an option from the board** (owner 2026-09-29: "Yes, in
SB-21"; write mode: commit just that file). Owner: keep drafts / ready-to-build where they are (the Stories tab's
Draft and To do chips, SB-15) — no new lists.

## Story
As the owner, I want one gallery of every story's mockups with a full-screen viewer, side-by-side compare and a Pick
button, so that I can review and pick design directions on the board instead of opening HTML files one by one.

## Why
Mockup sets live in each project's `docs/mockups/<ID>/` and are opened by path; picks are recorded by hand in the story
file. Nothing shows which sets still await a pick.

## In scope
- A **Mockups** link in the sidebar → `/mockups`: every mockup set across enabled projects, grouped by project, each
  showing story ID, title, option count, and state — **awaiting pick** or **picked (a/b/c)** with the recorded reason
  (read from the story's `## Design mockup gate`). Awaiting-pick sets first. A per-project filter.
- A **Mockups** button on a story's detail (SB-8 modal) and on the project page when that project has sets.
- **Full-screen viewer** `/mockups/{project}/{story}`: a small title bar (ID + title), one-line description (from the
  set's `index.html` or the story's Story line), **Where** (the route or page the story names), the picked badge, an
  option switcher (a/b/c), Esc / back to the gallery.
- **Compare**: two panes side by side in one screen, any option vs any option; below 768 px the panes stack with a
  toggle.
- Mockup HTML is read at the project's ref through `GitReader` and served from a board route inside a sandboxed iframe
  (`sandbox="allow-scripts"`, no same-origin), so a mockup cannot read the board.
- Empty state: "No mockups yet" with where they come from (`docs/mockups/<ID>/`).
- **Pick from the board.** In the viewer, a set awaiting a pick shows **Pick a / b / c** with a one-line reason field.
  Confirming rewrites that story file's `- Chosen option:` and `- Why I chose it:` lines (reason prefixed
  `owner pick <date> (board)`) and commits **only that file** on the project's current branch as
  `docs(<ID>): record mockup pick <x>`. Never pushes. The gallery then shows it as picked.
- **Guards — refuse and say why, writing nothing,** when: the file has uncommitted changes; the checkout's current
  branch is not the branch the board reads for that project; the story has no `## Design mockup gate` section or no
  `- Chosen option:` line; the story already has a pick (re-picking is out of scope); a git operation (rebase/merge)
  is in progress. The reason is one line, newlines stripped, max 200 characters.
- All writes go through one gateway, `StoryPickWriter` — the only code in the board that writes to a registered
  project (same single-gateway rule as `GitReader` / `ProductionReader`). New ADR: the board writes, narrowly.

## Out of scope (do NOT build)
- The **Current** pane (SB-23) and the app map (SB-24).
- Re-picking or clearing an existing pick; "Neither / park" from the board. Pushing. Writing any other file.
- Editing mockups. Any new drafts / ready-to-build lists (owner: keep the SB-15 chips).

## Acceptance criteria (executable — these become the Pest test names)
- Given two projects with mockup sets, when `/mockups` loads, then both are listed grouped by project with story ID,
  title and option count.
- Given a story whose mockup gate has no chosen option, then its set shows "awaiting pick" and is listed first.
- Given a story with `Chosen option: b` and a reason, then its set shows "picked b" and the reason.
- Given a set, when the viewer opens, then it shows the ID, title, description, Where and option a in a sandboxed
  iframe; switching to c loads option c without a full page load.
- Given compare with a and c, then both render side by side in one screen; at 375 px they stack with a toggle.
- Given a mockup file requested at a path outside `docs/mockups/<ID>/` (e.g. `../../.env`), then the route returns 404
  and nothing is read.
- Given a disabled or unknown project, then its sets are not listed and the viewer returns 404.
- Given no project has mockups, then `/mockups` shows the empty state and returns 200.
- Given a story awaiting a pick, when b is picked with reason "cleaner table", then the story file's Chosen option
  reads b and its reason line contains "cleaner table", one commit `docs(<ID>): record mockup pick b` touching only
  that file exists on the current branch, and nothing is pushed.
- Given another file is staged in the project, when a pick is committed, then the staged file is still staged and not
  in the pick commit.
- Given the story file has uncommitted edits, then the pick is refused with "this story has unsaved changes" and the
  file is unchanged.
- Given the project's checkout is on a different branch than the board reads, then the pick is refused naming both.
- Given a story that already has a pick, then no Pick button shows, and a forged pick request is refused.
- Given a reason with newlines or over 200 characters, then it is written as one line, truncated.
- Given the gallery or viewer loads, then `board.mockups_viewed` / `board.mockup_viewed` is logged with `project`,
  `story`, `option`; a pick logs `board.mockup_picked` (`project`, `story`, `option`, `commit`) or
  `board.mockup_pick_refused` (warning; `project`, `story`, `reason`).

## Applicable standards
- Design: design-A shell and sidebar (SB-7); chosen mockup option below; tokens are law. Viewer and compare toggles are
  Alpine (no round-trip for UI state).
- Codebase/DB: no schema change. Reads go through `GitReader` (`listFiles`, `show`) at the project's ref — ADR-004.
  File route whitelists `docs/mockups/<ID>/*.html` (and assets beside them) by exact listing, never by path join.
  Served with `Content-Security-Policy: sandbox allow-scripts`. `StoryPickWriter` edits the two lines in place (no
  reformatting), commits with `git commit --only -- <path>` and a fixed message; git runs through the same process
  wrapper as `GitReader::run` with timeouts. A pick request is a Livewire action with the story ID re-validated
  server-side.
- Logging: `board.mockups_viewed` (info; `sets`, `awaiting`), `board.mockup_viewed` (info; `project`, `story`,
  `option`, `compare`), `board.mockup_file_refused` (warning; `project`, `path`).

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/SB-21/option-{a,b,c}.html (gallery, full-screen viewer, compare incl. the SB-23 Current pane)
- Chosen option: a
- Why I chose it: owner pick 2026-09-29 (/story mockup gate), no reason given. Direction: grid + lightbox — cards with
  live thumbnails grouped by project, awaiting-pick first, filter by status/project and search; a card opens a
  full-screen lightbox with title, description, Where, Current/A/B/C tabs and a width switch; "Side by side" splits
  into two panes each with its own picker and a swap. Entry from a story: "Open in mockup gallery" on the story modal
  (SB-8). The Current tab is filled by SB-23; until then it shows the placeholder.

## Do NOT touch
- `app/Services/GitReader.php` beyond adding a read method if one is missing (reads only). `config/`. Any registered
  project's files **except** a story file's two mockup-gate lines, written only by `StoryPickWriter`.

## Data & interfaces
- Schema/migrations: none.
- Routes: `GET /mockups` (`mockups`), `GET /mockups/{project}/{story}` (`mockups.show`),
  `GET /mockups/{project}/{story}/file/{file}` (`mockups.file`). Livewire: `MockupGallery`, `MockupViewer`. Action:
  `ReadMockupSets`. Service: `StoryPickWriter`. Sidebar gains Mockups (with the awaiting count). `docs/UI-INVENTORY.md` updated.

## Test plan
- Pest: failing tests from the acceptance criteria first; fixture repos with `docs/mockups/` committed.
- Journey test: none.
- Browser check: `/mockups` → SB-16 → option a → compare a vs c → Esc back to gallery. Pick against a throwaway
  fixture repo only — never pick on a real project during tests.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-7, SB-8 · Next: SB-23 (Current pane), SB-24 (app map)
