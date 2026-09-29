# Stories

Build-ready stories from `/story`. One story = one file = one session = one commit.

## Status — the only three values

A story's `Status:` line is one of exactly three values. This section is the
single source of truth; everywhere else references it and nothing restates it.

- `draft`    — written, not approved
- `approved` — approved, ready to build or in progress
- `built`    — the build commit is on main

There is no fourth value. `built` is owned by git, not by hand: a story is
`built` exactly when a `feat|fix|test(<ID>): …` commit for it is on main
(CLAUDE.md step 6). Preflight fails any story whose file lags that fact.

## Structure

Stories are grouped into subfolders by **initiative** — never a flat directory:

```
stories/<initiative>/<ID>-<slug>.md
```

- One folder per initiative, named in kebab-case. An initiative is usually a
  journey or a coherent effort (e.g. `action-plan`, `dashboard-ux`).
- The story ID prefix maps 1:1 to its folder — `AP-*` lives in
  `stories/action-plan/`, and no other folder uses `AP-*`. Never the same prefix
  in two folders, or two prefixes in one folder.
- Trivial one-offs with no natural home go in `stories/misc/`.
- Each journey doc (`docs/journeys/<slug>.md`) lists the full path of every story
  that composes it.

`/story` decides the folder: new stories for an existing initiative join its
folder and reuse its prefix; a genuinely new effort gets a new folder + new
prefix; one-offs go in `stories/misc/`.

## Example tree

```
stories/
  action-plan/          # journey: action plan (prefix AP-*)
    AP-1-create-plan.md
    AP-2-assign-owners.md
    AP-3-track-progress.md
  dashboard-ux/         # effort: dashboard polish (prefix DASH-*)
    DASH-1-empty-state.md
    DASH-2-loading-skeletons.md
  misc/                 # trivial one-offs, no shared initiative
    MISC-1-fix-footer-link.md
```
