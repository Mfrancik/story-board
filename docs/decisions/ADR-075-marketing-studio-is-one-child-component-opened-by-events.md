# ADR-075 — The post studio is one child Livewire component, opened by events

Date: 2026-10-12 · Status: accepted · Story: MK-9

## Context

The studio is opened from several places: a campaign page's timeline row, the campaigns list's badges, "Start
approving", "+ One-off post", and later MK-10's calendar chips and library cards. Each of these must open the same
studio, with the same rules and the same queue. After a change, the page underneath must show the change.

## Decision

- `app/Livewire/Marketing/PostStudio.php` is a child component. Each Marketing page that needs it mounts it, which
  today means `CampaignList` and `CampaignPage`. It starts closed and renders a stub.
- The doors dispatch browser events: `open-studio` `{campaign?, date?, post?}` and `start-one-off`. The studio
  works out what to show from what it is given.
- After every write the studio dispatches `marketing-changed`. Each host has an empty `#[On('marketing-changed')]`
  listener, so the host re-renders in the same request cycle.
- What is shown (`campaignId`, `date`, `postId`, `queueAll`) is `#[Locked]`. Every post id the client sends is
  checked against the date on screen.

Alternatives rejected:
- **A route of its own (`/marketing/studio/...`).** It would leave the page the owner was on, lose the timeline
  context under the modal, and need a "back" path for each door.
- **A copy of the studio markup in each page.** The rules would drift between doors. That is the problem the single
  studio exists to solve.

## Consequences

- A new door (MK-10) needs only a `$dispatch('open-studio', …)` and, if the page is new, a `<livewire:marketing.post-studio>`
  mount plus the empty listener.
- Each host pays one extra component on every render, closed or not, which is cheap because it renders a stub.
- A host that forgets the `marketing-changed` listener shows stale data under the studio until it reloads.
