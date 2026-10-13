# Marketing: post studio
Status: active   ·   Last updated: 2026-10-12   ·   Stories: MK-9

## Overview
The post studio is one large modal where the owner handles a campaign date from start to finish. They see its three
drafts big in the brand's look and flip between them. They can tell the AI what to change, like or dislike a draft,
edit the caption, give the post a photo and approve it, then move on to the next date. For an approved post the
studio also has Copy caption, Download image, Reschedule, Unapprove and Mark as posted. It replaces the planned
Review page, so the owner can approve several weeks ahead in one sitting from wherever they see a post.

## How it works
**One component, many doors.** `app/Livewire/Marketing/PostStudio.php` is a child Livewire component. It is
mounted on the campaigns list (`campaign-list.blade.php`), the campaign page (`campaign-page.blade.php`, only
once a campaign exists), and since MK-10 the calendar and library. It starts closed and renders `<div data-studio-closed>` until one of two events arrives:
- `open-studio` → `open(?campaign, ?date, ?post)`:
  - **campaign + date**: a timeline row (`components/marketing/occurrence-row.blade.php`, a full-row button under
    the row's content), the list's "Missed" badge, or its "N open dates" badge (the first open date).
  - **campaign only**: the "N to approve" and "Needs a photo" badges. These open the campaign's first date to
    approve.
  - **nothing**: "Start approving" in the alerts banner. This opens the first date to approve across every enabled
    campaign.
  - **post**: a post id. A campaign post is opened on its date. A one-off is shown on its own.
- `start-one-off` → `startOneOff()`: "+ One-off post" on the campaigns list. The form defaults to noon three days
  out, in the owner's timezone.

Every write dispatches `marketing-changed`. The host page has an empty `#[On('marketing-changed')]
refreshFromStudio()`, so the request re-renders it underneath
([ADR-075](../decisions/ADR-075-marketing-studio-is-one-child-component-opened-by-events.md)).

**The queue.** "1 of N to approve" and "Approve & next (N left)" come from
`app/Actions/Marketing/ListDatesToApprove.php`. That Action reads each campaign's
`BuildCampaignRunway::datesToApprove()`, which shares its private `toApprove()` rule with the runway's `to_approve`
count, so the studio and the campaigns list cannot disagree. When the studio was opened with nothing ("Start
approving"), `queueAll` is true and the queue covers every enabled campaign. Otherwise it covers the shown campaign
only. The queue is sorted by time, then campaign id
([ADR-076](../decisions/ADR-076-marketing-to-approve-is-one-rule-for-list-and-studio.md)).

**What a date shows.** `render()` loads the date's kept posts through
`app/Actions/Marketing/FindOccurrencePosts.php` (local day, half-open UTC range, not discarded). It takes the
date's state from `BuildCampaignRunway::state()` and promotes `approved` to `missed` with `MarketingPost::isMissed()`.
- `review`: the drafts, flipped in Alpine (`cur`: ← / →, the dots, the arrow keys while not typing).
- `approved` / `missed`: the approved post, with Copy, Download (only when it has a photo), Reschedule, and either
  Unapprove + Mark as posted or Post it now.
- `posted`: posted time and the saved Facebook link.
- `open`: Skip this date, and Fill this date (MK-8's `FillCampaign`, polled while drafting).
- `skipped`: Restore this date.
- `one-off`: the create form.

**The preview.** `resources/views/components/marketing/post-preview.blade.php` draws the post as a square card in
the brand's font, colours and radius with its logo. A post with a photo shows the caption's first line over the
photo. A post with no photo gets the brand text card. A post held by `needsPhoto()` shows "Needs a photo" with a
slot for Upload / Use a favourite. Type scales with the card (`.post-card` in `resources/css/app.css`). The brand
values go only into CSS custom properties, and the sanitised SVG logo is the one unescaped value. MK-10's library
cards reuse it.

**Each action is a thin call into an Action.** The studio validates input, then calls an Action. A refused Action
throws `MarketingPostRefusedException` and is toasted (or shown under the field for reschedule, the post link and
the one-off form). Every action takes the post id from the client and checks it with `shownPost()`. An id that is
not one of the posts on the date being shown (another date's, another project's, a discarded one) is logged as
`marketing.studio.refused` and returns a 404.

| Studio action | Action | What it does |
|---|---|---|
| Rebuild ("What should change?" + chips) | `ReviseDraft` (MK-8) | New version in place; the card shows "v2 · Undo · Compare". An unchanged result is undone and explained (below) |
| Undo | `UndoRevision` | Restores the latest kept version (caption, photo, meta), deletes that version row, re-lints |
| Compare | Alpine only | Before / Now captions side by side |
| 👍 / 👎 + reasons | `RecordFeedback` | Replaces the post's one vote row; reasons are `REASONS` (dislikes only) |
| Caption box (on blur) | `EditPostCaption` | Saves and re-lints; version unchanged; warn-only lint inline |
| Upload a photo | `StoreMarketingAsset` (`favourite: false`) + `SetPostPhoto` | JPG/PNG/WebP, ≤ 10 MB |
| Use a favourite / Text only | `SetPostPhoto` | Sets `asset_id`, or clears it and sets `is_text_only` |
| Approve / Approve & next | `ApprovePost` | Approves one draft and discards the date's other drafts in one transaction |
| Skip date / Restore | `SkipOccurrence` / `RestoreOccurrence` | See "Skip and Restore" below |
| Regenerate all 3 | `RegenerateOccurrence` (MK-8) | A failure keeps the old drafts |
| Fill this date | `FillCampaign` (MK-8) | Queues the date's draft job |
| Reschedule | `ReschedulePost` | Approved posts only, forward only; the studio follows the post to its new date |
| Unapprove (confirm-modal) | `UnapprovePost` | Back to draft; the discarded siblings stay discarded |
| Mark as posted / Post it now | `MarkPostPosted` | `posted` + `posted_at`; an optional link → `facebook` network row |
| + One-off post | `CreateOneOffPost` | A `manual` draft with no campaign, at a future time |

**A rebuild that changes nothing.** `revise()` compares the trimmed caption and `asset_id` before and after
`ReviseDraft`. If both match, it undoes the new version with `UndoRevision`, keeps the owner's note as taste
feedback, and returns false so the box keeps the text and the studio stays open. It then sets the locked
`$unchanged` (`post_id`, `reason`, `message`), shown as a warning under that draft's box
(`data-revise-unchanged`). `app/Actions/Marketing/ExplainUnchangedRevision.php` picks the reason from the note, the
chips, the campaign's `photo_mode` and whether the project has favourites, in this order: `same_caption` (no photo
asked for), `text_only`, `no_favourites` (adds an "Open Settings" link), `per_post`, `photo_chip` (asked in words,
chip not chosen), and `kept_photo`. Any later change (`changed()`), `open()`, `close()` and Approve & next clear it
([ADR-080](../decisions/ADR-080-marketing-an-unchanged-rebuild-is-undone-and-explained.md)).

**The photo hold.** `MarketingPost::needsPhoto()` is true when the campaign's mode is `per_post`, the post has no
`asset_id`, and `is_text_only` is false. While it holds, Approve and Approve & next are disabled with "Needs a photo",
and `ApprovePost` refuses as well (`needs_photo`), so the hold does not depend on the button
([ADR-078](../decisions/ADR-078-marketing-text-only-is-a-stored-choice.md)).

**Skip and Restore.** A date's state is read from its posts. So Skip on a date with drafts marks them `skipped`. Skip
on an open date writes one `skipped` placeholder post with an empty caption at the campaign's time. Restore turns
skipped drafts back into drafts and discards the placeholder, which it recognises by its empty caption
([ADR-077](../decisions/ADR-077-marketing-skip-writes-a-placeholder-and-restore-undoes-it.md)).

**Votes and versions.** Each post has at most one vote. A new vote deletes the old vote row and adds the new one, and
0 clears it. Revise notes (`vote` null) are never touched. Undo removes the version row it restores from, but the
feedback given on that revision stays. Hand edits do not create a version
([ADR-079](../decisions/ADR-079-marketing-one-vote-per-post-and-what-makes-a-version.md)).

**Uploads.** `updatedUploads()` validates against `MarketingAsset::PHOTO_MIMES` and `Settings::PHOTO_MAX_KB`. It
always unsets the slot afterwards, because a rejected file left in the array would fail the next pick too.
`_uploadErrored()` is aliased in the same way as in Settings (MK-2), so Livewire's own 12 MB temporary limit reads
"Photos can be 10 MB at most." (see RUNBOOK, MK-2).

**Layout.** At 768 px and above the studio is a framed dialog, with the preview on the left and the controls on the
right (stacked below 1024 px). Below 768 px it is a full-screen sheet. The header and footer stay in place while
the body scrolls, so Approve is always on screen. Esc and the backdrop close it, except while the Unapprove confirm
is open.

## Data model
- `marketing_post_networks` (migration `2026_10_12_000009`, `app/Models/MarketingPostNetwork.php`): `post_id` (FK
  cascade), `network` (`facebook`), `status` (`posted`), `external_id`, `url` (2048), `engagement` (json), `synced_at`,
  timestamps. Unique `(post_id, network)`. Phase 1 writes only `network`, `status` and `url`; the other columns are
  for phase 2's engagement backfill. Read through `MarketingPost::networks()`.
- `marketing_posts.is_text_only` (migration `2026_10_12_000010`, boolean, default false, after `asset_id`). This is
  an owner-approved addition that is not in the story.
- Written but not new: `marketing_posts.status` / `asset_id` / `caption` / `lint` / `scheduled_for` / `posted_at`,
  `marketing_post_versions` (Undo deletes a row), and `marketing_feedback` (vote rows replaced).
- Uploaded post photos are `marketing_assets` rows of kind `photo` that are not favourites.

## Interfaces
- Events: `open-studio` `{campaign?, date?, post?}`, `start-one-off`, and the outgoing `marketing-changed`.
- `PostStudio` public methods, each taking a post `$id` that must be on screen: `revise($id, $feedback, $chips)
  : bool`, `undo`, `vote($id, $vote, $reasons)`, `useFavourite($id, $asset)`, `textOnly`, `approve($id, $next)`,
  `unapprove`, `reschedule($id, $at)` (`Y-m-d\TH:i` in the campaign's timezone), `markPosted($id, $link)`. Methods
  that act on the date: `skip`, `restore`, `regenerate`, `fillDate`, `createOneOff`, `close`.
- Actions (all in `app/Actions/Marketing/`): `ApprovePost::handle($post)`, `UnapprovePost::handle($post)`,
  `ReschedulePost::handle($post, $at, $now)`, `MarkPostPosted::handle($post, ?$url, $now)`,
  `RecordFeedback::handle($post, $vote, $reasons)`, `EditPostCaption::handle($post, $caption)`,
  `SetPostPhoto::handle($post, ?$asset)`, `UndoRevision::handle($post)`, `SkipOccurrence::handle($campaign, $date)`,
  `RestoreOccurrence::handle($campaign, $date)`, `CreateOneOffPost::handle($project, $at, $caption, $now)`,
  `FindOccurrencePosts::handle($campaign, $date)` / `::at()`, `ListDatesToApprove::handle($project, ?$campaign, $now)`,
  `BuildCampaignRunway::datesToApprove($campaign, $now)`, `ExplainUnchangedRevision::handle($post, $feedback, $chips)`
  → `{reason, message}` (reads only).
- Refusals: `app/Exceptions/MarketingPostRefusedException.php` has a `reason` (`not_draft`, `needs_photo`,
  `not_approved`, `past`, `decided`, `not_skipped`, `unknown_reason`, `bad_vote`, `empty_caption`, `not_editable`,
  `no_version`). Every Action refuses through the `Concerns/RefusesPostActions` trait, so every guard has a log line.
- Component `<x-marketing.post-preview :post :brand :project :label>`.
- Test hooks: `data-studio`, `data-studio-state`, `data-studio-counter`, `data-studio-when`, `data-draft`,
  `data-version`, `data-rebuild`, `data-approve`, `data-approve-next`, `data-approve-held`, `data-use-favourite`,
  `data-favourite`, `data-open-studio`, `data-start-approving`, `data-one-off`, and more in
  `post-studio.blade.php`.

## Configuration
None of its own. Revise, Regenerate and Fill need `ANTHROPIC_API_KEY` (see
[marketing-ai-fill-and-revise.md](marketing-ai-fill-and-revise.md)). Without it those buttons are disabled with the
same tooltip. One-off times use `config('board.timezone')`.

## Observability
Post events carry `post_id`, `campaign_id` and `version`, per the story.
- `marketing.post.approved` (info, + `discarded` ids), `.unapproved`, `.rescheduled` (`from`, `to`), `.posted`
  (`has_link`), `.caption_edited` (`lint` codes), `.photo_set` (`asset_id`, `text_only`), `.revision_undone`,
  `.one_off_created` (`project`).
- Date events: `marketing.post.skipped` (`campaign_id`, `date`, `post_ids`) and `marketing.post.restored`.
- `marketing.feedback.recorded` (info): `vote`, `reasons`.
- `marketing.post.refused` (info): `action`, `reason`, `post_id`, `campaign_id`, and sometimes `date` / `to` /
  `project`. This is the line to look for when a button "did nothing" but toasted.
- `marketing.studio.refused` (warning): `reason` (`unknown_post`, `unknown_campaign`, `bad_date`, `not_shown`) with
  the id. Each one is a 404, usually a stale tab or a forged id.
- `marketing.studio.opened` (debug). `marketing.runway.failed` (warning, `source: studio`) when a campaign's
  timezone cannot be read. The studio then shows the error instead of the date.
- The AI calls log MK-8's `marketing.ai.revise.*` / `marketing.ai.draft.*`.
- `marketing.ai.revise.unchanged` (info): `project`, `campaign_id`, `post_id`, `reason` (the `ExplainUnchangedRevision`
  constants). It follows `marketing.ai.revise.succeeded` and the `marketing.post.revision_undone` of the rollback, so
  `revision_undone` does not always mean the owner pressed Undo.
- A healthy Approve & next reads `studio.opened` → `post.approved` with its `discarded` ids → the next date's render.
  There is no second `opened`, because the studio moves in place.

## Testing & verification
- `tests/Feature/Marketing/PostStudioTest.php`: one `it()` per acceptance criterion (named as in the story), plus
  refusal datasets per action and wrong state, open refusals, vote replacement, skip/restore including the
  placeholder, one-offs, missed posts, and the entry points from both host pages. Claude is faked with
  `fakeClaude()`.
- `tests/Browser/Marketing/PostStudioTest.php`: flipping (arrows, dots, keyboard) and Escape; Rebuild → v2 →
  Compare (no round trip) → Undo; the 375 px sheet with no sideways scroll and Approve visible; the studio staying
  open after a Rebuild when the host's shape changes mid-session (idea menu seeded on the campaign page, a campaign
  paused on the list); an unchanged rebuild showing its warning with the note kept.
- The feature tests also cover the unchanged rebuild: no version kept and the log line, one dataset row per
  `ExplainUnchangedRevision` reason, and the warning clearing on the next real change.
- `tests/Browser/Journeys/MarketingPostTest.php` step 5: open Nov 3 on TrueView Tuesday, flip to draft 2, Rebuild,
  check that Approve & next is held, use a favourite, then Approve & next lands on Oct 27 with the counter down. The
  photo is a favourite, not an upload, because Pest's browser server drops uploaded files (RUNBOOK, MK-2). The upload
  path is covered by the feature tests.
- **Owed by the owner (not done):** the real-key browser check on coins (Fill, open the studio, flip, revise,
  approve two dates, Copy caption) and one real photo upload in a browser.

## Key decisions & tradeoffs
- One child component on both pages, opened by events, rather than a route or per-page copies →
  [ADR-075](../decisions/ADR-075-marketing-studio-is-one-child-component-opened-by-events.md).
- "To approve" is one rule in `BuildCampaignRunway`. Start approving queues every enabled campaign, and a badge or
  row queues its own →
  [ADR-076](../decisions/ADR-076-marketing-to-approve-is-one-rule-for-list-and-studio.md).
- Skip on an open date writes a placeholder post, and Restore (an owner-approved extra) discards it →
  [ADR-077](../decisions/ADR-077-marketing-skip-writes-a-placeholder-and-restore-undoes-it.md).
- "Text only" is a stored flag (`is_text_only`), approved by the owner beyond the story →
  [ADR-078](../decisions/ADR-078-marketing-text-only-is-a-stored-choice.md).
- One vote per post, Undo deletes the kept version, and hand edits keep the version →
  [ADR-079](../decisions/ADR-079-marketing-one-vote-per-post-and-what-makes-a-version.md).
- A rebuild that comes back the same is undone in the studio and explained, rather than changing MK-8's
  `ReviseDraft` (Do-NOT-touch) or keeping a no-op version →
  [ADR-080](../decisions/ADR-080-marketing-an-unchanged-rebuild-is-undone-and-explained.md).
- Both hosts mount the studio with an explicit `wire:key="post-studio"`. Livewire's auto key shifts when the host's
  loops change shape, which remounted the studio closed (RUNBOOK, 2026-10-12).
- One-off posts are hand-written (`source` manual), as the story says. The prototype had AI draft three, but MK-8's
  drafting is campaign-only and MK-9 does not change it.
- Dislike reasons follow the story (too wordy, wrong tone, boring, off-brand), not the prototype's list.
- Fill on an open date inside the studio is an owner-kept extra, not in the story.

## Known limitations & gotchas
- **"Save to Inspiration" is not drawn** until MK-18 ships its action.
- **Download is hidden when the post has no photo** (text-only, or a manual post with none). Rendering the text card
  to an image is phase 3.
- **One-offs are reachable only from the calendar and library** (MK-10), which dispatch `open-studio` with a `post`.
  They have no campaign, so they never enter the "to approve" queue or the campaigns list's counts.
- Taste examples (MK-8's `BuildTasteExamples`) quote a post's *current* caption. A draft liked and then rebuilt is
  quoted as rebuilt, which is now reachable through the studio.
- Unapprove does not bring back the drafts discarded on approve. Regenerate makes new ones.
- Reschedule is for approved posts only. A draft cannot be moved off its date.
- The placeholder for a skipped open date is identified by its empty caption. Captions are otherwise never empty
  (`EditPostCaption` and `CreateOneOffPost` refuse it).
- Livewire uploads are not browser-tested (Pest limitation). A real upload in a browser is still owed.
- An unchanged rebuild still made, and paid for, one Claude call. Only the version is rolled back.
- The photo-request check is an English word match (photo, image, picture, pic). A request worded otherwise is
  explained as `same_caption`.
- On a one-off post (no campaign, so `photo_mode` is null) the `per_post` message still says "for this campaign".
- Do not drop the `wire:key` on either `<livewire:marketing.post-studio>` tag; the studio would close after writes
  again on some page shapes.

## Change history
2026-10-12 — Post studio: flip, revise/undo/compare, votes, caption, photo, approve & next, skip/restore, regenerate,
approved-post actions, mark posted with link, one-off posts; `marketing_post_networks`, `is_text_only` (MK-9, `9d71022`)
2026-10-12 — Skip date is hidden on one-off posts, where it 404'd for want of a campaign (MK-9, `ec8603c`)
2026-10-12 — Studio stays open after a change (explicit `wire:key` on both hosts); a rebuild that changes nothing is
undone and explained under the box (`ExplainUnchangedRevision`, ADR-080) (MK-9, `d415a49`)
2026-10-12 — Mounted on the calendar and library, whose cards give one-off posts a way back in (MK-10, `80be063`)
