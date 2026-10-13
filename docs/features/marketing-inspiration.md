# Marketing: inspiration
Status: active   ·   Last updated: 2026-10-13   ·   Stories: MK-18

## Overview
`/p/{project}/marketing/inspiration` is one unorganised wall where the owner drops ads they like: no folders, no
tags. Claude reads each image **once** (vision) and stores its headline, one or two style notes and a topic. Fill
then quotes the owner's favourites and the closest matches as examples of tone and copy. The same one-time read
runs on every favourite photo in Settings, so Fill picks photos by what they show rather than by file name ("full
(1).jpg"). Posts the owner likes can be saved back to the wall with one click from the studio or the library.

It shapes **copy and tone only**. Layout matching needs the designer (phase 3), and the page says so.

## How it works
**The wall.** Route `projects.marketing.inspiration` (`routes/web.php`, under `EnsureProjectIsShown`). Its name is
what puts Inspiration in the marketing sub-nav. `app/Livewire/Marketing/Inspiration.php`:
- `updatedImages()` validates the whole drop (`MAX_PER_DROP` files, each JPG/PNG/WebP up to
  `MarketingAsset::MAX_KB`), so one bad file stores none, then calls `StoreInspiration::handle()` per file. Dropping
  on the label is Alpine handing `dataTransfer.files` to `$wire.uploadMultiple('images', …)`; clicking uses the
  same `images` input.
- `toggleFavourite()` → `SetInspirationFavourite`; `delete()` (behind `board/confirm-modal`) → `DeleteMarketingAsset`;
  `retry()` → `QueueAssetRead`. All go through `find()`, which refuses another project's id (`FindProjectAsset`) and
  a favourite photo's id (`not_inspiration`).
- `render()` passes the images as **`$wall`**, not `$images` (see RUNBOOK 2026-10-13), and `wire:poll.3s` only while
  any tile is reading.
- `resources/views/livewire/marketing/inspiration.blade.php` is a CSS-columns masonry wall (one column on a phone,
  up to four wide). A tile shows "Reading…", "Couldn't read · Retry", or its source label ("AI read it", "From the
  kit", "From your post") with the headline and style/topic. A text card is drawn with `marketing/post-preview`.

**The one read.** `app/Actions/Marketing/QueueAssetRead.php` is the only way a read is asked for (upload, new
favourite photo, Retry, backfill). It writes `ai_notes.status = reading` *before* dispatching, because on the sync
queue the job has finished by the time dispatch returns, and it refuses an image already reading.
`app/Jobs/ReadInspiration.php` (unique per asset for 600 s, `tries = 1`, carries the asset id and the request id)
runs `app/Actions/Marketing/ReadMarketingAsset.php:handle()`:
1. `image()` downscales to `MAX_SIDE` (1024 px) on white and re-encodes as JPEG.
2. One `MarketingAiClient::complete()` call with an image block plus a text block, against `INSPIRATION_SCHEMA`
   (`headline`, `style[]`, `topic`) or, for `purpose=photo`, `PHOTO_SCHEMA` (`description`, cut to
   `DESCRIPTION_MAX` 120 chars at a word).
3. Re-reads the row (the owner may have hearted it meanwhile), merges the notes, `status = read`, and the call's
   model, tokens and cost under `ai_notes.read`.
A `MarketingAiException` is recorded as `status = failed` with `error` and logged, not thrown. Anything else is
recorded as failed by the job, then rethrown. `MarketingAsset::readState()` reads a `reading` older than
`READ_STALE_MINUTES` (10) as `failed`, so a lost job ends in Retry rather than a forever spinner
([ADR-088](../decisions/ADR-088-marketing-one-vision-read-per-image-in-ai-notes.md)).

**Into Fill.** `app/Services/Marketing/MarketingPrompts.php:draft()` calls
`app/Actions/Marketing/PickInspirationExamples.php:handle($campaign)`: only images whose `readState()` is `read`;
favourites first (newest first), then non-favourites ranked by plain word overlap between their notes and the
campaign's name, brief and topics. An image sharing no word is never picked; at most `MAX` (5) in all
([ADR-089](../decisions/ADR-089-marketing-inspiration-examples-by-favourite-then-word-overlap.md)).
`inspirationLines()` adds them as "Ads the owner likes (follow their tone and wording; never copy them)", after
MK-8's taste lines and separate from them. `draft()` returns `inspiration_ids`, which `DraftOccurrence` writes to
each draft's `generation_meta`; `ReviseDraft` carries them through a rebuild. In the same prompt, `photos()` now
lists each favourite as `id: description`, or `id: original_name` while unread or failed.

**Favourite photos (Settings).** `Settings::updatedPhotos()` queues a read for each new favourite; each tile shows
the description, Reading… or Couldn't read · Retry (`retryRead()`), and the page polls only while one is reading.
A photo uploaded for a single post in the studio is not read. Photos stored before MK-18 are read by the backfill
`marketing:read-favourites {project}` (`QueueFavouriteReads`): one read per favourite with no description that is
not already reading, so it also re-queues failed ones.

**"Inspired by".** `PostStudio::inspiredBy()` loads the shown drafts' `inspiration_ids` in one query; the view draws
a row of thumbnails (or "Aa" for a text card) linking to the wall. An image deleted since simply drops out.

**Save to Inspiration.** `app/Actions/Marketing/SaveToInspiration.php:handle($post)` creates a favourite
inspiration from a post: it **copies** the photo to `marketing/{project}/inspirations/` (the post keeps its own
file), or for a post with no photo stores a text card (`path = ''`, `mime = MarketingAsset::TEXT_CARD_MIME`). The
caption is the notes (`source: post`, `post_id`, headline = first line, `status: read`), so no Claude call. A post
already saved returns the existing asset. `RemoveSavedInspiration` is Undo. The studio (`saveToInspiration()` /
`undoInspiration()`, post must be one it is showing) and each library card (another project's post is a logged 404)
show "♡ Save to Inspiration", then an inline "♥ Saved · Undo" control. `savedPostIds()` answers "which of these
posts are saved" in one query ([ADR-091](../decisions/ADR-091-marketing-save-to-inspiration-copies-and-undoes-inline.md)).

**The kit.** `SeedMarketingKit::inspiration()` adds each image in the kit's `posts/` and `covers/` as inspiration,
keyed by `ai_notes.kit_file`, with its kit caption from `captions-and-bios.txt` (`kitCaptions()`) as notes and the
headline from the caption's first line or, for covers, the file name. No Claude call. Re-running adds only what is
missing.

**Upload rules.** `app/Livewire/Marketing/Concerns/ValidatesMarketingImages.php` is the one set of rules and
messages for Settings, the studio and the wall; `Settings::PHOTO_MAX_KB` became `MarketingAsset::MAX_KB`.

## Data model
No migration. Uses MK-2's `marketing_assets` with `purpose = inspiration`, and its `ai_notes` JSON:
- `status`: `reading | read | failed` (absent = unread); `error` (failure reason); `read` (model, tokens,
  `cost_micro_usd`, `read_at`).
- Inspiration: `headline`, `style` (≤ 2), `topic`; `source` (`upload | kit | post`); kit: `kit_file`, `caption`;
  saved post: `post_id`, `caption`, `note`.
- Favourite photo: `description`.
`marketing_posts.generation_meta.inspiration_ids` (list of asset ids). Model additions on
`app/Models/MarketingAsset.php`: `scopeInspiration()`, `readState()`, `isTextCard()`, `MAX_KB`, `TEXT_CARD_MIME`,
`READ_*`. `DeleteMarketingAsset` skips the disk delete when `path` is empty.

## Interfaces
- `QueueAssetRead::handle(MarketingAsset): bool` (false = already reading).
- `ReadMarketingAsset::handle(MarketingAsset): MarketingAsset`, `markFailed()`, `event()`.
- `PickInspirationExamples::handle(MarketingCampaign): Collection<MarketingAsset>`.
- `StoreInspiration::handle(Project, UploadedFile)`, `SetInspirationFavourite::handle(asset, bool)`.
- `SaveToInspiration::handle(MarketingPost): {asset, created}`, `::savedFrom($post)`, `::savedPostIds($projectId, $ids)`;
  `RemoveSavedInspiration::handle(MarketingPost): bool`.
- `MarketingAiClient::complete(string $system, string|array $user, array $schema)`: `$user` may be Messages API
  content blocks ([ADR-090](../decisions/ADR-090-marketing-ai-client-takes-content-blocks-for-vision.md)).
- Livewire: `Inspiration` (`images`, `toggleFavourite`, `delete`, `retry`); `Settings::retryRead`;
  `PostStudio` and `Library` `saveToInspiration` / `undoInspiration`.
- Commands: `marketing:read-favourites {project}`; `marketing:seed-kit` now also prints the inspiration counts.
- Test hooks: `data-inspiration-drop`, `data-inspiration-upload`, `data-inspiration-wall`, `data-inspiration`,
  `data-read-state`, `data-favourite-inspiration`, `data-delete-inspiration`, `data-retry-read`,
  `data-inspiration-empty`, `data-inspiration-errors`, `data-photo-note`, `data-inspired-by`,
  `data-inspired-asset`, `data-save-inspiration`, `data-saved-inspiration`, `data-undo-inspiration`.

## Configuration
`ANTHROPIC_API_KEY` (read fails `not_configured` without it) and a queue worker. No new config.

## Observability
- `marketing.inspiration.stored` (info) per upload; `marketing.asset.read_queued` / `read_skipped` (`already_reading`,
  or `gone` for a deleted asset).
- `marketing.inspiration.read_succeeded|read_failed` and `marketing.photo.read_succeeded|read_failed`: tokens,
  `cost_cents`, `duration_ms`; failures carry `reason` (`missing_file`, `unreadable_image`, `invalid_reply`,
  `not_configured`, an API reason, or `exception`).
- `marketing.inspiration.favourited`, `saved_from_post`, `save_skipped`, `unsaved`, `unsave_skipped`;
  `marketing.inspiration.refused` (warning, `not_inspiration`); `marketing.library.save_refused` (warning);
  `marketing.photo.backfill_queued` / `backfill_refused`; `marketing.asset.upload_empty`.
- The job restores the upload's `request_id`, so a read's log lines trace back to the drop.
- A tile stuck on "Reading…": no worker. After 10 minutes it shows Retry. A favourite listed by file name in a
  prompt: its read never ran or failed; run the backfill.

## Testing & verification
- `tests/Feature/Marketing/InspirationTest.php`: one `it()` per acceptance criterion (named as in the story), plus
  the read failure reasons, stale reads, the already-reading guard, cross-project and not-an-inspiration refusals,
  text-card saves, the empty drop and the per-drop cap.
- `tests/Browser/Marketing/InspirationTest.php`: heart and delete through the confirm at 1280 px; 375 px with no
  sideways scroll.
- `tests/Browser/Journeys/MarketingPostTest.php` step 7: an ad stored and read (fake Claude), hearted on the wall,
  then Fill quotes it first and the studio shows it under "Inspired by". The image is stored by `StoreInspiration`
  because Pest's browser server drops uploads; the drop itself is covered in the feature test.

## Key decisions & tradeoffs
- One vision read per image, kept in `ai_notes`; read state and staleness in `ai_notes.status` →
  [ADR-088](../decisions/ADR-088-marketing-one-vision-read-per-image-in-ai-notes.md).
- Examples: favourites, then word overlap, max 5, beside taste, not in it →
  [ADR-089](../decisions/ADR-089-marketing-inspiration-examples-by-favourite-then-word-overlap.md).
- One AI client that takes content blocks →
  [ADR-090](../decisions/ADR-090-marketing-ai-client-takes-content-blocks-for-vision.md).
- Save to Inspiration copies the file, saves text-only posts as text cards, undoes inline →
  [ADR-091](../decisions/ADR-091-marketing-save-to-inspiration-copies-and-undoes-inline.md).
- Kit images and saved posts arrive with known words, so they are never sent to Claude.

## Known limitations & gotchas
- Tone and copy only: nothing about layout is asked for or used.
- With more than five favourites, only the newest five are quoted and no closest match is added.
- Word overlap is English-ish and literal: no synonyms; a plural "s" is the only stemming.
- Revise does not add inspiration to its prompt; a rebuilt draft keeps its original `inspiration_ids`.
- A kit image the owner deletes comes back on the next `marketing:seed-kit`.
- Save to Inspiration is offered on any post the studio shows, drafts included.
- **Owner follow-up:** run `php artisan marketing:read-favourites <project>` once after merge (needs the key and a
  worker) so existing favourites get descriptions.

## Change history
2026-10-13 — Inspiration wall, one-time reads of inspiration and favourite photos, examples in Fill, "Inspired by",
Save to Inspiration in studio and library, kit seeded as inspiration, `marketing:read-favourites` (MK-18, `b874421`)
