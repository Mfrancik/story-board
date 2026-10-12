# ADR-067 — Brand logos are sanitised with PHP's DOM, and the brand preview reads saved values only

Date: 2026-10-12 · Status: accepted · Story: MK-2

## Context

A brand logo is SVG that Settings inlines into the page with `{!! !!}`, and later stories will put it on post
previews. It comes from a project's repo (coins keeps its swirl inside a Blade component,
`resources/views/components/app-mark.blade.php`, with no `xmlns`) or from an uploaded file. Both are untrusted.

The story makes the brand editable, though the prototype shows it read-only. The colours, font and radius are
written into `style` attributes for the preview.

## Decision

- `app/Actions/Marketing/SanitiseSvg.php` uses PHP's `DOMDocument`, no new dependency. It lifts the `<svg>` out of
  surrounding text (a Blade file), strips Blade echoes and comments, gives bare attributes a value, and parses with
  `LIBXML_NONET` and without `LIBXML_NOENT` (no entity substitution); any doctype is refused. It removes
  `script`, `foreignObject`, `iframe`, `embed`, `object` and media elements; animations targeting `href`,
  `xlink:href` or `on*`; `<style>` that imports or loads `url()` from elsewhere; `on*` attributes; any value
  containing `javascript:` (after removing whitespace and control characters); non-`#fragment` `href`,
  `xlink:href` and `src`; and external `url()` in `style`. It adds `xmlns` (and `xmlns:xlink` when needed).
- Every logo reaches the database through `SetBrandLogo`, which always sanitises.
- The brand is one inline form (create = edit), with "Upload an SVG" beside "Read logo".
- The preview renders **saved** values only, so input that has not passed validation never reaches a `style`
  attribute. The font name is limited to letters, digits, spaces and hyphens. Defaults are neutral (accent
  `#2563EB`), not StackerMint's.

Alternatives rejected:
- **A sanitiser package** (e.g. enshrined/svg-sanitize). A new dependency for a few hundred bytes of logo; the
  allow/deny rules here are short and tested in `tests/Unit/Marketing/SanitiseSvgTest.php`.
- **Serving the logo as an `<img>` from a file.** Safer by construction, but `currentColor` and inline styling
  would not work, and the prototype inlines it.
- **A live preview bound to the inputs.** Would put unvalidated text into `style`.

## Consequences

- The sanitiser is a deny-list over the SVG vocabulary. A new SVG feature that can load or run something needs
  adding to it.
- The preview updates only after "Save brand".
