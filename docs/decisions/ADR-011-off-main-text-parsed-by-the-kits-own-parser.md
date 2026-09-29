# ADR-011 — Story text that is not at a ref is parsed by the kit's own parser

Date: 2026-09-29 · Status: accepted

## Context

The ref snapshot comes from the kit's `bin/story-index <repo> <ref>`, which reads stories through git
at a ref. SB-5 also has to parse texts that no ref holds in one piece: a file taken from a branch tip,
and an untracked file in a checkout. The board could parse them itself.

## Decision

`app/Services/StoryParser.php` pipes `{readme, files, mockup_files}` as JSON to
`scripts/parse-story-files.py`. That script loads `bin/story-index` as a Python module (read-only,
never modified) and calls its own `parse_vocabulary()` and `index_story()` on each text. The output is
the same record shape as the ref snapshot.

Alternatives rejected:
- **A second parser in PHP.** It would drift from the kit's parser, so a branch version and the ref
  version of the same story could be read by different rules. This is the same reason as
  [ADR-009](ADR-009-unparsed-choice-is-quoted-not-guessed.md): one parser, owned by the kit.
- **Changing `bin/story-index` to accept stdin.** That would change the kit, which the board does not
  own.

## Consequences

- A kit parser fix (for example F-1, bold `Chosen option`) reaches off-main rows on the next refresh
  with no board change.
- The shim depends on `index_story()` and `parse_vocabulary()` keeping their signatures. These are
  internal functions, not the kit's documented CLI contract. A kit refactor that renames them breaks
  the off-main scan: `board.offmain_failed` fires and the ref snapshot is unaffected.
