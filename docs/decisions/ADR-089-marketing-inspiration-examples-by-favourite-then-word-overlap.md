# ADR-089 — Inspiration examples: favourites first, then plain word overlap, beside taste

Date: 2026-10-13 · Status: accepted · Story: MK-18

## Context

Fill should quote some inspiration as examples. The story says "favourites and the closest matches", with no
embeddings. MK-8's taste examples (`BuildTasteExamples`) are in the same prompt and are a Do-NOT-touch.

## Decision

`app/Actions/Marketing/PickInspirationExamples.php` returns at most 5 read images: the favourites first (newest
first), then non-favourites scored by how many distinct meaningful words their notes share with the campaign's
name, brief and topics (lower-cased, > 3 letters, stop words out, a trailing plural "s" dropped). An image with
zero overlap is never picked just to fill a slot.

`MarketingPrompts::inspirationLines()` puts them in their own block, after the taste lines, never inside them:
taste is the owner's verdicts on drafts, inspiration is outside material. The ids are stored on each draft
(`generation_meta.inspiration_ids`) for the studio's "Inspired by", and `ReviseDraft` carries them through a
rebuild.

Alternatives rejected:
- **Embeddings / semantic similarity.** Out of scope; overkill for a few dozen ads.
- **Always fill five slots.** An unrelated ad teaches the wrong topic.
- **Merging inspiration into taste examples.** Changes MK-8's logic and blurs "the owner liked this draft" with
  "the owner likes this ad".

## Consequences

- Cheap and explainable; misses synonyms. More than five favourites crowd out every closest match.
