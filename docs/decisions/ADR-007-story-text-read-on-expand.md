# ADR-007 — Story text is read from git when a row is expanded, not stored in the snapshot

Date: 2026-09-29 · Status: accepted

## Context

At SB-3's mockup gate the owner asked for home-page rows that expand in place to show the story text
and its mockups. The `stories` snapshot holds only the fields the lists need. coins alone has
hundreds of stories, and a body is many times the size of a row's other fields. Most bodies are
never opened.

## Decision

`app/Actions/Board/RenderStory.php` reads the file through `GitReader::show()` at the row's own `sha`,
so the body and the row always describe the same commit. It renders the markdown with
`Str::markdown(['html_input' => 'escape', 'allow_unsafe_links' => false])`. `Home::expand()` calls it
once per story, the first time that story is opened. The HTML is kept in the component's `bodies`
property, which is `#[Locked]` because the view prints it raw.

Alternatives rejected:
- A `body` column in `stories`. It would grow the snapshot by roughly 10x and make every wholesale
  replace slower, all for text that is rarely read.
- Rendering the body on every row at page load. That is a git process per row, which is the cost the
  snapshot exists to avoid.

## Consequences

- Expanding a row is the one request-time git read on the home page. That is an exception to "the
  lists read only the `stories` table", and the owner ruled it in at the gate.
- A body read fails if the snapshot's SHA has been garbage-collected from the project (for example
  after a force-push and gc). `RenderStory` then logs `board.story_read_failed` and the row says so.
- Markdown from other repos is treated as untrusted: raw HTML is escaped and unsafe links are
  dropped.
