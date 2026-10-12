# Marketing: settings (brand kit, favourite photos, copy rules)
Status: active   ·   Last updated: 2026-10-12   ·   Stories: MK-2

## Overview
`/p/{project}/marketing/settings` holds what every marketing draft for a project must look and sound like: one
brand (name, colours, font, radius, shadow, logo), a small set of favourite photos, and copy rules (voice, filler
phrases, banned claims). Generation (MK-8) and previews (MK-9) read these as fixed inputs the AI can never vary.
`php artisan marketing:seed-kit` fills them for StackerMint from its Facebook kit, along with the five starter
campaigns and the kit's idea menu for MK-7's topic picker.

## How it works
**Route.** `routes/web.php` registers `GET /p/{project:name}/marketing/settings` as `projects.marketing.settings`
behind `EnsureProjectIsShown`. Its route name is what makes Settings appear in `marketing/sub-nav`, which lists only
pages whose route exists.

**Component.** `app/Livewire/Marketing/Settings.php` (`GuardsProjectPage`, holds the project by name only). `mount()`
loads the brand through `SaveBrand::brandOf()`, which returns the saved brand or an unsaved one holding
`MarketingBrand::DEFAULTS` (neutral, accent `#2563EB`) with the project's name. Nothing is written until the owner
saves. The page has four parts, plus a disabled Facebook "Phase 2" card:
- **Brand.** `saveBrand()` validates (hex colours against `MarketingBrand::HEX_PATTERN`; font name letters, digits,
  spaces and hyphens only; radius 0–32; shadow in `SHADOWS`) and calls `SaveBrand::handle()`, which creates or
  updates the one row and stores colours as upper-case `#RRGGBB`. The preview swatches and sample card render the
  **saved** values, never the inputs, so unvalidated text never reaches a `style` attribute (ADR-067).
- **Logo.** Two ways in, one way to the database:
  - "Read logo" → `ReadLogoFromRepo::handle($project, $path, $ref)` checks the path (no leading `/`, no `..`, no
    control characters) and the ref (`GitReader::isValidRef`), then uses only GitReader's existing read-only
    `resolve()` and `show()`. Nothing is fetched, so the ref is read as the clone has it.
  - "Upload an SVG" → `updatedLogoFile()` (SVG, ≤ 512 KB).
  - Both call `SetBrandLogo::handle()`, which runs `SanitiseSvg` and saves `logo_svg` plus `logo_source`
    (`"<path> @ <ref> · <short sha>"` or `"uploaded <name>"`). `mount()` parses the last source back into the
    path/ref fields, so re-reading after an upstream change is one click.
- **Favourite photos.** `updatedPhotos()` stores on pick: up to 20 files, JPG/PNG/WebP
  (`MarketingAsset::PHOTO_MIMES`), ≤ 10 MB each. One bad file stores none. Each goes through
  `StoreMarketingAsset::handle()` to `marketing/{project}/photos/<uuid>.<ext>` on the existing `local` disk (no
  config change); the uploaded name is kept only as a label. Delete is confirmed in `board/confirm-modal` (Alpine),
  then `deletePhoto()` → `FindProjectAsset` → `DeleteMarketingAsset` (row first, then file).
- **Copy rules.** `saveCopyRules()` stores the voice and the filler and banned lists (one per line; blanks and
  duplicates dropped).

**Serving files.** `app/Http/Controllers/MarketingAssetController.php` answers `marketing.asset`
(`/p/{project}/marketing/assets/{asset}`, outside the page group like the other file routes). It asks
`FindProjectAsset::handle($project, $id, needsFile: true)`, the single lookup shared with delete, so another
project's id is a 404 exactly like an unknown one. The response uses the stored MIME with `nosniff`.

**Seeding.** `app/Console/Commands/MarketingSeedKit.php` → `app/Actions/Marketing/SeedMarketingKit.php`. It checks
the project, the kit folder and `captions-and-bios.txt` before writing anything, then in one transaction:
- creates the brand with `BRAND`, `VOICE` and `COPY_RULES` only if none exists;
- always re-reads `idea_menu` from the file's `== IDEA MENU ==` section (`ideaMenu()`);
- creates each of the `CAMPAIGNS` whose slug is missing.

The logo is read afterwards, only with `--logo-path`, and a failure there is a warning, not a failed seed. Kit
images are never imported (MK-18 makes them inspiration). Full rules: ADR-066.

## Data model
Migrations `database/migrations/2026_10_12_000003_create_marketing_brands_table.php`, `…000004_create_marketing_assets_table.php`,
`…000005_add_asset_foreign_key_to_marketing_posts.php`.
- `marketing_brands` (`app/Models/MarketingBrand.php`): `project_id` (unique, FK cascade; the unique index is what
  makes the form create = edit), `values` (JSON `{name, accent, ink, surface, font, radius, shadow}`; `name` null
  means the project's name), `logo_svg` (sanitised only), `logo_source`, `voice`, `copy_rules` (JSON
  `{filler[], banned[]}`), `idea_menu` (JSON list `[{category, ideas: [{title, text}]}]` in the kit's order, not
  a keyed map; ADR-065). Both JSON columns default to empty so they always decode to arrays.
- `marketing_assets` (`app/Models/MarketingAsset.php`): `project_id` (FK cascade), `purpose` (`photo |
  inspiration`), `is_favourite`, `path` (relative to the local disk, generated), `original_name`, `mime`, `width`,
  `height`, `ai_notes` (JSON, for MK-18). Index `(project_id, purpose, is_favourite)`; scope `favouritePhotos()`.
- `marketing_posts.asset_id` gains its FK to `marketing_assets` (`nullOnDelete`): deleting a photo leaves its posts
  photo-less. The migration's `down()` also drops MySQL's implicit index of the same name.
- Campaign topics written by the seed are `"Category|Title"` strings in `topics.ideas` (ADR-065).
- `Project` gains `marketingBrand()` (HasOne) and `marketingAssets()`; `MarketingPost` gains `asset()`. Factories:
  `MarketingBrandFactory`, `MarketingAssetFactory`.

## Interfaces
- Routes: `projects.marketing.settings` (page), `marketing.asset` (file, numeric id). Both 404 for an unknown or
  disabled project.
- Command: `marketing:seed-kit {project} {kitPath} [--logo-path=…] [--logo-ref=origin/main]`. Prints brand
  created/kept, idea and category counts, campaigns created/kept, and the logo outcome. Exit 1 with a message for an
  unknown project, a missing kit folder or a missing captions file.
- Actions (`app/Actions/Marketing/`): `SaveBrand::handle(Project, array $changes)` and `brandOf(Project)`;
  `SetBrandLogo::handle(Project, string $raw, string $source)`; `ReadLogoFromRepo::handle(Project, $path, $ref)`;
  `SanitiseSvg::handle(string): array{svg, removed, xmlns_added}`; `StoreMarketingAsset::handle(Project,
  UploadedFile, $purpose = photo, $favourite = true)`; `FindProjectAsset::handle(Project, int $id, bool $needsFile)`;
  `DeleteMarketingAsset::handle(MarketingAsset)`; `SeedMarketingKit::handle(...)` and `ideaMenu(string)`.
- Exceptions: `MarketingLogoException` (`field` for the form error, `reason`); `MarketingKitException`.
- Test hooks: `data-brand-form`, `data-brand-sample`, `data-brand-logo`, `data-brand-defaults`, `data-swatch="<key>"`,
  `data-logo-source`, `data-logo-repo-form`, `data-logo-upload`, `data-read-logo`, `data-save-brand`,
  `data-copy-rules`, `data-save-rules`, `data-favourites`, `data-favourite-grid`, `data-favourite`,
  `data-favourites-empty`, `data-photo-upload`, `data-photo-errors`, `data-delete-photo`, `data-delete-photo-modal`,
  `data-facebook-card`.

## Configuration
None new. Files go on the existing `local` disk under `marketing/{project}/`. Seeded campaigns take
`config('board.timezone')` (`BOARD_TIMEZONE`, default `America/New_York`).

## Observability
Context is project name and counts, never file or SVG contents.
- `marketing.brand.saved` (info): `project`, `brand_id`, `created`, `fields`. Every brand write, including logos and seeds.
- `marketing.brand.logo_sanitised` (info): `removed`, `xmlns_added`, `source`. Only when something was stripped.
- `marketing.brand.logo_refused` (warning): `reason` `bad_path | bad_ref | unknown_ref | not_found | not_svg |
  malformed | too_big`.
- `marketing.asset.stored` (info): `asset_id`, `purpose`, `mime`, `bytes`. `marketing.asset.deleted` (info):
  `project_id`, `asset_id`, `posts_unlinked`. `marketing.asset.upload_empty` (info): an upload arrived with no files.
- `marketing.asset.refused` (warning): `asset_id`, `reason` `unknown | other_project | missing_file`. A 404 on a
  photo should have one of these.
- `marketing.kit_seed.succeeded` (info) with the counts and logo outcome; `marketing.kit_seed.failed` with
  `unknown_project | missing_kit | missing_captions`.
- Healthy seed: `kit_seed.succeeded` with `campaigns_created: 5` the first time and `0` after. A run that leaves
  the logo empty shows `logo: failed` plus a `logo_refused` line giving the reason.

## Testing & verification
- `tests/Feature/Marketing/MarketingSettingsTest.php`: one `it()` per acceptance criterion, plus edit-in-place,
  the font guard, path traversal, SVG upload, empty upload, delete and unlink, cross-project delete, missing-file
  404, the Facebook card and sub-nav, and a disabled project. Logo reads use a GitFixture repo.
- `tests/Feature/Marketing/SeedMarketingKitTest.php` against `tests/Fixtures/marketing-kit/` (a trimmed captions
  file and tiny PNGs): run twice gives five campaigns and one brand, an edited brand and existing slug are kept,
  the logo is read, and missing kit, missing captions and unknown project fail with a log line.
- `tests/Unit/Marketing/SanitiseSvgTest.php`: Blade lifting, stripping, doctype/entity refusal, malformed input.
- `tests/Browser/Marketing/SettingsTest.php`: 1280 px brand beside favourites, serve and delete through the modal;
  375 px one column, no sideways scroll. Uploads are not exercised here (see RUNBOOK 2026-10-12).
- Journey step 2 in `tests/Browser/Journeys/MarketingPostTest.php`: seed the fixture kit, open Settings, see
  StackerMint and the `#B79F82` swatch.
- Browser check: run the seed against `~/Downloads/StackerMint-Facebook-Kit` with
  `--logo-path=resources/views/components/app-mark.blade.php`, open settings for the swirl, swatches and a
  favourites upload, then resize to 375 px.

## Key decisions & tradeoffs
- The idea menu is an ordered list and topics are `"Category|Title"` →
  [ADR-065](../decisions/ADR-065-marketing-idea-menu-is-an-ordered-list.md).
- The seed adds what is missing and never overwrites; the logo is optional →
  [ADR-066](../decisions/ADR-066-marketing-seed-kit-never-overwrites.md).
- SVG sanitised with PHP's DOM; editable brand with a saved-values-only preview →
  [ADR-067](../decisions/ADR-067-marketing-logos-sanitised-with-php-dom.md).
- Deviation from the prototype: the brand is editable (the story says so) where the prototype shows it read-only,
  and "Upload an SVG" sits beside "Read logo".
- One lookup (`FindProjectAsset`) for serving and deleting, so the cross-project rule lives in one place.

## Known limitations & gotchas
- **Livewire's 12 MB temporary-upload cap** rejects big files before the 10 MB rule. `Settings::_uploadErrored()`
  (aliasing the trait's method) swaps in the page's own message. Livewire appends each pick to the previous
  `photos` array, so a rejected pick is cleared or it would fail every later upload (RUNBOOK).
- Pest's browser server passes no files, so browser tests cannot upload (RUNBOOK). A real photo upload through the
  picker had not been checked in a browser when MK-2 shipped.
- The brand preview changes only after "Save brand".
- The seed does not re-apply the brand or campaigns once they exist; there is no `--force` (ADR-066).
- Renaming an idea in the kit orphans campaign picks that used the old title (ADR-065).
- After merging, run `php artisan migrate` and then the seed for coins (see Browser check).

## Change history
2026-10-12 — Marketing settings: brand kit, favourite photos, copy rules, `marketing:seed-kit` (MK-2, `76e2101`)
