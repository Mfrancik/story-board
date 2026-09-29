# Design Standards
Read before any UI work. Goal: every screen looks like it came from the same
hand, and the agent never freestyles visual decisions.

## Mockup approval gate (visual work stops here until I choose)
Any story involving visual design, new layout, or significant styling changes
must clear this gate BEFORE a single line of implementation code is written:

1. Produce **2–3 distinct design directions** as static mockups — self-contained
   HTML files (inline styles or the Tailwind CDN, dummy data, zero dependency on
   app code or a running dev server). Save to
   `docs/mockups/<story-id>/option-a.html`, `option-b.html`, (`option-c.html`).
2. **Open every option in my browser** for side-by-side comparison
   (`open docs/mockups/<story-id>/*.html` on macOS). Never just report file paths.
3. **Wait for me to pick.** No implementation code — Blade, components, CSS —
   before I have chosen a direction.
4. Record the **chosen option AND my one-line reason** in the story file. The
   build honors that intent, not just the pixels.
5. Leave the mockups in the repo until the story ships — they are the record of
   what was approved.

Non-visual stories (pure logic, data, config) skip this gate.

## Reuse before build (the anti-duplication law)
Before creating ANY new form, modal, table, or component:
1. Check `docs/UI-INVENTORY.md` and `resources/views/components/` for an
   existing component that does this job.
2. **Reuse** it as-is → or **extend** it with a prop/slot → or, only if neither
   fits, **create new** — and creating new requires a one-line justification in
   the story of why existing components don't fit.
3. The same record must have ONE form. Create and edit share a single form
   component (different mode, same component). Two slightly-different forms for
   the same entity is a bug, found in preflight or not.
4. Anyone who creates or meaningfully changes a component updates
   `docs/UI-INVENTORY.md` (name, purpose, where used) in the same story.

## Canonical interaction patterns (decide once, never per-screen)
- **Create/edit a simple record** (≤ ~6 fields, no steps): modal, opened in
  place. The user never leaves the list they were on.
- **Complex create/edit** (multi-step, file uploads, > ~6 fields): dedicated
  page with breadcrumb back to the list.
- **Delete/destructive**: confirmation modal, never a bare button, never a page.
- **Detail view**: page if it has its own URL-worthy identity; slide-over/modal
  if it's a quick peek from a list.
- The same entity uses the SAME pattern everywhere it appears. If invoices edit
  in a modal on the dashboard, they edit in a modal on the reports page too.
- If a story seems to need a deviation, that's an interview question for me —
  not a silent judgment call.

## Tokens are law
All visual values come from `tailwind.config.js` / `resources/css/app.css`
theme tokens. **Never hardcode** hex colors, arbitrary pixel values (`w-[347px]`),
or one-off font sizes. If a needed token doesn't exist, propose adding it — don't
inline it.

- Color: use semantic token names (`primary`, `surface`, `danger`, `muted`), not
  raw palette values, so re-theming a project is a one-file change.
- Spacing: Tailwind's default scale only. Type: the project's defined scale only.
- Dark mode: not supported unless a project's brief says so. Don't half-add it.

## Component hierarchy (decide in this order)
1. **Admin screens → Filament.** All back-office CRUD, tables, and forms use
   Filament resources. Do not hand-build admin UI that Filament provides.
2. **Reusable UI → Blade components** in `resources/views/components/`.
   Buttons, inputs, cards, badges, layout shells. If the same markup appears
   twice, it becomes a component. Props over duplication.
3. **Data-driven interactivity → Livewire components.** Anything that reads or
   writes data (search, forms, lists, wizards).
4. **Pure-UI interactivity → Alpine.js.** Modals, dropdowns, tabs, toggles,
   accordions — anything that needs zero server data. NEVER a Livewire
   round-trip for UI-only state.
5. Page navigation uses `wire:navigate` for SPA-feel transitions.

## Every interactive view must handle four states
1. **Loading** — `wire:loading` indicators on anything that round-trips. Buttons
   disable while submitting (`wire:loading.attr="disabled"`).
2. **Empty** — a designed empty state with one clear next action, never a blank
   region.
3. **Error** — validation errors inline next to their field via the shared error
   component; system errors as a non-technical flash message.
4. **Success** — visible confirmation (flash/toast), using the same verb as the
   button that triggered it ("Save changes" → "Changes saved").

## Forms
- Every field: visible label, validation message slot, and correct input type.
- Livewire validation with real-time feedback where it helps (`#[Validate]`).
- Submit buttons state exactly what happens: "Create invoice", not "Submit".

## Baseline quality (non-negotiable)
- Responsive at 375px, 768px, 1280px. Test the narrow width, not just desktop.
- Semantic HTML first; buttons are `<button>`, links are `<a>`.
- Keyboard: visible focus states, Escape closes modals, sensible tab order.
- Images have alt text; icon-only buttons have `aria-label`.
- No layout shift from loading states — reserve space.

## Copy in the UI
Sentence case everywhere. Plain verbs. Errors say what went wrong and how to
fix it — never just "Something went wrong" when we know what did.
