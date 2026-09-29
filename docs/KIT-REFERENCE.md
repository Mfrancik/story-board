# dev-standards — a build kit for Claude Code on Laravel

This repo is not an application. It is the **operating manual, standards, skills
and gates** that a Laravel 12 project is built with — copied into a project once,
then used every day through six slash commands.

The design goal is that quality is enforced by *machinery*, not by remembering:
a deterministic script gates what a script can decide, a read-only subagent
audits what it can't, and everything the agent needs to read is pinned to a
trigger point instead of a good intention.

**Stack it assumes (fixed):** Laravel 12 · PHP 8.3+ · MySQL 8 everywhere ·
Livewire 3 + Alpine · Tailwind · Filament · Pest · Pint.

---

## Install into a project

From the root of the target Laravel project:

```bash
KIT=~/Code/dev-standards          # this repo

cp -R  "$KIT"/.claude/skills      .claude/skills
cp -R  "$KIT"/.claude/agents      .claude/agents
cp     "$KIT"/.claude/settings.json .claude/settings.json   # merge if you have one
cp     "$KIT"/CLAUDE.md           CLAUDE.md
cp     "$KIT"/preflight.sh        preflight.sh
cp -R  "$KIT"/bin                 bin
cp -R  "$KIT"/docs                docs
cp -R  "$KIT"/stories             stories

chmod +x preflight.sh bin/*.py bin/*.sh
```

Do **not** copy `.claude/settings.local.json` (machine-local permissions) or
`wip/` (unproven kit work — see below).

Then:

1. Edit `CLAUDE.md` if the project deviates from the stack. Everything else in it
   is meant to be stable; the file is the agent's first read of every session.
2. Confirm `.env` has `DB_CONNECTION=mysql`. SQLite is a hard failure, including
   for tests.
3. Run `./preflight.sh`. It will fail until the project actually has `vendor/`,
   `node_modules/`, a reachable MySQL, and a feature branch — that is the point.

> `./preflight.sh` cannot pass **in this kit repo**. There is no Laravel app here.
> It is source, not something you run in place.

---

## The loop

Every feature goes through the same seven steps (`CLAUDE.md` is the authority):

| # | Step | Command |
|---|------|---------|
| 0 | Idea arrives mid-build → capture it and keep building | `/feature <dump>` |
| 1 | Turn the idea into a build-ready story with executable acceptance criteria | `/story` |
| 2 | Plan before code, show the plan | — |
| 3 | Build **one** story, in a fresh session, from the saved story file | — |
| 4 | Must return **GO** before the story is done | `/preflight` |
| 5 | Verify with evidence — Pest output + a browser check | — |
| 6 | Commit `<type>(<STORY-ID>): <subject>` | — |
| 7 | Write the docs. A story without docs is not done. | `/document` |

Plus, whenever a mistake is corrected: `/lesson`. At the end of every phase:
`/lesson retro` **and** a full unscoped `/preflight`.

### Commit convention

```
feat|fix|test(<STORY-ID>): <subject>     ← build commits; flip Status: to built
docs|chore|refactor(<STORY-ID>): ...     ← never mark a story built
```

Story IDs match `[A-Z]{2,}-[0-9]+[a-z]?` (`AP-1`, `SS-10`, `MT-2a`). A `feat|fix|test`
commit is what makes a story `built` — and `preflight.sh` fails any story file
whose `Status:` line lags git. Status has exactly three values: `draft`,
`approved`, `built` (`stories/README.md` §Status is the single source of truth).

---

## The six commands

### `/feature` — the idea log
`/feature <anything>` captures the idea verbatim into `docs/BACKLOG.md` and gets
out of the way; no interview, no scoping. Bare `/feature` browses the log. Entries
get IDs (`F-1`, `F-2`, never recycled) and leave `## Open` exactly two ways: they
ship (removed by `/document`) or they're cut with a reason. The log is swept
automatically at `/story` time for duplicates, which is what stops it being
write-only.

### `/story` — freeform description → build-ready stories
Decomposition is the agent's job. It reads the codebase, sweeps the backlog for
overlap, asks 2–4 sharp questions about genuine unknowns only, and writes stories
to `stories/<initiative>/<ID>-<slug>.md`. It detects when stories chain into an
end-to-end journey and maintains `docs/journeys/` plus the Playwright journey test
that walks the whole flow. Visual stories are flagged for the mockup approval gate.
You approve the story list before full stories are written.

### `/build` — batch orchestration of approved stories
`/build AP 1-7` (or a mixed list, `/build AP-5 AP-6 ship`) runs a batch of
**approved** stories end-to-end with the session as pure orchestrator — it never
writes feature code itself. Mockup gates run first (concurrent, opened in the
browser for owner approval); builds run in subagents in isolated worktrees cut
from `origin/main`, waved by dependency edges *and* file overlap; preflight is
batched per CLAUDE.md's batch exception (once on the integrated result, plus at
most one mid-batch run after a high-risk story, behind
`.claude/browser-lock.sh`); `/document` runs per story. A scope gate pushes back
on oversized batches, and the `ship` keyword is the only thing that authorizes
merging to main — without it, everything stays on branches and the final report
(table-first) says what's ready. The plan file `.claude/plans/<batch-slug>.md`
is kept current so an interrupted run can be resumed by a later session.

### `/preflight` — GO / NO-GO
Two phases:

- **Phase 1 — hard gates** (`./preflight.sh`): PHP/deps/env/MySQL, migrations
  current, git hygiene, story `Status:` vs git, story format, `dd()`/`dump()` debris, committed
  `.env`, secret grep, journey references resolving, UI-inventory currency, Pint,
  PHPStan, the Pest suite, all journey tests, Vite build. Deterministic, ~0 tokens.
  **If Phase 1 fails, the audit never runs** — auditing a red tree wastes a run.
- **Phase 2 — quality audit**, delegated to the read-only `preflight` subagent,
  rating five pillars (Design, DB/codebase, Logging, Tests, Docs) against
  `docs/standards/`. Read-only by construction, so "don't silently fix things
  during preflight" is a structural fact rather than an instruction.

Scoped to the diff by default; `full` once per phase, which is the only place
codebase-wide duplication and drift get looked at. **The audit runs on Sonnet;
fixes run in the main session on the main model** — cheap to find, expensive to fix.
Every preflight turn opens with a cost label:

```
preflight — 4m12s — 118,430 tok (in … / out … · subagent …) · 9 turns · audit sonnet
```

### `/document` — the mandatory last step
Writes the feature doc, the `docs/INDEX.md` entry, any ADR or RUNBOOK entry, the
project brief, the journey map, and the backlog close-out. The writing runs in the
`documenter` subagent, never inline: docs are the last step of a loop, so they land
where session context is largest (measured at 260k on a real story) and
main-session context is permanent. The subagent starts empty and reconstructs from
the **story file plus the diff** — which is also more honest, since a diff can't
misremember what shipped. The main session's job is the *handoff note*: the
decisions, alternatives and solved bugs that are invisible in the diff.

### `/lesson` — turn a correction into something that can't recur
Pushes every lesson as far up this ladder as it will go:

```
1. CHECK   a preflight.sh gate / hook / test — makes it impossible to ship
2. AUDIT   a bullet in the preflight subagent's pillars
3. RULE    a directive in the right standards doc, at the right trigger point
4. LEDGER  an entry in docs/LESSONS.md — weakest, never the only output
```

Standards edits are shown as a diff for approval before they're applied.
`/lesson retro` at phase end sweeps for anything that happened twice.

---

## What's in here

```
CLAUDE.md                  the operating manual — read first, every session
preflight.sh               all deterministic gates (+ `audit-pack`, `story-status` modes)
bin/preflight-meter.py     measures what each preflight run costs; enforces the label
bin/document-trigger.py    notices a commit that flipped a story to built, asks for docs
bin/provision-worktree.sh  creates one agent build worktree + its test database
bin/dispose-worktree.sh    the inverse: drops the databases, removes the tree — Rule 3 first
bin/workspace-sweep.sh     reports (and with --apply reclaims) leftover worktrees and DBs
bin/disposal-fixture.sh    17 asserted cases over the two above — run before changing them
bin/story-index            reads stories from a git ref, emits JSON — the story-format contract
bin/story-index-fixture.sh asserted cases over story-index and its gate — run before changing it

.claude/skills/            feature · story · build · preflight · document · lesson
.claude/agents/            preflight (read-only, Sonnet) · documenter
.claude/settings.json      the three hooks that wire the above together

docs/standards/            design · codebase · logging · disposal — what the audit judges against
docs/INDEX.md              one line per feature → its doc
docs/features/             the durable "how it works" reference per feature
docs/decisions/            ADRs — point-in-time, never edited
docs/journeys/             end-to-end flows, their story chains, their tests
docs/RUNBOOK.md            bugs solved before — check here before debugging twice
docs/LESSONS.md            the ledger of lessons (the record, never the enforcement)
docs/UI-INVENTORY.md       every reusable component — consult before building UI
docs/BACKLOG.md            captured ideas · docs/backlog/F-<n>.md for worked-out specs

stories/<initiative>/      one folder per initiative, ID prefix maps 1:1 to folder
wip/                       kit work written but not yet proven — NOT installed
```

`docs/PROJECT-BRIEF.md` and `docs/mockups/<story-id>/` aren't scaffolded here;
`/document` and the mockup gate create them on first use.

### Hooks

`.claude/settings.json` wires three:

| Hook | Fires on | Does |
|---|---|---|
| `PreToolUse` → Skill | any skill invocation | starts the preflight cost meter when `/preflight` fires |
| `PostToolUse` → Bash | any bash call | if the command was a commit that flipped a story to `built`, prints a `/document` reminder — **once per SHA** |
| `Stop` | end of turn | bounces the turn if a preflight run's cost label is missing from line one — also **once**, then it clears |

Both reminders fire exactly once by design. A hook that nags forever trains you to
ignore it, so ignoring one loses it.

### `preflight.sh` extras

```bash
./preflight.sh                  # all gates
./preflight.sh story-status     # just the story-Status-vs-git gate, with debug output
./preflight.sh gate story-format   # one gate alone (any <name>_gate; hyphens = underscores)
./preflight.sh audit-pack       # the evidence blob the audit subagent reads first

PF_QUICK=1     ./preflight.sh   # skip the expensive tail — iteration only, never clears a story
PF_FAIL_FAST=1 ./preflight.sh   # stop at the first failure
PF_PEST_ARGS='--filter=Foo' ./preflight.sh
PF_MAX_BYTES=8000 ./preflight.sh   # per-gate failure output cap (default 4000)
```

### `bin/story-index` — the story format as a contract

```bash
bin/story-index <repo> [ref]           # JSON, one record per story; ref defaults to origin/main
bin/story-index --check <repo> [ref]   # `<path>: <error>` per parse error; exit 1 if any
```

The one parser for story files. Anything that reads stories (a board, a sweep)
reads this output rather than re-parsing the markdown. It reads **through git**
(`git ls-tree` plus one `git cat-file --batch`), never the working tree, because a
checkout can lag its remote by days. Python 3, stdlib only.

A story file is any `stories/**/*.md` except a `README.md`, the same rule the
story-status gate uses. The `story-format` preflight gate runs `--check . HEAD`.

**The JSON shape.** A list, sorted by `path`. Every record carries every key:

| Key | Type | Source at the ref |
|---|---|---|
| `id` | string · null | filename `<ID>-<slug>.md` or `<ID>.md`, ID = `[A-Z]{2,}-[0-9]+[a-z]?` |
| `title` | string · null | the H1, minus its `<ID> — ` prefix |
| `status` | string · null | first `Status:` line, first word (same tolerance as the story-status gate: `**Status:**`, trailing `Journey:`, `draft — pending`) |
| `journey` | string · null | `Journey:` value in the header (before the first `##`) |
| `initiative` | string · null | the folder under `stories/` |
| `path` | string | repo-relative path |
| `source` | string · null | `Source:` value, joined across wrapped lines |
| `depends_on` | string[] | story IDs in `Depends on:` under `## Links`, up to `·` or `Blocks:`; `none` → `[]` |
| `mockups` | object | `{dir, options, chosen}` — below |
| `parse_errors` | string[] | empty when the story conforms |

`mockups.dir` is `docs/mockups/<ID>` if that directory exists at the ref, else
`null`. `options` lists the `*` of every `option-*.html` directly inside it, sorted.
`chosen` is the lone leading letter of the `Chosen option:` value, lowercased
(`**b — "The Ledger"**` → `b`). A value that is not a single letter, such as `n/a`,
gives `null`. `chosen` is not cross-checked against `options`: a story may choose
from mockups filed under another story's ID.

**What counts as a parse error:** no `Status:` line; a status missing from the ref's
`stories/README.md` §Status backticked bullet list (the vocabulary always comes
from the project, never a hardcoded set); no §Status list at all; a filename
without a story ID; no H1. A parse error never crashes the run or drops the
record, so a board shows a broken row instead of losing it.

**Exit codes:** `0` ok · `1` `--check` found errors · `2` bad repo or ref, with one
line on stderr and nothing on stdout. It never prints partial JSON. A repo with
no `stories/` prints `[]` and exits 0.

---

## Working on the kit itself

- **Prefer a check to a rule.** Anything a script can decide belongs in
  `preflight.sh`, where it costs wall clock and ~0 tokens — not in the audit,
  where it costs tokens on every run and grows with the codebase.
- **`wip/` is a quarantine.** Kit code that exists but has never been executed
  against a real fixture lives there, outside every directory a new project copies
  from. It graduates only after being proven against a reproducible fixture, in its
  own commit that installs it where it belongs. A gate proved only in a transcript
  dies with the session (LESSONS L-2).
- **Standards are load-bearing.** `/lesson` shows the diff and waits for approval
  before touching `docs/standards/`.
- A lesson marked `PROMOTE TO CENTRAL` belongs back in this repo so every future
  project inherits it; project-specific quirks stay local.

## Background

`docs/decisions/ADR-001` explains why the preflight audit is isolated, diff-scoped
and metered, with the measurements behind it. `docs/LESSONS.md` records the two
lessons already converted into enforcement: the mockup approval gate (L-1) and the
ban on docs restating machine-readable facts (L-2).
