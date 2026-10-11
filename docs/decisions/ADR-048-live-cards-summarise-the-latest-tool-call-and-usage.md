# ADR-048 — Live cards summarise the latest tool call and usage record from the transcript tail

Date: 2026-10-11 · Status: accepted · Story: RD-5

## Context

Home A's Live now cards show what each session is doing and how full its context is. Until RD-5, `SessionReader`
kept only five fields of one line (`cwd`, `gitBranch`, `timestamp`, `sessionId`, `version`), and never message
content (ADR-023). The new facts live inside assistant messages: their `tool_use` blocks and `usage` records. A
transcript names the model but not whether it runs with the 1M-token context.

## Decision

- `SessionReader::activity()` walks the existing tail (`TAIL_BYTES`) from the end, decoding only lines that
  mention `"assistant"`, and skips sidechain (subagent) turns. It stops once it has the newest usage record and
  the newest `tool_use` block. Each is cut down in the same call, so nothing else of the message survives it.
- **Doing** is one line built by `summarise()`: the tool name plus a path relative to the session cwd, or for Bash
  the first line of the command with a leading `cd … &&` stripped. Control characters are flattened and the line
  is cut to 120 chars. Edit/Write content, prompts and tool output are never used.
- **Tokens** = input + cache creation + cache read + output. A record with a non-integer or negative field reads as
  absent, and logs `board.live_session_unparsed` once per file version.
- **Window** is 200k, unless the model id contains `[1m]` or the tokens already exceed 200k, in which case it is
  1M. The bar turns amber at 60% or more.
- **Started** is the first timestamp in the file's first 64 KB (`HEAD_BYTES`), because a long session's tail no
  longer holds line 1.

Alternatives rejected:
- **Show the last assistant text or the tool's input in full.** Breaks ADR-023's rule that message content is
  never rendered, and an edit's content can be a whole file.
- **Assume 200k always.** A long-context session would read over 100%.
- **A lookup table of model ids to windows.** It goes stale with every model release; the two-signal rule needs none.

## Consequences

- **Trade-off, accepted:** whatever is typed on a command line surfaces on the card (first line, up to 120 chars),
  including anything sensitive passed inline. This is acceptable only because the board is an owner-only localhost
  app. Revisit before SB-6 puts it on a domain.
- A 1M session under 200k whose model id lacks `[1m]` is measured against 200k until it passes 200k.
- Each scan decodes more lines per file (bounded by the tail), still once per 20 s cache window.
- The reader is more tied to the transcript shape. A format change degrades fields to "—" or "No tool calls yet"
  and logs a warning; it does not break the page.
