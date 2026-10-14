# ADR-092 — Three ad styles over the same five layouts, chosen per campaign

Date: 2026-10-13 · Status: accepted · Story: MK-5

## Context

MK-5's mockup gate offered three layout families for the rendered ads: A Gallery card, B Vault and C Poster blocks
(`docs/mockups/MK-5/option-{a,b,c}.html`). Each one drew the same five layouts: photo + headline, bold text card,
question, coin spotlight and stat. Normally the owner picks one. The owner said instead: "all of these are fine, we
can use every design here they're all great and fit the brand. we could use different ones for difference
campaigns".

## Decision

Ship all three as **ad styles**: 3 styles × 5 layouts = 15 templates under
`resources/views/marketing/ads/<style>/<layout>.blade.php`. The style is a campaign setting:
- `marketing_campaigns.ad_style` is nullable (`gallery | vault | poster`), and null reads as gallery
  (`MarketingCampaign::adStyle()`).
- `SaveCampaign` stores gallery as null, so existing campaigns need no data rewrite and an untouched form never
  reads as changed.
- One-off posts have no campaign, so they use gallery.
- The campaign form has an "Ad style" select.

The layout belongs to the post's spec, and the style belongs to its campaign. MK-19's art director varies layout
per draft, while the campaign keeps one look.

Alternatives rejected:
- **One family.** That would discard designs the owner wants. A single family plus per-post variety is MK-19's job,
  not a style choice.
- **Style per post in the spec.** That gives no consistent look per campaign, which was the owner's reason, and
  MK-19 would have one more thing to choose.

## Consequences

- Every new layout must be written three times, and every style's CSS change must be checked across five layouts
  and two sizes.
- Changing a campaign's style does not re-render its existing posts; only new renders use it.
