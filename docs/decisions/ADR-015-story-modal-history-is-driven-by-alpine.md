# ADR-015 — The story modal's browser history is driven by Alpine, not by Livewire's `#[Url(history: true)]`

Date: 2026-09-29 · Status: accepted

## Context

SB-8's modal is addressed by `?story=<project>/<ID>`. Back must close it, Escape must close it and
remove `?story`, and closing must never leave the board. The obvious tool is Livewire's
`#[Url(history: true)]` on `StoryModal::$story`, which pushes a history entry whenever the property
changes.

Livewire pushes that entry only after the server round trip returns. When Escape was pressed before
the response landed, the close logic stepped back one entry more than had actually been pushed. The
browser left the board for `about:blank`. It happened intermittently, depending on request timing,
which made the browser test flaky (see the RUNBOOK entry).

## Decision

The view (`resources/views/livewire/board/story-modal.blade.php`, the root `x-data`) owns history:
- `show(link)` calls `history.pushState` **before** `$wire.open(link)` and increments `depth`. The
  count and the real history therefore always agree, whatever the server's timing.
- `close()` steps back through every entry it pushed (`history.go(-depth)`). A modal opened directly
  from a URL has `depth === 0` and closes with `$wire.close()` instead, so it never navigates away.
- `popped()` (on `popstate`) re-reads `?story` from `location` and either loads that story or closes.
- `StoryModal::$story` stays `#[Url(except: '')]` without `history: true`, so Livewire only
  *replaces* the current entry to keep `?story` in step. `except: ''` stops Livewire leaving an empty
  `?story=` in the URL after close.

Alternatives rejected:
- **`#[Url(history: true)]`**: its asynchronous push is the cause of the bug above.
- **Waiting for the round trip before handling Escape**: Escape would feel dead during a slow git
  read, and the race would still be there for Back.

## Consequences

- Opening a story from a row or a chip and then closing it leaves history where it was before the
  first open, so Back after closing goes to the page the owner came from.
- History logic lives in Alpine rather than PHP, so it is covered only by the browser tests
  (`tests/Browser/StoryModalTest.php`). The feature tests exercise `open()`, `close()` and a browser
  setting `story` to empty.
- Anything else that later opens the modal must dispatch `board-story` rather than call `$wire.open()`
  directly, or `depth` will drift from history.
