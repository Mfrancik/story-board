# UI Inventory
Every reusable component. Consult BEFORE building any new UI (design-standards
§Reuse before build). Updated in the same story that creates/changes a component.

| Component | Type (Blade/Livewire/Filament) | Purpose | Used in |
|---|---|---|---|
| Home | Livewire (Board) | The old all-projects dashboard (SB-9): What needs me cards, In flight, tiles, sections; embeds StoryModal; unrouted since RD-5, RD-9 deletes it | tests only (`/test/old-home`) |
| Overview | Livewire (Board) | Home overview (RD-5–7): `?projects=` filter, count sentence, figures, Needs me, Live now cards, Projects tiles, Shipped this week; `wire:poll.30s` | `/` |
| shell/figure | Blade | One headline figure: uppercase label, mono number ("—" when null), accent toned needs/live, one line below | Overview |
| board/section | Blade | Boxed list with heading, count, hint; `collapsible` for closed-by-default sections | Home (collapsible sections), ProjectPage (open Not on main kinds) |
| board/story-row | Blade | One story row opening StoryModal via `board-story`; `variant="card"` is the compact What needs me card row; shows where an off-main version lives | Home, ProjectPage, needs-me-cards |
| board/project-card | Blade | The dashboard tile, a link to `/p/{project}`: state, story total, status bar, count chips, not-on-main, refresh age, parse errors | Home |
| board/status-chip | Blade | A story's raw status with parse-error marker; danger tone for out-of-vocabulary values; `count` makes a tally chip; `variant="tag"` reads Built/To do/Draft/Cancelled, others grey; `shell:` tones | Home, story-row, project-card, ProjectStories |
| board/status-bar | Blade | Stacked bar of counts by raw status; out-of-vocabulary values get a danger-toned segment (grey with `variant="tag"`); `shell:` tones | project-card, ProjectPage initiative rows, ProjectStories, Overview |
| layouts/board | Blade layout | The old board shell (until RD-9): board/sidebar beside the page, persisted Flux toasts, no auth, Flux appearance for light/dark | Home, ProjectPage, ProductionDashboard |
| board/sidebar | Blade (class) | Project switcher: filter box (⌘K), All projects, Production (connected/shown), Mockups (awaiting-pick count), enabled projects with state dot, count and live badge; drawer below 768px; Manage slot | layouts/board |
| layouts/shell | Blade layout | The RD-2 dark command-center shell (Home A): `.shell` on `<html>`, Geist, shell/sidebar beside the page, dark only; persisted Flux toasts behind an appearance stub (RD-4) | ProjectPreflight, StoryPage, ProjectStories, ProjectHandbook, ProjectAppMap, MockupGallery, MockupViewer, ManageProjects, Overview |
| shell/sidebar | Blade (class) | Shell nav: logo, Home, Mockups (awaiting count), enabled projects with state dot and ID prefix, current marked; Alpine drawer below 768px | layouts/shell |
| shell/logo | Blade | Logo mark and "Story Board", linking home | shell/sidebar |
| shell/tag | Blade | Shell outlined pill for an ID, status, initiative or count; `tone` needs/go/live/fail, `mono` for Geist Mono | StoryPage, Overview |
| StoryPage | Livewire (Board) | Short story page (RD-3): tags, title, summary, Pick, one mockup frame with Today/option buttons, five folded `<details>`; `?v=` versions | `/p/{project}/s/{id}` |
| StoryModal | Livewire (Board) | Design-A story modal at `?story=<project>/<ID>`: text, details, dependency chips, mockup thumbnails and Open in mockup gallery, versions off main; opened by `board-story`; RD-9 deletes it | ProjectPage, Home (unrouted) |
| ProjectPage | Livewire (Board) | One project's dashboard (SB-10): header with Refresh this project, scoped What needs me cards, Progress by initiative, Not on main by kind; embeds StoryModal | `/p/{project}` |
| board/needs-me-cards | Blade | The three What needs me cards (pick, approval, build) with count, top rows and Show all calling the host's `showAll()` | Home, ProjectPage |
| board/state | Blade | A project's snapshot state: `part="dot"` coloured dot, `part="label"` word (none for `ok`, danger for unknown states); dark-shell tones via tokens | sidebar, shell/sidebar, project-card, ProjectPage header, Overview |
| ManageProjects | Livewire (Board) | Manage projects (SB-12): every project with its switch, path, ref, state, count, refresh age, Production summary opening its panel; Add project form; Remove via confirm-modal | `/projects` |
| board/switch | Blade | On/off `role="switch"` button showing the stored state; the caller's `wire:click` writes it; disables while `target` runs | ManageProjects, ProductionSettings |
| board/confirm-modal | Blade | Alpine confirmation for a destructive action: `show` var, `title` slot, body, Cancel + red `confirm` button running `action` | ManageProjects (Remove), ProductionSettings (Remove connection) |
| ProjectStories | Livewire (Board) | A project's stories by initiative (SB-15): initiatives on the left, stories tagged by status on the right; Alpine filters and Expand all; rows link to StoryPage | `/p/{project}/stories` |
| board/project-header | Blade | A project sub-page's header (breadcrumb to the project's Stories, name, state, ref; slot extends the ref line) above project-tabs; shell tokens only | ProjectHandbook, ProjectStories, ProjectPreflight, ProjectAppMap |
| ProjectHandbook | Livewire (Board) | A project's handbook (SB-14): section tabs over one reading card; each section loads on first open; kit badges; decisions in a modal | `/p/{project}/handbook` |
| board/project-tabs | Blade | Stories / Preflight / Handbook / Map page tabs under a project's header; scrolls sideways on a phone; `shell:` tones | ProjectPage, project-header |
| board/kit-badge | Blade | How a file compares with the kit: Same as kit, Changed in this project, Missing, Project only; `dot` for a sub-tab | ProjectHandbook (standards, skills) |
| board/handbook-empty | Blade | Empty state naming the project and the missing file, with a one-line hint slot | ProjectHandbook sections |
| board/prose | Blade | Rendered markdown (from RenderStory::toHtml) in the reading width | ProjectHandbook sections, decision modal |
| LiveSessions | Livewire (Board) | Live now (SB-11): a card per active Claude Code session with checkout, branch, age and linked stories; `wire:poll.30s` on the panel only | ProjectPage, Home (unrouted) |
| ProductionSettings | Livewire (Board) | A project's Production panel (SB-17), lazy under its row: read-only connection form + inline check, read-only user one-liner, preset and custom metrics with Test | ManageProjects |
| board/metric-result | Blade | A production metric's last Test result inline: value + ms, timed out, or the refusal/error | ProductionSettings |
| ProductionDashboard | Livewire (Board) | Production (SB-18): projects × metrics table (cards below 768px), skeleton per row until its queued read is back, Refresh per row, Not connected list | `/prod` |
| board/prod-stat | Blade | One production metric: value, change vs yesterday and 7 days ago, trend, read time, greyed when last-good; `layout="cell"` or `"line"` | ProductionDashboard |
| board/prod-change | Blade | A signed change with ▲/▼ and gain/loss colour, "—" when no snapshot that day | prod-stat |
| board/prod-row-status | Blade | Under a project's name on /prod: Reading… / read time, and the failed read's badge and sentence | ProductionDashboard |
| board/sparkline | Blade | Inline-SVG trend line, gaps for missing days, end dot, Alpine hover readout; `decorative` is a bare aria-hidden line | prod-stat, Overview |
| ProjectPreflight | Livewire (Board) | A project's preflight runs (SB-16): trend figures and two SVG line charts over a ledger table; Alpine filters, hover and columns picker (`preflightHistory` in resources/js) | `/p/{project}/preflight` |
| MockupGallery | Livewire (Board) | Mockup gallery (SB-21): every set as a live-thumbnail card grouped by project, awaiting first; Alpine status/project/search filters, Show all per project | `/mockups` |
| MockupViewer | Livewire (Board) | Full-screen mockup viewer (SB-21): title bar, description, Where, Current/A/B/C tabs (opens Current vs option when a shot exists), width switch, side by side with per-pane picker and swap, Pick with reason | `/mockups/{project}/{story}` |
| board/mockup-state | Blade | A mockup set's pick state: Awaiting pick (`pick` token), Picked X (`built`, "not pushed" for a local pick), or grey | MockupGallery, MockupViewer |
| board/mockup-current | Blade | Viewer's Current pane (SB-23): the matching journey shot with capture time and stale label, else new-page or uncovered state; props `current`, `project` | MockupViewer, StoryPage |
| ProjectAppMap | Livewire (Board) | App map (SB-24): All flows lanes with lettered shared screens, one flow's stage and strip; Alpine stepping (`appMap`) | `/p/{project}/map` |
| board/map-screen | Blade | One app-map screen in browser chrome with its route: journey shot, drawn placeholder (`.map-page`), chosen mockup frame, awaiting pick, no mockup, not linked; `size` sm/lg | ProjectAppMap |
| GuardsProjectPage | Livewire trait | One-project page guard (RD-1): locked `$project`, `hydrate()` re-check and redirect; hooks `refusedRoute()`, `logProjectRefused()` | ProjectStories, ProjectPreflight, ProjectHandbook, ProjectAppMap, MockupViewer, StoryPage |
