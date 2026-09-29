# SB-6 — The board on an always-live domain (PARKED)
Status: draft          Journey: none
Source: owner 2026-09-29 (coins /story): *"or maybe some centralized domain we host thats always live
for all of my projects"*. Owner ruling: localhost first, hosted later.

> **Parked.** Written so the idea isn't lost; not in the build queue. Unpark it only after the
> blocker below is resolved and the owner has settled the open questions.

## Blocker
A hosted board can only see what reaches GitHub. On 2026-09-29, 67 mockup directories in coins' primary
checkout had never been committed, and more sat in sibling checkouts. Hosting before mockups are
reliably committed gives a board that looks complete but isn't. That is worse than no board.
Resolution is probably a kit rule plus a preflight check ("a story with a mockup gate has its mockup
directory committed"). That would be its own SB story in the kit.

## Story
As the owner, I want the board reachable at a private domain from any device, so that I can check
what needs me without being at my Mac.

## In scope (when unparked)
- A second `GitReader` source that reads from the GitHub API (fine-grained token, read-only on the
  registered repos) instead of local paths.
- Auth: owner-only login (Fortify, 2FA required). Everything behind it, mockup bytes included.
- Scheduled refresh plus a GitHub push webhook. Deploy target: Forge, like coins.
- SB-5 degrades cleanly: branches are visible through the API; untracked and worktree items are not,
  and the page says so.

## Out of scope
- Write actions (still read-only).
- Other users or sharing.

## Open questions for the owner (settle before unparking)
- Domain.
- Same Forge server as coins, or separate?
- Does "all my projects" include repos not yet on the kit?

## Acceptance criteria (draft — finalise on unpark)
- Given an unauthenticated request to any board or mockup URL, then it redirects to login.
- Given a push to a registered repo, then the board reflects it within one minute.
- Given the GitHub token is revoked, then refresh fails loudly (`board.github_auth_failed`) and the
  last snapshot stays, marked stale.

## Applicable standards
- Design: none new. Codebase/DB: token in `.env` only. Logging: `board.github_auth_failed`,
  `board.webhook_received`.

## Design mockup gate
n/a. Reuses SB-3/SB-4 plus the stock Fortify login.

## Do NOT touch
- Registered repos (the token is read-only).

## Data & interfaces
- `projects.source` enum `local|github`, `projects.github_repo`.

## Test plan
- Pest with a faked GitHub HTTP client; no live calls in tests.

## Definition of done
- [ ] Unparked by owner; open questions settled; acceptance criteria finalised

## Links
Journey: none · Depends on: SB-2..SB-5, plus the mockup-commit kit rule (unwritten)
