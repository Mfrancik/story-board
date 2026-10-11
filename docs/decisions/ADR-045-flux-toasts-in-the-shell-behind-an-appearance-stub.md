# ADR-045 — Flux toasts in the shell, behind an appearance stub

Date: 2026-10-11 · Status: accepted · Story: RD-4

## Context

`layouts/shell` (RD-2) loaded no Flux at all: it is dark only, so it skipped `@fluxAppearance`, and it had no
toast host. Three things RD-4 moves in confirm their actions with `Flux::toast`: Manage projects, its lazy
`ProductionSettings` panels and the mockup viewer's Pick. The story says behaviour is unchanged.

Loading `flux.js` (needed for toasts) has a side effect: on load and after each `wire:navigate` swap it calls
`window.Flux.applyAppearance()`, and with that undefined it clears the light/dark choice the old pages saved in
`localStorage`. Flux also styles a toast dark only under a `.dark` ancestor, which the shell never sets.

## Decision

- `layouts/shell.blade.php` adds `@persist('toast')` around `<flux:toast.group><flux:toast /></flux:toast.group>`,
  wrapped in `<div class="dark">`, plus `@fluxScripts`. The wrapper opts the toast alone into Flux's dark look.
- `partials/head.blade.php`, for shell pages, defines a stub `window.Flux = { applyAppearance() }` before
  `flux.js` runs. It only removes `.dark` from `<html>` when `<html>` has `.shell`, and never writes the
  saved choice. Old pages keep `@fluxAppearance`.
- `app.css` matches `.shell, .shell.dark` for the token re-pointing, so the shell's colours win even if `.dark`
  survives a navigation from an old page.

Alternatives rejected:
- **Replace the toasts with inline messages** (as the RD-3 story page does). A behaviour change on pages the owner
  kept as they are, and three components' worth of edits.
- **Put `.dark` on the shell's `<html>`.** Every old `dark:` class in a shared primitive would then fire inside
  the shell and fight the `shell:` tones.
- **Load `@fluxAppearance` in the shell.** It would apply the saved light choice to a dark-only page.

## Consequences

- Any shell page can raise a Flux toast. The story page still uses its inline result.
- Light/dark on the old pages survives a round trip through the shell.
- The stub depends on Flux's `applyAppearance` hook name; a Flux upgrade that renames it would bring back the
  wiped preference (pinned by `ReskinKeptPagesTest`'s check that shell pages ship no `flux.appearance`, and by
  the browser test's no-JS-error check, not by a preference test).
