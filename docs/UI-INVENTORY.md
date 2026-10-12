# UI Inventory
Every reusable component. Consult BEFORE building any new UI (design-standards
§Reuse before build). Updated in the same story that creates/changes a component.

| Component | Type (Blade/Livewire/Filament) | Purpose | Used in |
|---|---|---|---|
| Overview | Livewire (Board) | Home overview (RD-5–7): `?projects=` filter, count sentence, figures, Needs me, Live now cards, Projects tiles, Shipped this week; `wire:poll.30s` | `/` |
| shell/figure | Blade | One headline figure: uppercase label, mono number ("—" when null), accent toned needs/live, one line below | Overview |
| board/status-chip | Blade | A story's raw status with parse-error marker; danger tone for out-of-vocabulary values; `count` makes a tally chip; `variant="tag"` reads Built/To do/Draft/Cancelled, others grey; `shell:` tones | ProjectStories |
| board/status-bar | Blade | Stacked bar of counts by raw status; out-of-vocabulary values get a danger-toned segment (grey with `variant="tag"`); `shell:` tones | ProjectStories, Overview, ProjectOverview |
| layouts/shell | Blade layout | The RD-2 dark command-center shell (Home A): `.shell` on `<html>`, Geist, shell/sidebar beside the page, dark only; persisted Flux toasts behind an appearance stub (RD-4) | ProjectPreflight, StoryPage, ProjectStories, ProjectHandbook, ProjectAppMap, MockupGallery, MockupViewer, ManageProjects, Overview, ProjectOverview, CampaignList, Settings, CampaignPage |
| shell/sidebar | Blade (class) | Shell nav: logo, Home, Mockups (awaiting count), enabled projects (each opening its Overview) with state dot and ID prefix, current marked; Alpine drawer below 768px | layouts/shell |
| shell/logo | Blade | Logo mark and "Story Board", linking home | shell/sidebar |
| shell/tag | Blade | Shell outlined pill for an ID, status, initiative or count; `tone` needs/go/live/fail, `mono` for Geist Mono | StoryPage, Overview, ProjectOverview |
| StoryPage | Livewire (Board) | Short story page (RD-3): tags, title, summary, Pick, one mockup frame with Today/option buttons, five folded `<details>`; `?v=` versions | `/p/{project}/s/{id}` |
| board/state | Blade | A project's snapshot state: `part="dot"` coloured dot, `part="label"` word (none for `ok`, danger for unknown states); dark-shell tones via tokens | shell/sidebar, project-header, ManageProjects, Overview |
| ManageProjects | Livewire (Board) | Manage projects (SB-12): every project with its switch, path, ref, state, count, refresh age, Production summary opening its panel; Add project form; Remove via confirm-modal | `/projects` |
| board/switch | Blade | On/off `role="switch"` button showing the stored state; the caller's `wire:click` writes it; disables while `target` runs | ManageProjects, ProductionSettings, CampaignPage |
| board/confirm-modal | Blade | Alpine confirmation for a destructive action: `show` var, `title` slot, body, Cancel + red `confirm` button running `action` | ManageProjects (Remove), ProductionSettings (Remove connection), Marketing Settings (Delete photo) |
| ProjectOverview | Livewire (Board) | A project's overview (RD-11, option B): tile grid of progress, Needs you, Live now, latest preflight, Production, About, biggest initiatives; Refresh this project | `/p/{project}` |
| ProjectStories | Livewire (Board) | A project's stories by initiative (SB-15): initiatives on the left, stories tagged by status on the right; Alpine filters and Expand all; rows link to StoryPage | `/p/{project}/stories` |
| board/project-header | Blade | A project sub-page's header (breadcrumb to the project's Overview, name, state, ref; slot extends the ref line; `actions` slot on the right) above project-tabs; shell tokens only | ProjectOverview, ProjectHandbook, ProjectStories, ProjectPreflight, ProjectAppMap, CampaignList, Settings, CampaignPage |
| ProjectHandbook | Livewire (Board) | A project's handbook (SB-14): section tabs over one reading card; each section loads on first open; kit badges; decisions in a modal | `/p/{project}/handbook` |
| board/project-tabs | Blade | Overview / Stories / Preflight / Handbook / Map / Marketing page tabs under a project's header; Marketing's count of posts to approve; scrolls sideways on a phone; `shell:` tones | project-header |
| board/kit-badge | Blade | How a file compares with the kit: Same as kit, Changed in this project, Missing, Project only; `dot` for a sub-tab | ProjectHandbook (standards, skills) |
| board/handbook-empty | Blade | Empty state naming the project and the missing file, with a one-line hint slot | ProjectHandbook sections, ProjectOverview (About) |
| board/prose | Blade | Rendered markdown (from RenderStory::toHtml) in the reading width | ProjectHandbook sections, decision modal, ProjectOverview (About) |
| ProductionSettings | Livewire (Board) | A project's Production panel (SB-17), lazy under its row: read-only connection form + inline check, read-only user one-liner, preset and custom metrics with Test | ManageProjects |
| board/metric-result | Blade | A production metric's last Test result inline: value + ms, timed out, or the refusal/error | ProductionSettings |
| board/sparkline | Blade | Inline-SVG trend line, gaps for missing days, end dot, Alpine hover readout; `decorative` is a bare aria-hidden line | Overview (project tiles), ProjectOverview (Production) |
| ProjectPreflight | Livewire (Board) | A project's preflight runs (SB-16) and where each ran (RD-8): trend figures and two SVG line charts over a ledger table; Alpine filters, hover and columns picker (`preflightHistory` in resources/js) | `/p/{project}/preflight` |
| MockupGallery | Livewire (Board) | Mockup gallery (SB-21): every set as a live-thumbnail card grouped by project, awaiting first; Alpine status/project/search filters, Show all per project | `/mockups` |
| MockupViewer | Livewire (Board) | Full-screen mockup viewer (SB-21): title bar, description, Where, Current/A/B/C tabs (opens Current vs option when a shot exists), width switch, side by side with per-pane picker and swap, Pick with reason | `/mockups/{project}/{story}` |
| board/mockup-state | Blade | A mockup set's pick state: Awaiting pick (`pick` token), Picked X (`built`, "not pushed" for a local pick), or grey | MockupGallery, MockupViewer |
| board/mockup-current | Blade | Viewer's Current pane (SB-23): the matching journey shot with capture time and stale label, else new-page or uncovered state; props `current`, `project` | MockupViewer, StoryPage |
| ProjectAppMap | Livewire (Board) | App map (SB-24): All flows lanes with lettered shared screens, one flow's stage and strip; Alpine stepping (`appMap`) | `/p/{project}/map` |
| board/map-screen | Blade | One app-map screen in browser chrome with its route: journey shot, drawn placeholder (`.map-page`), chosen mockup frame, awaiting pick, no mockup, not linked; `size` sm/lg | ProjectAppMap |
| GuardsProjectPage | Livewire trait | One-project page guard (RD-1): locked `$project`, `hydrate()` re-check and redirect; hooks `refusedRoute()`, `logProjectRefused()` | ProjectOverview, ProjectStories, ProjectPreflight, ProjectHandbook, ProjectAppMap, MockupViewer, StoryPage, CampaignList, Settings, CampaignPage |
| CampaignList | Livewire (Marketing) | Marketing tab's Campaigns list (MK-1): alerts banner, campaign rows (next post, approved-through, runway, badges), Paused group, empty state; names open CampaignPage, + New campaign (MK-7) | `/p/{project}/marketing` |
| CampaignPage | Livewire (Marketing) | One campaign (MK-7): header with on/off, sentence, runway status line, next 8 dates, past posts, criteria form (create = edit) with Alpine Unsaved changes + Discard | `/p/{project}/marketing/campaigns/{slug}`, `/campaigns/new` |
| Settings | Livewire (Marketing) | Marketing settings (MK-2): one brand form (create = edit) with preview, logo from repo or SVG upload (sanitised), favourite photos, copy rules, Facebook phase-2 card | `/p/{project}/marketing/settings` |
| marketing/sub-nav | Blade | Marketing pages (Campaigns · Calendar · Library · Inspiration · Settings); lists a page only once its `projects.marketing.*` route exists | CampaignList, Settings, CampaignPage |
| marketing/runway-bar | Blade | A campaign's next dates as coloured blocks (approved, to approve, open, skipped) + "N of 6 approved"; `legend` renders the key | CampaignList, CampaignPage |
| marketing/alert-badge | Blade | One "needs you" pill on a campaign row: `tone` missed, photo, approve, open, thin or quiet; a label, not a control | CampaignList, marketing/occurrence-row |
| marketing/occurrence-row | Blade | One timeline date: date/time, state dot and pill (approved, posted, drafts, open, skipped, missed), preview or draft thumbnails, amber edge; Off schedule tag; an open date's Fill / Drafting… / Retry (MK-8) | CampaignPage |
