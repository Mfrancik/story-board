# ADR-074 — MarketingPrompts is a service; one Claude call shape for draft and revise

Date: 2026-10-12 · Status: accepted · Story: MK-8

## Context

Two Actions (`DraftOccurrence`, `ReviseDraft`) ask Claude for captions. Their prompts share a fixed part: the brand
voice and the copy rules. Each also has a part that changes per call. The brand's look must never reach the model.

## Decision

- `app/Services/Marketing/MarketingPrompts.php` is a service, not an Action. It has no single verb: it exposes
  `system()`, `draft()` and `revise()`, plus `DRAFTS_SCHEMA` and `CAPTION_SCHEMA` for the replies.
- Topic rotation reads `generation_meta.topic_key` from the campaign's last 30 posts, not a separate table. Each of
  the three drafts gets a distinct topic, the least recently used first.
- `MarketingAi` (behind `MarketingAiClient`, faked in tests with `Http::fake` so the real request shape is
  exercised) makes every call the same way:
  - The system part is sent with `cache_control: ephemeral`.
  - It asks for structured JSON output against the schema, at medium effort.
  - It allows a server-side fallback model.
  - Timeouts are 10 s to connect and 90 s overall, with `max_tokens` 16000. The limit is generous, because a
    cut-off reply is useless.
- The model named in the reply is the one recorded and priced, which may be a fallback.

Alternatives rejected:
- **Prompt building inside each Action.** The system part, the cache prefix and the "no look" rule would be
  duplicated.
- **Free-text replies parsed by line.** They are fragile. A schema makes the reply always JSON, and only refusals
  and truncation remain as failure modes.
- **A topic-history table.** The posts already carry the key.

## Consequences

- A system prompt shorter than the model's cache minimum is silently not cached. This is harmless.
- Prompt text is stored only as a sha256 `prompt_hash`. Reproducing a call means rebuilding the prompt from the same
  data.
