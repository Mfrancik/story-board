# MK-18 — Inspiration dump: past ads the AI builds from
Status: approved        Journey: marketing-post (step 7 of 7)
Source: owner 2026-10-12: "a place to dump ads ive already done that i like so we can build off those and have similar
designs. ideally a single dumping place that has many images, unorganized. ONLY if you believe this is going to be
effective"

## Story
As the owner, I want one unorganised place to drop ads I like, so that new drafts follow their tone and copy.

## Why
Effective for **copy and tone now**: on upload, Claude reads each image once (vision) and stores the headline text and
1–2 style notes; Fill then uses the owner's favourites and the closest matches as examples. Cheap (one read per image,
not per draft). **Layout matching only arrives with the designer (phase 3)** — the page says so plainly.

## In scope
- `GET /p/{project}/marketing/inspiration` — Livewire `Marketing\Inspiration`: big drop zone + multi-file upload into
  one masonry wall; no folders, no required tags; heart (favourite) and delete (confirm) per image.
- On upload: `ReadInspiration` job sends the downscaled image to Claude once → `ai_notes` (`headline`, `style[]`,
  `topic`), shown as a small "AI read it" note; "Reading…" until done; failure shows "Couldn't read · Retry".
- Assets stored with `purpose=inspiration` (MK-2's table).
- MK-8's prompt builder adds up to 5 inspiration examples (favourites first, then the closest matches by topic to the
  campaign's topics) as text notes; drafts record which they used, shown as a tiny "Inspired by" row in the studio.
- `marketing:seed-kit` also adds the kit's 15 posts + 3 covers as inspiration, with their kit captions as notes
  (idempotent). The kit is reference material, not post history.
- **Save to Inspiration** (owner round 5: "if i generate an ad i like i should have a quick way to add to
  inspirations"): `SaveToInspiration` Action copies a post's image (or its text-card render data) and caption into a
  favourite inspiration asset with a "From your post" note and the source post id; one click from the studio and the
  library; Undo removes it; saving twice is a no-op.
- **Favourite photos are read too** (owner 2026-10-12, after testing Fill: Claude was choosing photos by filename
  only — "full (1).jpg"): the same one-time read runs on every favourite photo (`purpose=photo`, on upload and once
  for existing ones) and stores `ai_notes.description` (what is in the picture, ≤ 120 chars). MK-8's photo list in
  the prompt then shows `id: description` instead of the filename (filename only while unread or if the read failed).
  Settings shows the note under each favourite with the same Reading… / Couldn't read · Retry states.

## Out of scope (do NOT build)
- Visual layout cloning (needs the designer, phase 3), image generation, tagging/folders, embeddings.
- Retiring a page or feature? None retired.

## Acceptance criteria (executable — these become the Pest test names)
- Given I drop 12 images, then 12 inspiration assets exist and a read job is queued for each.
- Given a faked Claude read, then the image shows its headline and style notes.
- Given Claude fails, then the tile shows Retry and `marketing.inspiration.read_failed` is logged; Retry re-queues.
- Given 3 favourites and 10 others, when a draft's prompt is built, then it includes the 3 favourites plus at most 2
  closest matches, and the draft records their ids.
- Given a draft with recorded inspiration, then the studio shows its "Inspired by" thumbnails.
- Given a PDF or a 20 MB file, then it is rejected.
- Given the seed runs twice, then exactly 18 kit inspiration assets exist.
- Given a post I like, when I Save to Inspiration, then a favourite inspiration asset exists with the post's caption and
  source post id; saving it again creates nothing new; Undo deletes it.
- Given a 375px viewport, then the wall is one or two columns with no sideways scroll.
- Given a favourite photo is uploaded, then a read job is queued and a faked read stores its description.
- Given favourites with descriptions, when a draft's prompt is built, then each photo is listed by id and description,
  not filename; given an unread favourite, then it is listed by filename.
- Given existing favourites with no description, when the backfill runs, then each gets exactly one read job.

## Applicable standards
- Design: prototype Inspiration route; reuse MK-2's upload + asset components.
- Codebase/DB: Actions `StoreInspiration`, `ReadInspiration` (job), `PickInspirationExamples`; reuse MK-8's `MarketingAi`.
- Logging: `marketing.inspiration.stored`, `marketing.inspiration.saved_from_post`, `marketing.inspiration.read_succeeded|read_failed` (tokens, cost_cents).

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/MK-prototype/index.html (Inspiration route, round 4)
- Chosen option: the prototype Inspiration wall (rounds 4–5), owner 2026-10-12
- Why I chose it: "inspirations is good"; "a single dumping place that has many images, unorganized".

## Do NOT touch
- `config/`; MK-8's taste logic (add examples beside it, don't change it). MK-8's photo list may change only to
  show descriptions (owner 2026-10-12).

## Data & interfaces
- Schema: `marketing_posts.generation_meta` records `inspiration_ids`; no new tables (MK-2's `marketing_assets.ai_notes`).
- Route `projects.marketing.inspiration`; Livewire `Marketing\Inspiration`.

## Test plan
- Pest: failing tests first, Claude faked.
- Journey test: step 7 — upload one inspiration image, Fill, see "Inspired by" in the studio.
- Browser check: drop 5 of the owner's past ads, read notes, Fill a date, compare tone; 375px.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-2, MK-8, MK-9
