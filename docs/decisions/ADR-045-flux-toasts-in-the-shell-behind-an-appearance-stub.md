# ADR-045 — Flux toasts in the shell, behind an appearance stub

Date: 2026-10-11 · Status: accepted · Story: RD-4

## Context

`layouts/shell` (RD-2) loaded no Flux at all: it is dark only, so it skipped `@fluxAppearance`, and it had no
toast host. Three things RD-4 moves in confirm their actions with `Flux::toast`: Manage projects, its lazy
`ProductionSettings` panels and the mockup viewer's Pick. The story says behaviour is unchanged.

Loading `flux.js` (needed for toasts) has a side effect: it runs `window.Flux.applyAppearance()` (see the
amendment below for exactly when), and with that undefined it clears the light/dark choice the old pages saved in
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

## Amendment — 2026-10-11 (RD-4 WARN-1, `test(RD-4): light/dark choice survives a round trip through the shell`)

- The Context above is imprecise. At `alpine:init`, `flux.js` reads `window.Flux?.applyAppearance` **once**, on the
  first full page load, into a local (falling back to a function that sets `Flux.appearance = null` and removes
  `localStorage['flux.appearance']`). It then calls that captured function from an `Alpine.effect` and on every
  `livewire:navigated`. The function from the first full load serves the whole `wire:navigate` session.
- So the stub only runs in a session that **starts** with a full load of a shell page. A session that starts on an
  old page captures the real `@fluxAppearance` function and keeps using it inside the shell; that function adds
  `.dark` to the shell's `<html>` when the saved choice is dark, which is why the `.shell, .shell.dark` bullet in
  the Decision is needed.
- The last Consequence no longer holds: the stub is now pinned by a preference test.
  `tests/Browser/ReskinKeptPagesTest.php` "keeps the light choice an old page saved through a wire:navigate round
  trip into the shell" runs the browser in dark mode with `flux.appearance = light` saved, then does two legs: old
  page → shell → back, and a full load of a shell page → old page. Deleting the stub turns the second leg red; the
  first leg stays green by design, since it never runs the stub.
