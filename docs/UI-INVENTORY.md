# UI Inventory
Every reusable component. Consult BEFORE building any new UI (design-standards
§Reuse before build). Updated in the same story that creates/changes a component.

| Component | Type (Blade/Livewire/Filament) | Purpose | Used in |
|---|---|---|---|
| Logout | Livewire action | Starter kit: logs the user out (Fortify auth, unused by the board) | layouts/app sidebar user menu |
| Appearance | Livewire | Starter kit: light/dark/system appearance setting | settings/appearance |
| DeleteUserForm | Livewire | Starter kit: delete-account form with password confirm | settings/profile |
| Profile | Livewire | Starter kit: name/email profile form | settings/profile |
| Security | Livewire | Starter kit: password, passkeys and 2FA settings | settings/security |
| RecoveryCodes | Livewire | Starter kit: shows/regenerates 2FA recovery codes | settings/security |
| app-logo-icon | Blade | Starter kit: logo mark SVG | app-logo |
| app-logo | Blade | Starter kit: logo mark plus app name | layouts/app sidebar, auth layouts |
| auth-header | Blade | Starter kit: title + description heading on auth screens | auth pages |
| auth-session-status | Blade | Starter kit: flash status line on auth screens | auth pages |
| desktop-user-menu | Blade | Starter kit: user dropdown in the sidebar | layouts/app sidebar |
| passkey-registration | Blade | Starter kit: register-a-passkey control | settings/security |
| passkey-verify | Blade | Starter kit: sign in with a passkey | auth login |
| placeholder-pattern | Blade | Starter kit: striped SVG placeholder | dashboard |
| settings/layout | Blade | Starter kit: settings page shell with sub-nav | settings pages |
| Home | Livewire (Board) | The all-projects dashboard (SB-9): What needs me cards, In flight, project tiles, collapsible sections; embeds StoryModal | `/` |
| board/section | Blade | Boxed list with heading, count, hint; `collapsible` for closed-by-default sections | Home (collapsible sections), ProjectPage (open Not on main kinds) |
| board/story-row | Blade | One story row opening StoryModal via `board-story`; `variant="card"` is the compact What needs me card row; shows where an off-main version lives | Home, ProjectPage, needs-me-cards |
| board/project-card | Blade | The dashboard tile, a link to `/p/{project}`: state, story total, status bar, count chips, not-on-main, refresh age, parse errors | Home |
| board/status-chip | Blade | A story's raw status with parse-error marker; danger tone for out-of-vocabulary values; `count` prop makes a tally chip | Home, story-row, project-card |
| board/status-bar | Blade | Stacked bar of counts by raw status; out-of-vocabulary values get a danger-toned segment | project-card, ProjectPage initiative rows |
| layouts/board | Blade layout | The board's shell: board/sidebar beside the page, persisted Flux toasts, no auth, Flux appearance for light/dark | Home, ProjectPage, StoryPage, ManageProjects, ProductionDashboard |
| board/sidebar | Blade (class) | Project switcher: filter box (⌘K), All projects, Production (connected/shown), enabled projects with state dot, count and live badge; drawer below 768px; Manage slot | layouts/board |
| StoryPage | Livewire (Board) | Story above its mockups (side-by-side / one-at-a-time, 375/768/1280), full-screen compare; `?v=` versions banner for off-main copies | `/p/{project}/s/{id}` |
| StoryModal | Livewire (Board) | Design-A story modal at `?story=<project>/<ID>`: text, details, dependency chips, mockup thumbnails, versions off main; opened by the `board-story` event | Home, ProjectPage |
| ProjectPage | Livewire (Board) | One project's dashboard (SB-10): header with Refresh this project, scoped What needs me cards, Progress by initiative, Not on main by kind; embeds StoryModal | `/p/{project}` |
| board/needs-me-cards | Blade | The three What needs me cards (pick, approval, build) with count, top rows and Show all calling the host's `showAll()` | Home, ProjectPage |
| board/state | Blade | A project's snapshot state: `part="dot"` coloured dot, `part="label"` word (none for `ok`, danger for unknown states) | sidebar, project-card, ProjectPage header |
| ManageProjects | Livewire (Board) | Manage projects (SB-12): every project with its switch, path, ref, state, count, refresh age, Production summary opening its panel; Add project form; Remove via confirm-modal | `/projects` |
| board/switch | Blade | On/off `role="switch"` button showing the stored state; the caller's `wire:click` writes it; disables while `target` runs | ManageProjects, ProductionSettings |
| board/confirm-modal | Blade | Alpine confirmation for a destructive action: `show` var, `title` slot, body, Cancel + red `confirm` button running `action` | ManageProjects (Remove), ProductionSettings (Remove connection) |
| ProjectHandbook | Livewire (Board) | A project's handbook (SB-14): section tabs over one reading card; each section loads on first open; kit badges; decisions in a modal | `/p/{project}/handbook` |
| board/project-tabs | Blade | Dashboard / Handbook page tabs under a project's header | ProjectPage, ProjectHandbook |
| board/kit-badge | Blade | How a file compares with the kit: Same as kit, Changed in this project, Missing, Project only; `dot` for a sub-tab | ProjectHandbook (standards, skills) |
| board/handbook-empty | Blade | Empty state naming the project and the missing file, with a one-line hint slot | ProjectHandbook sections |
| board/prose | Blade | Rendered markdown (from RenderStory::toHtml) in the reading width | ProjectHandbook sections, decision modal |
| LiveSessions | Livewire (Board) | Live now (SB-11): a card per active Claude Code session with checkout, branch, age and linked stories; `wire:poll.30s` on the panel only | Home, ProjectPage |
| ProductionSettings | Livewire (Board) | A project's Production panel (SB-17), lazy under its row: read-only connection form + inline check, read-only user one-liner, preset and custom metrics with Test | ManageProjects |
| board/metric-result | Blade | A production metric's last Test result inline: value + ms, timed out, or the refusal/error | ProductionSettings |
| ProductionDashboard | Livewire (Board) | Production (SB-18): projects × metrics table (cards below 768px), skeleton per row until its queued read is back, Refresh per row, Not connected list | `/prod` |
| board/prod-stat | Blade | One production metric: value, change vs yesterday and 7 days ago, trend, read time, greyed when last-good; `layout="cell"` or `"line"` | ProductionDashboard |
| board/prod-change | Blade | A signed change with ▲/▼ and gain/loss colour, "—" when no snapshot that day | prod-stat |
| board/prod-row-status | Blade | Under a project's name on /prod: Reading… / read time, and the failed read's badge and sentence | ProductionDashboard |
| board/sparkline | Blade | Inline-SVG 30-day trend line, gaps for missing days, end dot, Alpine hover readout; no chart library | prod-stat |
