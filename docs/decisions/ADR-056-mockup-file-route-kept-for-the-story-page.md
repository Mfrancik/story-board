# ADR-056 — The mockup file route stays, serving the story page's frame and `?v` versions

Date: 2026-10-11 · Status: accepted · Story: RD-9

## Context

RD-9's story listed `/p/{project}/m/{id}/{file}` for deletion with `MockupFileController`, `ReadMockupFile`,
`ParseVersion` and `MockupFileTest`, and its acceptance criteria said the route should 404. The grep at build time
showed a live caller. `Story::mockupUrl()` builds `mockups.file` URLs, adding `v=<row id>` for an off-main row, and the
story page (RD-3, kept) loads its sandboxed mockup frame through them. The gallery's own file route,
`mockups.frame` (`MockupSetFileController`), serves the ref only and has no `?v` support.

## Decision

Keep the route, its controller, both actions and `MockupFileTest` (owner ruling at build). The "is 404" criterion
covers `/prod` and bare `/p/{project}` only. `RetireOldPagesTest` asserts `/p/fx/m/RD-1/option-a.html` returns 200.

Alternative rejected:
- **Re-point `Story::mockupUrl()` to `mockups.frame` and add `?v` support to `MockupSetFileController`.** This would
  leave one file route, but it means new behaviour and new tests in a deletion story. That is scope growth, and it
  risks the story page's off-main mockups in a step meant to change nothing that is kept.

## Consequences

- Two routes still serve mockup files from git: `mockups.file` (story page, ref or `?v` version) and `mockups.frame`
  (gallery and viewer, ref only). Both go through the same CSP sandbox (ADR-008).
- Merging them stays possible later, as its own story.
