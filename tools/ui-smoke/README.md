# UI smoke

Browser smoke checks and Bootstrap 3 migration gates for the UI redesign. Nothing here is loaded by the application.

## What it checks

For every screen in `screens.json`, per language (`ar`, `en`) and viewport (390x844, 768x1024, 1440x900):

- HTTP status of the page, failed requests, bad sub-resource responses, console and page errors
- horizontal overflow, `<html dir>` / `lang`, presence of the viewport meta tag
- loaded IBM Plex Sans Arabic faces
- count of legacy Bootstrap 3 elements in the rendered DOM
- accessibility violations (axe-core, WCAG 2 A and AA)
- request count and transferred bytes by resource type, with the browser cache disabled
- a full-page screenshot

## Requirements

- Node.js 22 or newer
- a Chromium binary (`PLAYWRIGHT_EXECUTABLE_PATH`, or the Playwright browsers path)
- the application running against a MySQL or MariaDB database
- the `mysql` client, only for `setup.sh`

```sh
cd tools/ui-smoke
npm install
```

## Fixtures

`setup.sh` resets the database, runs the migrations through the login page, loads `seed.sql` and records four sales through the UI. The application under test must already be running against that database.

```sh
UI_DB_NAME=ospos_ui UI_BASE_URL=http://127.0.0.1 npm run setup
```

It refuses to run against a non-local URL or a database whose name does not end in `_ui` or `_test`, unless `UI_SETUP_FORCE=1` is set. `UI_MYSQL` overrides the `mysql` command, for example `UI_MYSQL="mysql -uroot -p..."`.

## Running

```sh
npm run smoke
npm run smoke -- --langs ar --viewports 390x844,1440x900 --only items,sales-register-cart
```

| Option | Default | Meaning |
|---|---|---|
| `--base-url` | `UI_BASE_URL` or `http://127.0.0.1` | application root |
| `--langs` | `ar,en` | languages to switch to through the Localization settings |
| `--viewports` | `390x844,768x1024,1440x900` | `WIDTHxHEIGHT` list |
| `--only` | all | comma separated screen ids |
| `--out` | `build/ui-smoke/<timestamp>` | output folder |
| `--no-axe`, `--no-screenshots` | off | skip those checks |
| `--write-baseline FILE` | | store compact metrics |
| `--compare FILE` | | print regressions against a stored baseline |
| `--fail-on-regression` | off | exit with 1 when `--compare` finds regressions |

Credentials come from `UI_USER` and `UI_PASS` (default to the application's initial `admin` account). The run switches the application language and restores the original one at the end.

The run writes `report.json` and a screenshot gallery `index.html` to the output folder. It exits with 1 when a screen returns an error status, raises a page error or loads a sub-resource with an error status.

`baseline.json` was captured on the commit recorded in `docs/ui-redesign/baseline.md`.

## Design standard gates

```sh
npm run gates          # from the project root, or: node tools/ui-smoke/gates.mjs
node tools/ui-smoke/gates.mjs --update
```

Scans `app/` (views, helpers, controllers, libraries, models), `public/js` and `resources/assets` for what the design standard forbids (`docs/ui-redesign/design-system.md`). The `UI gates` workflow runs it on every change to those paths.

| Count | Meaning |
|---|---|
| Bootstrap 3 classes and removed plugins | legacy markup |
| `physicalUtility`, `physicalCss` | left/right utilities and properties (`ml-*`, `text-left`, `margin-left`, `float: left`) instead of logical ones |
| `inlineStyle` | `style=` attributes |
| `varInScripts` | `var` in `resources/assets/js` |
| `varInViews`, `inlineHandler` | `var` statements and inline `on*=` handlers in views |
| `hexColor` | color literals outside `00-theme.css` |

Every count must be zero, with three exceptions that may not exceed the numbers in `gates.baseline.json`: the plugins that stay by decision (`pluginSelectpicker`, `pluginNotify`) and `importantCss`. Print and email markup (receipts, documents, the barcode sheet, the PDF footer, `80-receipt.css`, `81-document.css`, `82-print.css`) is not counted. Run with `--update` only after one of the retained patterns goes down, so the baseline goes down with it.

## Screens

`screens.json` lists the screens in the order they are visited. The `styleguide` screens render the live component reference (`/styleguide`). Screens of the office menu group come last, because the active menu group is kept in the session. Steps are `click`, `open`, `fill`, `type`, `press`, `waitFor`, `pause` and `idle`. `open` clicks its selector only when it is visible, which opens the collapsed settings navigation on narrow screens.
