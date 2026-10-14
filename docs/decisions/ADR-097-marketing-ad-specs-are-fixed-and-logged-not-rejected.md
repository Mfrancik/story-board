# ADR-097 — Bad ad specs are fixed and logged, not rejected; only one-layout replies fail

Date: 2026-10-13 · Status: accepted · Story: MK-19

## Context

Claude's reply schema bounds `layout` and `crop` with enums, but structured outputs do not enforce string
`maxLength`, so headlines can come back too long. MK-8 replies (and fakes in older tests) carry no `ad` at all. The
story requires that three drafts span at least two layouts.

## Decision

- **Lengths in code.** The caps (headline `min(60, BuildAdHtml::MAX_HEADLINE[layout])`, subline 120, cta 24,
  badge 24) are stated in the schema descriptions and the prompt. They are enforced only in `NormaliseAdSpec`, which
  cuts at a word boundary and logs `marketing.ai.ad_spec_fixed` (`too_long`).
- **Per-draft problems are fixed, not fatal.** A missing ad or unknown layout gets MK-5's default spec, a photo
  layout without a photo becomes the text card, and a bad crop is centred. Each fix is logged with a `reason`.
- **One reply-level rule rejects.** `DraftOccurrence::drafts()` throws `invalid_reply` only when all three drafts
  name the same *known* layout. Missing or unknown layouts do not count, so an MK-8-shaped reply still fills.

Alternatives rejected:
- **`maxLength` in the schema.** It is not enforced, and so would only be a false comfort.
- **Reject any malformed spec.** It costs the whole call (three drafts) over one fixable field, and would break
  every reply without `ad`.
- **Count unknown layouts as one layout.** It would reject replies whose drafts would actually render differently
  after the fallback.

## Consequences

- Prompt drift shows up as `ad_spec_fixed` volume in the logs, not as failed Fills.
- The rule checks Claude's layouts *before* normalising. Three drafts can therefore still render alike: unknown
  layouts that fall back to the same default, or photo layouts turned into text cards beside a text card on a
  photo-less date. This is accepted as rare, and each fix is logged.
