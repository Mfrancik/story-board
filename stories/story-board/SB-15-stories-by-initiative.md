# SB-15 — Stories by initiative
Status: built          Journey: none
Source: F-4 + owner 2026-09-29 (/story): *"in a project id like to go in and see all of the built stories in
openable sections with their groups. so i would see brand, then open it to see all the brand stories, with
tags of built, canceled (crossed out), draft, to do."* Owner answers: a new Stories tab; off-list statuses
show as a grey tag with the raw value; status filter chips and Expand all / Collapse all.

## Story
As the owner, I want a project's stories grouped into openable sections by initiative, each story tagged
with its status, so that I can open one initiative (e.g. branding) and see at a glance what is built, what
is still to do, what is a draft and what was cancelled.

## Why
The project page's "Progress by initiative" shows one bar per initiative. It shows how much is done, but
not which stories are done. Today the only way to see the stories behind a bar is to open the repo.

## In scope
- A **Stories** tab on the project page tab bar (Dashboard | Handbook | Stories), at
  `/p/{project}/stories`, inside the design-A shell.
- **Two panes (mockup option c).** The left pane lists every initiative of the project's on-ref
  stories (`Story::onRef()`, the snapshot at the project's ref). Each entry shows the initiative name,
  a "parked" tag when `is_parked`, and its counts as open / all. Stories with no initiative form one
  entry named "No initiative". The right pane lists the stories of the selected initiative. On load,
  the first initiative in order is selected. On a phone, the left list becomes a select.
- Initiative order is the same as SB-10's Progress by initiative: most open work first, ties A–Z, and "No
  initiative" last among equals. Reuse `ReadProjectProgress` ordering; do not re-derive it.
- Selecting an initiative lists its stories in ID order, natural sort (BR-2 before BR-10). Each row shows the
  ID, the title and a status tag:
  - `built` → **Built**
  - `approved` → **To do**
  - `draft` → **Draft**
  - `cancelled` → **Cancelled**; the row's ID and title are struck through (`line-through`) and muted.
  - Any other value, or none → a grey tag showing the raw value, or "no status". Nothing is guessed:
    rent-track's `done` is not shown as Built.
- Clicking a story opens the existing SB-8 story modal.
- **Status filter chips**: Built, To do, Draft, Cancelled and Other. All are on by default. Turning a chip
  off hides those rows. An initiative whose rows are all hidden drops out of the left list, and "N of M
  initiatives" shows how many remain. The per-initiative counts stay the full counts, so filtering never
  changes the numbers.
- **Expand all / Collapse all**: Expand all switches the right pane to one long list of every initiative,
  grouped, with sticky group headers. Clicking an initiative on the left then jumps to its group.
  Collapse all returns to one initiative at a time.
- Selection, filters and Expand all are pure UI (Alpine). The story list for the whole project is
  rendered once, so selecting an initiative never makes a server call.
- The empty state: a project with no on-ref stories says "<project> has no stories on <ref> yet."

## Out of scope (do NOT build)
- Off-main stories. SB-5 and SB-10's "Not on main" panel own those.
- A timeline of what was built, in order (F-2 is its own idea).
- Search by ID or title (not chosen). Log it with /feature if it's wanted later.
- Remembering the selected initiative, Expand all or the filter across visits.
- Any change to the status vocabulary or to how statuses are parsed.
- Any write to a project.

## Acceptance criteria (executable — these become the Pest test names)
- Given a project with initiatives branding (7 built, 3 draft) and acquisition (2 built, 1 cancelled,
  4 approved), when `/p/{project}/stories` loads, then the left pane lists both, acquisition first (more
  open work), branding shows its counts "3 open / 10", and acquisition's stories fill the right pane.
- Given branding is selected, then its 10 stories are listed in natural ID order in the right pane, each
  with its tag, and no request is sent to the server.
- Given a `cancelled` story, then its row is struck through and tagged "Cancelled".
- Given an `approved` story, then it is tagged "To do".
- Given stories with the status `done` and with no status, then they show grey tags reading "done" and
  "no status", and neither is counted as Built.
- Given the Built chip is turned off, then no built row is visible, an initiative with only built
  stories leaves the left list, "N of M initiatives" updates, and every count still shows the full
  numbers.
- Given Expand all, then the right pane lists every initiative's stories in grouped order. Clicking
  branding on the left then scrolls to its group. Given Collapse all, then only the selected initiative
  shows.
- Given a story row is clicked, then the SB-8 modal opens for that story.
- Given stories with no initiative, then they appear under an entry named "No initiative".
- Given a parked initiative, then its left-pane entry carries the "parked" tag.
- Given an off-main story row for the same project, then it is not listed.
- Given a project with no on-ref stories, then the page shows the empty state and returns 200.
- Given a disabled or unknown project, then `/p/{project}/stories` returns 404 (the same `CheckProjectShown`
  rule as SB-10 and SB-14), and `hydrate()` re-checks it on every request.
- Given the page loads, then `board.stories_viewed` is logged with `project` and `stories` (count).

## Applicable standards
- Design: design-A shell (SB-7 gate) and the project tab bar (`board/project-tabs`, SB-14). The status
  tag uses the existing `board/status-chip` component. Where it needs "To do", "Cancelled" and "Other"
  styles, extend that one component; do not add a second chip. Today that chip gives off-list values
  (which includes `cancelled`) a danger tone. On this page, per the owner's answer, off-list values are
  grey and `cancelled` has its own muted style. Add this as a variant, so the chip's danger tone
  elsewhere (Home, story rows) is unchanged. Selection, chips and Expand all are
  Alpine (pure UI, no server round-trip). Loading: a skeleton for the first render only.
- Codebase/DB: no schema change. One query for all on-ref stories of the project (id, story_id, title,
  status, initiative, is_parked), grouped in PHP. It must stay one query at coins' size (946 on-ref
  stories, 57 initiatives): add a test asserting the query count. The ordering comes from
  `ReadProjectProgress`.
- Logging: `board.stories_viewed` (info; `project`, `stories`). The 404 path reuses
  `CheckProjectShown`'s existing logging.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/SB-15/option-{a,b,c}.html (inside the design-A shell, with coins' real
  initiatives)
- Chosen option: c
- Why I chose it: owner pick 2026-09-29 (/story mockup gate), no reason given. Direction: initiatives listed on the left with counts, the chosen initiative's stories on the right, and Expand all switching to one long grouped list. Filter chips sit above both panes.

## Do NOT touch
- Any registered project's files. `app/Services/GitReader.php`. The status parser in the kit.
  `ReadProjectProgress`'s output shape (SB-10 reads it; reuse, don't change it).

## Data & interfaces
- Schema/migrations: none.
- Route: `GET /p/{project}/stories` (named `projects.stories`). Livewire: a new `ProjectStories`
  component. `board/project-tabs` gains the Stories tab. `board/status-chip` may gain tag styles.
  `docs/UI-INVENTORY.md` updated.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: `/p/coins/stories` shows 57 initiatives plus "No initiative" if present. Select
  acquisition and see ACQ-22 struck through as Cancelled. Turn Built off and see mostly open work.
  Expand all, then Collapse all. Click a story and see the modal. `/p/rent-track/stories` shows grey
  "in" and "done" tags.
- Checked against real data (2026-09-29, on-ref stories in the dev DB):
  - coins: 946 stories in 57 named initiatives (built 844, approved 45, draft 38, cancelled 19). The
    largest initiatives are mobile 83, bullion 66, intake 66. `branding` has 7 built and 3 draft.
    The cancelled stories include ACQ-22, 23 and 24 (acquisition).
  - client-dashboard: 97 (built 89, draft 7, approved 1), 13 named initiatives.
  - asset-track: 11 (draft 8, built 3), 4 initiatives.
  - rent-track: 15 in 1 initiative, with off-list statuses `in` (1) and `done` (3).

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [ ] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: none · Depends on: SB-8 (modal), SB-10 (initiative ordering), SB-14 (project tabs)
