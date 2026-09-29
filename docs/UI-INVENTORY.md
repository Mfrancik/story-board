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
| Home | Livewire (Board) | SB-3 home: what needs me, Not on main / Built / Parked drafts, project cards; URL filters; embeds StoryModal; pinned to one project on `/p/{project}` (SB-7) | `/`, `/p/{project}` |
| board/section | Blade | Boxed list with heading, count, hint; `collapsible` for closed-by-default sections | Home |
| board/story-row | Blade | One story row; a button dispatching `board-story` to open StoryModal (plain row if the ID is not well formed); shows where an off-main version lives | Home |
| board/project-card | Blade | One project's counts by raw status, parse-error warning, snapshot state | Home |
| board/status-chip | Blade | A story's raw status with parse-error marker; danger tone for out-of-vocabulary values | Home, story-row |
| layouts/board | Blade layout | The board's shell: board/sidebar beside the page, no auth, Flux appearance for light/dark | Home, StoryPage |
| board/sidebar | Blade (class) | Project switcher: filter box (⌘K), All projects, enabled projects with state dot and count; drawer below 768px; Manage slot | layouts/board |
| StoryPage | Livewire (Board) | Story above its mockups (side-by-side / one-at-a-time, 375/768/1280), full-screen compare; `?v=` versions banner for off-main copies | `/p/{project}/s/{id}` |
| StoryModal | Livewire (Board) | Design-A story modal at `?story=<project>/<ID>`: text, details, dependency chips, mockup thumbnails, versions off main; opened by the `board-story` event | Home (`/`, `/p/{project}`) |
