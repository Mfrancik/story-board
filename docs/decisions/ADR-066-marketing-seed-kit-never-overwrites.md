# ADR-066 — `marketing:seed-kit` creates what is missing and never overwrites

Date: 2026-10-12 · Status: accepted · Story: MK-2

## Context

The story asks for an idempotent seed: running it twice must leave one brand and five campaigns. "Idempotent" can
mean "re-applies the kit" or "adds only what is missing". Settings lets the owner edit the brand and copy rules, and
MK-7 will let them edit campaigns, so a re-run that re-applies the kit would silently undo the owner's edits.

## Decision

`app/Actions/Marketing/SeedMarketingKit.php`:
- **Brand:** created with StackerMint's values, voice and copy rules only if the project has none. An existing brand
  keeps them.
- **Idea menu:** always re-read from the kit's `captions-and-bios.txt`. The kit owns it and Settings cannot edit it.
- **Campaigns:** matched by slug; an existing slug is left untouched. Did you know is seeded disabled (Thu 12:00).
  The prototype's JS weekdays (Sun = 0) are converted to ISO weekdays. Timezone is `config('board.timezone')`.
- **Kit images are not imported.** No posts and no assets are made from them; MK-18 turns them into inspiration.
- **Logo** is read only with `--logo-path` (`--logo-ref` defaults to `origin/main`). A failed read warns but does
  not fail the seed; the brand and campaigns are written first in their own transaction. The source line stores
  `"<path> @ <ref> · <short sha>"`.

Alternatives rejected:
- **Upsert everything from the kit.** Overwrites owner edits on every run.
- **Fail the seed when the logo cannot be read.** The logo is optional and fixable later from Settings.
- **Matching on kit file name** (the story's wording). Campaigns come from the prototype, not kit files, so the
  slug is the only stable key.

## Consequences

- To re-apply StackerMint's brand after edits, the owner edits it in Settings or deletes the brand row; there is no
  `--force`.
- Changing a starter campaign's definition in code does not reach a project that already has that slug.
