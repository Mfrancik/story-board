# SB-7 — App shell and project switcher
Status: built            Journey: none
Source: owner 2026-09-29 (/story): *"a bettetr way of switching between projects … a view like this of
all projects, but also a view of a sepcific single project. lets focus on some UI stuff here to make this
more organized."*

## Story
As the owner, I want a persistent sidebar that lists every project and switches between an all-projects
view and one project's view, so that moving between projects is one click instead of a filter dropdown.

## Why
Today switching project means the filter bar's `project` dropdown on `/`. With four projects, soon more,
the board needs a stable frame: where am I, what else is there, and how do I get there.

## In scope
- The design-A shell (`docs/mockups/SB-7/option-a.html`): a left sidebar holding, from top to bottom:
  - A search box with a ⌘K / Ctrl+K hint that filters the project list as you type (Alpine, no round trip).
  - "All projects" → `/`.
  - One entry per **enabled** project, alphabetical: a state dot (`ok|pending|stale|unreachable`),
    the name and its on-ref story count. Links to `/p/{project}`.
  - A "Manage projects" slot at the bottom. It is hidden until SB-12 ships the page.
- Below 768px the sidebar is a slide-in drawer behind a menu button (`aria-label="Open projects"`),
  closed with Escape, the close button or the backdrop.
- A new route `/p/{project}`, the single-project page. Until SB-10 replaces it, it renders today's home
  content scoped to that project (the existing `project` filter, fixed to the route's project).
- `/?project=coins` (today's filter URL) redirects to `/p/coins` with a 301, so old links keep working.
- The current entry is marked with `aria-current="page"`. Links use `wire:navigate`.
- The story page `/p/{project}/s/{id}` renders inside the same shell, with its project marked current.

## Out of scope (do NOT build)
- New dashboard content (SB-9, SB-10), the story modal (SB-8), live badges (SB-11) and the Manage
  projects page (SB-12). This story builds the frame they fill.
- A global story search in the ⌘K box. It filters projects only.

## Acceptance criteria (executable — these become the Pest test names)
- Given 4 enabled projects and 1 disabled one, when `/` loads, then the sidebar lists the 4 enabled
  projects alphabetically with their story counts, and not the disabled one.
- Given `/p/coins`, when it loads, then the coins entry has `aria-current="page"` and the page shows only
  coins stories.
- Given `/p/nope` (no such project), then the response is 404 and `board.project_page_refused` is logged
  with reason `unknown`.
- Given a disabled project `acme-site`, when `/p/acme-site` loads, then the response is 404 and
  `board.project_page_refused` is logged with reason `disabled`.
- Given `/?project=coins`, then it redirects 301 to `/p/coins`. Given `/?project=nope`, then it
  redirects to `/`.
- Given a project in state `stale`, then its sidebar dot uses the warning tone and carries a text label
  for screen readers ("stale").
- Given a 375px viewport, then the sidebar is hidden, the menu button opens it, and Escape closes it.
- Given the story page for coins MOB-65, then it renders inside the shell with coins marked current.

## Applicable standards
- Design: design-A shell as chosen. Status and state colours come from the theme tokens in
  `resources/css/app.css` (the SB-3 tokens), and any new colour becomes a token there. Sidebar and
  drawer toggling is Alpine only (§Component hierarchy 4). Four states: an empty sidebar ("No projects
  yet") links to the SB-12 page once it exists, otherwise gives the `board:project add` command.
- Codebase/DB: no schema change. The route binds `{project:name}` and refuses a disabled project in one
  place, reused by the SB-4 story route. L-5: both refusals get a test and a log line.
- Logging: `board.project_viewed` (info; `project`), `board.project_page_refused` (info; `project`,
  `reason` = `unknown|disabled`).

## Design mockup gate
- Mockups: docs/mockups/SB-7/option-{a,b,c}.html. Each shows all projects, a single project, the story
  modal and Manage projects, so this one gate covers SB-7 to SB-10 and SB-12.
- Chosen option: a
- Why I chose it: "A is the best design" (owner, 2026-09-29). Sidebar command center: a persistent
  sidebar switcher, "What needs me" cards first, a centered two-column story modal.

## Do NOT touch
- `app/Services/GitReader.php`, `app/Actions/Board/RefreshProject.php`, any registered project's files.
- `config/`.

## Data & interfaces
- Schema/migrations: none.
- Routes: `GET /p/{project:name}` (new, named `projects.show`); `/` keeps `home`.
- Livewire/Blade: `layouts/board` gains the sidebar; new `board/sidebar` Blade component (a sidebar is
  new UI; no existing component lists projects as navigation). `docs/UI-INVENTORY.md` updated.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: at 1280px open `/`, click coins in the sidebar and see `/p/coins` with coins
  highlighted, then type "cli" in the sidebar search and see only client-dashboard. At 375px, open and
  close the drawer with the button and with Escape.
- Checked against real data (2026-09-29): enabled projects are asset-track 11, client-dashboard 97,
  coins 921 and rent-track 15 on-ref stories (`board:project list` + snapshot counts); `board:project`
  has no `enable` action, so a disabled test project is created in the fixture, not in the dev DB.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-3, SB-4
