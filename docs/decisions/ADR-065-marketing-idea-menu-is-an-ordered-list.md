# ADR-065 — The idea menu is stored as an ordered list, and campaign topics as "Category|Title"

Date: 2026-10-12 · Status: accepted · Story: MK-2

## Context

The story says `marketing_brands.idea_menu` holds the kit's idea menu as "category → ideas". The obvious shape is
a JSON object keyed by category. MySQL's JSON type normalises objects and sorts their keys, so the menu would come
back alphabetical, not in the order the owner wrote it in the kit. MK-7's topic picker shows the menu in that order.

Campaigns also need to say which menu ideas they draw from (`marketing_campaigns.topics.ideas`, from MK-1).

## Decision

- `idea_menu` is a list: `[{category, ideas: [{title, text}]}]`, in the kit's order
  (`SeedMarketingKit::ideaMenu()`). **MK-7 must read this shape.**
- A campaign's picked ideas are `"Category|Title"` strings, the prototype's convention. When seeding, picks the
  kit's menu does not contain are dropped rather than stored as dangling references.

Alternatives rejected:
- **A category-keyed object.** Loses the order on MySQL (see Context).
- **A category-keyed object plus a separate order array.** Two things to keep in step for no gain.
- **Ids for ideas.** The menu is re-read from the kit on every seed; title text is the stable key the owner sees.

## Consequences

- Renaming a category or title in the kit orphans campaign picks that used the old text. The seed only filters picks
  for campaigns it creates; existing campaigns are not re-checked.
- Any reader must not assume category names are unique keys.
