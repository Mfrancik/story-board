# Disposal Standards — worktrees, test databases, logs
Read before running concurrent builds, and before writing anything that creates
a worktree or a database. Every concurrent agent build creates per-agent
artifacts; until this doc existed, nothing removed them. These rules say where
disposal happens, what must be true before anything is destroyed, and what
enforces each one.

## Why this exists (measured on a live project, 2026-09-20)
One worktree per concurrent story build, one MySQL test database derived per
worktree, one more `_test_<n>` database per parallel worker. After ~3 months:

| | count | size |
|---|---|---|
| worktree directories on disk | 586 | 218.7 GB |
| leftover `<project>_test_*` databases | 3,491 | 43 GB |
| local branches | 765 | — |
| registrations vs directories | 590 / 586 | — |
| data volume | | **86% full** (759 of 926 GiB) |

Worktrees are five times the databases and 29% of the volume in use, so §Rule 3
is the rule that reclaims real space — and the only one here that can destroy
work. Its conditional check is the substance of this standard, not a caveat on it.

What was actually paid, none of it hypothetical:
- **248 of 1,145 test failures** mined from three weeks of preflight runs were
  SQLSTATE exhaustion — `Too many connections`, `Unknown database`, `Base table
  or view already exists`. Each read as a product bug and cost triage time
  before being recognised as infrastructure.
- `information_schema` queries took **over two minutes** with thousands of
  schemas present, so every tool that introspects the database crawled.
- The same project had already lost a disk to 0 bytes from this growth once.

## Rule 1 — a test database is dropped when the work it belongs to lands
**The disposal point is the merge.** When a story's branch merges to main and
its worktree is removed, that worktree's test database *and every `_test_<n>`
worker database derived from it* are dropped in the same step. A merge that
leaves them behind is an **incomplete merge**, not a merge plus a chore.

Enforced by: `bin/dispose-worktree.sh <name>`, which drops the databases, removes
the worktree, deletes the branch if merged, and prunes the registration — one
command, so "the same step" is achievable rather than aspirational. Called from
`/build`'s merge step. It derives the database name with the same expression
`bin/provision-worktree.sh` used to create it, so the two cannot drift.

- *Costs:* seconds per merge.
- *Prevents:* the entire 3,491-database backlog. Every leftover database is a
  merge that skipped this step.

## Rule 2 — bulk cleanup drops from a frozen snapshot, never a live pattern
This is the rule most easily got wrong, so it is stated with its reason. The
obvious implementation — `DROP` everything matching `LIKE 'project_test_%'`,
evaluated when the drop runs — **deletes a database a concurrent session created
after the cleanup began**. Observed during the cleanup that produced this doc: a
sibling session created a new test database four minutes in. It survived only
because the drop list had been snapshotted to a file first and the live glob was
never used.

Required, in this order:
1. Capture `SHOW DATABASES` **to a file**.
2. Filter that file.
3. Drop the names in that file, and only those.

Two exclusions are mandatory and are asserted at drop time, not trusted to the
filter:
- The **shared base database** (`<project>`) and the unsuffixed test database
  (`<project>_test`), plus the primary checkout's own `<project>_test_<n>`
  parallel workers, are never candidates.
- Non-project databases are excluded by an **allow-list**: only names matching
  the project's own `<project>_test_` prefix are ever considered. This MySQL
  server also hosts unrelated projects and a production dump, and a pattern
  loose enough to be convenient reaches them.

Enforced by: `bin/workspace-sweep.sh`, which snapshots, filters, refuses any
protected name, refuses any name containing a character outside `[a-z0-9_]`, and
keeps the droplist file — what was dropped is a record, not a claim.

- *Costs:* one `SHOW DATABASES` and a temp file.
- *Prevents:* deleting a live sibling session's database — a failure that
  surfaces as someone else's tests dying mid-run, with no trace of why.

## Rule 3 — a worktree is removable only when all four are true
A blanket "delete old worktrees and branches" rule is **data-destructive here**.
Measured on the same repo the same day: **58 branches not merged into main, and
all 58 not pushed to any remote** — their commits exist in no other place.
Story branches are routinely stacked and left unpushed for days, and several
were checked out in a worktree at the time.

A script evaluates all four **per worktree**, as a conjunction:

- **a.** its branch is fully merged into main (`git branch --merged main`), **OR**
  its tip exists on a remote (`git branch -r --contains <branch>` is non-empty)
- **b.** `git status --porcelain --ignored` in that worktree is empty, once
  regenerable build output is allowed for (§The ignored allow-list)
- **c.** main is not checked out in it — main in a worktree strands the primary
  checkout
- **d.** no test process or preflight run is live against it

### The ignored allow-list
`--ignored` in 3b is load-bearing, not thoroughness for its own sake.
`git worktree remove` already refuses on modified and untracked files, so
checking plain `--porcelain` here gives 3b and the remove guard **one blind spot
instead of two independent checks** — neither sees ignored files. Measured on the
live repo: **30 of 123 worktrees** held non-empty `shots-sell36/`,
`storage/app/browser-evidence/` or `shots/`, captured screenshots with no copy
anywhere else. Reproduced in `bin/disposal-fixture.sh`: on a tree holding one
ignored screenshot, `git status --porcelain` prints nothing and
`git worktree remove` (no `--force`) deletes the tree and the screenshot with it.

These ignored paths do **not** block disposal, because `composer install`,
`npm ci` or the next test run recreates them:

    vendor · node_modules · public/build · public/hot · public/storage
    bootstrap/cache · .phpunit.cache · storage/app/public/coins

Anything else ignored and non-empty **keeps the tree and is reported**. The list
is short on purpose: every entry is a promise that losing that path costs
nothing, and that promise is the whole thing 3b protects. Append per project with
`PF_IGNORED_ALLOW`, never by widening it to make a sweep quieter.

Two details the fixture pins down, because both silently invert the rule:
- An **empty** ignored directory does not block. It is evidence of nothing, and a
  leftover empty `shots/` would otherwise strand a worktree permanently.
- git reports ignored paths at the **shallowest level it can**: a `public/`
  holding only `public/build` is reported as `public`, not `public/build`. A
  plain prefix match against the allow-list misses that and keeps every such
  tree. The check descends into a collapsed parent, prunes the allow-listed
  subtrees, and blocks only on what is left.

**Age is a trigger for evaluating a worktree, never on its own a reason to
delete it.** A three-week-old worktree holding the only copy of unpushed commits
and a three-week-old finished one are indistinguishable by mtime.

When any condition is false the script **skips and reports** — it never prompts.
A prompt at 3am in an unattended batch is either ignored or answered wrongly;
the skipped tree costs disk and nothing else, and will be evaluated again next run.

Enforced by: `bin/dispose-worktree.sh` (the conjunction, in full, before anything
is destroyed) and `bin/workspace-sweep.sh` (narrows the list with two bulk git
calls, then runs the full conjunction per candidate — the bulk pass is an
optimisation, never the decision). `git worktree remove` without `--force` and
`git branch -d` never `-D` mean git itself re-checks **(a)**, and **(b) only for
tracked and untracked files** — ignored files are 3b's alone to catch, which is
why it does not delegate them. Proven by `bin/disposal-fixture.sh`: one worktree
per classification, asserted, before any change to these scripts ships.

- *Costs:* a handful of git calls per worktree, and some disk kept longer than
  strictly necessary whenever (d) is conservative.
- *Prevents:* destroying the only copy of unpushed work — the one failure here
  that cannot be undone. At the sampled average, the protected set is on the
  order of 25 GB against ~190 GB eligible; that split was **not verified
  per-directory** and is an order of magnitude, not a figure to quote.

## Rule 4 — nothing durable may live only inside a worktree
A worktree is scratch space. Anything another session will need to review — a
measurement, a baseline, a decision, a bug's root cause — is **committed, or
written outside the worktree, before that worktree becomes eligible for
disposal.** Durable evidence lives in three places, none of which is a worktree:
the session transcript store, committed docs (`docs/`, `stories/`), and git
history. Build output inside a worktree is regenerated per run and is not
evidence. **Screenshots are not in that category** — Pest purges
`tests/Browser/Screenshots` at the start of every browser run, which is precisely
why preserved copies (`shots/`, `shots-sell36/`,
`storage/app/browser-evidence/`) exist: they are the only copy, they are
gitignored, and nothing regenerates them. A screenshot that matters is committed
or written outside the worktree; one that has not been is exactly what 3b's
`--ignored` check is there to catch.

This rule is what justifies 3b being a hard condition rather than a nuisance: if
nothing durable is supposed to be in there, an unclean tree means either the
build is unfinished or something durable was left in the wrong place — and both
are reasons to stop, not to delete.

Enforced by: **3b, as its proxy** — a tree holding uncommitted, untracked, or
preserved ignored files is never disposed of, so the mistake costs disk rather
than the artifact. What stays **advisory** is the judgement, not the catch: a
script cannot tell which ignored file was worth keeping, so it keeps the tree and
says which path made it do so. Flagged deliberately — see §Advisory by design.

## Rule 5 — growth is visible before it is a problem
A warn-only preflight gate prints, every run: worktrees registered vs on disk,
stale registrations, worktrees meeting Rule 3, orphaned test databases, log size,
and an estimate of reclaimable space.

It trips at **25 orphaned databases** (`PF_DB_WARN`), **5 disposable worktrees**
(`PF_WT_WARN`), **512 MB of logs** (`PF_LOG_WARN_MB`), or **any** stale
registration. How those were chosen: the measured accumulation is ~6.5 worktrees
and ~39 databases a day, so these thresholds trip within roughly a day of a
backlog forming — early enough that the sweep is a minute's work, and high enough
that a healthy concurrent batch never trips them (a live batch's databases are
owned by live worktrees, and so are not orphans). The defaults live in
`bin/workspace-sweep.sh`; this section is why they are what they are.

**Warn-only permanently, not provisionally.** Reclaiming disk is never more
urgent than the build in front of you, and a gate that blocks a build on
housekeeping teaches people to skip gates. It must never be promoted to a `check`.

Enforced by: `preflight.sh` → `warn "workspace growth"` →
`bin/workspace-sweep.sh --quiet`. Cheap by construction: two bulk git calls and
one `SHOW DATABASES`, with the reclaimable figure an estimate
(`PF_WT_AVG_MB` × count) rather than a `du` over hundreds of gigabytes. The real
measurement is `bin/workspace-sweep.sh --report`, which is not on the build path.

- *Costs:* sub-second per preflight run, and one more line of output.
- *Prevents:* discovering the growth at a full disk. This project has already
  hit 0 bytes once; the number on screen is the difference between a minute of
  cleanup and a dead volume.

## Logs
An application log reached **3.6 GB** on this project. `config/logging.php` uses
the `daily` channel with explicit retention (`LOG_DAILY_DAYS`, 14 local / 30+
prod — see `logging-standards.md §Channel setup`), so no single file grows
without bound. A `laravel.log` in the gigabytes means the `single` channel is in
use somewhere and is a misconfiguration, not a big log.

Enforced by: the Rule 5 gate reports `storage/logs` total size and names any
single file over 100 MB. Truncation is a manual step — a script that deletes logs
on your behalf is a bad trade against a log you needed this morning.

## Stale worktree registrations
`git worktree prune` removes registrations whose directory is gone (590
registered against 586 on disk). Run automatically at the end of every
`bin/dispose-worktree.sh` and `bin/workspace-sweep.sh --apply`; any non-zero
count trips the Rule 5 warning, because the number should be zero between runs.
Branches outlive their worktrees, so `--apply` also deletes branches merged into
main with `git branch -d` — never `-D`, so git refuses any unmerged or
checked-out branch and Rule 3a cannot be bypassed by this path.

## Ad-hoc databases
A second class of hand-made scratch databases accumulated outside the per-agent
naming convention (~180 of them, from ad-hoc verification runs).

- **An ad-hoc database is the creator's to drop in the same session.** If you
  created it to check something, drop it when the check is done.
- **Backstop:** any database created outside `bin/provision-worktree.sh` is named
  `<project>_test_scratch_<what>`, which puts it inside the allow-list and makes
  it an orphan on the next sweep — so one rule disposes of both classes and a
  forgotten scratch database has a bounded life instead of an unbounded one.

Enforced by: the naming convention (mechanical — the sweep collects it) plus the
same-session habit (advisory — nothing can attribute a database to a session
after the fact).

## Not audited, on purpose
Nothing here is read by the `/preflight` audit subagent. Disposal maps to no
audit pillar, and every mechanical part of it is already a script or a gate —
so putting it in the audit would cost tokens on every run to re-derive what
`bin/workspace-sweep.sh` decides for free (CLAUDE.md: *prefer a check to a
rule*). This doc is read by whoever writes or changes worktree tooling.

## Advisory by design
Flagged deliberately, with the reason:
- **Rule 4** is advisory except through its 3b proxy. Judging whether a file in a
  worktree is durable evidence is not scriptable; the enforceable half — never
  delete a tree that holds one — is, and the allow-list is where that judgement
  is recorded once instead of being made per tree.
- **"Drop your own ad-hoc databases in the same session"** is advisory. MySQL does
  not record which session created a schema, so this cannot be checked, only
  backstopped by the naming convention.
- **Rule 5** is enforced but permanently warn-only, which is a deliberate ceiling
  rather than a stage on the way to a hard gate. See the rule.

Everything else here is a script or a gate. A rule about disk that depends on
someone remembering is a rule that has already failed once, at 218.7 GB.
