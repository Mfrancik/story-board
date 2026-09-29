# Feature Documentation Index
One line per feature. /document maintains this file.

| Feature | Summary | Status | Last updated | Doc |
|---|---|---|---|---|
| Project registry and story reader | Registers local project checkouts and keeps a read-only, git-sourced snapshot of each project's stories at its ref. | active | 2026-09-29 | [doc](features/project-registry-and-reader.md) |
| Not on main | Indexes stories and mockups on unmerged branches, in worktrees and untracked, and shows them labelled by location on the home page and story page. | active | 2026-09-29 | [doc](features/not-on-main.md) |
| Story page and mockups | `/p/{project}/s/{id}`: one story's text from the ref above its mockup options in sandboxed frames, with a full-screen compare of any two. | active | 2026-09-29 | [doc](features/story-page-and-mockups.md) |
| All-projects dashboard | The `/` page: What needs me cards (picks, drafts, ready to build), In flight figures, a tile per project, and the Not on main, Built and Parked sections. | active | 2026-09-29 | [doc](features/what-needs-me-home.md) |
| App shell and project switcher | The sidebar on every board page: filterable enabled-project list with state and counts, a drawer below 768px, and the `/p/{project}` route with its refusal and `/?project=` redirect. | active | 2026-09-29 | [doc](features/app-shell-and-project-switcher.md) |
| Story modal | Clicking a story row opens a design-A modal at `?story=<project>/<ID>` with its text, details, dependencies, mockups and off-main versions; Back closes it. | active | 2026-09-29 | [doc](features/story-modal.md) |
| Single-project dashboard | `/p/{project}`: one project's header and refresh, its What needs me cards, Progress by initiative and Not on main counted by branch, worktree and untracked. | active | 2026-09-29 | [doc](features/single-project-dashboard.md) |
| Manage projects | `/projects`: every project with an on/off switch, path, ref, state and count; add a project by folder; remove one behind a confirmation modal. | active | 2026-09-29 | [doc](features/manage-projects.md) |
| Live sessions | The Live now panel on `/` and `/p/{project}` plus a sidebar badge: active Claude Code sessions by project, checkout and branch, with the story each builds. | active | 2026-09-29 | [doc](features/live-sessions.md) |
| Project handbook | `/p/{project}/handbook`: a project's rules, lessons, standards, runbook, decisions and skills from git, with standards and skills badged against the dev-standards kit. | active | 2026-09-29 | [doc](features/project-handbook.md) |
