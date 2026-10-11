# RD-4 — Re-skin the kept pages
Status: built            Journey: none
Source: owner 2026-10-10: *"i do want the rules, lessons, standards, run book decisions and skill ssection in the
handbook section. i do like how we have stories currently also on the current version, wec an keep that, preflight
as well, this layout is good"*; *"we can keep what we built for mockups too"*; on /projects: "Re-skin it for now".

## Story
As the owner, I want the pages I'm keeping moved into the new shell with their layouts unchanged, so that the whole
board is one product while the parts that already work stay as they are.

## Why
These pages work and the owner likes their layouts. Only their look and their links into scrapped pages change.

## In scope
- Into the RD-2 shell, restyled with its tokens, **layout and behavior unchanged**:
  - Stories by initiative (`/p/{project}/stories`): drop the embedded story modal and the `?story=` links; each
    story row links to the RD-3 story page.
  - Handbook (`/p/{project}/handbook`): rules, lessons, standards, runbook, decisions and skills, kit badges included.
  - Mockup gallery (`/mockups`) and its full-screen viewer, Current pane and Pick included.
  - Manage projects (`/projects`): on/off switch, add by folder, remove behind the confirmation, and the
    production connection and metric settings.
  - App map (`/p/{project}/map`): shell only, as is (to be redesigned later).
- The project header and tabs (Stories · Preflight · Handbook · Map) restyled; the old "Dashboard" tab removed.
- Reused Blade primitives restyled in place: `status-chip`, `status-bar`, `state`, `project-header`,
  `project-tabs`, `prose`, `kit-badge`, `handbook-empty`, `mockup-state`, `mockup-current`, `confirm-modal`, `switch`.

## Out of scope (do NOT build)
- New features on these pages, or layout changes beyond the shell.
- The App map redesign.
- Deleting the old project dashboard, home or modal (RD-9).

## Acceptance criteria (executable — these become the Pest test names)
- Given each kept page, when it renders, then it uses the new shell and its project is current in the sidebar.
- Given the Stories page, then no story modal is on the page, and each row links to `/p/{project}/s/{id}`.
- Given the project tabs, then they are Stories, Preflight, Handbook and Map, with no Dashboard tab.
- Given the existing feature tests for Stories, Handbook, Mockup gallery, Mockup viewer, Manage projects,
  Production connection and App map, then all still pass (selectors updated only where markup changed).
- Given each kept page at 375 px, then nothing scrolls sideways except tables in their own scroll box (browser test).

## Applicable standards
- Design: RD-2 tokens; design-standards §Reuse before build (restyle the existing primitives, do not fork them).
- Codebase/DB: no logic changes; views and Blade components only, plus the link change on Stories.
- Logging: existing events unchanged.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: none. The owner kept the current layouts ("this layout is good"); the look comes from the RD-2 tokens
  approved on the canvas.
- Chosen option: keep the current layouts, restyled with RD-2 tokens (owner, 2026-10-10)
- Why I chose it: "i do like how we have stories currently … preflight as well, this layout is good".

## Do NOT touch
- The kept actions and readers (`ReadProjectStories`, `ReadHandbook`, `ReadMockupSets`, `StoryPickWriter`, the
  prod connection actions, `ReadJourneyMap`).

## Data & interfaces
- Schema/migrations: none.
- Views only, plus the Stories row link.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: visit every kept page at 1440 px and 375 px; Stories row → story page; gallery pick still works in
  a scratch project; /projects switch, add and remove still work.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: RD-2, RD-3
