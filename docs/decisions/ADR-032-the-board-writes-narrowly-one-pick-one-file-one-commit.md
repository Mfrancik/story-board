# ADR-032 — The board writes, narrowly: one pick, one file, one commit, through one gateway

Date: 2026-09-29 · Status: accepted · Story: SB-21

## Context

Until SB-21 the board never wrote to a registered project (owner ruling, SB-2; ADR-004). Picking a
mockup direction meant opening the story file and editing its `## Design mockup gate` by hand. SB-21
lets the owner pick from the full-screen viewer (owner 2026-09-29: "Yes, in SB-21"; write mode: commit
just that file). That is the first write the board makes into another repo. The owner's checkouts often
hold unrelated staged work, a half-finished rebase, or a different branch checked out by a build agent.

## Decision

`app/Services/StoryPickWriter.php` is the **only** code in the board that writes to a registered
project. GitReader stays read-only: its allow-list does not gain `commit` or `add`.

- **What it writes.** Exactly two lines of one story file, in place: the gate's `- Chosen option:` value
  (set to the letter) and `- Why I chose it:` (set to `owner pick <date> (board): <reason>`; added under
  Chosen when missing). Every other byte stays as it was. The reason is one line, whitespace collapsed,
  cut to 200 characters.
- **How it commits.** `git commit --only --no-verify -m "docs(<ID>): record mockup pick <x>" -- <path>`
  on the checkout's current branch. `--only` commits that path's working-tree content and leaves every
  other staged change staged and out of the commit. `--no-verify` follows the same principle as
  GitReader's `core.fsmonitor=false`: the board runs git, never a project's own helpers. It never pushes.
  If the commit fails, the file's original bytes are put back.
- **When it refuses, writing nothing** (logged as `board.mockup_pick_refused`, warning, with the reason):
  - the project is off the board;
  - the option is not one of the set's options;
  - the ref already records a letter;
  - the story is not `draft` or `approved`;
  - the story file is not a plain file inside the checkout (a symlink counts as not);
  - a rebase, merge, cherry-pick, revert or bisect is in progress;
  - the checkout is on a different branch from the one the board reads (named in the reason);
  - the file has uncommitted changes;
  - there is no gate section or no `Chosen option:` line;
  - the Chosen line already holds anything but the template placeholder.
- **One rule for "can be picked".** `refusalFor()` is pure. `ReadMockupSets` uses it to decide which
  sets show as awaiting and get Pick buttons, so the gallery and the writer can never disagree.
- **Reads go through GitReader.** Branch, git dir, status and the new batch read (`showMany`, one
  `cat-file --batch`) all run there. Only `commit` runs outside it, with the same environment and a
  30-second timeout.

Alternatives rejected:
- **Add `commit` to GitReader's allow-list.** That turns the read-only gateway into a read-write one, and
  every future caller could then commit. Keeping writes in a separate, single-purpose class keeps ADR-004
  true for everything else.
- **Stage and commit with `add` + `commit`.** That would sweep the owner's staged work into the pick
  commit, or require unstaging it. `--only` avoids both.
- **Send the pick to the session that owns the story (SB-13's model).** No session may be running. The
  owner asked for the board to commit the file itself.
- **Re-picking or clearing a pick from the board.** Out of scope. The writer refuses any Chosen line that
  is not the placeholder.

## Consequences

- A pick exists only in the checkout until the owner pushes. The gallery re-reads awaiting sets' stories
  on the checkout's branch and shows them as "Picked X · not pushed". The story modal and the story page
  read the ref, so they show the pick only after a push and a refresh.
- A new kind of board write means changing this class, which is a visible, reviewable diff. A second
  writer is a design change, not a convenience.
- Picking from a checkout that is behind the ref is refused only if the ref already has a letter. If the
  story changed on the ref in some other way, the pick commit can later conflict with it on pull. That
  conflict belongs to the owner, just as it would for a hand edit.
