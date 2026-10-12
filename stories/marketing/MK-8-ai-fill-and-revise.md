# MK-8 — AI Fill and Revise: drafts per date, rebuild from feedback
Status: approved        Journey: marketing-post (step 4 of 7)
Source: owner 2026-10-12: "we can have them generated, but anything that gets posted i manually pick", "a box i can
respond with changes where AI rebuilds or changes things up for me", like/dislike to "learn my style… brand design,
fonts, etc. all stay the same"

## Story
As the owner, I want Fill to draft three posts for each open campaign date and to rebuild a draft from my written
feedback, so that I only pick and steer — never write from scratch.

## Why
This is the one genuinely uncertain part of the initiative (is the copy good enough?), so it is built plainly and
measured: prompt + examples, warn-only rules, every call logged with cost.

## In scope
- Claude client via Laravel's HTTP client (Messages API). `config/services.php` gains `anthropic` (key from
  `ANTHROPIC_API_KEY`, model from `MARKETING_AI_MODEL`, default the current Sonnet) — **owner approved the config
  change at the story gate**; `.env.example` documents both. Load the `claude-api` skill before writing this code.
- `DraftOccurrence` Action: for one campaign date → 3 `draft` posts (`source=ai`). Prompt = brand voice + copy rules
  (fixed, cached) + campaign brief + topics (ticked ideas + own topics with detail, rotating so recent ones are not
  repeated) + taste examples + the campaign's photo mode. The AI never chooses colours, fonts or layout.
  - Photo mode `favourites` / `ai_decides`: the model picks an asset id from the project's favourites (or none);
    `per_post`: posts are created with no asset ("Needs a photo"); `text_only`: none.
  - Ideas with unfilled placeholders (`[set]`, `[coin]`) are skipped until a data source exists (phase 3).
- `FillCampaign` Action: every open occurrence in the next 2 weeks (convention), one queued job per date.
- `ReviseDraft` Action: post + feedback text + chips (Shorter, Warmer, More about the set, Different photo, Less
  salesy) → new version of that post; the previous version is kept (`marketing_post_versions`); feedback is stored.
- `RegenerateOccurrence`: discards the date's drafts and drafts 3 new ones.
- **Taste** = examples, not training: the last 10 liked and 5 disliked captions (with reasons) and the last 10
  feedback notes, campaign first then project. Stored in `marketing_feedback`.
- **Copy-rules lint** (`LintCaption`, pure, no AI): > 2 lines, a filler phrase, a banned claim (cert lookup), a
  comparison with no price source → warnings saved with the post. Warn, never block.
- Fill buttons on the campaign page (per date and "Fill N open dates") with a "Drafting…" state that polls the jobs.
- Every call records `generation_meta` (model, input/output tokens, cost estimate, prompt hash, duration).

## Out of scope (do NOT build)
- Automatic background drafting on a schedule (later), image generation, the studio UI (MK-9), inspiration examples
  (MK-18 extends the prompt), any publishing.
- Retiring a page or feature? None retired.

## Acceptance criteria (executable — these become the Pest test names)
- Given an open date and a faked Claude response, when I Fill it, then 3 draft posts exist for that date with captions,
  `source=ai` and `generation_meta`.
- Given the prompt is built, then it contains the brand voice, copy rules, brief and own topics, and contains no
  instruction to change colours, fonts or layout (assert on the built prompt).
- Given 12 likes and 7 dislikes, then the prompt includes exactly the latest 10 liked and 5 disliked examples.
- Given photo mode `per_post`, then drafts have no asset; given `favourites` and a returned id from another project,
  then the id is ignored and `marketing.ai.invalid_asset` is logged.
- Given a draft and feedback "Shorter. Mention album toning", when revised, then a new version exists, v1 is kept, and
  the feedback row is stored.
- Given a caption "Tell us below, we read every one" then the lint warns `filler`; "type a cert and we pull the coin"
  warns `banned_claim`; three lines warns `too_long`; a clean two-liner has no warnings.
- Given Claude returns an error or times out, then no drafts are created, the date shows "Drafting failed · Retry", and
  `marketing.ai.draft.failed` is logged with status and duration (never the API key or full prompt).
- Given no `ANTHROPIC_API_KEY`, then Fill is disabled with "Add ANTHROPIC_API_KEY to .env".
- Given "Fill 3 open dates", then 3 jobs are queued and each date fills independently.

## Applicable standards
- Design: Fill controls + Drafting/failed states on the prototype's campaign route (no new screens).
- Codebase/DB: Actions in `app/Actions/Marketing/`; a `MarketingAi` client class behind an interface so tests fake it
  (`Http::fake`); jobs `DraftOccurrenceJob`; no secrets in code; the queue already runs in `composer run dev`.
- Logging: `marketing.ai.draft.started|succeeded|failed`, `marketing.ai.revise.succeeded|failed`,
  `marketing.ai.invalid_asset`, `marketing.lint.warned` (debug) — context: campaign_id, post_id, model, tokens,
  cost_cents, duration_ms.

## Design mockup gate (visual stories only — else "n/a — non-visual")
- Mockups: docs/mockups/MK-prototype/index.html (Fill / Drafting states on the campaign route)
- Chosen option: the prototype, owner 2026-10-12
- Why I chose it: "when i go into a campaign and click fill…" — Fill lives on the campaign, not a separate page.

## Do NOT touch
- `config/` beyond the `anthropic` entry in `config/services.php`; brand values (read only here).

## Data & interfaces
- `marketing_post_versions`: id, post_id (FK cascade), version (int), caption, asset_id (null), feedback (text null),
  chips (json null), generation_meta (json null), created_at. `marketing_posts` gains `version` (int, default 1) and
  `lint` (json null).
- `marketing_feedback`: id, project_id (FK cascade), campaign_id (null, nullOnDelete), post_id (FK cascade), vote
  (tinyint null: 1 / -1), reasons (json null), note (text null), created_at. Index (project_id, created_at).
- Actions `DraftOccurrence`, `FillCampaign`, `ReviseDraft`, `RegenerateOccurrence`, `LintCaption`, `BuildTasteExamples`.

## Test plan
- Pest: failing tests first with `Http::fake` Claude responses; prove the lint and the "no brand instructions" test red.
- Journey test: step 4 — Fill one TrueView Tuesday date (faked AI) → 3 drafts.
- Browser check: with a real key, Fill one date for coins; read the 3 captions against the copy rules; note cost in
  the log.

## Definition of done
- [ ] Acceptance criteria pass (show Pest output)
- [x] Visual story: mockup gate cleared — chosen option + reason recorded above
- [ ] Journey test(s) green end-to-end
- [ ] Logging events in place per standard
- [ ] Status flipped to `built` in the same commit as the build (stories/README.md §Status)
- [ ] /preflight returns GO
- [ ] Feature doc written/updated via /document

## Links
Journey: marketing-post · Depends on: MK-1, MK-2, MK-7
