# ADR-073 — Drafting is all-or-nothing, and AI cost is stored as integer micro-USD

Date: 2026-10-12 · Status: accepted · Story: MK-8

## Context

A date should show three drafts or a clear failure, never a partial state the owner has to interpret. Every call's
cost must be recorded (the story's "measured" goal). One call costs a fraction of a cent.

## Decision

- `DraftOccurrence` treats these as failure, and writes nothing: fewer than three usable (non-empty) captions, a
  refusal (`stop_reason: refusal`), a truncated reply (`max_tokens`), non-JSON text, an HTTP error or a timeout.
  Extra captions are dropped.
- With `replace` (Regenerate), the old drafts are set to `discarded` inside the same transaction that creates the
  new ones. A failed regenerate keeps the old drafts.
- Cost is `generation_meta.cost_micro_usd`, an integer, computed from the reply's usage and `MarketingAi::PRICES`
  (USD per million tokens = micro-USD per token). That covers input, output, cache read and cache write.
- Logs carry `cost_cents`, a float rounded to 4 dp, because the logging spec names that field.
- A model not in the price table records `null`, not a guess.

Alternatives rejected:
- **Keep whatever captions came back.** It gives a date with one or two drafts and no signal that anything went
  wrong.
- **Discard first, then draft.** A failure would wipe the owner's drafts.
- **Integer cents.** Almost every call would round to 0.
- **A float column.** Money stays integer in this codebase.

## Consequences

- Prices are a constant in code and must be updated when Anthropic's prices change, or when the default model
  changes.
- The cost is an estimate from reported usage, not a billing figure.
