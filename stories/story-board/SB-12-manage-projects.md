# SB-12 — Manage projects from the board
Status: approved         Journey: none
Source: owner 2026-09-29 (/story): *"how do we get a new project into this system if i create a new one?"*
and *"a way to add or remove projects easily from a UI … if i do some one off wegbsite changes, i dont need
to see that on a main dashboard, id like to toggle some projects on and off entirely"*. Owner answer: off
means hidden everywhere and not refreshed, with its data kept.

## Story
As the owner, I want a page where I can add a project by its folder, switch any project off or on, and
remove one, so that the board shows only the projects I care about without touching the command line.

## Why
Today a project is added with `board:project add` and can be disabled but not re-enabled. A one-off
website does not belong on the main dashboard, and switching it off should be one click and reversible.

## In scope
- A `/projects` page, "Manage projects", linked from the SB-7 sidebar slot. It lists every project,
  enabled or not, with: an on/off switch, path, ref, state, on-ref story count and "refreshed N ago".
- **Switch off**: sets `is_enabled = false`. The project leaves the sidebar, both dashboards, every count
  and page-load refresh. Its rows and snapshot are kept. It takes effect at once, with no confirmation
  (it is reversible), and a toast reads "coins switched off".
- **Switch on**: sets `is_enabled = true` and queues one `RefreshProjectJob`. The toast reads "coins
  switched on".
- **Add project**: a form with path (required, `~` expanded), name (optional, defaults to the folder
  name) and ref (default `origin/main`). "Add project" runs the existing `RegisterProject` action and
  queues a first refresh. Errors show inline under their field. The toast reads "Project added".
- **Remove**: a confirmation modal: "Remove acme-site? Its stories and history are deleted from the board.
  The project's folder and git are not touched." "Remove project" deletes the project row and cascades
  its stories and locations (board DB only). The toast reads "Project removed".
- `board:project enable <name>` joins the existing `disable`, so the CLI can undo what it does.

## Out of scope (do NOT build)
- Editing a project's path or ref after adding it (remove and add again).
- Aliases (`board:project alias` stays CLI-only).
- Any auth. The board is localhost-only. If SB-6 (hosted) is revived, this page needs auth first.
- Anything that writes to a project's folder or git.

## Acceptance criteria (executable — these become the Pest test names)
- Given 4 enabled projects and a disabled `acme-site`, when `/projects` loads, then all 5 are listed and
  acme-site's switch is off.
- Given coins is on, when its switch is turned off, then `is_enabled` is false, coins leaves the sidebar
  and `/`, its stories are still in the DB, and `board.project_disabled` is logged with `source=ui`.
- Given coins is off, when its switch is turned on, then `is_enabled` is true, one `RefreshProjectJob`
  for coins is queued, and `board.project_enabled` is logged.
- Given a fixture repo path, when it is added with no name, then a project named after the folder exists
  in state `pending`, a refresh is queued, and `board.project_registered` is logged.
- Given a path that is not a git repository, when it is added, then no project is created, the path
  field shows "That folder is not a git repository.", and `board.project_add_refused` is logged with
  reason `not_a_repo`.
- Given a path or name already registered, then the matching field shows "Already on the board as
  <name>." and `board.project_add_refused` is logged with reason `duplicate`.
- Given the ref `-x` or `a..b`, then the ref field shows "That is not a valid git ref." and
  `board.project_add_refused` is logged with reason `bad_ref`.
- Given Remove is clicked for acme-site, then nothing is deleted until "Remove project" is confirmed in
  the modal. Cancel leaves it untouched.
- Given the removal is confirmed, then the project, its stories and its locations are gone from the DB,
  the project folder's `git status --porcelain` is byte-identical before and after, and
  `board.project_removed` is logged with `project` and `stories` (count).
- Given `php artisan board:project enable acme-site`, then it is enabled and one refresh is queued.
  Given `board:project enable nope`, then it exits non-zero with "No project named nope".

## Applicable standards
- Design: design-A Manage projects view (SB-7 gate). Add is a simple form of 3 fields, so it sits inline
  on the page (§Canonical interaction patterns). Remove is destructive, so it goes through a confirmation
  modal. The switches are Livewire because they write data. Loading: each switch and button disables
  while its request runs.
- Codebase/DB: no migration. `RegisterProject` is reused unchanged for validation. Removal uses the
  existing cascades, in one transaction. L-5: each refusal gets a test and a log line.
- Logging: `board.project_registered` (existing), `board.project_disabled` (existing, plus `source` =
  `ui|cli`), `board.project_enabled` (info; `project`, `source`), `board.project_removed` (warning;
  `project`, `stories`), `board.project_add_refused` (info; `path`, `reason` =
  `not_a_repo|duplicate|bad_ref|missing_path`).

## Design mockup gate
- Mockups: covered by docs/mockups/SB-7/option-a.html, view 4 (Manage projects).
- Chosen option: a (SB-7's gate)
- Why I chose it: "A is the best design" (owner, 2026-09-29).

## Do NOT touch
- Any registered project's files. `app/Services/GitReader.php` (reuse `isRepository()` through
  `RegisterProject`).

## Data & interfaces
- Schema/migrations: none. `projects.is_enabled` already exists.
- Route `GET /projects` (named `projects.manage`), Livewire `ManageProjects`. `BoardProject` command gains
  `enable`. `docs/UI-INVENTORY.md` updated (confirmation modal, switch).

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: switch rent-track off and see it leave the sidebar and `/`, then switch it back on and
  see "switched on" and a refresh in the log. Add `~/Code/story-board` with ref `main` and see it appear,
  then remove it through the confirmation.
- Checked against real data (2026-09-29): `board:project` actions today are `add`, `alias`, `list` and
  `disable`; there is no `enable`. All 4 projects are enabled, and story-board itself is not registered
  (it has no `origin`, so it needs ref `main`).

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-7
