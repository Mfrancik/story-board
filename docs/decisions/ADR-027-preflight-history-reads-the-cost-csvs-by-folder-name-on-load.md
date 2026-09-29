# ADR-027 — Preflight history reads the cost CSVs by folder name, on every load

Date: 2026-09-29 · Status: accepted

## Context

SB-16 shows a project's preflight runs. `bin/preflight-meter.py` appends one row per run to
`preflight-cost.csv` under `~/.claude/projects/<encoded path>/`, where the path is the checkout that ran
preflight. That checkout can be the project's own folder, a worktree (`.claude/worktrees/<name>`), or the
`.claude/plans` folder, and each one gets its own encoded folder. Several questions came up: where the
root comes from, which folders belong to which project, and whether to store anything.

## Decision

- **Root:** reuse `board.sessions_path` (SB-11, default `$HOME/.claude/projects`). The story named a new
  `board.claude_projects_path` key, but that is the same directory, and a new key would have meant
  touching `config/` (owner approval) for no behavioural gain.
- **Encoding:** every non-alphanumeric character of the project path becomes `-`. This is the same rule
  as `SessionFixture::folderFor()`.
- **Matching:** a folder belongs to the project only if its name is exactly the encoded path, the
  encoded path plus `--claude-plans`, or starts with the encoded path plus `--claude-worktrees-`. A plain
  prefix match would claim `story-board-x` for `story-board`.
- **Case-insensitive:** macOS paths are case-insensitive, and the registered path
  (`/Users/mikefrancik/code/story-board`) differs in case from the folder Claude made
  (`-Users-mikefrancik-Code-story-board`).
- **No storage:** files are read by header name on every page load. Nothing goes into the database.
  Nothing is ever written, locked or moved.

Alternatives rejected:
- **A new config key.** It duplicates `sessions_path` and needs a `config/` change.
- **Prefix or glob matching on the encoded path.** It leaks sibling projects' runs.
- **Importing rows into MySQL.** The story ruled it out. It would also need a sync step, and the CSV
  would stop being the one record.

## Consequences

- Moving `board.sessions_path` (env `BOARD_SESSIONS_PATH`) moves both Live sessions and Preflight history.
- On a case-sensitive filesystem, two folders that differ only by case would both match. That is
  accepted for a localhost macOS tool.
- A new meter column is picked up only if `ReadPreflightHistory::run()` names it. A renamed column
  silently becomes "—".
