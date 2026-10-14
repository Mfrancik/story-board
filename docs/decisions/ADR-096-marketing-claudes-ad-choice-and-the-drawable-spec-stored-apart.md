# ADR-096 — Claude's ad choice and the drawable spec are stored apart

Date: 2026-10-13 · Status: accepted · Story: MK-19

## Context

Claude's `ad` must be cleaned before MK-5 can draw it: a photo layout with no photo becomes the text card,
over-long words are cut, and unknown layouts fall back. The story also wants versions to keep their old spec, and
a post's spec must survive Undo, a photo change and a caption edit. Before MK-19, all of those rebuilt the
spec from the caption (`BuildDefaultAdSpec`).

## Decision

Two records per post:
- `generation_meta.ad`: Claude's choice as sent, limited to `AD_SCHEMA`'s keys by `MarketingPrompts::adChoice()`
  (null when there was none).
- `marketing_posts.ad`: the drawable spec, `NormaliseAdSpec`'s output, beside MK-5's render state.

`QueuePostRender` re-specs from `generation_meta.ad` through `NormaliseAdSpec` and uses MK-5's default only for a
post Claude never art-directed. Fill and Revise store the spec themselves and queue with `respec: false`.

Alternatives rejected:
- **Store only the normalised spec.** A photo layout downgraded to the text card for lack of a photo would be lost
  for good, so a photo added later could not restore it. Versions also do not copy `ad`, so "versions keep the old
  spec" would have needed a column on `marketing_post_versions`.
- **A spec column on versions.** That would be a migration, where `generation_meta` (already copied into every
  version) carries it for free.

## Consequences

- Undo restores the version's `generation_meta`, and with it the old ad. No migration.
- A caption edit no longer moves the on-image headline, because it re-specs from Claude's words, not the caption.
  This is accepted; Revise is how the ad's words change until MK-4's designer.
- Two places hold "the ad". Readers that draw use `ad`, and anything that re-specs uses `generation_meta.ad`.
