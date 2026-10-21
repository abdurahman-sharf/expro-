## What changed

<!-- One or two sentences: what the change does and why. -->

## UI checklist

Skip the section when the change touches no view, stylesheet or script. The standard is `docs/ui-redesign/design-system.md`, the patterns are in `docs/ui-redesign/patterns.md`, and the live reference is the `/styleguide` page (administrators only).

- [ ] Built from an existing pattern (list, form, report, settings section) and the shared helpers, not from scratch.
- [ ] No inline `style=`, no hard-coded colors (use the tokens in `00-theme.css`), no physical directions (`ml-*`, `text-left`, `margin-left`; use `ms-*`, `text-start`, `margin-inline-start`).
- [ ] Every visible string is a language key, added to all `app/Language/*` files in alphabetical order; Arabic (`ar-EG`, `ar-LB`) is translated.
- [ ] Numbers, dates and currency are isolated LTR inside Arabic text (`<bdi dir="ltr">`).
- [ ] Controls have labels, icon-only buttons have `aria-label`, and the change works with the keyboard.
- [ ] `node tools/ui-smoke/gates.mjs` passes and, for new screens, an entry was added to `tools/ui-smoke/screens.json`.
- [ ] Checked in Arabic (RTL) and English at 390, 768 and 1440 px.
- [ ] `npm run build` was run and `public/assets` is committed.

## Tests

<!-- PHPUnit tests added or changed, and how the change was checked in the browser. -->
