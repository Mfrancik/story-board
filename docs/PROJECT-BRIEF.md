# Project brief — story-board
Last refreshed: 2026-10-12 (MK-9)

## What it is, and for whom
A localhost Laravel app for one owner, with no auth (removed in RD-1). It reads the `stories/` folder of
every project that uses the dev-standards kit, from git at each project's ref (default `origin/main`), and
shows in one place what state each project's stories are in. Its one write into a project is a mockup pick
(from the gallery or the story page): two lines of one story file, one local commit, never pushed.

It also shows:
- Each project's production numbers, read-only from its production MySQL once a night, as a headline and trend on its home tile.
- Work not on the ref yet (unmerged branches, worktrees, untracked files), labelled with where it lives. It never
  counts as status.
- Live Claude Code sessions (transcript metadata, never messages); each project's handbook and its drift from the
  kit; its preflight runs, from the kit meter's cost CSVs under `~/.claude/projects`, and where each ran (brain or
  Mac) from per-run JSON records beside them, once RD-10 writes them.
- A clickable map of each project's journeys, from its journey docs, stories and mockups, with screenshots from
  the project's own journey tests (`journeyStep()`).
- A per-project **Marketing** area (MK initiative, being built): recurring campaigns and their posts, stored in the
  board's own database. MK-1 lists the campaigns with how far out each is approved and what needs the owner; MK-2
  holds each project's brand, favourite photos and copy rules, seeded for StackerMint by `marketing:seed-kit`; MK-7
  gives each campaign a page to create, edit and switch it and to see its next 8 dates and past posts; MK-8's Fill
  has Claude draft 3 captions per open date on the queue; MK-9's post studio is the one modal where a date's drafts
  are flipped, revised, voted on, given a photo and approved, and approved posts are copied and marked posted.

## Domain model
- **Project**: a registered checkout (`name`, `path`, `ref`, `is_enabled`). Off hides it everywhere and stops its
  refreshes but keeps its rows. It also holds refresh `state` (`pending | ok | stale | unreachable`), `sha` /
  `indexed_at`, and `refresh_attempted_at`.
- **Story**: one story file as reported by the kit's `bin/story-index`, plus `is_parked` and `dated_on`. Text and
  mockups are read from git at the row's SHA on demand. `location_kind` is null on the ref, else
  `branch | worktree | untracked`.
- **ProjectLocation**: another checkout of a project, either an `alias` (added by hand) or a `worktree` (found on refresh).
- **Snapshot**: a project's on-ref Story rows, replaced wholesale on each good refresh and kept when one fails.
  Off-main rows are a second snapshot.
- **Not stored, read on demand**: live sessions (transcripts changed in the last 10 min), preflight runs (cost-CSV
  rows, matched to `preflight-runs/*.json` records by branch and time), the kit (`board.kit_path`), mockup sets (`ReadMockupSets`), journey maps (`ReadJourneyMap`), journey
  shots (manifest lookup, `ReadJourneyShots`), and current version (`ReadCurrentVersion`).
- **ProdConnection / ProdMetric / ProdSnapshot**: one read-only production connection per project. A metric is
  a preset or a custom `SELECT`. A snapshot is one value per metric per local day.
- **Gateways**: `GitReader` runs every git read, including `remotes()` and one fixed `log` form (RD-7). `ProductionReader` opens every production
  connection. `StoryPickWriter` makes every write to a project, and only writes; both pick surfaces reach it
  through `PickMockup` (RD-3).
- **MarketingCampaign / MarketingPost** (MK-1): a project's weekly campaign (days, wall-clock time, timezone, on/off,
  photo mode) and its posts (`draft | approved | posted | skipped | discarded`, by `scheduled_for`). "Missed" is
  derived, never stored. Posts belong to a campaign date by local day.
- **MarketingBrand / MarketingAsset** (MK-2): one brand per project (colours, font, radius, shadow, sanitised SVG
  logo, voice, copy rules, the kit's idea menu as an ordered list) and its files (`photo | inspiration`) on the
  local disk under `marketing/{project}/`, served only under their own project.
- **MarketingPostVersion / MarketingFeedback** (MK-8): a post's earlier versions (the post row is always current)
  and the owner's taste (likes, dislikes, revise notes), read back into prompts as examples. AI posts carry
  `generation_meta` (model, tokens, micro-USD cost) and `lint` warnings. Drafting state is cache only.
- **MarketingPostNetwork** (MK-9): where a post went out (`facebook`, with the link from Mark as posted) for phase 2's
  engagement. Posts also carry `is_text_only`; a skipped open date is an empty-caption `skipped` post.
- **Shared rules** (pure, RD-1): `StoryStatusOrder` holds the status order, the open statuses and the
  initiative rollup. `MockupGateParser` reads a story's mockup gate.

## Features
27 entries: 22 active, 5 retired by RD-9 (old dashboard, old sidebar, story modal, old `/p/{project}`, `/prod`; their docs
stay as history, and [retired-pages.md](features/retired-pages.md) maps what replaced each). Every page is in the dark
shell. `/` is the home overview (RD-5) with a project filter (RD-6), project tiles and Shipped this week (RD-7); the
Preflight page and tiles say where each run ran (RD-8). `/p/{project}` is the project overview (RD-11): a tile grid,
Overview first in the project tabs, the sidebar's landing page, and Refresh this project; RD-12 adds git activity
tiles (commits per day, last edits with the uncommitted count, most-edited code files today). `/p/{project}/marketing`
is the Marketing tab's campaigns list (MK-1), last in the tabs with a count of posts to approve, with its
Settings page (MK-2: brand, favourites, copy rules) and a page per campaign at `/marketing/campaigns/{slug}` (MK-7)
with AI Fill per date (MK-8); every campaign date and list badge opens the post studio (MK-9). Each one's route, summary and doc: [INDEX.md](INDEX.md); the
stories behind it: its doc's header line.

## Journeys
- [marketing-post](journeys/marketing-post.md): campaign to approved posts. Steps 1–5 (MK-1, MK-2, MK-7, MK-8, MK-9) are built,
  and steps 6–7 (MK-10, 18) are approved. Every SB and RD story is `Journey: none`.

## Stories
- **built**: SB-2–5, SB-7–12, SB-14–19, SB-21–25 · RD-1 clean foundation · RD-2 dark shell and sidebar ·
  RD-3 short story page · RD-4 re-skin kept pages · RD-5 home overview · RD-6 home project filter · RD-7 project tiles and Shipped · RD-8 preflight "Ran on" · RD-9 retire old pages · RD-11 project overview · RD-12 project git activity
- **built (marketing)**: MK-1 campaigns list and marketing schema · MK-2 brand kit, favourite photos, copy rules · MK-7 campaign page · MK-8 AI fill and revise · MK-9 post studio
- **approved**: MK-10 (phase 1, next) · MK-18
- **draft**: SB-20 preflight run errors · RD-10 preflight run record (parked)
- **draft (parked)**: SB-6 the board on an always-live domain
- **cut**: SB-13 pick a mockup (SB-21's pick covers it)

SB-1 (the `bin/story-index` parser) lives in the dev-standards kit, not in this repo.

## Key decisions
- Git: all through `GitReader` (allow-list, untracked read only at refresh); snapshots replaced wholesale, stale on fetch failure, refreshed on the queue; story text read on demand → ADR-004–007, ADR-012
- Mockups served from git in a CSP sandbox, an unparsed choice quoted; off-main is what a branch changed since its merge-base, parsed by the kit → ADR-008–011
- Unknown or disabled projects are refused before route binding, and pages re-check on update → ADR-013. Retired with RD-9 (history): ADR-015–020
- Manage projects re-navigates after writes, Add re-runs `RegisterProject`'s checks; live sessions read transcripts defensively → ADR-021–023, ADR-034
- Home overview: Needs me lists each story once; Live cards summarise the latest tool call; panels fail alone; one `ProjectFilter` from `?projects=`; one fixed `git log` form; Shipped is build commits cached per snapshot; tiles use stored data → ADR-047–054
- Handbook loads sections lazily; Stories is one query filtered with CSS; preflight history reads cost CSVs by folder name, server-rendered and recomputed in Alpine; "Ran on" is a per-run JSON record → ADR-024–028, [ADR-055](decisions/ADR-055-preflight-run-record-format.md)
- Production only through `ProductionReader`, outcome cached, snapshot in-process → ADR-029–031. The board writes narrowly: one pick, one file, one local commit, via one `PickMockup` → [ADR-032](decisions/ADR-032-the-board-writes-narrowly-one-pick-one-file-one-commit.md), ADR-042
- App map generated server-side; journey shots a pinned-manifest test helper; "New page" read from route files → ADR-033, ADR-035, ADR-036
- RD foundation: starter kit and Fortify removed; kept logic in pure shared classes; dark shell scoped by `.shell`; refresh-on-load is one middleware; short story page in place → ADR-037–046
- RD-9: mockup file route kept for `?v`; coverage ported before a test goes; `/?project=` ignored → ADR-056–058. RD-11 overview: `ReadPreflightRuns::pairs()`, refresh completion from the attempt stamp, tiles reuse home's readers → ADR-059–061. RD-12: git activity through fixed `log`/`status` forms (no textconv, `status` listings only); activity cached as plain values, failures never → ADR-081, ADR-082
- Marketing (MK-1): posts match campaign dates by local day; approved-through lets skipped dates continue the run; "to approve" counts dates; tab, banner and badges share one count → ADR-062–064. MK-2: idea menu an ordered list, seed never overwrites,
  SVG logos sanitised with PHP's DOM and previewed from saved values → ADR-065–067. MK-7: the timeline reuses the runway's
  rules and a reschedule moves no posts; URL is a project-scoped slug fixed at creation; Unsaved changes is Alpine against a locked snapshot → ADR-068–070. MK-8: drafting state in the cache; draft job
  once and unique per date; all-or-nothing drafts, cost in micro-USD; one prompts service and call shape → ADR-071–074. MK-9: one studio component opened by events; "to approve" one rule for list and studio;
  skip writes a placeholder, Restore undoes it; Text only is stored; one vote per post, only Revise makes a version; an unchanged rebuild is undone and explained → ADR-075–080
- Preflight audit scope, audit cost record, build-artifact disposal (kit) → ADR-001 to ADR-003

## Current phase and what's next
Phase 3, the redesign (RD). RD-1 to RD-9, RD-11 and RD-12 are built: foundation, dark shell, short story page, every
kept page in the shell, the home overview with its filter, tiles and Shipped this week, the reading side of preflight
"Ran on", the deletion of every page the redesign replaced, and the project overview at `/p/{project}` with its git
activity tiles. No other RD story is approved. RD-10 (preflight run record writer) is parked. Dead code RD-9 left is
backlog F-15. Open for the owner: the home overview's project tiles still link to Stories, not the project overview.

Marketing phase 1 has started ([stories/marketing/README.md](../stories/marketing/README.md)). MK-1, MK-2, MK-7, MK-8 and MK-9 are built;
next is MK-10 (calendar and library, which also gives one-off posts a way back into the studio), then MK-18. Owed
by the owner: set `ANTHROPIC_API_KEY` and do the real-key checks: MK-8's (Fill one coins date, read the captions,
note the cost) and MK-9's (open the studio, flip, revise, approve two dates, Copy caption, one real photo upload). A campaign
can still be saved with no topics; Fill then drafts from the brief alone. The owner runs `php artisan migrate` and `marketing:seed-kit`
for coins (see the settings doc). Facebook publishing is phase 2.

Still open from phase 2: the owner confirms SB-19's checkbox look; `journeyStep()` is adopted in coins, followed
by the real-data check of the app map and Current pane; the `--text-thumb` token decision.

Backlog: [BACKLOG.md](BACKLOG.md).

## Known limitations
- Laravel 13 / Livewire 4 are installed, while `CLAUDE.md` says 12 / 3. Flagged, unchanged.
- Unused starter-kit leftovers (passkeys JS and npm dependency, `withTwoFactor()`, `users` / `passkeys` tables,
  `two_factor_*` columns) are left for the owner (RD-1).
- Refresh needs a queue worker (`composer run dev` starts one; a failing project retries at most every 5 min).
  Production is read only by the nightly `board:prod-snapshot`, which needs `schedule:work` (not started by it).
- Until the kit parser reads `Chosen option: **B**` (F-1), "Awaiting a pick" over-reports on the ref.
- No page lists off-main work since RD-9: tiles count it and a story page shows its versions. Untracked stories and
  mockups cannot be shown. `?v=` links break at the next refresh.
- Story summaries mostly fall back to the first sentence of `## Story` until the kit writes `Summary:` (F-6).
- Status-chip, status-bar and project-tabs still carry dead `dark:` tones beside `shell:` ones (F-15). The shell
  sidebar has no live badge and no Manage projects link (reachable from `/` only via the no-projects empty state).
  Handbook and app-map error copy still says "Refresh the project from its dashboard", which no longer exists.
- `/projects` has no auth, holds production credentials, and is safe only while localhost-only.
  SB-6 must add auth first. A project's path and ref cannot be edited.
- Tiles show only the first production metric; Shipped this week lists only `feat|fix|test(<ID>)` commits and can
  lag a new commit by up to 5 min (cached per snapshot).
- Live sessions rely on an undocumented transcript format and a 10-minute mtime. A home
  card shows a session's Bash command line (first line, 120 chars), accepted for localhost only (ADR-048); a 1M context is inferred.
- The handbook compares against the kit checkout's last-fetched ref. Its real-data check is pending.
- Production SSL is not certificate-verified, and rotating APP_KEY breaks stored credentials. The coins
  production check awaits the owner's read-only user.
- Preflight history has no test counts or verdict (F-5). "Ran on" and the project overview's verdict read "—"
  everywhere until RD-10 (parked) writes run records; matching is by branch and a 10-minute window, so a mismatched branch or clock stays unknown. Its median and number formats exist in both PHP and
  JS. Its hidden columns are saved per browser.
- Flux reads `localStorage` unguarded, so a browser that blocks site data errors on every page (RUNBOOK).
- A board pick shows at once in the gallery and on the story page, but elsewhere only after a push and refresh. Its `--no-verify`
  awaits owner confirmation. "New page" can be wrong for routes not in `routes/*.php`.
- The app map's page weight is its cost driver (coins: about 3.1 MB). Shots are read from the working tree,
  not the ref. Journey shots must not run under `--parallel`.
- Marketing: past posts are not paginated; a one-off post cannot be reopened once the studio closes (until MK-10);
  Save to Inspiration is hidden until MK-18 and Download until phase 3 renders text cards. Fill needs `ANTHROPIC_API_KEY`, a queue worker and a shared cache; AI cost uses a price table in code. The tab's count
  is computed on every project page render, so a campaign with a bad timezone logs a warning on each render.
  Browser tests cannot upload files (Pest's server drops them), and a real photo upload is not yet browser-checked.
- The dev server runs on port 8010. By owner ruling, this repo has no git remote.
