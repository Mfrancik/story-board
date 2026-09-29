#!/usr/bin/env python3
"""document-trigger — notice when a story just became built, and ask for its docs.

CLAUDE.md step 7 makes `/document` mandatory: a story without docs is not done.
Mandatory-by-instruction is not the same as mandatory-in-practice, though — the
documentation step lands at the end of a long session, which is exactly where
instructions get skipped and where the model is most inclined to call it a day.

So this watches for the one event that means a story shipped, and says so. It is a
reminder, not an executor: a hook cannot spawn a subagent, and it should not try to
force one. It prints, the session dispatches, the user sees the dispatch happen.

The trigger is deliberately narrow. Per CLAUDE.md step 6, `feat|fix|test` are build
commits and they flip the story's `Status:` to `built` in that same commit. So the
signal is not "a commit happened" and not even "a feat commit happened" — it is
**a commit that flipped a story to built**. That fires once per story, at the right
moment, and never on the `test(...)` commit halfway through a build or on the
`docs(...)` commit that follows.

Fires once per commit SHA. Ignoring the reminder does not make it nag: a hook that
re-fires forever trains you to skip it, which costs more than the reminder is worth.

Wired as a PostToolUse hook on Bash. Failure is always silent — a broken reminder
must never break the session that was doing real work.
"""

import json
import re
import subprocess
import sys
from pathlib import Path

# `[A-Z]{2,}-[0-9]+[a-z]?` is CLAUDE.md's story-ID shape (AP-1, SS-10, MT-2a).
BUILD_COMMIT = re.compile(r"^(feat|fix|test)\(([A-Z]{2,}-[0-9]+[a-z]?)\):")
STATE = "document-trigger-state"


def git(*args, cwd=None):
    """Run git, returning stripped stdout or None. Never raises."""
    try:
        out = subprocess.run(
            ["git", *args], cwd=cwd, capture_output=True, text=True, timeout=10
        )
    except (OSError, subprocess.SubprocessError):
        return None
    return out.stdout.strip() if out.returncode == 0 else None


def state_path():
    """Where the last-fired SHA lives.

    Resolved via `git rev-parse --git-dir` rather than assuming `.git/` is a
    directory, because this project builds in worktrees, where `.git` is a FILE
    pointing elsewhere. Writing to `<worktree>/.git/...` there would fail; worse,
    a shared state file would let one worktree suppress another's reminder.
    """
    d = git("rev-parse", "--absolute-git-dir")
    return Path(d) / STATE if d else None


def flipped_to_built(sha):
    """The story ID this commit flipped to `built`, or None.

    Reads the commit's own diff for a `+Status:` line landing on `built` under
    `stories/`. Checking the diff rather than the file's current content is what
    makes this fire once, at the flip, instead of on every later commit while the
    story sits there built.
    """
    diff = git("diff", f"{sha}~1", sha, "--unified=0", "--", "stories/")
    if diff is None:  # root commit, or no stories/ path — nothing to flip
        return None
    if not any(
        line.startswith("+") and "Status:" in line and "built" in line
        for line in diff.splitlines()
    ):
        return None

    subject = git("log", "-1", "--format=%s", sha) or ""
    m = BUILD_COMMIT.match(subject)
    return m.group(2) if m else None


def main():
    try:
        json.load(sys.stdin)  # payload unused; consumed so the hook never blocks
    except (json.JSONDecodeError, ValueError):
        pass

    sha = git("rev-parse", "HEAD")
    if not sha:
        return 0

    path = state_path()
    if path is None:
        return 0
    if path.exists() and path.read_text(errors="ignore").strip() == sha:
        return 0  # already said this, once is the deal

    story = flipped_to_built(sha)
    if not story:
        return 0

    try:
        path.write_text(sha + "\n")
    except OSError:
        return 0  # unwritable state would mean nagging every turn — stay quiet

    print(
        f"{story} just flipped to Status: built in {sha[:8]}, so step 7 of the loop "
        f"is now due: its docs.\n\n"
        f"Invoke the `document` skill. It dispatches the `documenter` subagent — do "
        f"NOT write the docs in this session. You are near the end of a long "
        f"session, which is the most expensive possible place to write them; the "
        f"subagent starts empty and reconstructs from the story file plus the diff.\n\n"
        f"What you owe the subagent is the handoff note: the decisions, alternatives "
        f"and solved bugs that are invisible in the diff. Write that from the context "
        f"you already have, then hand off.\n\n"
        f"If the user would rather stop here, say the docs are outstanding and leave "
        f"it to them — this is a reminder, not an instruction to override them."
    )
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception:
        sys.exit(0)
