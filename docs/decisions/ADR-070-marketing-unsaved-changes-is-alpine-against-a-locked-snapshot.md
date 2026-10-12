# ADR-070 — "Unsaved changes" is computed in Alpine against a locked server snapshot

Date: 2026-10-12 · Status: accepted · Story: MK-7

## Context

The criteria form is edited in place, with Save / Discard and an "Unsaved changes" marker. Computing "dirty" on the
server would need a request on every keystroke (live `wire:model`), which CLAUDE.md rules out for pure UI state. A
client-only baseline captured at page load would go stale after Save, and the client could move it.

## Decision

- The inputs are deferred `wire:model`s, so typing makes no request.
- `CampaignPage::$stored` is a `#[Locked]` snapshot of the criteria as last saved. `snapshot()` normalises it: days
  as sorted ints, ideas sorted, own topics as text and detail. The view's Alpine `dirty` getter normalises the live
  `$wire` values the same way and compares the two as JSON.
- Only add or remove an own topic, Save, Discard and the on/off switch make a request. Save and Discard rebuild
  `stored` through `fillFrom()`. Discard reloads from the database, not from the snapshot.
- The on/off switch is a separate Action (`ToggleCampaign`) and leaves unsaved criteria in the form.

Alternatives rejected:
- **Live `wire:model` with a server-side dirty flag.** A request per keystroke for a UI-only marker.
- **A pure Alpine baseline taken on init.** It can drift from what the DB holds after a save, and the client owns it.
- **Discard restores from the snapshot.** If anything else changed the row since then (another tab, or the seed),
  the form would show values that are not stored.

## Consequences

- The two normalisations (PHP `snapshot()` and the Alpine `current()`) must change together. If one changes alone,
  the marker shows a false "Unsaved changes" or misses a real one.
- The create page's sentence is server-rendered, so it updates only on a round trip.
