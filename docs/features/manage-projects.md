# Manage projects
Status: active   ·   Last updated: 2026-09-29   ·   Stories: SB-12, SB-17

## Overview
`/projects` ("Manage projects") is where the owner adds a project by its folder, switches any project off
or on, and removes one, without the command line. Switching off hides a project everywhere (sidebar, both
dashboards, counts, page-load refresh) and keeps its data, so a one-off website can leave the dashboard
with one reversible click. It is design A, view 4 (`docs/mockups/SB-7/option-a.html`). Every write is to
the board's own database. Nothing is written to a project's folder or git.

## How it works
**Route.** `routes/web.php` mounts `App\Livewire\Board\ManageProjects` at `/projects`
(`projects.manage`), with no project middleware and no auth (localhost-only). SB-7's sidebar already
rendered its Manage slot only when `Route::has('projects.manage')`
(`app/View/Components/Board/Sidebar.php`), so registering the route switched the slot on, and the empty
sidebar now links to this page instead of printing the `board:project add` command.

**The component.** `app/Livewire/Board/ManageProjects.php`. It holds only the three form fields (`path`,
`name`, `ref` defaulting to `origin/main`). Each action does its write through an action class, raises a
Flux toast and then calls `reload()`, a `redirectRoute('projects.manage', navigate: true)`:
- `setEnabled(int $id, bool $enabled)`: the switch sends the state it wants, not "toggle", so a replayed
  request cannot flip it back. An id that no longer exists (removed in another tab) logs
  `board.project_switch_refused` and toasts a warning. Otherwise it calls `SwitchProject` and toasts
  "coins switched off" / "coins switched on".
- `add()`: clears the error bag, calls `AddProject`. A `ProjectAddRefusedException` becomes
  `addError($e->field, $e->getMessage())`, so the message sits under Path, Name or Ref. Success resets
  the form and toasts "Project added".
- `remove(int $id)`: the one server call behind the confirmation modal. A missing id logs
  `board.project_remove_refused`. Otherwise `RemoveProject`, then "Project removed".
- `render()`: every project, enabled or not, `withCount` of on-ref stories, ordered by name, plus
  `AddProject::home()` so the view can show paths under the home folder as `~/…`.

**Why every action re-navigates.** The sidebar is rendered by `layouts/board`, not by this component, so it
only gains or drops a project on a page load. The reload is `wire:navigate`, and the toast survives it
because the layout now wraps `<flux:toast.group>` in `@persist('toast')`
([ADR-021](../decisions/ADR-021-manage-projects-re-navigates-so-the-sidebar-follows.md)).

**The actions** (`app/Actions/Board/`):
- `SwitchProject::handle(Project, bool $enabled, $source)` updates `is_enabled`. Off logs
  `board.project_disabled`. On logs `board.project_enabled` and dispatches one `RefreshProjectJob`,
  because the snapshot was not refreshed while the project was off. `source` is `SOURCE_UI` or
  `SOURCE_CLI`. `board:project enable|disable` uses the same action, so the CLI and the page cannot drift.
- `AddProject::handle($path, $name, $ref)` trims the inputs, expands a leading `~` / `~/` (PHP's
  `realpath` does not), treats a blank name as the folder's name and a blank ref as `origin/main`. It
  refuses a **blank path before calling anything** (see gotchas), then calls `RegisterProject`
  unchanged, the one place registration is validated, and dispatches the first `RefreshProjectJob`.
  The new project starts in state `pending`.
  When `RegisterProject` throws, `classify()` names the reason by re-running its checks in its order:
  ref (`GitReader::isValidRef`) → folder exists (`realpath`) → is a repo (`GitReader::isRepository`) →
  duplicate. It does not parse the exception's text
  ([ADR-022](../decisions/ADR-022-add-project-classifies-refusals-by-re-running-checks.md)). A path match
  is reported under Path even if the name clashes too, since the folder is the project; the message
  names the existing project.
- `RemoveProject::handle(Project)` counts the project's story rows (on the ref and off it), deletes the
  project row in one transaction, and lets the existing foreign-key cascades delete `stories` and
  `project_locations`. It returns and logs that count.

**The view.** `resources/views/livewire/board/manage-projects.blade.php`, under
`x-data="{ removing: null }"`:
1. A table-like list (a 7-column grid from `md`, stacked below it). One `<li data-project-row>` per
   project: `<x-board.switch>` wired to `setEnabled(id, !is_enabled)`, name and `~`-shortened path, a
   Remove button, ref, state (`board/state` when on, a grey "Off" when off), on-ref story count, and
   "Refreshed N ago" ("Last refreshed" when off, "Not read yet" before the first snapshot). An off row is
   dimmed.
2. The Add project form (`wire:submit="add"`, `novalidate`): Path (required), Name (optional, "Defaults
   to the folder name."), Ref ("The branch the board treats as main."). Each field has `aria-invalid`
   and a `flux:error` tied by `aria-describedby`. The submit button reads "Adding…" and disables while it
   runs.
3. `<x-board.confirm-modal>`: Remove sets `removing = {id, name}` in Alpine (no round trip). The modal
   reads "Remove acme-site? Its stories and history are deleted from the board. The project's folder and
   git are not touched." Only "Remove project" calls `$wire.remove(removing.id)`.

**Shared components** (new, in `docs/UI-INVENTORY.md`):
- `resources/views/components/board/switch.blade.php`: a `<button role="switch">` whose `aria-checked` is
  the stored state. It never toggles itself; the caller's `wire:click` writes, so it always shows what
  the server has. It disables while `target` runs.
- `resources/views/components/board/confirm-modal.blade.php`: an Alpine `role="alertdialog"` driven by a
  caller variable named in `show` (null closes it). Escape, the backdrop and Cancel close it. Focus is
  trapped (`x-trap`) and starts on Cancel, so Enter never destroys by accident. The red button runs
  `action` and disables while `target` runs. SB-13 is expected to reuse it.

**Production column (SB-17).** Each row also shows a Production summary that opens a lazy
`ProductionSettings` panel under it. See [Production connection and metrics](production-connection.md).
`render()` adds only `withExists('prodConnection')` and an enabled-metrics count, so no credential is decrypted.

## Data model
No migration. Writes `projects.is_enabled`; inserts `projects` rows through `RegisterProject`; deletes
`projects` rows, cascading to `stories` (every version, on and off the ref) and `project_locations`.

## Interfaces
- `GET /projects` (`projects.manage`) → `ManageProjects`.
- Livewire actions: `setEnabled(int $id, bool $enabled)`, `add()`, `remove(int $id)`. Public form state:
  `path`, `name`, `ref`.
- `SwitchProject::handle(Project $project, bool $enabled, string $source): void`; constants
  `SOURCE_UI`, `SOURCE_CLI`.
- `AddProject::handle(string $path, ?string $name = null, ?string $ref = null): Project`, throws
  `ProjectAddRefusedException`; `AddProject::home(): string` (home folder, no trailing slash).
- `RemoveProject::handle(Project $project): int` (story rows deleted).
- `App\Exceptions\ProjectAddRefusedException`: `field` (`path|name|ref`), `reason`
  (`MISSING_PATH|NOT_A_REPO|DUPLICATE|BAD_REF`), message = the owner-facing sentence.

  | reason | field | message |
  |---|---|---|
  | `missing_path` | path | "Enter the folder of a git checkout." (blank) / "That folder does not exist." |
  | `not_a_repo` | path | "That folder is not a git repository." |
  | `duplicate` | path or name | "Already on the board as <name>." |
  | `bad_ref` | ref | "That is not a valid git ref." |

  The two `missing_path` sentences were chosen by the builder; the story gave no wording.
- CLI: `php artisan board:project enable <name>` (enables, queues one refresh, prints "A refresh is
  queued.") and `disable <name>`. An unknown name exits non-zero with "No project named <name>." and logs.
- Blade: `<x-board.switch :on label target />`, `<x-board.confirm-modal show confirm action target id>`
  with a `title` slot and the body as the default slot.
- Test hooks: `data-project-row`, `data-project-switch`, `data-project-stories`, `data-remove`,
  `data-remove-modal`, `data-confirm-cancel`, `data-confirm-ok`, `data-add-project`.

## Configuration
None. The first refresh after add or switch-on needs a queue worker (`composer run dev` starts one).
`~` expands to `$_SERVER['HOME']` (or `getenv('HOME')`).

## Observability
| Event | Level | Where | Context |
|---|---|---|---|
| `board.project_enabled` | info | `SwitchProject` | `project`, `source` (`ui` / `cli`) |
| `board.project_disabled` | info | `SwitchProject` | `project`, `source` (`ui` / `cli`) |
| `board.project_switch_refused` | info | `ManageProjects::setEnabled()` / `BoardProject` | `id` (ui) or `project` (cli), `reason: unknown`, `source` |
| `board.project_registered` | info | `RegisterProject` | `project`, `path`, `ref` |
| `board.project_add_refused` | info | `AddProject::refuse()` | `path` (after `~` expansion), `reason` |
| `board.project_removed` | warning | `RemoveProject` | `project`, `stories` (all rows deleted, off-main versions included) |
| `board.project_remove_refused` | info | `ManageProjects::remove()` | `id`, `reason: unknown` |

Every line carries `request_id`. A healthy switch-on is `board.project_enabled` then, on the worker,
`board.refresh_started` / `board.refresh_finished` for that project with the same `request_id`. A healthy
add is `board.project_registered` followed by the same refresh pair. `board.project_removed` is a warning
because the board's data for that project is gone; `stories` on it can far exceed the on-ref count the
page showed. A `*_refused` with `reason: unknown` means a stale tab or a hand-made request.

## Testing & verification
- `tests/Feature/Board/ManageProjectsTest.php`: one `it()` per acceptance criterion (list with a disabled
  acme-site, switch off/on with logs and one queued job, add with no name, not-a-repo, duplicate by path
  and by name, `-x` / `a..b` refs, Remove only after confirm, removal cascade with the fixture folder's
  `git status --porcelain` byte-identical, CLI enable and `enable nope`). Extra `it()`s: the sidebar link,
  refused switch and remove for a missing id, blank and missing paths with `~` expansion, and
  `disable` logging `source=cli`.
- `tests/Browser/ManageProjectsTest.php`: rent-track off and on with the sidebar following and a toast;
  Remove kept by Cancel and Escape, done by "Remove project"; add a fixture repo and a bad ref's inline
  error.
- `tests/Feature/Board/AppShellTest.php`: the empty sidebar now links to Manage projects, and the slot is
  now visible (both SB-7 tests were updated, not added).
- Browser check (story): switch rent-track off and back on; add `~/Code/story-board` with ref `main`
  (it has no `origin`), see it appear, then remove it through the modal.

## Key decisions & tradeoffs
- Every write re-navigates the page so the layout's sidebar follows, with toasts persisted across the
  navigation → [ADR-021](../decisions/ADR-021-manage-projects-re-navigates-so-the-sidebar-follows.md).
- `RegisterProject` is reused unchanged; `AddProject` names refusals by re-running its checks in order →
  [ADR-022](../decisions/ADR-022-add-project-classifies-refusals-by-re-running-checks.md).
- The switch sends a target state (`setEnabled(id, bool)`), not a toggle: replays are idempotent.
- Switch-off has no confirmation (it is reversible); Remove does (it deletes data). The modal's open and
  close are Alpine, since they are pure UI.
- The CLI's `enable` and `disable` share `SwitchProject`, so both paths log `source` the same way.

## Known limitations & gotchas
- **No auth.** The page can add and delete projects. It is safe only because the board is localhost-only.
  If SB-6 (hosted) is revived, this page needs auth first (noted at the route in `routes/web.php`).
- A blank path must never reach `RegisterProject`: `realpath('')` resolves to the current directory,
  which is the board's own checkout (see RUNBOOK).
- Path and ref cannot be edited after adding; remove the project and add it again. Aliases stay CLI-only
  (`board:project alias`).
- Removal deletes every stored version of every story, off-main included; the next add re-reads it all
  from git.
- Switching on an already-enabled project (a replayed request) queues another refresh; the job is
  unique per project, so at most one runs.
- `~user/…` is not expanded, only `~` and `~/…`.
- If `classify()` finds no reason (a race between `RegisterProject` and the re-check), it falls through
  to `duplicate`.

## Change history
2026-09-29 — Manage projects page: switch on/off, add by folder, remove behind a confirmation modal; `board:project enable`; shared `board/switch` and `board/confirm-modal`; persisted toasts (SB-12, `617f555`)
2026-09-29 — Production column and panel per row (SB-17, `c8bcfbe`); see production-connection.md
