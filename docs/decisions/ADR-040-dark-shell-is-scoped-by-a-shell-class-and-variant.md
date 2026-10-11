# ADR-040 — The dark shell is scoped by a `.shell` class and a `shell:` variant

Date: 2026-10-11 · Status: accepted · Story: RD-2

## Context

RD-2 brings in the dark Home A look, but moves only the Preflight page into it. `project-header`,
`project-tabs` and `state` are shared components. Preflight uses them inside the shell, while Handbook,
Stories, the dashboard and the app map still use them on light/dark `layouts/board` pages until RD-4 / RD-9.

## Decision

`layouts/shell` puts `class="shell"` on `<html>`. In `resources/css/app.css`:
- `@custom-variant shell (&:where(.shell, .shell *))` lets a shared component carry its dark look as `shell:`
  classes beside its existing `dark:` ones.
- An `@layer theme` `.shell { … }` block re-points the shared semantic tokens at the Home A palette: `ok→go`,
  `warning→needs`, `danger→fail`, `pending→ink-muted`, `series→live`, `accent→needs`, and the sans and mono
  fonts to Geist. Anything drawn with those tokens recolours with no per-view override.

The shell does not output `@fluxAppearance`, so `.dark` never applies inside it. `wire:navigate` replaces
`<html>` attributes on each page swap, so the class turns on and off correctly when moving between shell pages
and old pages.

Alternatives rejected:
- **Fork `project-header`, `project-tabs` and `state` into shell copies.** That means two copies of the same
  markup to keep in step until RD-9, which is exactly the duplication the redesign is meant to remove.
- **Reuse Flux's `.dark` class for the shell.** The shell is dark only, with its own palette. Tying it to
  `.dark` would make it follow the old theme toggle and mix the two palettes.

## Consequences

- Shared components carry three tones for a while (`light`, `dark:`, `shell:`). RD-9 removes the first two
  when the old pages go.
- New shell-only views use the palette tokens directly (`bg-surface`, `text-ink-muted`) and need no variant.
- `@theme` defines the palette tokens globally, so a palette class outside `.shell` still resolves. It just
  looks wrong on a light page.
