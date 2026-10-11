# RD-6 — Home project filter
Status: built            Journey: none
Source: owner 2026-10-10: *"on main dashboard id like the ability to filter whatever i need, by default unfilted.
id filter by project."*

## Story
As the owner, I want to narrow the home overview to one or more projects, so that I can focus on one project
without leaving the command center.

## Why
With several projects running, the overview mixes everything. The default stays everything; the filter narrows it.

## In scope
- A **Projects** filter in the home header: "All projects" by default; a list of enabled projects with checkboxes;
  choose one or more. A "Clear" link returns to all.
- Every home panel follows it: the headline figures, Needs me, Live now, and, once RD-7 lands, the tiles and
  Shipped this week.
- The choice lives in the URL as `?projects=story-board,coins` (Livewire `#[Url]`), so a filtered view can be
  bookmarked and Back works.
- With a filter on, the header shows the chosen project names as removable chips.

## Out of scope (do NOT build)
- Filters other than project (status, kind and date can come later).
- Saving a default filter.
- Changing the sidebar's project links (they still open the project's pages).

## Acceptance criteria (executable — these become the Pest test names)
- Given no `projects` parameter, then every panel covers every enabled project.
- Given `?projects=story-board`, then Needs me, Live now and the headline figures count only story-board.
- Given two projects chosen, then each panel covers exactly those two.
- Given story-board chosen, when its chip is removed, then the URL drops it and the panels widen again.
- Given `?projects=nope`, then the unknown name is ignored, the page shows all projects, and a warning is logged.
- Given a disabled project named in the URL, then it is ignored the same way.
- Given the filter changes, then the page updates without a full reload and the URL matches the choice.

## Applicable standards
- Design: RD-2 tokens; the filter is a dropdown with checkboxes, keyboard reachable, 44 px targets.
- Codebase/DB: one filter value passed into each home action (no per-panel copies of the rule). No migrations.
- Logging: `board.overview_filtered` (project names); `board.overview_filter_ignored` (warning) for unknown or
  disabled names.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: none needed. A header control on the approved Home A board, in the filter style already used on the
  Preflight page. If the owner wants to see it first, add it to the canvas board before building.
- Chosen option: Home A header plus the existing filter style (owner, 2026-10-10)
- Why I chose it: "by default unfiltered. id filter by project."

## Do NOT touch
- `RedirectProjectFilter` (the legacy `/?project=` redirect; removed in RD-9). Use `projects`, not `project`.

## Data & interfaces
- Schema/migrations: none.
- The overview component gains a `projects` URL property; the home actions take an optional project-name list.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: choose one project, confirm every panel narrows and the URL changes; press Back and confirm all
  projects return.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: RD-5
