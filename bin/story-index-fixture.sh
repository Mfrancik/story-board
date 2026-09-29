#!/usr/bin/env bash
# story-index-fixture.sh — build throwaway git repos that cover every acceptance
# criterion of SB-1, assert what bin/story-index (and the `story-format` gate)
# report about each one, and tear them down.
#
# bin/story-index is a CONTRACT: a board (SB-2+) reads its JSON without
# re-parsing the files. A change to the parser is proven here before it ships,
# the same way bin/disposal-fixture.sh guards the disposal scripts. Each case is
# named for the behaviour it pins, so a failure line says what regressed.
#
# Usage: bin/story-index-fixture.sh [--keep]
#   --keep   leave the fixture repos on disk for inspection
#
# Needs: git, python3. Nothing else — assertions are python3 one-liners, not jq,
# because the CLI under test is stdlib-only and its tests should be too.
set -uo pipefail

KEEP=0; [ "${1:-}" = "--keep" ] && KEEP=1
KIT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INDEX="$KIT/bin/story-index"
ROOT="$(mktemp -d "${TMPDIR:-/tmp}/storyindexfix.XXXXXX")"
pass=0; fail=0

say() { printf '\n== %s\n' "$*"; }
ok()  { printf '  PASS  %s\n' "$*"; pass=$((pass + 1)); }
bad() { printf '  FAIL  %s\n' "$*"; fail=$((fail + 1)); }

# The kit's own §Status vocabulary — copied, not retyped, so the "kit README"
# cases test against what a pulling project actually receives.
KIT_README="$KIT/stories/README.md"

# new_repo <name> — an empty repo on branch main; prints its path.
new_repo() {
  local d="$ROOT/$1"
  mkdir -p "$d" && git -C "$d" init -q -b main
  git -C "$d" config user.email fixture@example.test
  git -C "$d" config user.name fixture
  git -C "$d" config commit.gpgsign false
  printf '%s\n' "$d"
}

# story <repo> <initiative/ID-slug> <status line> [extra body] — write one story file.
story() {
  mkdir -p "$1/stories/$(dirname "$2")"
  {
    printf '# %s — Title for %s\n' "$(basename "$2" | grep -oE '^[A-Z]{2,}-[0-9]+[a-z]?')" "$(basename "$2")"
    printf '%s\n' "$3"
    printf 'Source: owner 2026-09-29\n\n## Story\nAs a fixture, I exist.\n'
    [ -n "${4:-}" ] && printf '%s\n' "$4"
  } > "$1/stories/$2.md"
}

commit_all() { git -C "$1" add -A && git -C "$1" commit -qm "${2:-fixture}"; }

# check <name> <json-file> <python expr over `r` (the parsed list)> — assert a
# property of the index output. The expression must evaluate truthy.
check() {
  local name="$1" json="$2" expr="$3"
  if python3 -c "import json,sys; r=json.load(open(sys.argv[1])); sys.exit(0 if ($expr) else 1)" "$json" 2>/dev/null; then
    ok "$name"
  else
    bad "$name"; sed 's/^/        /' "$json" | head -40
  fi
}

# by <ID> — the python expression for that ID's record, for use inside check().
by() { printf "next(x for x in r if x['id']=='%s')" "$1"; }

# ---------------------------------------------------------------------------
say "AC1: draft, approved and built on main → three records, matching statuses"
R=$(new_repo three)
mkdir -p "$R/stories"; cp "$KIT_README" "$R/stories/README.md"
story "$R" demo/DM-1-first  "Status: draft          Journey: none"
story "$R" demo/DM-2-second "Status: approved       Journey: demo-flow (step 2 of 3)"
story "$R" demo/DM-3-third  "Status: built"
commit_all "$R"
"$INDEX" "$R" main > "$ROOT/three.json"; rc=$?
[ "$rc" -eq 0 ] && ok "exit 0" || bad "exit $rc"
check "three records (README not indexed)" "$ROOT/three.json" "len(r)==3"
check "statuses match" "$ROOT/three.json" "sorted((x['id'],x['status']) for x in r)==[('DM-1','draft'),('DM-2','approved'),('DM-3','built')]"
check "no parse errors" "$ROOT/three.json" "all(x['parse_errors']==[] for x in r)"
check "every contract key present" "$ROOT/three.json" \
  "all(set(x)=={'id','title','status','journey','initiative','path','source','depends_on','mockups','parse_errors'} for x in r)"
check "title strips the ID prefix" "$ROOT/three.json" "$(by DM-1)['title']=='Title for DM-1-first'"
check "initiative is the folder" "$ROOT/three.json" "$(by DM-1)['initiative']=='demo'"
check "path is repo-relative" "$ROOT/three.json" "$(by DM-1)['path']=='stories/demo/DM-1-first.md'"
check "journey read from the status line" "$ROOT/three.json" \
  "$(by DM-1)['journey']=='none' and $(by DM-2)['journey']=='demo-flow (step 2 of 3)' and $(by DM-3)['journey'] is None"
check "source read" "$ROOT/three.json" "$(by DM-1)['source']=='owner 2026-09-29'"

# ---------------------------------------------------------------------------
say "AC2: the ref wins over the working tree"
R=$(new_repo reftree)
mkdir -p "$R/stories"; cp "$KIT_README" "$R/stories/README.md"
story "$R" demo/DM-1-first "Status: draft"
commit_all "$R"
sed -i.bak 's/^Status: draft$/Status: approved/' "$R/stories/demo/DM-1-first.md" && rm -f "$R/stories/demo/DM-1-first.md.bak"
story "$R" demo/DM-9-uncommitted "Status: draft"      # exists only in the working tree
"$INDEX" "$R" main > "$ROOT/reftree.json"
check "working tree says approved, main says draft → draft" "$ROOT/reftree.json" "$(by DM-1)['status']=='draft'"
check "uncommitted story is invisible" "$ROOT/reftree.json" "len(r)==1"

# ---------------------------------------------------------------------------
say "AC3: tolerance matches story_status_gate"
R=$(new_repo tolerance)
mkdir -p "$R/stories"; cp "$KIT_README" "$R/stories/README.md"
story "$R" demo/DM-1-bold    "**Status:** built"
story "$R" demo/DM-2-pending "Status: draft — pending owner review"
story "$R" demo/DM-3-journey "Status: approved   Journey: some-flow"
commit_all "$R"
"$INDEX" "$R" main > "$ROOT/tolerance.json"
check "**Status:** built → built, no errors" "$ROOT/tolerance.json" "$(by DM-1)['status']=='built' and $(by DM-1)['parse_errors']==[]"
check "draft — pending → draft" "$ROOT/tolerance.json" "$(by DM-2)['status']=='draft' and $(by DM-2)['parse_errors']==[]"
check "trailing Journey: → approved" "$ROOT/tolerance.json" "$(by DM-3)['status']=='approved' and $(by DM-3)['journey']=='some-flow'"

# ---------------------------------------------------------------------------
say "AC4: allowed statuses come from the project's README, not a hardcoded set"
R=$(new_repo fourvalues)
mkdir -p "$R/stories"
cat > "$R/stories/README.md" <<'MD'
# Stories

## Status — the only four values

- `draft`     — written, not approved
- `approved`  — approved, ready to build or in progress
- `built`     — the build commit is on main
- `cancelled` — will never be built

There is no fifth value. `release` is not one of them.

## Structure
- `stories/<initiative>/<ID>-<slug>.md`
MD
story "$R" demo/DM-1-dead "Status: cancelled"
commit_all "$R"
"$INDEX" "$R" main > "$ROOT/four.json"
check "cancelled allowed by a 4-value README" "$ROOT/four.json" "$(by DM-1)['status']=='cancelled' and $(by DM-1)['parse_errors']==[]"
cp "$KIT_README" "$R/stories/README.md"; commit_all "$R" "kit readme"
"$INDEX" "$R" main > "$ROOT/three-values.json"
check "kit's 3-value README → parse_error naming cancelled" "$ROOT/three-values.json" \
  "len($(by DM-1)['parse_errors'])==1 and 'cancelled' in $(by DM-1)['parse_errors'][0]"
check "…and status is still reported, not dropped" "$ROOT/three-values.json" "$(by DM-1)['status']=='cancelled'"

# ---------------------------------------------------------------------------
say "AC5: ./preflight.sh gate story-format fails on Status: release"
R=$(new_repo gate)
mkdir -p "$R/stories" "$R/bin"; cp "$KIT_README" "$R/stories/README.md"
cp "$KIT/preflight.sh" "$R/"; cp "$INDEX" "$R/bin/"
story "$R" demo/DM-1-ok "Status: built"
commit_all "$R"
out=$("$R/preflight.sh" gate story-format 2>&1); rc=$?
[ "$rc" -eq 0 ] && ok "valid stories → gate passes" || { bad "valid stories → gate exit $rc"; printf '%s\n' "$out" | sed 's/^/        /'; }
story "$R" demo/DM-2-shipit "Status: release"
commit_all "$R"
out=$("$R/preflight.sh" gate story-format 2>&1); rc=$?
[ "$rc" -ne 0 ] && ok "release → gate exits non-zero ($rc)" || bad "release → gate exited 0"
if printf '%s' "$out" | grep -q 'stories/demo/DM-2-shipit.md' && printf '%s' "$out" | grep -q "release"; then
  ok "failure names the file and the value"
else
  bad "failure output missing file or value"; printf '%s\n' "$out" | sed 's/^/        /'
fi
# The gate reads HEAD: an uncommitted fix does not green it, a committed one does.
sed -i.bak 's/^Status: release$/Status: built/' "$R/stories/demo/DM-2-shipit.md" && rm -f "$R/stories/demo/DM-2-shipit.md.bak"
"$R/preflight.sh" gate story-format >/dev/null 2>&1 && bad "uncommitted fix greened the gate" || ok "uncommitted fix does not green the gate (reads HEAD)"
commit_all "$R" fix
"$R/preflight.sh" gate story-format >/dev/null 2>&1 && ok "committed fix greens the gate" || bad "committed fix still fails"

# ---------------------------------------------------------------------------
say "AC6: mockups — options from the ref, chosen from the story"
R=$(new_repo mockups)
mkdir -p "$R/stories" "$R/docs/mockups/SB-3" "$R/docs/mockups/SB-4"; cp "$KIT_README" "$R/stories/README.md"
echo '<p>a</p>' > "$R/docs/mockups/SB-3/option-a.html"
echo '<p>b</p>' > "$R/docs/mockups/SB-3/option-b.html"
echo 'notes'    > "$R/docs/mockups/SB-3/notes.md"            # not an option
echo '<p>a</p>' > "$R/docs/mockups/SB-4/option-a.html"
story "$R" board/SB-3-grid "Status: approved" "$(printf '\n## Design mockup gate\n- Chosen option: b\n')"
story "$R" board/SB-4-card "Status: draft" "$(printf '\n## Design mockup gate\n- Chosen option: **a — \"The Ledger\"** (approved 2026-08-14).\n')"
story "$R" board/SB-5-none "Status: draft" "$(printf '\n## Design mockup gate\nn/a — non-visual\n')"
commit_all "$R"
echo '<p>c</p>' > "$R/docs/mockups/SB-3/option-c.html"      # working tree only
"$INDEX" "$R" main > "$ROOT/mockups.json"
check "options = [a, b] (ref only, html only)" "$ROOT/mockups.json" "$(by SB-3)['mockups']['options']==['a','b']"
check "chosen = b" "$ROOT/mockups.json" "$(by SB-3)['mockups']['chosen']=='b'"
check "dir is the ref path" "$ROOT/mockups.json" "$(by SB-3)['mockups']['dir']=='docs/mockups/SB-3'"
check "decorated chosen value → its option letter" "$ROOT/mockups.json" "$(by SB-4)['mockups']['chosen']=='a'"
check "no mockup dir → dir null, options []" "$ROOT/mockups.json" \
  "$(by SB-5)['mockups']=={'dir':None,'options':[],'chosen':None}"

# ---------------------------------------------------------------------------
say "depends_on from ## Links"
R=$(new_repo links)
mkdir -p "$R/stories"; cp "$KIT_README" "$R/stories/README.md"
story "$R" demo/DM-1-a "Status: draft" "$(printf '\n## Links\nJourney: none · Depends on: none · Blocks: DM-2\n')"
story "$R" demo/DM-2-b "Status: draft" "$(printf '\n## Links\nJourney: demo · Depends on: DM-1, AP-3a (for live proof only — the build is\nfine without it), MT-10 · Blocks: DM-3\n')"
story "$R" demo/DM-3-c "Status: draft" "$(printf '\nDepends on: DM-1 (outside Links — not the contract)\n')"
commit_all "$R"
"$INDEX" "$R" main > "$ROOT/links.json"
check "Depends on: none → []" "$ROOT/links.json" "$(by DM-1)['depends_on']==[]"
check "IDs across a wrapped line, Blocks: excluded" "$ROOT/links.json" "$(by DM-2)['depends_on']==['DM-1','AP-3a','MT-10']"
check "no ## Links section → []" "$ROOT/links.json" "$(by DM-3)['depends_on']==[]"

# ---------------------------------------------------------------------------
say "format drift is a parse_error, never a crash"
R=$(new_repo drift)
mkdir -p "$R/stories"; cp "$KIT_README" "$R/stories/README.md"
story "$R" demo/DM-1-nostatus "Journey: none"
mkdir -p "$R/stories/demo"; printf '# Notes\nStatus: draft\n' > "$R/stories/demo/notes-on-things.md"
commit_all "$R"
"$INDEX" "$R" main > "$ROOT/drift.json"; rc=$?
[ "$rc" -eq 0 ] && ok "exit 0 with drifted files" || bad "exit $rc"
check "missing Status: → status null + parse_error" "$ROOT/drift.json" \
  "$(by DM-1)['status'] is None and any('Status' in e for e in $(by DM-1)['parse_errors'])"
check "filename without an ID → id null + parse_error" "$ROOT/drift.json" \
  "any(x['id'] is None and x['path']=='stories/demo/notes-on-things.md' and x['parse_errors'] for x in r)"
R=$(new_repo novocab)
story "$R" demo/DM-1-x "Status: draft"
commit_all "$R"
"$INDEX" "$R" main > "$ROOT/novocab.json"
check "no stories/README.md §Status → parse_error saying so" "$ROOT/novocab.json" \
  "any('README' in e for e in $(by DM-1)['parse_errors'])"

# ---------------------------------------------------------------------------
say "AC7: a ref that does not exist"
R=$(new_repo badref)
mkdir -p "$R/stories"; cp "$KIT_README" "$R/stories/README.md"; story "$R" demo/DM-1-a "Status: draft"; commit_all "$R"
"$INDEX" "$R" origin/nope >"$ROOT/badref.out" 2>"$ROOT/badref.err"; rc=$?
[ "$rc" -ne 0 ] && ok "exits non-zero ($rc)" || bad "exited 0"
[ ! -s "$ROOT/badref.out" ] && ok "no partial JSON on stdout" || { bad "stdout not empty"; head -5 "$ROOT/badref.out"; }
[ "$(wc -l <"$ROOT/badref.err" | tr -d ' ')" = 1 ] && grep -q 'origin/nope' "$ROOT/badref.err" \
  && ok "one-line error naming the ref: $(cat "$ROOT/badref.err")" || { bad "stderr not one line naming the ref"; cat "$ROOT/badref.err"; }
"$INDEX" "$ROOT/not-a-repo" main >"$ROOT/norepo.out" 2>"$ROOT/norepo.err"; rc=$?
[ "$rc" -ne 0 ] && [ ! -s "$ROOT/norepo.out" ] && [ "$(wc -l <"$ROOT/norepo.err" | tr -d ' ')" = 1 ] \
  && ok "not a repo → one-line error, no JSON" || { bad "not a repo (rc=$rc)"; cat "$ROOT/norepo.err"; }

# ---------------------------------------------------------------------------
say "AC8: no stories/ directory"
R=$(new_repo nostories)
echo app > "$R/README.md"; commit_all "$R"
out=$("$INDEX" "$R" main); rc=$?
[ "$rc" -eq 0 ] && [ "$out" = "[]" ] && ok "prints [] and exits 0" || bad "got rc=$rc out=$out"

# ---------------------------------------------------------------------------
say "the kit's own stories parse at HEAD"
out=$("$INDEX" --check "$KIT" HEAD 2>&1); rc=$?
[ "$rc" -eq 0 ] && ok "kit HEAD: --check exits 0" || { bad "kit HEAD: --check exit $rc"; printf '%s\n' "$out" | sed 's/^/        /'; }

printf '\n%d passed, %d failed\n' "$pass" "$fail"
if [ "$KEEP" = 1 ]; then echo "kept: $ROOT"; else rm -rf "$ROOT"; fi
[ "$fail" -eq 0 ]
