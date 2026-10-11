# RD-2 — Dark shell and sidebar
Status: approved         Journey: none
Source: owner 2026-10-10, picked Home A on the redesign canvas: *"A is better"*.

## Story
As the owner, I want every board page in one dark command-center layout with a sidebar listing my projects, so that
the board looks and navigates the same everywhere and I can reach any project in one click.

## Why
The redesign starts from the shell: every later RD page is built inside it. It also takes over the
refresh-stale-projects-on-load trigger, which today lives only in the home and project pages being scrapped.

## In scope
- A new layout and **design tokens** from Home A: graphite ground, raised surfaces, hairline borders, amber accent
  for "needs you", blue for live or running, green for built or GO, red for failed or NO-GO. Body in Geist, figures
  and IDs in Geist Mono, both self-hosted through the build (no runtime Google Fonts). Dark only.
- **Sidebar:** the logo; Home; Mockups with the awaiting-pick count; then **Projects**, every enabled project with
  a state dot and its ID prefix, each linking to its Stories page. The current page is highlighted.
- Below 768 px the sidebar becomes a drawer behind a menu button (Alpine), and the page has no sideways scroll.
- **Refresh on load:** on any board page load, each enabled project whose `needsRefresh()` is true gets one
  `RefreshProjectJob` dispatched. A middleware on the board route group does this.
- **The Preflight page moves into the new shell** as its first user, restyled with the tokens, layout unchanged.

## Out of scope (do NOT build)
- The Brain status card, Money and Brain nav items (later phases).
- Moving any page other than Preflight into the shell (RD-4), and the home overview (RD-5).
- A light theme or theme switcher.

## Acceptance criteria (executable — these become the Pest test names)
- Given two enabled projects and one disabled, when a board page renders in the shell, then the sidebar lists the
  two enabled ones with their state, and the disabled one is not listed.
- Given three mockup sets awaiting a pick, then the Mockups item shows 3; with none, then it shows no count.
- Given the Preflight page, then its sidebar entry for that project is marked current.
- Given a project that needs a refresh, when any board page loads, then exactly one refresh job is queued for it;
  given a fresh project, then none is.
- Given no enabled projects, then the sidebar shows "No projects yet" and a link to /projects.
- Given a 375 px viewport, then the sidebar is hidden behind a menu button that opens and closes it, and the page
  does not scroll sideways (browser test).

## Applicable standards
- Design: design-standards §Tokens are law (all colors and fonts as tokens), §Reuse before build, §Baseline quality
  (44 px targets, focus states, 4.5:1 contrast on the dark ground), §Every interactive view must handle four states.
  Alpine for the drawer (no server round-trip).
- Codebase/DB: one middleware, no migrations.
- Logging: `board.stale_refresh_dispatched` (project) when the middleware queues a job. The job's own events stay
  as they are.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: redesign canvas https://claude.ai/artifact/Ervkp5duofs7VbUbbz7tDg, board "Home A — Command center"
  (sidebar and tokens). Before building, save that board as a self-contained `docs/mockups/RD-2/option-a.html`
  so the repo holds the approved record.
- Chosen option: A (owner, 2026-10-10)
- Why I chose it: "A is better", chosen over Home B (top nav plus a stream).

## Do NOT touch
- `preflight.sh`, `bin/`, `config/`.
- `ReadPreflightHistory` logic (RD-8 changes it).

## Data & interfaces
- Schema/migrations: none.
- New: a board layout, a sidebar component, a refresh middleware on the board routes. Changed: the Preflight view
  extends the new layout.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: open `/p/story-board/preflight` at 1440 px and 375 px; the sidebar, drawer, colors and fonts match
  the canvas board.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: RD-1
