# ADR-003 — Per-agent build artifacts are disposed of at the merge, by a conjunction, from a frozen snapshot

Date: 2026-09-20 · Status: accepted

## Context

`/build` provisions one git worktree per concurrent story and derives one MySQL
test database per worktree, plus one `_test_<n>` database per parallel worker.
`bin/provision-worktree.sh` creates both. Nothing removed either: no `DROP
DATABASE` and no `git worktree remove` existed anywhere in `preflight.sh`, the
provisioner, or the test bootstrap.

Measured on a downstream project after ~3 months of this, 2026-09-20: 586
worktree directories on disk totalling **218.7 GB** (~434 MB each sampled;
`vendor/` and `node_modules/` are real directories, not symlinks, ~335 MB of
that), **3,491** leftover `<project>_test_*` databases totalling 43 GB, 765 local
branches, 590 registrations against 586 directories, and a data volume **86%
full** (759 of 926 GiB). The same project had previously lost a disk to 0 bytes
from the same growth.

The cost was not only disk. **248 of 1,145** test failures mined from three weeks
of preflight runs were SQLSTATE exhaustion — `Too many connections`, `Unknown
database`, `Base table or view already exists` — each triaged as a product bug
first. `information_schema` queries took over two minutes with thousands of
schemas present.

The obvious remedy is also the dangerous one. On the same repo the same day: **58
branches were not merged into main, and all 58 were on no remote.** Those commits
existed in exactly one place — a worktree directory. Story branches are routinely
stacked and left unpushed for days, and several of the 58 were checked out in a
worktree at that moment. An age-based "delete worktrees older than N days" sweep
would have destroyed them.

`git status --porcelain` is not enough to see this. `git worktree remove` refuses
on modified and untracked files but **not on ignored ones**, so the obvious 3b
check and the remove guard share a single blind spot rather than being
independent. Measured the same day: **30 of 123 live worktrees** held non-empty
`shots-sell36/`, `storage/app/browser-evidence/` or `shots/` — captured
screenshots with no copy anywhere else. `shots-sell36/` exists *because* Pest
purges `tests/Browser/Screenshots` at the start of every browser run.

A third hazard showed up during the cleanup itself: a sibling session created a
new test database **four minutes into the sweep**. A live `DROP ... LIKE
'prefix%'` would have deleted it.

## Decision

**1. The disposal point is the merge, and it is one command.**
`bin/dispose-worktree.sh` drops the worktree's test database and every
`_test_<n>` worker derived from it, removes the worktree, deletes the branch if
merged, and prunes the registration. A merge that leaves them behind is defined
as an incomplete merge (`disposal-standards.md` §Rule 1, `/build`'s merge step).
It derives the database name with the same expression the provisioner used to
create it, so creation and disposal cannot drift apart.

**2. Removal is a conjunction of four conditions, evaluated per worktree before
anything is destroyed**: branch merged-into-main **or** present on a remote;
clean `git status --porcelain --ignored`, against a short allow-list of
regenerable build output (`vendor`, `node_modules`, `public/build`, `public/hot`,
`public/storage`, `bootstrap/cache`, `.phpunit.cache`,
`storage/app/public/coins`); main not checked out there; nothing live against
it (process naming the path, open MySQL connection on its databases, or a write
within `PF_IDLE_MINUTES`). Any false condition means **skip and report** — never
prompt, never `--force`, never `-D`. Age triggers evaluation; it is never itself
a reason. `git worktree remove` (no `--force`) and `git branch -d` (never `-D`)
mean git independently re-checks two of the four.

**3. Bulk cleanup drops from a frozen snapshot.** `SHOW DATABASES` to a file,
filter the file, drop the names in the file. The allow-list is the project's own
`<project>_test_` prefix — this MySQL server also hosts unrelated projects and a
production dump. The shared base database, the unsuffixed test database, and the
primary checkout's own parallel workers are asserted against at drop time, not
merely filtered out. The droplist file is kept.

**4. Growth is a warn-only preflight gate**, `bin/workspace-sweep.sh --quiet`:
counts, thresholds, and an estimate of reclaimable space, every run. Warn-only
**permanently** — reclaiming disk is never more urgent than the build in front of
you, and a gate that can block a build on housekeeping teaches people to skip
gates. Thresholds (25 orphaned databases, 5 disposable worktrees, 512 MB of logs,
any stale registration) are set from the measured accumulation rate — ~6.5
worktrees and ~39 databases a day — so they trip about a day into a backlog and
never during a healthy batch.

**5. Ad-hoc scratch databases join the convention.** Named
`<project>_test_scratch_<what>`, they fall inside the allow-list and are disposed
of by the same rule; dropping your own in the same session stays the expectation
but is explicitly advisory, because MySQL does not record which session created a
schema.

## Consequences

- Ignored-file checking makes Rule 3 stricter than `git worktree remove` is, on
  purpose: the tree that git would happily delete is the one holding the only
  copy of something. The cost is that a Laravel worktree's own runtime files
  (`.env`, `storage/logs`) are ignored, non-empty, and not on the allow-list, so
  a provisioned tree is kept until that list is amended per project — see the
  open question at the end.
- Rule 3 is deliberately conservative, and the conservatism costs disk. A
  worktree written to in the last 30 minutes, or holding an untracked
  screenshot, is kept until the next sweep. That is the correct trade: the
  failure it avoids is unrecoverable and the failure it accepts is a warning line.
- The 58 protected branches keep their worktrees indefinitely until pushed or
  merged. At the sampled average that is on the order of 25 GB held against ~190
  GB eligible — **not verified per-directory**, an order of magnitude only.
- Cleanup is no longer safe to express as a one-liner. Anyone writing
  `DROP ... LIKE` by hand is outside this standard; the scripts exist so the safe
  path is also the short one.
- The gate adds one line of preflight output and two bulk git calls plus one
  `SHOW DATABASES` per run. The real `du` measurement stays off the build path,
  in `--report`.
- Unchanged: how worktrees are provisioned, how preflight derives test databases,
  and `/build`'s concurrency. This ADR adds the inverse of an existing step; it
  does not alter the step.

## Open question (not decided here)

The allow-list above is exactly the one measured and supplied. Applied to a tree
provisioned by `bin/provision-worktree.sh`, it keeps every worktree, because the
provisioner copies `.env` (gitignored, non-empty) and any test run writes
`storage/logs/*.log`. Both are genuinely regenerable — `.env` from the primary
checkout, logs by definition — but adding them is a widening of a safety list and
that is the owner's call, not a scripted one. `bin/disposal-fixture.sh` asserts
the current behaviour (case `runtime`: KEPT) so the consequence is visible rather
than discovered on contact.
