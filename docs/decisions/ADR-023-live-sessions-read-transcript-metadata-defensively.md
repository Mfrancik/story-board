# ADR-023 — Live sessions read Claude Code's transcript metadata, defensively

Date: 2026-09-29 · Status: accepted

## Context

SB-11 shows which Claude Code sessions are active now, and where. Claude Code already writes a JSONL
transcript per session under `~/.claude/projects/<folder>/<sessionId>.jsonl`. Each line carries `cwd`,
`gitBranch`, `timestamp`, `sessionId` and `version`, beside the message itself. The format is not
documented and may change between releases. Two versions (2.1.283, 2.1.284) were writing these files on
the day the story was checked against real data. The owner asked for metadata only: no message text.

## Decision

The board reads the transcripts directly, and defensively (`app/Services/SessionReader.php`):
- It reads only files whose mtime is within `LIVE_MINUTES`, and only their last `TAIL_BYTES`. It never
  follows a path outside the configured root (`realpath`-checked). It never writes and never runs git.
- It takes the newest line that decodes to an object with a string `cwd`, and cuts it down straight away
  to the five fields. Every other field, the message included, is dropped inside `lastValidLine()`.
- Every failure degrades instead of throwing. An unreadable file is skipped and logged once
  (`board.session_unreadable`). If every file fails, the panel says "Sessions unavailable". If the root is
  missing, the panel says "No Claude Code sessions folder found". The rest of the page is never affected.
- The root is a config value (`config/board.php` `sessions_path`, env `BOARD_SESSIONS_PATH`).

Alternative rejected:
- **A kit hook that posts session state to the board.** It would give a documented, owned format, but it
  needs changes to the dev-standards kit, a writer (an endpoint or a shared file) that the board does not
  have today, and a hook installed in every project. The transcripts already hold the data.

## Consequences

- A Claude Code release that changes the transcript format silently empties or degrades the panel. The
  signal is `board.session_unreadable` with `reason: no_valid_line` on every file, or cards reading "no
  branch". The fix is confined to `SessionReader`.
- Liveness is mtime, not process state. A process check can follow if mtime proves too coarse.
- Because the board reads a folder outside its repo, tests must never inherit the real one:
  `tests/TestCase.php` points `board.sessions_path` at an empty temp folder by default.
