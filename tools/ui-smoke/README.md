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

`setup.sh` resets the database, runs the migrations through the login page, loads `seed.sql` (which also turns accounting on and grants the accounting screens to `admin`), records four sales and then a purchase, a return from it and a payment against the purchase made from the receiving edit window through the UI (so the supplier balances and statement screens have something to show), posts the opening stock of the first location from the opening balances screen (`seed-opening-stock.mjs`, which checks the location is ready before and posted after; it runs before the journals because that script closes a period), adds two treasuries (one of them a bank account) and a transfer between the main treasury and the bank from the treasuries screen (`seed-treasuries.mjs`, which checks that the treasuries and the transfer are listed; it runs before the journals because that script closes a period), and posts accounting journals through the real endpoints (`seed-journals.mjs`: two posted, one of them reversed, and one draft). The application under test must already be running against that database.

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

## Purchase discount

`check-purchase-discount.mjs` works the purchase register the way a buyer does: it adds an item, sets its cost to 100 and its quantity to 10, switches the line discount to a fixed amount and enters 50, and checks that the line total on the screen is 500.00 (a fixed purchase discount comes off every unit, not off the line once). It cancels the purchase afterwards and records nothing, so it can run on any database prepared by `npm run setup`.

```sh
npm run purchase-discount
```

It exits with 1 when the total differs.

`check-legacy-purchase-discount.mjs` does the same for a purchase saved before the fixed discount became an amount for every unit. It records two purchases through the register (10 units at 100 with a fixed discount of 5), turns one of them into what the old code stored (a fixed discount of 50 for the line) and adds a purchase that never reached the books, then runs `php spark accounting:purchase_discount` and `php spark migrate` against the same database: the scan must read one purchase by what the books posted for the whole line, one for every unit and leave one undecided, the migration must stop and change nothing, and after the decision is recorded it must mark only the whole-line purchase. It then checks that the journal did not change and that the receipt, the purchase report and the edit form still show 950.00, and that the debit note of the purchase takes the whole discount for every unit and its share for what remains after a partial return. It needs `mysql` and `php` on the path (`UI_MYSQL`, `UI_DB_NAME` and `UI_DB_PREFIX` override the defaults), leaves its purchases and the two returns behind and puts the migration back, so run `npm run setup` again before `npm run smoke`.

```sh
npm run legacy-purchase-discount
```

`UI_SHOW=1` prints the scan and the refusal of the migration.

## Bootstrap 3 gate

```sh
npm run gates
npm run gates -- --update
```

Counts legacy Bootstrap 3 classes, inline `style=` attributes and plugin references in `app/` and `resources/assets/js`. Every count must be zero, except `pluginSelectpicker` and `pluginNotify`, the two plugins that stay by decision, which must not exceed `gates.baseline.json`. Inline styles in receipts, documents, the barcode sheet and the mPDF footer are not counted, because print and email markup cannot rely on a stylesheet. Run with `--update` after one of the retained plugins is removed.

## Screens

`screens.json` lists the screens in the order they are visited. Screens of the office menu group come last, because the active menu group is kept in the session. Steps are `click`, `open`, `fill`, `type`, `press`, `waitFor`, `pause` and `idle`. `open` clicks its selector only when it is visible, which opens the collapsed settings navigation on narrow screens.

## Phone pass

`phone-pass.mjs` measures the phone layout of the accounting pages without a running application. `tests/Support/PhonePagesDump.php` (outside the suite: its name does not end in `Test`) renders thirty pages through the controller test layer with real data and writes them as static HTML; the script opens each page at the given widths in Chromium with no network and prints, per page, the horizontal scroll of the page, every table of the content (stacked into cards or not, and whether it still scrolls sideways) and the elements that stick out of the screen outside a scrolling box.

```sh
PHONE_PAGES_DIR=/tmp/pages MYSQL_DB_NAME=ospos_test_x vendor/bin/phpunit --no-coverage tests/Support/PhonePagesDump.php
npm run phone-pass -- --pages /tmp/pages [--widths 360,390,768] [--shots DIR] [--css FILE]
```

It exits with 1 when a page scrolls sideways or a table of the content scrolls sideways below 768 px, the breakpoint of the cards. `--css FILE` answers the request for `assets/css/app.css` with FILE, to measure the same pages with another stylesheet, such as the one of an earlier commit.

It is not a live application: tables that Bootstrap loads by Ajax, dialogs and the behaviour of the server are not exercised, and `axe` is not run (it needs `npm install` in this folder and a live application: use `npm run smoke` for that).

`picker.mjs` checks the search box that replaces the list of accounts, customers or suppliers on the accounting screens when there are more entries than the threshold (setting `accounting_picker_threshold`, 200 by default). It opens `resources/assets/js/45-accounting.js` on a page of its own in Chromium, left to right and right to left, with the server answered by a stub, and checks the combobox roles and states, the live region, the arrow keys, Enter, Escape, the click, the limit of twenty and the message that says there are more, a row added after the page loaded, and that `public/assets/js/app.js` holds the same code. It needs no application and no database. It exits with 1 on a failure.

```sh
npm run picker
```
