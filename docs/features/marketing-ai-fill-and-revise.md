# Marketing: AI fill and revise
Status: active   ·   Last updated: 2026-10-12   ·   Stories: MK-8

## Overview
Fill asks Claude for three draft captions for each open campaign date, so the owner only picks and steers and never
writes from scratch. Revise rewrites one draft from the owner's written feedback and chips, and keeps the version
it replaced. Regenerate swaps a date's drafts for three new ones. The AI writes words only: colours, fonts and
layout stay fixed by the brand (MK-2) and never appear in a prompt. Every call records its model, tokens, estimated
cost and duration. Every caption is linted against the copy rules. The lint warns and never blocks.

Fill has UI on the campaign page (MK-7). Revise and Regenerate are Actions only for now: their buttons come with the
studio (MK-9).

## How it works
**Fill, from the page.** `app/Livewire/Marketing/CampaignPage.php` has two actions. `fillDate($date)` is a row's
Fill or Retry, and `fillOpen()` is the Future posts header's "Fill N open dates". They are not named `fill` because
that is a reserved Livewire method. Both call `app/Actions/Marketing/FillCampaign.php:handle()`. It refuses when there
is no key, when the campaign is paused, or (for one date) when the date is not an open, on-schedule row of the
page's timeline. It then marks each target date `drafting` in `DraftingStatus` and dispatches one
`DraftOccurrenceJob` per date. A date already marked drafting is skipped. The date is marked *before* dispatch,
because on the sync queue the job has already finished, and cleared the mark, by the time dispatch returns.
- "Fill N open dates" takes the open dates in the next `FillCampaign::WINDOW_DAYS` (14). It is shown only when
  there are some, or while one is drafting. So a campaign whose next open date is three weeks out gets row buttons
  only. The journey fills Nov 3 through its row button for this reason.
- `render()` reads `DraftingStatus::states()` for the timeline's dates and the window's. While any date is drafting,
  the Future posts section has `wire:poll.2s`. Polling stops once nothing is drafting.

**Drafting status: `app/Services/Marketing/DraftingStatus.php`.** This is cache, not schema. The key is
`marketing.drafting.{campaign}.{Y-m-d}` and the value is `drafting` (TTL 10 min) or `failed` (TTL 24 h). A lost or
expired entry only means the date reads as open again
([ADR-071](../decisions/ADR-071-marketing-drafting-state-lives-in-the-cache.md)). The row
(`components/marketing/occurrence-row.blade.php`) shows Fill, "Drafting…" plus "Writing 3 drafts…", or
"Drafting failed · Retry". It shows these only on `open` rows of an enabled campaign.

**The job: `app/Jobs/DraftOccurrenceJob.php`.** It is `ShouldBeUnique` on `campaign_id|date` (`uniqueFor` 600 s) with
`$tries = 1` ([ADR-072](../decisions/ADR-072-marketing-draft-job-runs-once-and-is-unique-per-date.md)). It carries
the request ID of the request that pressed Fill and restores it into `Context`. It calls `DraftOccurrence`, then:
on success it clears the status; on `MarketingDraftRefusedException` it clears it (the posts already say what the
date is); on `MarketingAiException` it marks the date failed; on any other throwable it logs
`marketing.ai.draft.failed` with `reason: exception`, marks the date failed and rethrows.

**Drafting one date: `app/Actions/Marketing/DraftOccurrence.php:handle($campaign, $at, $replace = false)`.**
1. It refuses when there is no key, or when the date already has a kept post. "Kept" means any status but
   `discarded`, placed on the date by `BuildCampaignRunway::day()`. With `$replace`, only a non-draft post blocks.
2. It builds the prompt with `MarketingPrompts::draft()` and calls `MarketingAiClient::complete()` with
   `DRAFTS_SCHEMA`.
3. It needs `MarketingPrompts::DRAFTS` (3) non-empty captions. Fewer is an `invalid_reply` failure and nothing is
   written ([ADR-073](../decisions/ADR-073-marketing-drafting-is-all-or-nothing-and-cost-is-micro-usd.md)).
4. In one transaction it discards the old drafts (with `$replace` only) and creates three `draft` posts
   (`source=ai`, `version` 1). Each post gets `generation_meta`: the call's meta plus `call`, `candidate`,
   `drafts_in_call`, `topic_key` and `topic`. Each also gets `lint` from `LintCaption`.
5. Photos: the model's `asset_id` is kept only if it was one of the favourite ids offered in the prompt. Any other
   id (another project's, a deleted one, an invented one) is dropped and logged as `marketing.ai.invalid_asset`.
   `per_post` and `text_only` offer no ids, so those posts never get a photo from the model. `per_post` drafts show
   "Needs a photo".

**The prompts: `app/Services/Marketing/MarketingPrompts.php`.** This is a service, not an Action. It owns two
prompts and their reply schemas ([ADR-074](../decisions/ADR-074-marketing-prompts-service-and-claude-call-shape.md)).
- `system($project)`: the fixed part. It names the page (brand name or project name) and gives the brand's voice and
  the copy rules: at most two lines, the filler and banned phrases quoted, no cert lookups, and price sources on
  comparisons. It ends with one line saying the look is fixed and not the model's job, without naming any part of
  the look. This part is the prompt-cache prefix.
- `draft($campaign, $at)`: the user part. It gives the campaign, the local date and time, and the brief, then one
  topic per draft, then the taste examples, then the photo instruction for the campaign's photo mode.
  - **Topic pool**: the ticked menu ideas (with their sample line), then the own topics (with their detail). An
    idea whose sample line still has a `[placeholder]` is skipped. The skipped ideas are returned so the caller can
    log them.
  - **Rotation**: it reads `generation_meta.topic_key` from the campaign's last `ROTATION_WINDOW` (30) posts. Topics
    never used come first, in the owner's order. Used topics follow, the longest-unused first. A pool smaller than 3
    wraps.
  - **Photos**: up to `MAX_PHOTOS` (30) of the project's own favourites, by id and file name.
- `revise($post, $feedback, $chips)`: the post's caption, then the feedback, then the chips. Photos are offered again
  only when the chips include "Different photo" and the campaign's mode picks from favourites. The current photo is
  left out of that list. Otherwise the photo stays.

**Taste: `app/Actions/Marketing/BuildTasteExamples.php`.** Taste is examples, not training. It takes the latest 10
liked captions, 5 disliked captions (with their reasons) and 10 feedback notes from `marketing_feedback`. Each list
is filled from the campaign first and topped up from the rest of the project, using two bounded queries.

**The client: `app/Services/Marketing/MarketingAi.php`** implements `MarketingAiClient` (bound in
`AppServiceProvider`). It makes one `POST` to the Messages API through Laravel's HTTP client:
- The system block has `cache_control: ephemeral`.
- `output_config` sets effort `medium` and a `json_schema` format.
- `fallbacks: default` sends the `server-side-fallback` beta header.
- Timeouts are 10 s to connect and 90 s overall. `max_tokens` is 16000.

The model that actually answered (`model` in the reply) is the one recorded and priced. `stop_reason` `refusal` and
`max_tokens` are failures (`refusal`, `truncated`), as are a non-2xx reply (`http_error`), a timeout (`timeout`) and
non-JSON text (`invalid_reply`). The key travels only in `x-api-key`. The prompt is stored only as
`prompt_hash` (sha256).

**Revise: `app/Actions/Marketing/ReviseDraft.php:handle($post, $feedback, $chips)`.** It refuses a non-draft post, an
unknown chip (`CHIPS`: Shorter, Warmer, More about the set, Different photo, Less salesy), empty feedback with no
chips, or no key. After a good reply it makes one transaction:
1. It copies the current post into `marketing_post_versions`, with the feedback and chips given on that version.
2. It writes a `marketing_feedback` row (`vote` null, `reasons` = chips, `note` = text).
3. It updates the post: new caption, photo, `version + 1`, new `generation_meta` (`call: revise`, keeping
   `topic_key`) and a new `lint`.

**Regenerate: `app/Actions/Marketing/RegenerateOccurrence.php`** is `DraftOccurrence` with `replace: true`. The old
drafts are set to `discarded` only inside the transaction that saves the new ones, so a failed regenerate keeps
them. A date with an approved, posted or skipped post is refused.

**Lint: `app/Actions/Marketing/LintCaption.php:handle($caption, $copyRules)`.** It is pure: an arch test
(`tests/Unit/Marketing/LintCaptionArchTest.php`) bans models, facades and services. It returns
`[{code, match}]`, in this order:
- `too_long`: more than 2 non-blank lines.
- `filler`: a brand filler phrase.
- `banned_claim`: a brand banned phrase, or the built-in `CERT_CLAIM` pattern when no listed phrase matched. The
  pattern is there because the kit's banned list misses "type a cert and we pull the coin".
- `no_price_source`: a comparison word with no named price source. This is a heuristic.

## Data model
Migrations `2026_10_12_000006`–`000008`:
- `marketing_posts` gains `version` (unsigned int, default 1, after `caption`) and `lint` (json null). Null means
  not linted, and `[]` means clean.
- `marketing_post_versions`: `post_id` (FK cascade), `version`, `caption`, `asset_id` (FK null on delete),
  `feedback`, `chips` (json), `generation_meta` (json), `created_at`; unique `(post_id, version)`. The post row is
  always the current version, and this table holds only the earlier ones. Read it through
  `MarketingPost::versions()` (ordered by version).
- `marketing_feedback`: `project_id` (FK cascade), `campaign_id` (null on delete, so feedback still counts for the
  project), `post_id` (FK cascade), `vote` (tinyint: `MarketingFeedback::LIKED` 1, `DISLIKED` -1, or null for a
  written note), `reasons` (json), `note`, `created_at`; index `(project_id, created_at)`. Read it through
  `MarketingPost::feedback()`.
- `generation_meta` on posts and versions: `model`, `input_tokens`, `output_tokens`, `cache_read_input_tokens`,
  `cache_creation_input_tokens`, `cost_micro_usd` (int or null), `prompt_hash`, `duration_ms`, `call`
  (`draft|revise`), `topic_key`, `topic`. Drafts also carry `candidate` and `drafts_in_call`.

## Interfaces
- `MarketingAiClient`: `isConfigured()`, `model()`, and `complete(string $system, string $user, array $schema):
  array{data, meta}`. It throws `MarketingAiException` (`reason`, `status`, `durationMs`, `errorType`).
- `FillCampaign::handle(MarketingCampaign, CarbonInterface $now, ?string $date = null): list<string>` returns the
  dates queued. `::openDates()` returns the window's open dates; the page uses it for N.
- `DraftOccurrence::handle(MarketingCampaign, CarbonImmutable $at, bool $replace = false): Collection<MarketingPost>`.
- `RegenerateOccurrence::handle(MarketingCampaign, CarbonImmutable $at)`.
- `ReviseDraft::handle(MarketingPost, string $feedback, array $chips = []): MarketingPost`.
- `LintCaption::handle(string $caption, array $copyRules): list<array{code, match}>`.
- `BuildTasteExamples::handle(MarketingCampaign): array{liked, disliked, notes}`.
- `MarketingDraftRefusedException` carries a `reason`: `not_configured`, `paused`, `not_open`, `not_draft`,
  `empty_feedback` or `unknown_chip`. The page toasts its message.
- Page: `CampaignPage::fillDate(string $date)`, `::fillOpen()`. Test hooks: `data-fill="<Y-m-d>"`,
  `data-fill-open`, `data-fill-failed`, `data-ai-missing`, `data-polling`.
- Test helpers in `tests/Pest.php`: `fakeClaude(?array $payload)` (sets a test key and fakes
  `api.anthropic.com/*`) and `claudeReply($payload, $stopReason)`.

## Configuration
- `config/services.php` → `anthropic.key` (`ANTHROPIC_API_KEY`) and `anthropic.model` (`MARKETING_AI_MODEL`,
  default `claude-sonnet-5-5`). Both are documented in `.env.example`. With no key, Fill is disabled. Its tooltip
  and a line under Future posts both read "Add ANTHROPIC_API_KEY to .env".
- Prices are the `MarketingAi::PRICES` constant, in micro-USD per token: input, output, cache read and cache write.
  A model missing from that table records a null cost.
- The jobs need a queue worker (`composer run dev`), and the drafting status needs a cache shared with the worker
  (`CACHE_STORE=database`).

## Observability
All events carry `campaign_id`. Cost is logged as `cost_cents`, a float rounded to 4 dp.
- `marketing.ai.fill.queued` (info): `dates`, `count`. `marketing.ai.fill.refused` (info): `reason`, `date`.
- `marketing.ai.draft.started` (info): `date`, `model`, `topics`, `skipped_ideas`, `replace`.
- `marketing.ai.draft.succeeded` (info): `post_ids`, `replaced`, `model`, `input_tokens`, `output_tokens`,
  `cost_cents`, `duration_ms`.
- `marketing.ai.draft.failed` (error): `reason`, `status`, `error_type`, `duration_ms`. It never carries the key or
  the prompt. From the job's catch-all it carries `reason: exception` and the message.
- `marketing.ai.draft.refused` (info): `reason`, `date`.
- `marketing.ai.revise.succeeded` (info): `post_id`, `version`, `chips`, then tokens, cost and duration.
  `marketing.ai.revise.failed` (error) and `marketing.ai.revise.refused` (info).
- `marketing.ai.invalid_asset` (warning): `asset_id`, `photo_mode` or `post_id`.
- `marketing.lint.warned` (debug): `post_id`, `codes`.
- Trace: the job restores the Fill request's `request_id`. A healthy fill reads `fill.queued`, then one
  `draft.started` → `draft.succeeded` pair per date. A date stuck on "Drafting…" with no `draft.started` means no
  worker is running. It reverts to open after 10 minutes.

## Testing & verification
- `tests/Feature/Marketing/AiFillAndReviseTest.php`: one `it()` per acceptance criterion, all against
  `Http::fake`. Extra cases cover: refusals (not open, paused, not a draft, empty feedback, unknown chip); short and
  refused replies; topic rotation; taste taken campaign-first; Regenerate, with and without a failure; lint saved
  and logged; `no_price_source`; "Different photo"; and the job's catch-all.
- `tests/Unit/Marketing/LintCaptionArchTest.php`: the lint stays pure.
- `tests/Browser/Journeys/MarketingPostTest.php` step 4: Fill the open TrueView Tuesday date 2026-11-03 with fake
  Claude. It ends with 3 drafts, "Needs a photo" (per-post mode) and `generation_meta.model` recorded. Only
  `RefreshProjectJob` is faked on the queue, so the draft job runs inline.
- **Owed by the owner (not done):** the real-key browser check. Set `ANTHROPIC_API_KEY`, Fill one coins date, read
  the 3 captions against the copy rules, and note `cost_cents` in `marketing.ai.draft.succeeded`.

## Key decisions & tradeoffs
- Drafting/failed state is in the cache with TTLs, not a table. The page polls only while a date is drafting →
  [ADR-071](../decisions/ADR-071-marketing-drafting-state-lives-in-the-cache.md).
- One job per date, tried once, unique per campaign date. The owner's Retry is the retry →
  [ADR-072](../decisions/ADR-072-marketing-draft-job-runs-once-and-is-unique-per-date.md).
- Drafting is all-or-nothing, and Regenerate discards only on success. Cost is integer micro-USD →
  [ADR-073](../decisions/ADR-073-marketing-drafting-is-all-or-nothing-and-cost-is-micro-usd.md).
- `MarketingPrompts` is a service. The call uses a structured-JSON schema, a cached system part, a 90 s timeout and
  the answering model's price →
  [ADR-074](../decisions/ADR-074-marketing-prompts-service-and-claude-call-shape.md).

## Known limitations & gotchas
- **No UI for Revise, Regenerate, likes or dislikes** until MK-9. Until then `marketing_feedback` fills only
  through `ReviseDraft`, so taste has notes but no votes.
- Taste examples quote a post's *current* caption. A liked post that was later revised is quoted as revised.
- The system prompt may be under the model's cache minimum. It then goes uncached without any error, which is
  harmless.
- Cost is priced by the reply's `model` string, matched to the longest `PRICES` alias it starts with, so a dated
  snapshot (`claude-sonnet-5-5-20261001`) is priced as its alias. A model in no listed family (e.g. an unlisted
  fallback) still records `cost_micro_usd: null` — check `model` in `marketing.ai.draft.succeeded`.
- "Fill N open dates" covers the next 14 days only. Later open dates have row buttons.
- If no worker runs, a date shows "Drafting…" for up to 10 minutes and then reads as open. The queued job still runs
  once a worker starts. A Retry made before then is refused as `not_open` if the first job has already drafted.
- A failure shows on an open row for 24 hours, or until a Retry succeeds.
- `no_price_source` is a heuristic and can miss a comparison or flag a false one. Ideas with `[placeholders]` are
  never drafted until a data source exists (phase 3).
- A campaign with no topics drafts from its brief alone.

## Change history
2026-10-12 — Fill (per date and "Fill N open dates"), DraftOccurrence, Revise, Regenerate, taste, lint, cost in generation_meta (MK-8, `e694a73`)
