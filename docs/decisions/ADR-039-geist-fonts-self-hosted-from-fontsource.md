# ADR-039 — Geist fonts are self-hosted from the fontsource variable packages

Date: 2026-10-11 · Status: accepted · Story: RD-2

## Context

The Home A shell sets its body in Geist and its figures and IDs in Geist Mono. The story requires the fonts to
be self-hosted through the build, with no runtime font CDN. The owner's first pick was Vercel's `geist` npm
package.

## Decision

Install `@fontsource-variable/geist` and `@fontsource-variable/geist-mono` as devDependencies. In
`resources/css/app.css`, declare our own `@font-face` for each, pointing at the package's
`files/*-latin-wght-normal.woff2`. The family names stay `Geist` / `Geist Mono`, and Vite copies the files
into the build. The latin subset is enough for this UI. The owner chose this option on 2026-10-11.

Alternatives rejected:
- **Vercel's `geist` package.** It declares `next` as a peer dependency. Since npm 7, peers install
  automatically, so Next, React and sharp would land in the lock file. Avoiding that means setting
  `legacy-peer-deps=true` in `.npmrc`, which turns off peer checks for the whole project.
- **Vendoring the woff2 files into the repo.** That works, but a versioned package can be upgraded with npm,
  while copied files go stale silently.
- **Importing the package's own CSS.** That would register its family names (`Geist Variable`) and every
  subset. Our own `@font-face` keeps the names the tokens use and loads one file per family.

## Consequences

- `--font-geist` / `--font-geist-mono` in `@theme` name the families. Inside `.shell`, `--font-sans` /
  `--font-mono` point at them (ADR-040).
- The `@font-face` URLs depend on the package's internal file layout. A major upgrade of fontsource could move
  them, and the build would fail loudly rather than drift.
- Non-latin text falls back to the system font.
