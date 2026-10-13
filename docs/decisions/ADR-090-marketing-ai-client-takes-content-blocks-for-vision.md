# ADR-090 — One Marketing AI client, whose user part may be content blocks

Date: 2026-10-13 · Status: accepted · Story: MK-18

## Context

The image read (ADR-088) has to send an image to Claude. MK-8's `MarketingAiClient::complete()` took the user part
as a string only. The story says to reuse MK-8's `MarketingAi`.

## Decision

`MarketingAiClient::complete(string $system, string|array $user, array $schema)`: `$user` is either text or a list
of Messages API content blocks (an `image` block plus a `text` block for a read). `MarketingAi` passes either
through; `prompt_hash` hashes the JSON of the blocks. The invalid-reply message now says "the JSON asked for"
rather than "the drafts", since reads use it too.

Alternatives rejected:
- **A second vision client.** Duplicates the key check, timeout, structured-output schema, pricing and cost
  logging that ADR-074 settled for the one client.

## Consequences

- Reads are priced and logged exactly like drafts. Any fake of `MarketingAiClient` must accept an array user part.
