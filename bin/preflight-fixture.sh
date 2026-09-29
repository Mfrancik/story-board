#!/usr/bin/env bash
# preflight-fixture.sh — build a throwaway downstream repo from this kit, for
# proving kit changes against something real before they ship.
#
# The kit's own tree is not a Laravel project: it has no app/, no components, an
# empty INDEX. Every gate and the audit pack therefore short-circuit here, and a
# change "proven" on an empty scaffold is not proven (wip/README.md). This script
# makes a repo that LOOKS like a downstream project at a chosen size — the kit
# files copied in verbatim (no manual edits, which is also the contract a pulling
# project relies on), a synthetic INDEX and UI-INVENTORY, a few app files — and a
# feature branch with one build commit that adds a component whose name and header
# comment deliberately overlap an inventoried one. That planted near-duplicate is
# what the pack's DUPLICATION CANDIDATES section must surface.
#
# Usage:
#   bin/preflight-fixture.sh [dir] [--stories N] [--bloat]
#     dir        where to build (default: mktemp -d). Existing dir is REFUSED.
#     --stories  INDEX rows to generate (default 40; try 300 to see scale)
#     --bloat    append ~2.7 KB of prose to every INDEX row — reproduces the
#                downstream growth and makes the `audit pack <= cap` gate fail,
#                which is how the gate's own failure path is exercised.
#   Prints the path. Then: cd <dir> && ./preflight.sh audit-pack
#
# Only the kit's files are copied; nothing is installed. The fixture has no
# vendor/, so a full `./preflight.sh` run fails its dependency gates by design —
# use it for `audit-pack`, the docs gates, and the A/B harness.
set -euo pipefail

KIT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIR=""; STORIES=40; BLOAT=0
while [ $# -gt 0 ]; do
  case "$1" in
    --stories) STORIES="$2"; shift 2;;
    --bloat) BLOAT=1; shift;;
    -*) echo "unknown flag $1" >&2; exit 2;;
    *) DIR="$1"; shift;;
  esac
done
if [ -z "$DIR" ]; then DIR=$(mktemp -d "${TMPDIR:-/tmp}/pf-fixture.XXXXXX"); rmdir "$DIR"; fi
[ -e "$DIR" ] && { echo "refusing to build into existing path: $DIR" >&2; exit 2; }
mkdir -p "$DIR"

# 1. The kit, verbatim. Same file set a downstream project pulls.
for p in preflight.sh bin .claude docs stories CLAUDE.md; do
  [ -e "$KIT/$p" ] && cp -R "$KIT/$p" "$DIR/$p"
done
rm -rf "$DIR/bin/__pycache__"
cd "$DIR"
git init -q -b main
git config user.email fixture@example.test; git config user.name fixture
git config commit.gpgsign false

# 2. A plausible app tree and inventory. Names are chosen so the planted file
#    below overlaps: `user-form-modal` shares the stems "user" and "form".
mkdir -p app/Livewire/Users resources/views/components resources/views/livewire/users tests/Feature database/migrations
cat > resources/views/components/user-form-modal.blade.php <<'B'
{{-- Modal form for creating or editing a user: name, email, role. One form for both modes (design-standards §Reuse). --}}
<div x-data="{ open: false }" class="p-4">{{ $slot }}</div>
B
cat > resources/views/components/data-table.blade.php <<'B'
{{-- Generic sortable data table. Columns via slot; rows via $rows. --}}
<table class="min-w-full">{{ $slot }}</table>
B
cat > resources/views/components/confirm-dialog.blade.php <<'B'
{{-- Destructive-action confirmation modal. Never a bare delete button. --}}
<div role="dialog">{{ $slot }}</div>
B
cat > app/Livewire/Users/UserTable.php <<'P'
<?php

namespace App\Livewire\Users;

use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Paginated users list with inline status toggles.
 */
class UserTable extends Component
{
    /** Toggle a user's active flag, logging the change for the audit trail. */
    public function toggle(int $id): void
    {
        Log::info('user.toggled', ['user_id' => $id]);
    }
}
P
{
  echo "# UI Inventory"
  echo "Every reusable component. Consult BEFORE building any new UI (design-standards"
  echo "§Reuse before build). Updated in the same story that creates/changes a component."
  echo
  echo "| Component | Type (Blade/Livewire/Filament) | Purpose | Used in |"
  echo "|---|---|---|---|"
  echo "| \`user-form-modal\` | Blade | Modal form for creating or editing a user (name, email, role); one component, two modes | users index, admin users |"
  echo "| \`data-table\` | Blade | Generic sortable table, slot-driven columns | every index page |"
  echo "| \`confirm-dialog\` | Blade | Destructive-action confirmation | delete flows |"
  echo "| \`UserTable\` | Livewire | Paginated users list with inline status toggles | /users |"
  for i in $(seq 1 16); do
    echo "| \`widget-$i\` | Blade | Dashboard widget $i: shows metric $i with a sparkline | dashboard |"
  done
} > docs/UI-INVENTORY.md

# 3. A synthetic INDEX at the requested size. With --bloat, each row carries the
#    kind of prose /document used to append instead of moving to the feature doc.
bloat=""
if [ "$BLOAT" = 1 ]; then
  bloat=" — $(printf 'Detail that belongs in the feature doc: the flow, the edge cases, the rollout notes, the tests that cover it and why the alternative was rejected. %.0s' $(seq 1 18))"
fi
{
  echo "# Feature Documentation Index"
  echo "One line per feature. /document maintains this file."
  echo
  echo "| Feature | Summary | Status | Last updated | Doc |"
  echo "|---|---|---|---|---|"
  for i in $(seq 1 "$STORIES"); do
    echo "| Feature $i — thing number $i | Does the $i-th thing for users who need it, with résumé-safe UTF-8 ✓${bloat} | active | 2026-0$(( (i % 8) + 1 ))-1$(( i % 9 )) | docs/features/feature-$i.md |"
  done
} > docs/INDEX.md
mkdir -p docs/features
cat > docs/features/users.md <<'D'
# Users
Status: active · Last updated: 2026-08-01 · Stories: SS-1
## Overview
User management.
D

git add -A && git commit -qm "chore(kit): scaffold fixture project" 

# 4. The story branch with one build commit and the planted near-duplicate.
git checkout -q -b feat/SS-7-user-edit-form
mkdir -p stories/sample
cat > stories/sample/SS-7-user-edit-form.md <<'S'
# SS-7 — User edit form
Status: built
Journey: user-admin

## Goal
Admins edit a user's name, email and role from the users list.

## Do NOT touch
- app/Livewire/Users/UserTable.php pagination

## Acceptance criteria
- it('edits a user from the list')
S
cat > resources/views/components/user-edit-form.blade.php <<'B'
{{-- Edit form for a user record: name, email, role. Opens in a modal from the users list. --}}
<form class="p-4" style="color:#333333">{{ $slot }}</form>
B
cat >> app/Livewire/Users/UserTable.php <<'P'

// SS-7: opens the edit form for a row
P
cat > tests/Feature/UserEditTest.php <<'T'
<?php

it('edits a user from the list', function () {
    expect(true)->toBeTrue();
})->skip('pending fixture');
T
git add -A && git commit -qm "feat(SS-7): add user edit form" 
echo "$DIR"
