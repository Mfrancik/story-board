# SB-13 — Pick a mockup from the board
Status: approved         Journey: none
Source: owner 2026-09-29 (/story): *"for pending mockups, would i be able to go in here and select an option,
then this system trigger an update to a session saying that mockup was chosen"* and *"any approval just needs
secondary confirmation so i dont accidently select osmething and it gets submitted. as long as this back and
forth doesnt slow down projects builds."*

## Story
As the owner, I want to pick a mockup option, with my reason, from the board and confirm it, so that
the next Claude session in that project records the pick in the story file for me.

## Why
Picking today means telling a session in that project which option you chose. The board is where the
mockups are compared, so the pick belongs there. The project's own session still writes the story
file, so the board keeps its rule of never writing to a project.

## Owner ruling this story changes
Until now the board had no decision actions at all (brief §Key decisions: "The board is read-only"). The
new ruling, recorded in a new ADR and the brief, is:
- The board may record a **pick request** in its own DB.
- A session in the project collects and applies it.
- The board still never writes to a project, and every other decision action stays out.
- Every decision taken on the board has a confirmation step.

## In scope
- In the SB-8 modal and on the story page, a story that is awaiting a pick gets a "Choose option X" button
  per option. Awaiting a pick means on the ref, status `draft|approved`, options present, chosen null.
- The button opens a confirmation modal: "Record option B for coins AUC-21? The next Claude session in
  coins will write it into the story file." It has a required reason field ("Why this one?") and two
  buttons: "Confirm pick" and "Cancel". Nothing is saved before "Confirm pick".
- A new table `pick_requests`: `project_id`, `story_id`, `option`, `reason`, `state`
  (`pending|delivered|recorded|superseded|withdrawn`), `requested_at`, `delivered_at`, `resolved_at`.
  There is at most one pending or delivered request per story.
- The story shows its request's state wherever it appears, as a banner or chip:
  - "Picked B on the board — waiting for a coins session" (pending)
  - "… delivered to a session, not on main yet" (delivered)
- **Withdraw**: while a request is pending, the owner can withdraw it, through its own confirmation.
- **Reconcile on refresh**: when a refresh sees the story's chosen option on the ref, the request becomes
  `recorded` if the options match, or `superseded` if they differ (the story shows "On main: C, board pick
  was B").
- A localhost-only JSON API for the kit's session hook:
  - `GET /api/picks?project=<name>` returns the project's pending requests (story ID, story path, option,
    reason, id, and the exact instruction text for the session).
  - `POST /api/picks/{id}/delivered` marks one delivered.
  - Requests not from a loopback address are refused.
- **Speed guard** (the owner's "doesn't slow down builds"): `GET /api/picks` answers from one indexed
  query and runs no git. With nothing pending it returns `[]`. The hook side's timeout is 1 second, set
  in the kit story.

## Out of scope (do NOT build)
- The session hook itself. It is a separate kit story in dev-standards: it calls the API at session start
  and on each prompt, times out after 1 second, stays silent when the board is down, and injects the pick
  instruction.
- Approving drafts from the board, or any decision other than a mockup pick. The same pattern could carry
  them later, as a new story.
- Writing to, committing in or pushing any project.

## Acceptance criteria (executable — these become the Pest test names)
- Given coins AUC-21 awaiting a pick (options a/b), when "Choose option b" is clicked, then a confirmation
  modal opens and no `pick_requests` row exists yet.
- Given the confirmation modal, when "Confirm pick" is clicked with an empty reason, then the reason field
  shows "Say why you chose it." and nothing is saved.
- Given a reason, when "Confirm pick" is clicked, then one `pending` request exists for AUC-21 option b,
  the modal shows "Picked B on the board — waiting for a coins session", and `board.pick_requested` is
  logged.
- Given "Cancel" in the confirmation, then nothing is saved.
- Given AUC-21 already has a pending request, then its pick buttons are replaced by the request's banner
  and a "Withdraw" button. A second POST for it is refused with `board.pick_refused` reason `pending`.
- Given a story that is built, cancelled, off main only, or already has a chosen option, then it shows no
  pick buttons, and a forged pick for it is refused with `board.pick_refused` reason `not_awaiting`.
- Given an option that is not in the story's options, then the pick is refused with reason `bad_option`.
- Given a pending request, when "Withdraw" is confirmed, then its state is `withdrawn` and
  `board.pick_withdrawn` is logged.
- Given `GET /api/picks?project=coins` from 127.0.0.1 with one pending request, then it returns that
  request with the instruction text, and `board.picks_polled` is logged at debug.
- Given the same call with nothing pending, then it returns `[]`.
- Given a request from a non-loopback address, then it returns 403 and `board.pick_api_refused` is logged.
- Given `POST /api/picks/{id}/delivered`, then the state is `delivered` with `delivered_at` set. Given an
  unknown id, then 404 and `board.pick_api_refused` reason `unknown`.
- Given a delivered request for AUC-21 option b, when a refresh finds `Chosen option: b` on the ref, then
  the request is `recorded`. When it finds `c`, then the request is `superseded` and
  `board.pick_superseded` is logged.
- Given any of the above, then no registered project's `git status --porcelain` changes.

## Applicable standards
- Design: the design-A modal (SB-7 gate). The confirmation reuses SB-12's confirmation modal, and the
  reason field uses the shared field-and-error pattern. The primary button says "Confirm pick", and
  success says "Pick recorded on the board".
- Codebase/DB: a new migration for `pick_requests`, with a unique index on
  `(project_id, story_id, state)` enforced for `pending|delivered` in the action. Actions are
  `RequestPick`, `WithdrawPick` and `ReconcilePicks` (called from `RefreshProject` after the snapshot
  swap). Refresh itself must not fail because of reconcile: its failure is logged, never fatal. L-5: each
  refusal gets a test and a log line.
- Logging: `board.pick_requested`, `board.pick_withdrawn`, `board.pick_delivered`, `board.pick_recorded`,
  `board.pick_superseded` (info), `board.pick_refused` (info; `reason` =
  `pending|not_awaiting|bad_option`), `board.pick_api_refused` (warning; `reason` =
  `not_loopback|unknown`), `board.picks_polled` (debug; `project`, `count`).

## Design mockup gate
- Mockups: the pick buttons live in the design-A modal (SB-7 gate), and the confirmation is SB-12's
  modal. No new layout. If the builder finds the pick controls need one, stop and open a gate.
- Chosen option: a (SB-7's gate)
- Why I chose it: "A is the best design" (owner, 2026-09-29).

## Do NOT touch
- Any registered project's files or git. `app/Services/GitReader.php`. The kit (the hook is its own story
  in dev-standards).

## Data & interfaces
- Migration: `create_pick_requests_table`.
- Routes: `GET /api/picks`, `POST /api/picks/{pickRequest}/delivered` (loopback middleware). Livewire:
  pick controls in `StoryModal` and `StoryPage`. ADR: pick requests are the board's one write path.

## Test plan
- Pest: write the failing tests from the acceptance criteria FIRST, then build.
- Journey test: none.
- Browser check: open AUC-21, choose b, cancel, choose b again, try to confirm without a reason and see
  the error, then confirm with a reason and see the banner. `curl localhost:8010/api/picks?project=coins`
  shows it. Withdraw it.
- Checked against real data (2026-09-29): the board lists 9 stories as awaiting a pick. Only 3 truly are:
  coins AUC-17, coins AUC-21 and client-dashboard SS-17. The other 6 (coins MOB-44, MOB-65; asset-track
  CV-1, TS-3; rent-track MT-4b, MT-4c) have bold picks the kit parser misreads (F-1). **F-1 must be
  fixed in the kit before this story is built**, or the board offers picks on 6 stories that are already
  decided.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end (n/a: no journey)
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document (includes the ADR and the brief's changed ruling)

## Links
Journey: none · Depends on: SB-8, SB-12 (confirmation modal), kit F-1 fix. The kit session-hook story
depends on this one.
