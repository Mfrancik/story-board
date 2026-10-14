# ADR-095 — Claude picks each ad's layout and words; the campaign keeps the style

Date: 2026-10-13 · Status: accepted · Story: MK-19

## Context

MK-19 asks Claude to art-direct every draft as a whole ad. The story was written before MK-5 shipped, and MK-5 then
gave each campaign one of three ad styles (gallery, vault, poster), each drawing the same five layouts. The owner
chose the style per campaign ([ADR-092](ADR-092-marketing-three-ad-styles-chosen-per-campaign.md)). Since MK-8 the
brand's look has been off-limits to the model (owner, 2026-10-12).

## Decision

Claude chooses only the **layout** (one of `MarketingPost::AD_LAYOUTS`) and the **words on the image**
(headline, subline, cta, badge), plus the photo crop. The style always comes from `MarketingCampaign::adStyle()`.
The prompt names the style only as context ("the style is fixed; you choose the layouts"). Colours, fonts and
design stay out of every prompt, and MK-8's prompt test still bans them. Only the word "layout" was removed from
that ban.

Alternatives rejected:
- **Claude picks the style too.** That would override an owner decision made one story earlier, and make three
  drafts differ by look rather than by idea.
- **Keep the model out of design entirely and pick layouts by rule.** Three drafts would not really differ, and
  the layout is best chosen from what the caption says, which is what the model knows.

## Consequences

- One campaign's ads look like one family. Variety between drafts comes from layout and words.
- A new layout means a new `LAYOUT_USE_WHEN` and `AD_LAYOUT_LABELS` entry along with MK-5's templates.
