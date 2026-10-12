# MK-2 — Marketing settings: brand kit, favourite photos, copy rules, StackerMint seed
Status: built           Journey: marketing-post (step 2 of 7)
Source: owner brain dump 2026-10-12 (brand kit, copy rules) + prototype round 2 ("photo library probably becomes not
needed… i could upload pics of some of my favorite coins though")

## Story
As the owner, I want each project's Marketing settings to hold its brand, a few favourite photos and its copy rules,
seeded for StackerMint from the kit, so that every draft looks and sounds like that project.

## Why
Generation (MK-8) and previews (MK-9) need a fixed brand and voice per project. The brand is an input the AI can never
vary. Photos are mostly uploaded per post, so this is a small favourites set, not a library.

## In scope
- `marketing_brands` (one per project) and `marketing_assets` (files) — see Data & interfaces.
- Route `GET /p/{project}/marketing/settings` (`projects.marketing.settings`), Livewire `Marketing\Settings`:
  - **Brand**: name, colours (accent, ink, surface), font family, corner radius, shadow, logo (SVG) — editable; a
    read-only "source" line when values came from the repo.
  - **Logo from the repo**: optional "read from `<path>` at `<ref>`" using the existing read-only `GitReader::show()`;
    the SVG is sanitised (no scripts, no external refs) and given an `xmlns` before storing (the kit's swirl needed it).
  - **Favourite photos**: multi-upload (jpg/png/webp, ≤10 MB each), grid, delete with confirm modal. Quiet line:
    "Real coin photos will come from the coins app later."
  - **Copy rules**: voice (text) + editable filler-phrase list + banned-claim list, used by MK-8's lint and prompt.
  - **Facebook**: a disabled "Phase 2" card.
- Files stored on the local disk under `marketing/{project}/…`, served by a route that only answers ids of that
  project's assets (`marketing.asset`).
- `php artisan marketing:seed-kit {project} {kitPath} [--logo-ref=origin/main --logo-path=…]`, idempotent:
  - brand: StackerMint values (tan `#B79F82`, ink `#222`, surface `#fafafa`, Instrument Sans, 5px radius, halo
    shadow) and the swirl from coins `origin/main` `resources/views/components/app-mark.blade.php`;
  - copy rules from the brief (short and human, 1–2 lines; no "tell us below / we read every one"; no cert-lookup
    claims; sets are the centrepiece; comparisons pair similar value and cite the price source);
  - the 4 starter campaigns from the prototype (Coin of the week Tue 10:00, TrueView Tuesday Tue 19:00, Set spotlight
    Fri 18:00, Ask the community Sun 12:00) plus Did you know (disabled), topics from the kit's idea menu;
  - re-running changes nothing (matched by kit file name / campaign slug).
- The kit's idea menu is parsed into `marketing_brands.idea_menu` (category → ideas) for MK-7's topic picker.

## Out of scope (do NOT build)
- Inspiration images (MK-18 — same assets table, different purpose), Facebook connection (phase 2), a coins photo
  connector (phase 3), tags on photos, reuse rules.
- Retiring a page or feature? None retired.

## Acceptance criteria (executable — these become the Pest test names)
- Given a project with no brand, when I open settings, then defaults show and saving creates the brand row.
- Given I edit the accent colour to an invalid hex, then validation fails and nothing is saved.
- Given a logo path and ref, when I read the logo from the repo, then the sanitised SVG is stored with an `xmlns` and
  the source line shows path @ ref; given an SVG with a `<script>`, then it is stripped and
  `marketing.brand.logo_sanitised` is logged.
- Given I upload three photos, then three favourite assets exist and render; given a 20 MB file or a PDF, then it is
  rejected with a message.
- Given an asset of project A, when it is requested under project B, then 404 and `marketing.asset.refused` is logged.
- Given the kit folder, when `marketing:seed-kit coins <path>` runs twice, then exactly 5 campaigns and one brand
  exist (the kit's posts become inspiration in MK-18, not posts).
- Given a missing kit path, then the command fails with a clear message and logs `marketing.kit_seed.failed`.

## Applicable standards
- Design: prototype Settings route; one form component for the brand (create = edit); confirm modal for delete.
- Codebase/DB: Actions `SaveBrand`, `ReadLogoFromRepo`, `StoreMarketingAsset`, `SeedMarketingKit`; GitReader stays
  read-only (no new git forms); uploads validated in the Livewire component.
- Logging: `marketing.brand.saved`, `marketing.brand.logo_sanitised`, `marketing.asset.stored`,
  `marketing.asset.refused`, `marketing.kit_seed.succeeded|failed` (context: project, counts — never file contents).

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/MK-prototype/index.html (Settings route); docs/mockups/MK-1/option-b.html settings panel
- Chosen option: the prototype, owner 2026-10-12
- Why I chose it: "photo library probably becomes not needed for coins… i guess i could upload pics of some of my
  favorite coins though".

## Do NOT touch
- `config/` (the `marketing` disk uses the existing `local` disk with a path prefix); the coins repo (read only).

## Data & interfaces
- `marketing_brands`: id, project_id (FK cascade, unique), values (json: colours, font, radius, shadow), logo_svg
  (text null), logo_source (string null), voice (text null), copy_rules (json: filler[], banned[]), idea_menu (json),
  timestamps.
- `marketing_assets`: id, project_id (FK cascade), purpose (`photo|inspiration`), is_favourite, path, original_name,
  mime, width, height, ai_notes (json null — MK-18), timestamps. `marketing_posts.asset_id` FK added here
  (nullOnDelete).
- Routes `projects.marketing.settings`, `marketing.asset`; command `marketing:seed-kit`.

## Test plan
- Pest: failing tests first; a fixture kit folder under `tests/Fixtures/marketing-kit/` (3 tiny PNGs + a trimmed
  captions file) and a GitFixture repo holding an SVG.
- Journey test: step 2 — settings shows the StackerMint brand after the seed.
- Browser check: run the seed against `~/Downloads/StackerMint-Facebook-Kit/`, open settings → swirl, swatches,
  favourites upload; resize to 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-1
