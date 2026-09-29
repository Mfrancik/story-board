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
| layouts/board | Blade layout | The board's shell: board/sidebar beside the page, persisted Flux toasts, no auth, Flux appearance for light/dark | Home, ProjectPage, StoryPage, ManageProjects |
| board/sidebar | Blade (class) | Project switcher: filter box (⌘K), All projects, enabled projects with state dot and count; drawer below 768px; Manage slot | layouts/board |
| StoryPage | Livewire (Board) | Story above its mockups (side-by-side / one-at-a-time, 375/768/1280), full-screen compare; `?v=` versions banner for off-main copies | `/p/{project}/s/{id}` |
| StoryModal | Livewire (Board) | Design-A story modal at `?story=<project>/<ID>`: text, details, dependency chips, mockup thumbnails, versions off main; opened by the `board-story` event | Home, ProjectPage |
| ProjectPage | Livewire (Board) | One project's dashboard (SB-10): header with Refresh this project, scoped What needs me cards, Progress by initiative, Not on main by kind; embeds StoryModal | `/p/{project}` |
| board/needs-me-cards | Blade | The three What needs me cards (pick, approval, build) with count, top rows and Show all calling the host's `showAll()` | Home, ProjectPage |
| board/state | Blade | A project's snapshot state: `part="dot"` coloured dot, `part="label"` word (none for `ok`, danger for unknown states) | sidebar, project-card, ProjectPage header |
| ManageProjects | Livewire (Board) | Manage projects (SB-12): every project with its switch, path, ref, state, count, refresh age; inline Add project form; Remove via board/confirm-modal | `/projects` |
| board/switch | Blade | On/off `role="switch"` button showing the stored state; the caller's `wire:click` writes it; disables while `target` runs | ManageProjects |
| board/confirm-modal | Blade | Alpine confirmation for a destructive action: `show` var, `title` slot, body, Cancel + red `confirm` button running `action` | ManageProjects (Remove) |
