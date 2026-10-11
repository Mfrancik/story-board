# ADR-038 — Kept board logic moves into pure shared classes

Date: 2026-10-11 · Status: accepted · Story: RD-1

## Context

The redesign keeps the backend and replaces the pages. The audit found the kept backend tied to pages RD-9
will delete:
- `ReadProjectStories` reached into `ReadProjectProgress`, which reached into `ListWhatNeedsMe`, for status order.
- "Open" was defined three times.
- `StoryPickWriter` doubled as the gallery's gate parser, and shelled out to `git remote` for the branch.
- Five Livewire pages each carried the same `hydrate()` guard.

## Decision

- **`StoryStatusOrder`** is a static, pure class in `app/Services`. It holds `ORDER`, `OPEN`, `isOpen()`, `ordered()`
  and `rollup()`. Its `OPEN` replaces the three copies of `['draft','approved']` (the pick gate, the rollup's `open`
  column and the What needs me pick query). It is in `app/Services` because `app/Concerns` was starter kit and is
  now deleted.
- **`MockupGateParser`** is pure. `boardBranch($ref, $remotes)` takes the remote names. Callers get them from
  the new `GitReader::remotes()`, which is one method, so the parsing is not copied into three callers.
  Rejected: the parser running `git remote` itself, because the story requires a pure parser.
- **`StoryPickWriter::pick()` returns `array{sha, branch}`.** The writer is write-only now. It already knows the
  branch from `assertCheckoutReady()`, and `MockupViewer`'s toast used to call `$writer->boardBranch()` for it.
  Rejected: `MockupViewer` reading the git remotes itself, which would put git logic in a component. Nothing
  used the old string return.
- **The `GuardsProjectPage` trait has two override hooks**, `refusedRoute()` and `logProjectRefused()`.
  `MockupViewer` returns to `mockups`, and `ProjectHandbook` keeps its `board.handbook_refused` event. Rejected:
  unifying the redirect and event, which would change behaviour and event names in a story with no visible change.

## Consequences

- Arch tests (`tests/Unit/FoundationArchTest.php`) pin the independence and the purity.
- A new caller of `boardBranch()` must pass `GitReader::remotes($path)`.
- A new one-project page uses the trait rather than copying `hydrate()`. `ProjectPage` keeps its own guard.
