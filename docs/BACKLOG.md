# Backlog — captured ideas

Raw, unrefined ideas caught via `/feature`. **Not stories:** nothing here has
acceptance criteria, approval, or a commitment behind it. Dumps are captured
verbatim and are never edited.

This file's main job is to answer one question at `/story` time: *am I describing
something I already logged?* That only works if it stays readable — so it holds
one-line entries, and any worked-out spec lives beside it in `docs/backlog/F-<n>.md`.

An entry leaves `## Open` exactly two ways:

- **It ships** — a story named it in `Source: F-<n>`, and `/document` removed it
  on completion. Its trace lives in that story file and in git. A preflight gate
  to pin this is written at `wip/backlog_sync_gate.sh` but is not yet installed.
- **It's cut** — moved to `## Cut` with a reason, via `/feature cut` or at retro.

It does not leave because it got scheduled, and it does not leave because someone
tidied up.

Swept twice: by `/story` when a brain dump arrives (same / partial / overlap), and
by `/lesson retro` at phase end (promote, cut, or explicitly keep). `/feature list`
prints what's open on demand. If `## Open` passes ~25 entries,
that's a signal — either capture is outrunning the build loop, or half of this is
aspiration nobody will build.

Entry format:

```
### F-<n> — <date> — <short title>
Area: <feature/page/surface, or unknown>
Scope: PROJECT | PROMOTE
Spec: docs/backlog/F-<n>.md   ← only once spec'd
Story: <story-id>             ← only once folded into a story

<the dump, verbatim>
```

IDs are never recycled — F-12 always means the same F-12, including after it's
cut or shipped.

## Open

## Cut
