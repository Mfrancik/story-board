# ADR-069 — A campaign's URL is a slug looked up within its project, fixed at creation

Date: 2026-10-12 · Status: accepted · Story: MK-7

## Context

The story names the route `/p/{project}/marketing/campaigns/{campaign:slug}`. Slugs are unique only per project
(`unique(project_id, slug)`). Laravel's implicit `{campaign:slug}` binding would not scope the lookup to
`{project:name}`, so two projects with a `trueview-tuesday` campaign could resolve to the wrong one. The create page
also needs an address under the same prefix.

## Decision

- The route parameter is a plain `{campaign}` string. `CampaignPage::mount()` resolves it through
  `$project->marketingCampaigns()->where('slug', …)`. A slug this project does not have is a 404 and logs
  `marketing.campaign.not_found`.
- `/campaigns/new` is registered before `/campaigns/{campaign}`, and `SaveCampaign::RESERVED_SLUGS` holds `new`, so
  no campaign can take that address. A campaign named "New" becomes `new-2`.
- The slug is made once at creation from the name (`Str::slug`, falling back to `campaign`). A collision gets `-2`,
  `-3` and so on. The slug is **kept through renames**, so links do not break.
- The name stays unique per project through validation, which is case-insensitive through the database collation.
  Two different names can still produce the same slug ("TrueView Tuesday!"), which is why the collision suffix exists.

Alternatives rejected:
- **Implicit `{campaign:slug}` binding, as the story wrote it.** It is not scoped to the project, so a slug used in
  two projects is ambiguous, and the 404 path would have no log line.
- **Re-slug on rename.** It breaks every saved link, and the URL would change while the owner is on the page.
- **Numeric ids in the URL.** They are not readable, and the prototype and journey test use slugs.

## Consequences

- After a rename, the URL can read differently from the name.
- A future story that deletes campaigns frees their slug for reuse.
