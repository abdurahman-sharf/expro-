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

`setup.sh` resets the database, runs the migrations through the login page, loads `seed.sql` (which also turns accounting on and grants the accounting screens to `admin`), records four sales through the UI, and posts accounting journals through the real endpoints (`seed-journals.mjs`: two posted, one of them reversed, and one draft). The application under test must already be running against that database.

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

## Branch-limited employee

`branch-scope.mjs` is an interactive check, not a screenshot sweep. On a database prepared by `npm run setup` it signs in as the administrator, gives the sensitive accounting permissions one by one on the real employee form (and checks that Select all does not tick them), creates an employee limited to the Jeddah branch, then works the closing and opening balance screens as that employee: closes and reopens the branch, fills the opening balance grid, posts, reverses and posts a replacement, and sends forged requests for the head office. Every page and every JSON or HTML response the employee receives is scanned for the head office's code, name, closing date, opening balance and journal descriptions, and a control check proves the same markers fire on the administrator's view.

```sh
UI_DB_NAME=ospos_ui npm run setup
npm run branch-scope -- --out build/ui-smoke/branch-scope
```

It exits with 1 when a check fails. It leaves one extra employee behind and puts the closings back, so run `npm run setup` again before the next run or before `npm run smoke`.

## Bootstrap 3 gate

```sh
npm run gates
npm run gates -- --update
```

Counts legacy Bootstrap 3 classes, inline `style=` attributes and plugin references in `app/` and `resources/assets/js`. Every count must be zero, except `pluginSelectpicker` and `pluginNotify`, the two plugins that stay by decision, which must not exceed `gates.baseline.json`. Inline styles in receipts, documents, the barcode sheet and the mPDF footer are not counted, because print and email markup cannot rely on a stylesheet. Run with `--update` after one of the retained plugins is removed.

## Screens

`screens.json` lists the screens in the order they are visited. Screens of the office menu group come last, because the active menu group is kept in the session. Steps are `click`, `open`, `fill`, `type`, `press`, `waitFor`, `pause` and `idle`. `open` clicks its selector only when it is visible, which opens the collapsed settings navigation on narrow screens.
