# ADR-024 — Handbook sections load on first open and stay client-side

Date: 2026-09-29 · Status: accepted

## Context

SB-14's handbook shows six sections per project (rules, lessons, standards, runbook, decisions, skills),
each read from git. Some are large: coins' `docs/RUNBOOK.md` is about 20,000 lines, and its
`docs/decisions/` holds 1,747 files. The story asks for content to load through Livewire only when a
tab is first opened, with the tabs themselves in Alpine.

The usual Livewire shape would keep each loaded section's HTML in component state and re-render. But
a Livewire re-render sends the whole component's HTML back. Once the runbook was loaded, every later
tab click would resend it, and every other section already open.

## Decision

`app/Livewire/Board/ProjectHandbook.php` renders once. The first tab is filled server-side. Every
other section comes from `loadSection()`, and every decision from `openDecision()`. Both are
`#[Renderless]` and return an HTML string. Alpine keeps the strings in `bodies` and injects them with
`x-html`, so each section crosses the wire once per visit. Tab state, the loaded list, the decisions
filter and paging, and the decision modal are all Alpine. The component holds only locked identity
props (project name, kit path and SHA, initial tab, a `?decision=` from the URL).

Alternatives rejected:
- **A re-rendering component holding loaded sections.** Simple, but it resends every loaded section on
  each click, which is worst on exactly the projects the page is for.
- **Render every section on page load and switch with `x-show`.** No round trips, but coins' first
  paint would pay for its runbook and every other section, even when the owner only wanted the rules.
- **A separate route per section.** Real URLs, but a full page load per tab, and it gives up the
  single reading card the chosen mockup asked for.

## Consequences

- A tab opened twice costs one git read and one `board.handbook_viewed` line, not two.
- A section's HTML is not refreshed during a visit. A new snapshot shows on the next page load.
- Anything that needs every section at once (tab counts, a drift summary line) conflicts with this and
  was left out. See the feature doc's known limitations.
- Because renderless calls skip the route middleware, `hydrate()` re-checks the project on every
  request (ADR-019 amendment) and returns nothing to a call that arrives after it was switched off.
