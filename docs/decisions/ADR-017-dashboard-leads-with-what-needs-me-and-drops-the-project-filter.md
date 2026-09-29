# ADR-017 — The dashboard leads with What needs me, and the project filter is removed, not hidden

Date: 2026-09-29 · Status: accepted

## Context

SB-9 turns `/` into the all-projects dashboard in the design-A layout
(`docs/mockups/SB-7/option-a.html`, view 1). The mockup's `h1` was "All projects". The owner's
ruling for SB-9 is that the home page leads with what needs a decision. SB-7 had already added the
project sidebar and `RedirectProjectFilter`, which sends any `/?project=<name>` to `/p/<name>` before
`Home` runs. That left `Home`'s `#[Url] public string $project` dropdown on `/` beside the sidebar,
doing the same job twice.

## Decision

- The first heading in `<main>` on `/` is "What needs me". "All projects · N projects · N stories"
  is the subtitle. The What needs me cards come before In flight and the project tiles.
- `Home::$project` is deleted, along with its `<select>`, its reset in `clearFilters()` and its part of
  the `filtered` flag. `render()` narrows by `$pinned` only. `board.home_viewed` logs
  `{initiative, q}`; the `project` key is gone.

Alternatives rejected:
- **Keep the mockup's "All projects" `h1`.** It describes scope, not purpose, and the owner ruled
  that the page leads with decisions.
- **Hide the dropdown but keep the property.** `/` can never carry `?project=` (the redirect runs
  first), so the property would be dead state that the browser could still set, and a second way to
  scope the page that the sidebar does not know about.

## Consequences

- The sidebar is the only project switcher. A shared `/?project=` link still works through SB-7's redirect.
- Log queries that read `board.home_viewed.project` get nothing from SB-9 on. Use
  `board.project_viewed` for project pages.
- ADR-014's description of `render()` preferring `pinned` over the `project` filter is now history:
  there is no filter to prefer over. The pinned mode itself is unchanged until SB-10.
