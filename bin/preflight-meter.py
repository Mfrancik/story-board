#!/usr/bin/env python3
"""preflight-meter — measure what a preflight run actually costs.

Preflight grows with the project: every new pillar, journey and feature doc adds
files for the Phase 2 audit to read. The cost of that growth is invisible unless
it is printed, so this meter stamps every preflight turn with its wall time and
token spend. Measurement only — it never blocks a run for being expensive.

Tokens are read back out of the session transcript JSONL that Claude Code writes
as it goes (`~/.claude/projects/<slug>/<session>.jsonl`). Each assistant message
carries a `usage` block; subagent (sidechain) messages carry one too, which is
exactly where a fan-out audit hides its spend, so they are counted the same way.

Subcommands:
  start            mark the beginning of a preflight run (idempotent)
  report [--mode scoped|full]
                   print the label line for the run in progress, append the run's
                   row to the cost CSV (once per run), print the CSV row count
  cancel           discard the run in progress (drops the label requirement)
  hook-pre         PreToolUse hook — auto-start when the /preflight skill fires;
                   refuse a `preflight` subagent dispatch that has no dispatch note
  hook-stop        Stop hook — enforce that the label made it into line one; write
                   the CSV row if `report` never did

The label is a BACKSTOP; the CSV is the RECORD. A label is one line in one turn
that nobody re-reads, which is how a month of growth (a 901 KB audit pack, 28–62
turns per audit against a documented "~6") went unseen. Every run appends one
row to ~/.claude/projects/<project-slug>/preflight-cost.csv — per project,
outside the repo so it is never committed, next to the transcripts it is
derived from. Columns: ts, project, branch, mode, wall_s, turns, tool_calls,
tokens_in, tokens_out, subagent_tokens, pack_bytes, audit_model.

Two things start a run, both of which mean preflight is genuinely executing:
`preflight.sh` calling `start` from inside a full gate run, and the /preflight
skill being invoked (exact tool match). Deliberately NOT a hook that greps Bash
commands for "preflight.sh" — `grep preflight.sh`, `cat preflight.sh` and any
command quoting the name would arm the meter too, and a 500k-token label on a
turn that audited nothing is worse than no label at all.

Marker state lives in $TMPDIR/preflight-meter/<session>.json: per-session, so
two sessions on one repo do not clobber each other, and disposable, so a stale
marker dies with the temp dir rather than being committed.
"""

import json
import os
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

# The label every preflight turn must open with. hook-stop greps line one for
# this prefix, so the skill's format and the gate's check cannot drift apart.
LABEL_PREFIX = "preflight —"


# --- locating the transcript ------------------------------------------------

def project_slug(cwd):
    """Claude Code's project dir name: the cwd with every non-alphanumeric run
    flattened to a dash (/Users/me/Code/app -> -Users-me-Code-app)."""
    return re.sub(r"[^A-Za-z0-9]", "-", str(cwd))


def find_transcript(cwd):
    """Newest transcript for this project, or None.

    Only used when no hook supplied `transcript_path` (i.e. a human ran
    `report` by hand). Newest-by-mtime is a guess when several sessions share a
    repo, which is why the hook path is always preferred.
    """
    base = Path.home() / ".claude" / "projects" / project_slug(cwd)
    if not base.is_dir():
        return None
    # Exact match first: transcripts are named <session>.jsonl and the session id
    # is in the environment of every tool shell.
    env_session = os.environ.get("CLAUDE_CODE_SESSION_ID")
    if env_session and (base / f"{env_session}.jsonl").is_file():
        return base / f"{env_session}.jsonl"
    logs = sorted(base.glob("*.jsonl"), key=lambda p: p.stat().st_mtime, reverse=True)
    return logs[0] if logs else None


# --- marker state ----------------------------------------------------------

def marker_path(session_id):
    d = Path(os.environ.get("TMPDIR", "/tmp")) / "preflight-meter"
    d.mkdir(parents=True, exist_ok=True)
    return d / f"{session_id or 'manual'}.json"


def read_marker(session_id):
    p = marker_path(session_id)
    if not p.is_file():
        return None
    try:
        return json.loads(p.read_text())
    except (json.JSONDecodeError, OSError):
        # A corrupt marker must not take the turn down with it — treat it as
        # "no run in progress" and let the next start() write a clean one.
        return None


def write_marker(session_id, data):
    marker_path(session_id).write_text(json.dumps(data))


def clear_marker(session_id):
    try:
        marker_path(session_id).unlink()
    except OSError:
        pass


def resolve_session(transcript, override=None):
    """Which session's marker does a hook-less `start`/`report` belong to?

    Order matters: an explicit --session, then CLAUDE_CODE_SESSION_ID (exported
    into every tool shell, so it is exact — no guessing from mtimes even when two
    sessions share the repo), then the transcript filename stem, whose stem IS
    the session id, then any marker recording this exact transcript.
    """
    if override:
        return override
    env_session = os.environ.get("CLAUDE_CODE_SESSION_ID")
    if env_session:
        return env_session
    if not transcript:
        return None
    stem = Path(transcript).stem
    if marker_path(stem).is_file():
        return stem
    d = Path(os.environ.get("TMPDIR", "/tmp")) / "preflight-meter"
    for candidate in d.glob("*.json"):
        try:
            if json.loads(candidate.read_text()).get("transcript") == str(transcript):
                return candidate.stem
        except (json.JSONDecodeError, OSError):
            continue
    return stem


# --- token accounting ------------------------------------------------------

def parse_ts(raw):
    """ISO-8601 with a trailing Z -> aware datetime, or None if unparseable."""
    if not raw:
        return None
    try:
        return datetime.fromisoformat(raw.replace("Z", "+00:00"))
    except ValueError:
        return None


def subagent_logs(transcript):
    """Subagent transcripts for a session, or [].

    Subagent usage is NOT written into the main transcript — there are no
    sidechain rows there. It goes to a sibling tree:
        <project>/<session-id>.jsonl            <- main
        <project>/<session-id>/subagents/agent-*.jsonl   <- one per subagent
    Missing these is not a rounding error. When the audit runs as a subagent
    (the whole point of moving it out of the main context), essentially ALL of
    preflight's spend lands in those files — measured 1.36M tokens in a single
    session — and a label that reads the main transcript alone would report a
    few thousand and call an expensive run cheap.
    """
    p = Path(transcript)
    d = p.parent / p.stem / "subagents"
    return sorted(d.glob("agent-*.jsonl")) if d.is_dir() else []


def tokens_since(transcript, since_iso):
    """Sum input/output tokens across assistant messages logged at or after
    `since_iso`, in this transcript AND any subagent it spawned.

    Returns (input_tokens, output_tokens, subagent_tokens, turns, models,
    tool_calls, pack_bytes).

    `subagent_tokens` is the share of the first two that came from subagents —
    broken out because "the audit is cheap now" and "the audit moved somewhere I
    stopped looking" produce the same main-transcript number.

    `turns` is the count of assistant messages, which is the OTHER half of the
    cost model: spend is turns x context_per_turn, and each turn re-reads what
    the last one pulled in. A run that creeps from 8 turns to 20 gets expensive
    long before any single read looks unreasonable, so the count is surfaced
    next to the tokens rather than left to be inferred from them.

    Streaming writes the same assistant message several times as it grows, each
    copy carrying the same cumulative `usage` and the same `message.id` — so
    counting lines would multiply the bill by 3-4x. Dedupe on message id, keeping
    the first sighting (its timestamp is when the message started, which is what
    the `since` window should test).
    """
    since = parse_ts(since_iso)
    main_in, main_out, turns, _, calls, pack = _usage_from(transcript, since)
    sub_in = sub_out = 0
    sub_models = set()
    for log in subagent_logs(transcript):
        i, o, t, models, c, p = _usage_from(log, since)
        sub_in += i
        sub_out += o
        turns += t
        calls += c
        pack = max(pack, p)
        sub_models |= models
    return main_in + sub_in, main_out + sub_out, sub_in + sub_out, turns, sub_models, calls, pack


# The tool result of `./preflight.sh audit-pack` is either inline or a persisted
# notice; either way its size is the pack's size, and the meter reads it from the
# transcript rather than trusting the pack to report itself (the pack runs in the
# subagent's shell, whose session env is not guaranteed to reach the marker).
PACK_COMMAND = "preflight.sh audit-pack"
PERSISTED_RE = re.compile(r"Output too large \(([\d.]+)\s*KB\)", re.I)


def _result_bytes(content):
    """Size of a tool_result's text; a persisted-output notice is read for the
    real size it names, so a persisted pack is recorded at its true size."""
    if isinstance(content, list):
        text = "".join(b.get("text", "") for b in content if isinstance(b, dict))
    else:
        text = str(content or "")
    m = PERSISTED_RE.search(text)
    if m:
        return int(float(m.group(1)) * 1024)
    return len(text.encode("utf-8", errors="replace"))


def _usage_from(path, since):
    """(input, output, turns, models, tool_calls, pack_bytes) for one transcript
    file, deduped by message id.

    `tool_calls` counts tool_use blocks — the other half of "turns": a run with
    few turns but many calls per turn is the cheap shape, and only the pair
    shows which one a run had. Streaming re-writes a growing message, so the
    count kept per message id is the largest seen.

    `models` is the set of model families that produced the counted messages. The
    audit is pinned to Sonnet in the subagent's frontmatter, which is a claim in a
    file nobody re-reads; this is the measurement that makes it checkable. A run
    that silently comes back on Opus looks identical in every other respect except
    the bill.
    """
    seen = {}
    models = set()
    calls = {}        # message id -> max tool_use blocks seen
    pack_ids = set()  # tool_use ids whose command was the audit pack
    pack_bytes = 0
    with open(path, encoding="utf-8", errors="replace") as fh:
        for line in fh:
            try:
                entry = json.loads(line)
            except json.JSONDecodeError:
                continue
            ts = parse_ts(entry.get("timestamp"))
            if since and ts and ts < since:
                continue
            msg = entry.get("message") or {}
            content = msg.get("content")
            if entry.get("type") == "user" and isinstance(content, list):
                for b in content:
                    if isinstance(b, dict) and b.get("type") == "tool_result" and b.get("tool_use_id") in pack_ids:
                        pack_bytes = max(pack_bytes, _result_bytes(b.get("content")))
                continue
            if entry.get("type") != "assistant":
                continue
            mid = msg.get("id") or entry.get("uuid")
            if isinstance(content, list):
                uses = [b for b in content if isinstance(b, dict) and b.get("type") == "tool_use"]
                calls[mid] = max(calls.get(mid, 0), len(uses))
                for u in uses:
                    cmd = str((u.get("input") or {}).get("command", ""))
                    if PACK_COMMAND in cmd:
                        pack_ids.add(u.get("id"))
            usage = msg.get("usage")
            if not usage:
                continue
            if mid in seen:
                continue
            # No timestamp at all: count it. Under-reporting the number we exist
            # to surface is the worse failure.
            seen[mid] = usage
            family = model_family(msg.get("model"))
            if family:
                models.add(family)

    tokens_in = sum(
        u.get("input_tokens", 0)
        + u.get("cache_creation_input_tokens", 0)
        + u.get("cache_read_input_tokens", 0)
        for u in seen.values()
    )
    tokens_out = sum(u.get("output_tokens", 0) for u in seen.values())
    return tokens_in, tokens_out, len(seen), models, sum(calls.values()), pack_bytes


# --- formatting ------------------------------------------------------------

def human_duration(seconds):
    seconds = int(round(seconds))
    if seconds < 60:
        return f"{seconds}s"
    if seconds < 3600:
        return f"{seconds // 60}m{seconds % 60:02d}s"
    return f"{seconds // 3600}h{(seconds % 3600) // 60:02d}m"


def human_tokens(n):
    if n < 1000:
        return str(n)
    if n < 1_000_000:
        return f"{n / 1000:.1f}k"
    return f"{n / 1_000_000:.2f}M"


# The audit's pinned tier. Anything else in the subagent logs gets flagged in the
# label rather than silently accepted — see model_note().
EXPECTED_AUDIT_MODEL = "sonnet"
MODEL_FAMILIES = ("haiku", "sonnet", "opus", "fable")


def model_family(model_id):
    """'claude-sonnet-5' -> 'sonnet'. Unknown ids pass through; None stays None.

    Matching on the family rather than the exact id means a version bump does not
    start reporting the audit as off-tier.
    """
    if not model_id:
        return None
    lowered = model_id.lower()
    for family in MODEL_FAMILIES:
        if family in lowered:
            return family
    return model_id


def model_note(sub_models):
    """The `· audit sonnet` fragment of the label, or a warning if it is not.

    Cheap-audit/expensive-fix only saves anything if the audit actually ran cheap.
    That is one frontmatter line away from being wrong at any time, and the failure
    is invisible: the report reads the same, only the bill moves. So the tier is
    printed on every labelled turn next to the number it explains.
    """
    if not sub_models:
        return ""
    if sub_models == {EXPECTED_AUDIT_MODEL}:
        return f" · audit {EXPECTED_AUDIT_MODEL}"
    ran = "+".join(sorted(sub_models))
    return f" · audit {ran} (expected {EXPECTED_AUDIT_MODEL} — check the tier)"


def build_label(marker, transcript):
    """The line-one label.

    `preflight — 4m12s — 187.4k tok (in 179.1k / out 8.3k · subagent 171.0k) ·
     14 turns · audit sonnet`
    """
    elapsed = human_duration(time.time() - marker.get("started_at", time.time()))
    if not transcript or not Path(transcript).is_file():
        # Time is still real even when the transcript can't be read; say which
        # half is missing rather than printing a confident zero.
        return f"{LABEL_PREFIX} {elapsed} — tokens unavailable (no transcript)"
    tokens_in, tokens_out, sub, turns, sub_models, _, _ = tokens_since(
        transcript, marker.get("started_iso")
    )
    total = tokens_in + tokens_out
    label = (
        f"{LABEL_PREFIX} {elapsed} — {human_tokens(total)} tok "
        f"(in {human_tokens(tokens_in)} / out {human_tokens(tokens_out)}"
    )
    # Only shown when a subagent actually ran, so the label stays short on the
    # common path but never hides where the spend went when the audit is delegated.
    if sub:
        label += f" · subagent {human_tokens(sub)}"
    return label + f") · {turns} turns{model_note(sub_models)}"


# --- the cost record ---------------------------------------------------------

CSV_COLUMNS = ["ts", "project", "branch", "mode", "wall_s", "turns", "tool_calls",
               "tokens_in", "tokens_out", "subagent_tokens", "pack_bytes", "audit_model"]


def csv_path(cwd):
    """~/.claude/projects/<slug>/preflight-cost.csv — per project, beside its
    transcripts, outside the repo so it can never be committed."""
    return Path.home() / ".claude" / "projects" / project_slug(cwd) / "preflight-cost.csv"


def git_branch(cwd):
    try:
        import subprocess
        out = subprocess.run(["git", "rev-parse", "--abbrev-ref", "HEAD"], cwd=cwd,
                             capture_output=True, text=True, timeout=5)
        return out.stdout.strip() if out.returncode == 0 else ""
    except (OSError, subprocess.SubprocessError):
        return ""


def append_cost_row(session_id, marker, transcript, cwd, mode=None):
    """Append this run's row ONCE. Returns the row count after the write (or the
    existing count if the row was already written), or None if unwritable.

    Exactly-once is the marker's `csv_written` flag: `report` writes it when the
    skill calls it, and hook-stop writes it on the run's final clear if `report`
    never ran — so a run that skipped the label still leaves a row, and a run
    that printed the label twice leaves one.
    """
    path = csv_path(cwd)
    if marker.get("csv_written"):
        return _csv_rows(path)
    mode = mode or marker.get("mode") or "?"
    started = marker.get("started_at", time.time())
    if transcript and Path(transcript).is_file():
        tokens_in, tokens_out, sub, turns, sub_models, calls, pack = tokens_since(
            transcript, marker.get("started_iso"))
    else:
        tokens_in = tokens_out = sub = turns = calls = pack = 0
        sub_models = set()
    row = [
        datetime.fromtimestamp(started, timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z"),
        project_slug(cwd), git_branch(cwd), mode, str(int(round(time.time() - started))),
        str(turns), str(calls), str(tokens_in), str(tokens_out), str(sub), str(pack),
        "+".join(sorted(sub_models)),
    ]
    try:
        import csv
        path.parent.mkdir(parents=True, exist_ok=True)
        new = not path.is_file() or path.stat().st_size == 0
        with open(path, "a", newline="", encoding="utf-8") as fh:
            w = csv.writer(fh)
            if new:
                w.writerow(CSV_COLUMNS)
            w.writerow(row)
    except OSError:
        return None  # an unwritable record must never break the turn
    marker["csv_written"] = True
    if mode and mode != "?":
        marker["mode"] = mode
    write_marker(session_id, marker)
    return _csv_rows(path)


def _csv_rows(path):
    try:
        with open(path, encoding="utf-8", errors="replace") as fh:
            return max(0, sum(1 for _ in fh) - 1)
    except OSError:
        return 0


# --- transcript introspection for the Stop gate ----------------------------

def last_assistant_text(transcript):
    """Text of the final assistant message — what the user actually sees.

    Streaming means the last full copy wins; tool-use-only messages have no text
    block and are skipped so a trailing tool call doesn't read as an empty reply.
    """
    text = ""
    try:
        with open(transcript, encoding="utf-8", errors="replace") as fh:
            for line in fh:
                try:
                    entry = json.loads(line)
                except json.JSONDecodeError:
                    continue
                if entry.get("type") != "assistant" or entry.get("isSidechain"):
                    continue
                content = (entry.get("message") or {}).get("content")
                if not isinstance(content, list):
                    continue
                blocks = [
                    b.get("text", "")
                    for b in content
                    if isinstance(b, dict) and b.get("type") == "text" and b.get("text")
                ]
                if blocks:
                    text = "\n".join(blocks)
    except OSError:
        return ""
    return text


# --- subcommands -----------------------------------------------------------

def cmd_start(session_id, transcript, force=False):
    """Open a measurement window. Idempotent: the first trigger in a turn wins,
    so `/preflight` invoking the skill and then running ./preflight.sh measures
    one run, not two."""
    if not force and read_marker(session_id):
        return
    now = time.time()
    write_marker(session_id, {
        "started_at": now,
        "started_iso": datetime.fromtimestamp(now, timezone.utc).isoformat().replace("+00:00", "Z"),
        "transcript": str(transcript) if transcript else "",
        "nudged": False,
    })


def cmd_report(session_id, transcript, cwd, mode=None):
    """Print the label (line one), append the CSV row, print the row count.

    Called by the skill as its final action, so the numbers exclude only the
    summary message itself (not yet written to the transcript). Line one is what
    the skill copies verbatim; line two is for the human, and says where the
    record is so the one line in the terminal is never mistaken for it.
    """
    marker = read_marker(session_id)
    if not marker:
        # Nothing was measured — don't invent a number.
        print(f"{LABEL_PREFIX} not measured (no start marker for this session)")
        return
    transcript = transcript or marker.get("transcript")
    print(build_label(marker, transcript))
    n = append_cost_row(session_id, marker, transcript, cwd, mode)
    if n is None:
        print(f"preflight-cost.csv: not written ({csv_path(cwd)} unwritable)")
    else:
        print(f"preflight-cost.csv: {n} rows · {csv_path(cwd)}")


def cmd_cancel(session_id):
    """Drop the run in progress, so this turn is not asked for a label.

    The escape hatch for a meter armed by something that turned out not to be a
    preflight — an aborted run, or a /preflight that stopped at story readiness.
    """
    clear_marker(session_id)


# What a preflight dispatch must carry. Measured on a real story with a known
# CRITICAL (a licence gate bypassed in one untouched view): the Sonnet auditor
# found it 2/2 times when the dispatch said where a leak would be CRITICAL, and
# 0/4 times with a generic "audit this diff" — one of those runs wrote a false
# PASS for the very file without opening it. The note is the cheapest change in
# the whole loop and the one with the largest measured effect, so it is a CHECK,
# not a paragraph in a skill: a dispatch without these markers is refused.
DISPATCH_MARKERS = ("dispatch note", "story:", "critical", "eye on")


def dispatch_note_missing(prompt):
    """The markers a preflight dispatch prompt lacks, or [] if it is valid."""
    low = (prompt or "").lower()
    return [mk for mk in DISPATCH_MARKERS if mk not in low]


def cmd_hook_pre(payload):
    """PreToolUse: start the meter when the /preflight skill is invoked, and
    refuse a `preflight` subagent dispatch that carries no dispatch note.

    Exact tool + skill-name match only. A direct `./preflight.sh` run is NOT
    detected here — the script starts its own meter (see preflight.sh), because
    matching command text catches commands that merely mention the script.

    The dispatch check matches the Agent tool (named `Task` in older harnesses)
    with `subagent_type: preflight` ONLY — the documenter and every other
    subagent are untouched. Refusal is exit 2 + the reason on stderr, the one
    blocking form every hook version honours; the reason names what is missing
    so the fix is one re-dispatch, not an investigation.
    """
    tin = payload.get("tool_input") or {}
    name = payload.get("tool_name")
    if name == "Skill" and str(tin.get("skill", "")).endswith("preflight"):
        cmd_start(payload.get("session_id"), payload.get("transcript_path"))
        return 0
    if name in ("Agent", "Task") and str(tin.get("subagent_type", "")) == "preflight":
        missing = dispatch_note_missing(str(tin.get("prompt", "")))
        if missing:
            print(
                "preflight dispatch refused: the prompt has no dispatch note "
                f"(missing markers: {', '.join(missing)}). Measured 2/2 vs 0/4 — a generic "
                "'audit this diff' is not a valid dispatch. Re-dispatch with a DISPATCH NOTE "
                "block: (1) Story: <two sentences>; (2) Forbidden / CRITICAL if: <where a leak "
                "is CRITICAL>; (3) Eye on: 3–5 things, each tied to a standards section. "
                "See .claude/skills/preflight/SKILL.md Phase 2.",
                file=sys.stderr,
            )
            return 2
    return 0


def cmd_hook_stop(payload):
    """Stop: make the label non-optional.

    The skill instructs the label, but instructions get skipped on long runs —
    and a cost figure that appears only when remembered is worse than none, since
    its absence reads as "cheap". So: if a preflight ran this turn and line one
    doesn't carry the label, block once and hand back the computed line.
    """
    session_id = payload.get("session_id")
    marker = read_marker(session_id)
    if not marker:
        return  # no preflight this turn — nothing to enforce
    transcript = payload.get("transcript_path") or marker.get("transcript")

    # Never block twice for the same run: stop_hook_active means we are already
    # inside a continuation we caused, and `nudged` survives across hook calls.
    already = payload.get("stop_hook_active") or marker.get("nudged")

    first_line = last_assistant_text(transcript).lstrip().splitlines()
    first_line = first_line[0].strip() if first_line else ""
    if first_line.startswith(LABEL_PREFIX) or already:
        # The run is over either way. The record is written here if `report`
        # never wrote it — a skipped label must not also mean a missing row.
        append_cost_row(session_id, marker, transcript, Path(payload.get("cwd") or Path.cwd()))
        clear_marker(session_id)
        return

    label = build_label(marker, transcript)
    marker["nudged"] = True
    write_marker(session_id, marker)
    print(json.dumps({
        "decision": "block",
        "reason": (
            "A preflight ran this turn, so the summary's FIRST line must be its "
            f"cost label. Re-send your summary with this as line one, unchanged:\n\n{label}\n\n"
            "Then continue as normal. Do not re-run preflight and do not "
            "recompute the numbers."
        ),
    }))


def flag_value(argv, name):
    """`--flag value` lookup. Returns None when absent or value-less."""
    if name in argv:
        i = argv.index(name)
        if i + 1 < len(argv):
            return argv[i + 1]
    return None


def main():
    argv = sys.argv[1:]
    cmd = argv[0] if argv else "report"
    cwd = Path.cwd()

    if cmd in ("hook-pre", "hook-stop"):
        try:
            payload = json.load(sys.stdin)
        except (json.JSONDecodeError, ValueError):
            return 0  # a malformed hook payload must never break the session
        if cmd == "hook-pre":
            return cmd_hook_pre(payload)
        cmd_hook_stop(payload)
        return 0

    # Manual/skill invocation: no hook payload, so infer the session from the
    # newest transcript for this repo. --transcript/--session override that for
    # tests and for the multiple-sessions-per-repo case.
    transcript = flag_value(argv, "--transcript") or find_transcript(cwd)
    session_id = resolve_session(transcript, flag_value(argv, "--session"))

    if cmd == "start":
        cmd_start(session_id, transcript, force="--force" in argv)
    elif cmd == "report":
        cmd_report(session_id, transcript, cwd, flag_value(argv, "--mode"))
    elif cmd == "cancel":
        cmd_cancel(session_id)
    else:
        print(__doc__, file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
