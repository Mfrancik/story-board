# ADR-033 — The app map is generated on the server from each project's own files

Date: 2026-09-29 · Status: accepted · Story: SB-24

## Context

The owner wanted a wireframe of each app, showing where it stands and how its flows move, that can be
flipped through. It had to work with **no change to any project** (owner 2026-09-29). Real screenshots of
built screens are a bonus, and they appear only when a project adopts SB-22's journey shots. A
hand-drawn map would go stale the day after it was drawn. The inputs that already exist are the journey
docs (free-form markdown, several shapes), story files and mockup sets (SB-21). Coins alone has
27 journeys and 205 steps.

## Decision

- **Generated, not drawn.** `ReadJourneyMap` builds the map on every page load. It reads journey docs
  and story files from git at the snapshot sha and mockup sets through `ReadMockupSets`. The parser is
  tolerant of every doc shape seen in the four registered projects. A doc it cannot read is named on the
  page and logged, not fatal.
- **Truth order.** A step's state comes from its story file's `Status:` (the snapshot). The journey
  table's status column is only a fallback. A step's route is the first `/path` in its text, else the
  story's `- Routes:` line. Journey docs rarely name routes, so without that fallback shared screens
  would almost never be found.
- **Rendered on the server, stepped in Alpine.** The whole map is in the page, and Back/Next, flow
  switching, full screen and hover never call the server. Only the open flow's stage and strip exist in
  the DOM (`x-if`), because hidden iframes load eagerly.
- **The placeholder is one CSS element.** A built screen with no shot is drawn by `.map-page`'s layered
  backgrounds, not by a couple of dozen elements. That cut coins' page from about 4.2 MB to about 3.1 MB.
- **Shots are a manifest lookup, never a path.** `ReadJourneyShots` treats each
  `storage/app/journey-shots/<j>/manifest.json` as a whitelist. It resolves each entry with
  `realpath()` and requires it to stay inside `journey-shots/`. `shots.file` looks up the requested
  journey and file names among what was read, and never joins them onto a path. Its `file` parameter
  accepts `.*` so that traversal attempts reach the action and are logged.

Alternatives rejected:
- **Render the stage and strip client-side from a JSON payload.** The page would be lighter, but the
  screen drawing would then exist twice (Blade `board/map-screen` and JS), and the two would drift.
  Page weight is accepted as the known cost instead.
- **Ask projects to annotate journeys with routes or state.** This breaks the "no change to any project"
  rule, and it is a second copy of what story files already say.
- **Validate the shot path from the URL** (as SB-4's `isPlainRelativePath()` does). An exact lookup
  against the manifest needs no traversal rules and cannot serve an unlisted file.

## Consequences

- Page weight grows with the number of journey steps. It is the first thing to revisit if the map gets
  slow.
- The parser encodes the doc shapes /story has written so far. A new shape that has neither a numbered
  flow list nor a `| Step |` table shows as "could not be read" until the parser learns it.
- SB-22 must write manifests in a shape the reader accepts: a `file` (or `path`/`png`) key, and `width`
  to tell phone from desktop. SB-23 reuses `ReadJourneyShots` unchanged.
