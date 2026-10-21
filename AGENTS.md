# Agent Instructions

This document provides guidance for AI agents working on the Open Source Point of Sale (OSPOS) codebase.

## Code Style

- **PSR-12** enforced via PHP-CS-Fixer (config: `.php-cs-fixer.no-header.php`)
- Follow PHP CodeIgniter 4 coding standards
- `camelCase` for variables and methods; `PascalCase` for classes; `UPPER_CASE` for constants
- When editing existing code containing non-PSR-compliant local variable names, refactor those variable names to `camelCase` as part of the edit
- All newly written code (variables, classes, functions) must use PSR-compliant naming, regardless of surrounding code style
- PHP 8.2+ features acceptable (named arguments, enums, readonly properties)
- Write PHP 8.2+ compatible code with proper type declarations
- Always import classes, functions, and constants with a `use` statement at the top of the file instead of referencing them inline via fully-qualified name (e.g. `use Config\Database;` then `Database::connect()`, not `\Config\Database::connect()`)
- No useless comments or docblocks: if the code speaks for itself, explanatory comments are unnecessary. Do not add comments or docblocks that merely restate what the code already makes clear (e.g. `@param array $items` next to `array $items`, or a comment repeating a method's name) — only comment on non-obvious rationale, constraints, or behavior
- Views in `app/Views/errors/html/` are excluded from the fixer
- Run fixer before committing: `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.no-header.php`
- **JavaScript**: use `const` for variables that are never reassigned, `let` for variables that are. Never use `var`.

## Development

- Create a new git worktree for each issue, based on the latest state of `origin/master`
- Commit fixes to the worktree and push to the remote

## Testing

- Run PHPUnit tests: `composer test`
- Tests must pass before submitting changes
- **One test file per class under test.** A controller, model, library, or helper gets exactly one test file covering all of its behavior — `Item_kits.php` → `tests/Controllers/Item_kitsTest.php` (or `Item_kitsControllerTest.php`, matching this codebase's existing `*ControllerTest.php` suffix for controllers), `Sale_lib.php` → `tests/Libraries/Sale_libTest.php`, etc. Do not create feature- or endpoint-scoped test files alongside a class's main test file (e.g. no `Item_kitsBarcodeTest.php` next to `Item_kitsControllerTest.php`) — add the new test methods to the existing file for that class instead. If no test file exists yet for the class, create the one canonical file rather than a narrowly-scoped one.
- **Name test methods as `test{MethodUnderTest}_{Behavior}`.** `PascalCase` the method under test, then an underscore, then a short behavior phrase — `testPostSave_RejectsNegativeTaxPercent`, `testSaveLocale_AcceptsValidReferenceCodeMinMax`. Start the behavior with a verb: `Accepts`/`Requires` for valid or boundary cases, `Rejects` for invalid ones. The name should read as a self-documenting sentence and needs no docblock.

## Build

- Install dependencies: `composer install && npm install`
- Build assets: `npm run build` or `gulp`

## Conventions

- Controllers go in `app/Controllers/`
- Models go in `app/Models/`
- Views go in `app/Views/`
- Database migrations in `app/Database/Migrations/`
- Use CodeIgniter 4 framework patterns and helpers
- Sanitize user input; escape output using `esc()` helper

## UI Standard (locked)

The interface design is final. Every new or changed screen follows `docs/ui-redesign/design-system.md` and the skeletons in `docs/ui-redesign/patterns.md`. The live reference is the `/styleguide` page (administrators only).

- Start from an existing pattern: module list, modal form, report, settings section, KPI cards. Do not design a screen from scratch.
- Bootstrap 5.3 with logical, RTL-safe utilities only: `ms-*`, `me-*`, `ps-*`, `pe-*`, `text-start`/`text-end`, `float-start`/`float-end`, `margin-inline-*`. Never `ml-*`, `mr-*`, `pl-*`, `pr-*`, `text-left`/`text-right`, `margin-left` or Bootstrap 3 classes.
- Colors come only from the tokens in `resources/assets/css/00-theme.css`; never write a hex value anywhere else. A new color is a new token, documented in the design system.
- No inline `style=` attributes, no inline `on*=` handlers (bind events in the page script; printing is `data-action="print"`), no `!important`, no `var` in JavaScript (use `const`, then `let`). Print, email, PDF and barcode markup is the only exception.
- Notifications in new code go through `notify_support.show(message, 'success'|'danger'|'info')`, not `$.notify` directly.
- Everything user visible is a language key (see Localization). Numbers, currency and dates inside Arabic text are isolated with `<bdi dir="ltr">`. Latin digits everywhere.
- Every control has an associated label, icon-only buttons have an `aria-label`, and the screen works with the keyboard.
- No new UI library. Available: Bootstrap 5, bootstrap-table, bootstrap-select, flatpickr, daterangepicker, Chart.js.
- A component the standard does not cover needs a row in `docs/ui-redesign/decisions.md` plus an entry in `/styleguide` and in `patterns.md`, in the same change.
- Source CSS and JS live in `resources/assets/`. Run `npm run build` and commit `public/assets/` (the Assets workflow rejects stale builds). Never edit `public/assets/` by hand.
- Before committing UI work: `node tools/ui-smoke/gates.mjs` must pass, new screens go into `tools/ui-smoke/screens.json`, and the screen is checked in Arabic (RTL) and English at 390, 768 and 1440 px. The `UI gates` workflow and the pull request template enforce this.

## Localization

- When adding new keys to language files, add the key to all `app/Language/*/` variants
- **New keys must be inserted in alphabetical order** within the language array
- Non-English files must use an empty string (`''`) as the value when no translation is provided — CodeIgniter automatically falls back to the default (`en`) language. This applies only when a translation genuinely isn't available yet.
- **When explicitly asked to translate a phrase for a non-English language file, always provide the actual translation** — never leave the value as an empty string, and never leave source English text in a non-English language file
- Never copy English text from a neighboring key as a value for a non-English language file, even if that neighboring key is already untranslated — evaluate each key independently
- Only `app/Language/en/` and `app/Language/en-GB/` should contain English strings
- When translating a string containing placeholders (e.g. `{filePath}`, `{reason}`) or literal filenames/keys (e.g. `throttle.key`, `.env`), keep that placeholder or filename text unchanged and untranslated — move it to whatever position is grammatically correct in the translated sentence
- Plugin language files (`app/Plugins/*/Language/`) follow the same localization rules as `app/Language/`
- Use `'` to encapsulate key and string values. If the value contains `'` then it should be escaped as `\'`
- Align the `=>` of a newly inserted key with the `=>` column already used by the rest of the file, if that file pads keys to a fixed column (not all do — some files have no padding at all). If the new key is longer than the widest existing key and would push the alignment column further right, reformat the whole file to the new wider column rather than leaving only the new line at a different width

## Security

- Never commit secrets, credentials, or `.env` files
- Use parameterized queries to prevent SQL injection
- Validate and sanitize all user input
- Never reference security advisory IDs (CVE, GHSA, etc.) in code, comments, commit messages, docblocks, documentation, or URLs — treat them the same as secrets. They act as a roadmap for attackers researching the exact exploit a fix addresses.
