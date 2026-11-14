// Phase B in a real browser: console errors and failed requests on the main screens, the double click on a dialog
// submit, and the back button after a sale. Run with NODE_PATH pointing at the global modules (playwright).
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const BASE = process.env.R2_BASE || 'http://127.0.0.1:8082';
const OUT = process.env.R2_OUT || '/tmp/r2-browser.json';
const SHOTS = process.env.R2_SHOTS || '/tmp';
const pw = () => fs.readFileSync(__dirname + '/../../.env', 'utf8').split('\n').find((l) => l.startsWith('database.tests.password')).split('=').slice(1).join('=').trim().replace(/^['"]|['"]$/g, '');
const sql = (q) => execFileSync('mysql', ['-h127.0.0.1', '-uadmin', '-N', '-B', process.env.R2_DB || 'ospos_test_r2_ui', '-e', q], { env: { ...process.env, MYSQL_PWD: pw() }, encoding: 'utf8' }).trim();
const results = [];
const add = (id, steps, expected, actual, pass) => { results.push({ id, steps, expected, actual: String(actual), result: pass ? 'PASS' : 'FAIL' }); console.log(pass ? 'PASS' : 'FAIL', id, '|', actual); };

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
  const page = await ctx.newPage();
  let events = [];
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') events.push(`console.${m.type()}: ${m.text().slice(0, 200)}`); });
  page.on('pageerror', (e) => events.push('pageerror: ' + e.message.slice(0, 200)));
  page.on('requestfailed', (r) => events.push('requestfailed: ' + r.url().replace(BASE, '') + ' ' + (r.failure() || {}).errorText));
  page.on('response', (r) => { if (r.status() >= 400 && !/favicon/.test(r.url())) events.push(`http ${r.status()}: ${r.url().replace(BASE, '')}`); });
  page.on('dialog', (d) => d.accept());

  await page.goto(BASE + '/login');
  await page.fill('input[name=username]', 'admin');
  await page.fill('input[name=password]', 'pointofsale');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');

  const screens = ['home', 'sales', 'receivings', 'items', 'item_kits', 'customers', 'suppliers', 'giftcards', 'expenses', 'cashups', 'taxes', 'reports', 'employees', 'config', 'stock_documents', 'price_lists', 'purchase_orders', 'customer_orders',
    'accounting_chart', 'accounting_journals', 'accounting_receivables', 'accounting_payables', 'accounting_cheques', 'accounting_treasuries', 'accounting_assets', 'accounting_periods', 'accounting_periods/year_end', 'accounting_reports', 'accounting_reports/tax',
    'accounting_reports/inventory_valuation', 'accounting_statements/balance_sheet', 'accounting_statements/profit_loss', 'accounting_statements/cash_flow', 'accounting_statements/income_statement', 'accounting_settings', 'accounting_routing', 'receipt_vouchers', 'branches', 'roles'];
  const seen = {};
  for (const s of screens) {
    events = [];
    const res = await page.goto(BASE + '/' + s, { waitUntil: 'networkidle' }).catch((e) => ({ status: () => 0, err: e.message }));
    await page.waitForTimeout(400);
    const status = res && res.status ? res.status() : 0;
    const title = await page.title();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 2);
    const bad = events.filter((e) => !/debugbar|toolbar|favicon/.test(e) && !/Failed to load resource: the server responded with a status of 500/.test(e));
    seen[s] = { status, title, overflow, events: bad.slice(0, 6) };
    add('BR-' + s.replace(/\//g, '-'), `open /${s} and watch the console`, '200, no console error, no failed request', `${status} ${bad.slice(0, 3).join(' ; ')}${overflow ? ' [horizontal overflow]' : ''}`, status === 200 && bad.length === 0);
  }

  // the dialogs that used to answer 404: create through the real screen and count the rows
  for (const [id, page_, selector, fields, table, extra] of [
    ['voucher', 'receipt_vouchers', '[data-href*="receipt_vouchers/view"]', { amount: '33.00' }, 'ospos_revenues', ''],
    ['price-list', 'price_lists', '[data-href*="price_lists/view"]', { name: 'Browser list ' + Date.now().toString().slice(-5) }, 'ospos_price_lists', ''],
    ['revenue-category', 'revenue_categories', '[data-href*="revenue_categories/view"]', { category_name: 'Browser cat ' + Date.now().toString().slice(-5) }, 'ospos_revenue_categories', ''],
  ]) {
    const countBefore = Number(sql('SELECT COUNT(*) FROM ' + table));
    await page.goto(BASE + '/' + page_, { waitUntil: 'networkidle' });
    await page.click(selector);
    await page.waitForSelector('.modal.show form', { timeout: 10000 });
    for (const [name, value] of Object.entries(fields)) await page.fill('.modal.show [name=' + name + ']', value);
    const submit = await page.$('.modal.show button:has-text("Submit"), .modal.show #submit');
    await submit.click();
    await page.waitForTimeout(2500);
    const countAfter = Number(sql('SELECT COUNT(*) FROM ' + table));
    add('BR-new-' + id, 'create a new ' + id + ' through its dialog in the browser', 'one row saved', `${countAfter - countBefore} row(s)`, countAfter - countBefore === 1);
  }

  // double click on the submit of a dialog: an expense
  events = [];
  await page.goto(BASE + '/expenses', { waitUntil: 'networkidle' });
  const before = Number(sql('SELECT COUNT(*) FROM ospos_expenses'));
  await page.click('[data-href*="expenses/view"]');
  await page.waitForSelector('#expenses_edit_form, form[action*="expenses/save"]', { timeout: 10000 });
  await page.fill('input[name=amount]', '3.00');
  const btn = await page.$('button:has-text("Submit"), #submit');
  await btn.dblclick();
  await page.waitForTimeout(2500);
  const after = Number(sql('SELECT COUNT(*) FROM ospos_expenses'));
  add('BR-dblclick-expense', 'double click Submit on the new expense dialog', 'one expense', `${after - before} expense(s)`, after - before === 1);

  // double click on the new customer dialog
  await page.goto(BASE + '/customers', { waitUntil: 'networkidle' });
  const cb = Number(sql('SELECT COUNT(*) FROM ospos_customers'));
  await page.click('[data-href*="customers/view"]');
  await page.waitForSelector('form[action*="customers/save"]', { timeout: 10000 });
  await page.check('input[name=consent]').catch(() => {});
  await page.fill('input[name=first_name]', 'Dbl');
  await page.fill('input[name=last_name]', 'Click' + Date.now().toString().slice(-5));
  const cbtn = await page.$('button:has-text("Submit"), #submit');
  await cbtn.dblclick();
  await page.waitForTimeout(2500);
  const ca = Number(sql('SELECT COUNT(*) FROM ospos_customers'));
  add('BR-dblclick-customer', 'double click Submit on the new customer dialog', 'one customer', `${ca - cb} customer(s)`, ca - cb === 1);

  // sale in the browser then back
  events = [];
  await page.goto(BASE + '/sales', { waitUntil: 'networkidle' });
  await page.click('#cancel_sale_button').catch(() => {});
  await page.waitForTimeout(500);
  await page.goto(BASE + '/sales', { waitUntil: 'networkidle' });
  await page.fill('#item', 'R2-Q');
  await page.press('#item', 'Enter');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(600);
  const sb = Number(sql('SELECT COUNT(*) FROM ospos_sales'));
  await page.click('#add_payment_button');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(600);
  const fin = await page.$('#finish_sale_button');
  add('BR-finish-visible', 'the finish button appears after the payment is added', 'present', !!fin, !!fin);
  if (fin) {
    await fin.dblclick();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1500);
    const sa = Number(sql('SELECT COUNT(*) FROM ospos_sales'));
    add('BR-dblclick-finish', 'double click the finish sale button', 'one sale', `${sa - sb} sale(s); events ${events.slice(0, 2).join(' ; ')}`, sa - sb === 1);
    await page.screenshot({ path: SHOTS + '/br-receipt.png' });
    let backError = '';
    await page.goBack({ timeout: 8000 }).catch((e) => { backError = String(e.message).split('\n')[0]; });
    await page.waitForTimeout(1200);
    const url = page.url();
    const finBack = await page.$('#finish_sale_button');
    if (finBack) {
      await finBack.click().catch(() => {});
      await page.waitForTimeout(1500);
    }
    const sb2 = Number(sql('SELECT COUNT(*) FROM ospos_sales'));
    add('BR-back-finish', 'press the browser back button after the receipt and finish again', 'no second sale and the page comes back', `${sb2 - sb} sale(s) in total; page after back: ${url.replace(BASE, '')}${backError ? ' [' + backError + ']' : ''}`, sb2 - sb === 1 && !backError);
  }
  fs.writeFileSync(OUT, JSON.stringify({ results, seen }, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
