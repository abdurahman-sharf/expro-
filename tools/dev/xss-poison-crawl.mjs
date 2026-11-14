// Stored-XSS crawl: writes a hostile string into the descriptive text columns of a disposable fixture database, then
// walks every GET screen and JSON endpoint, loads the screens in Chromium and reports every place where the string reached
// the page unescaped, grew injected elements or ran as script. Rerunnable; it refuses a database whose name does not start
// with ospos_test_.
//
//   XSS_DB=ospos_test_x_ui XSS_BASE=http://127.0.0.1:8192 node tools/dev/xss-poison-crawl.mjs all --out build/xss
//
// Steps, each can run alone:
//   seed     extra master rows and documents with the hostile string for the tables the UI fixture leaves empty
//   poison   UPDATE the descriptive text columns and the identifier columns typed by users (cheque numbers, serials...)
//   crawl    every GET route over HTTP (raw string in HTML, raw string in JSON, an entity escaped twice) and then each
//            HTML screen in Chromium (page errors, dialogs, injected img/onerror/b elements in the final DOM);
//            --no-browser stops after the HTTP walk
//   refresh  the row/ID JSON of every table pushed into the table at desktop and phone widths, as after a save
//   edit     the first row of every list opened in its dialog and saved as it stands (answer message and row refresh)
//   portal   the screens of the customer portal, signed in as a portal account of a poisoned customer
//   all      the six steps above in order
//   literal  on a database that is NOT poisoned: ordinary names with an ampersand and quotes must show as typed in the
//            dropdowns and labels (--lang en or ar), never with a visible &amp;
//
// Take a mysqldump of the poisoned database and load it again before each run: the edit step saves records.
// The application must run against XSS_DB with allowedHostnames equal to the host and port of XSS_BASE (so the port
// is kept in redirects), CI_ENVIRONMENT=development and the built assets in public/assets. With php -S the router must
// end in `return require .../system/rewrite.php;`: without the return the built-in server answers every static file
// (the scripts) empty. See tools/ui-smoke/README.md for the rest of the server recipe. Playwright is resolved from
// NODE_PATH (the global modules) because tools/dev has no node_modules. XSS_USER and XSS_PASS default to the initial
// administrator of the UI fixture.
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';

const ROOT = new URL('../../', import.meta.url).pathname;
const require = createRequire(import.meta.url);

const DB = process.env.XSS_DB ?? '';
const BASE = (process.env.XSS_BASE ?? 'http://127.0.0.1:8092').replace(/\/+$/, '');
const USER = process.env.XSS_USER ?? 'admin';
const PASS = process.env.XSS_PASS ?? 'pointofsale';

const PAYLOAD_LONG = '"\'></script><img src=x onerror=zq9(1)>';
const PAYLOAD_SHORT = '<b zq9>';
const RAW_MARKER = /<img src=x onerror=zq9|<b zq9>/i;
const DOUBLE_ESCAPED = /&amp;(?:lt|gt|quot|amp|#0?39);/;

const argv = process.argv.slice(2);
const command = argv[0] ?? 'all';
const option = (name, fallback) => {
    const at = argv.indexOf(`--${name}`);
    return at >= 0 ? argv[at + 1] : fallback;
};
const OUT = resolve(option('out', 'build/xss-poison-crawl'));
mkdirSync(OUT, { recursive: true });

if (!/^ospos_test_/.test(DB)) {
    console.error('Set XSS_DB to a disposable database whose name starts with ospos_test_');
    process.exit(1);
}

const password = () => {
    const line = readFileSync(ROOT + '.env', 'utf8').split('\n').find((row) => row.startsWith('database.tests.password'));
    return line.split('=').slice(1).join('=').trim().replace(/^['"]|['"]$/g, '');
};

const sql = (query) => {
    const out = execFileSync('mysql', ['-h127.0.0.1', '-uadmin', '--default-character-set=utf8mb4', '-N', '-B', DB, '-e', query], {
        env: { ...process.env, MYSQL_PWD: password() },
        encoding: 'utf8',
        maxBuffer: 256 * 1024 * 1024
    });
    return out.trim() === '' ? [] : out.trimEnd().split('\n').map((row) => row.split('\t'));
};
const quote = (value) => `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

const SEED_ROWS = [
    ['ospos_branches', { company_id: 1, code: 'ZQ', name: PAYLOAD_LONG }],
    ['ospos_stock_locations', { location_name: PAYLOAD_LONG }],
    ['ospos_item_brands', { name: PAYLOAD_LONG }],
    ['ospos_customer_classifications', { name: PAYLOAD_LONG }],
    ['ospos_price_lists', { name: PAYLOAD_LONG }],
    ['ospos_roles', { name: PAYLOAD_LONG, description: PAYLOAD_LONG }],
    ['ospos_tax_codes', { tax_code: PAYLOAD_LONG }],
    ['ospos_tax_categories', { tax_category: PAYLOAD_LONG, tax_group_sequence: 1 }],
    ['ospos_tax_jurisdictions', { tax_group: PAYLOAD_LONG, tax_type: 1 }],
    ['ospos_tax_definitions', { tax_name: PAYLOAD_LONG }],
    ['ospos_asset_categories', { name: PAYLOAD_LONG }],
    ['ospos_shipping_companies', { name: PAYLOAD_LONG }],
    ['ospos_revenue_categories', { category_name: PAYLOAD_LONG }],
    ['ospos_attribute_definitions', { definition_name: PAYLOAD_LONG, definition_type: 'TEXT', definition_flags: 0 }],
    ['ospos_unit_templates', { name: PAYLOAD_LONG, main_unit_name: PAYLOAD_LONG, main_small_name: 'zq' }],
    ['ospos_item_kits', { name: PAYLOAD_LONG, description: PAYLOAD_LONG }],
    ['ospos_expense_categories', { category_description: PAYLOAD_LONG }],
    ['ospos_accounting_cost_centers', { code: 'ZQ9', name: PAYLOAD_LONG }],
    ['ospos_treasuries', { name: PAYLOAD_LONG }],
    ['ospos_dinner_tables', { name: PAYLOAD_LONG }],
        ['ospos_notification_rules', { kind: 'low_stock', name: PAYLOAD_LONG }]
];

const failure = (error) => String(error.stderr ?? error).split('\n').find((line) => line.startsWith('ERROR')) ?? String(error).slice(0, 120);

const fit = (table, column, value) => {
    const [row] = sql(`SELECT IFNULL(CHARACTER_MAXIMUM_LENGTH, 0) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='${DB}' AND TABLE_NAME='${table}' AND COLUMN_NAME='${column}'`);
    const length = Number(row?.[0] ?? 0);
    if (value !== PAYLOAD_LONG || length === 0 || length >= PAYLOAD_LONG.length) {
        return value;
    }
    return PAYLOAD_SHORT.slice(0, length);
};

const seedPeople = () => {
    for (const kind of ['employee', 'customer', 'supplier']) {
        const personId = sql(`INSERT INTO ospos_people (first_name, last_name, phone_number, email, address_1, address_2, city, state, zip, country, comments) VALUES (${quote(PAYLOAD_LONG)}, ${quote(PAYLOAD_LONG)}, '0', ${quote('zq9@example.test')}, ${quote(PAYLOAD_LONG)}, '', ${quote(PAYLOAD_LONG)}, '', '', '', ${quote(PAYLOAD_LONG)}); SELECT LAST_INSERT_ID()`)[0][0];

        const statement = {
            employee: `INSERT INTO ospos_employees (username, password, person_id) VALUES ('zq9emp', 'x', ${personId})`,
            customer: `INSERT INTO ospos_customers (person_id, employee_id, company_name, account_number) VALUES (${personId}, 1, ${quote(PAYLOAD_LONG)}, 'ZQ9C')`,
            supplier: `INSERT INTO ospos_suppliers (person_id, company_name, agency_name, category, account_number) VALUES (${personId}, ${quote(PAYLOAD_LONG)}, ${quote(PAYLOAD_LONG)}, 0, 'ZQ9S')`
        }[kind];
        try {
            sql(statement);
        } catch (error) {
            console.log(`  seed ${kind} failed: ${failure(error)}`);
        }
    }
};

const SEED_STATEMENTS = [
    `INSERT INTO ospos_cash_up (open_amount_cash, transfer_amount_cash, closed_amount_cash, closed_amount_card, closed_amount_check, closed_amount_total, description, open_employee_id, close_employee_id, closed_amount_due) VALUES (0, 0, 0, 0, 0, 0, ${quote(PAYLOAD_LONG)}, 1, 1, 0)`,
    `INSERT INTO ospos_cheque_books (treasury_id, name, first_serial, last_serial) SELECT MIN(treasury_id), ${quote(PAYLOAD_LONG)}, 1, 10 FROM ospos_treasuries`,
    `INSERT INTO ospos_cheques (direction, status, party_id, treasury_id, cheque_number, amount, issue_date, due_date, drawer_name, endorsed_to, description, status_note) SELECT 'received', 'received', (SELECT MIN(person_id) FROM ospos_customers), MIN(treasury_id), ${quote(PAYLOAD_LONG)}, 10, '2026-10-01', '2026-11-01', ${quote(PAYLOAD_LONG)}, ${quote(PAYLOAD_LONG)}, ${quote(PAYLOAD_LONG)}, ${quote(PAYLOAD_LONG)} FROM ospos_treasuries`,
    `INSERT INTO ospos_custom_fields (entity, name, field_type) VALUES ('item', ${quote(PAYLOAD_LONG)}, 'text')`,
    `INSERT INTO ospos_revenues (date, amount, payment_type, description) VALUES ('2026-10-01', 5, 'cash', ${quote(PAYLOAD_LONG)})`,
    `INSERT INTO ospos_sales_orders (customer_id, order_date, note) SELECT MIN(person_id), '2026-10-01', ${quote(PAYLOAD_LONG)} FROM ospos_customers`,
    `INSERT INTO ospos_purchase_orders (location_id, supplier_id, document_date, note) SELECT MIN(location_id), (SELECT MIN(person_id) FROM ospos_suppliers), '2026-10-01', ${quote(PAYLOAD_LONG)} FROM ospos_stock_locations`,
    `INSERT INTO ospos_stocktakes (location_id, count_date, note) SELECT MIN(location_id), '2026-10-01', ${quote(PAYLOAD_LONG)} FROM ospos_stock_locations`
];

const seed = () => {
    seedPeople();
    for (const [table, row] of SEED_ROWS) {
        const columns = Object.keys(row);
        const values = columns.map((column) => (typeof row[column] === 'number' ? row[column] : quote(fit(table, column, row[column]))));
        try {
            sql(`SET FOREIGN_KEY_CHECKS=0; INSERT INTO ${table} (${columns.map((column) => `\`${column}\``).join(', ')}) VALUES (${values.join(', ')})`);
        } catch (error) {
            console.log(`  seed ${table} failed: ${failure(error)}`);
        }
    }
    for (const statement of SEED_STATEMENTS) {
        try {
            sql(`SET FOREIGN_KEY_CHECKS=0; ${statement}`);
        } catch (error) {
            console.log(`  seed failed: ${statement.slice(0, 60)} ${failure(error)}`);
        }
    }
    console.log('seed done');
};

const SKIP = /(password|hash|token|secret|status|type|mode|kind|state|currency|locale|language|lang|permission|module|menu_group|method|format|unit|direction|scope|origin|series|doc_type|entity|source|payload|json|details|serial|barcode|number|code|key|color|colour|icon|theme|timezone|slug|path|file|mime|sha|checksum|prefix|flag|enabled|active|name_key|label_key|class|view|sql|query|ip|ua|agent)/i;
const WANT = /(name|description|comment|note|address|city|state|zip|country|phone|email|company|reference|reason|title|label|memo|remark|text|supplier|brand|category|location|website|policy|message|body|subject|bank|drawer|party|holder|branch)/i;
const FORCE = /(name|description|comment|note|address|company)/i;
const SKIP_TABLE = /(migrations|sessions|_grants|_modules|_permissions|app_config|audit_log|posting_failures)$/;
const CONFIG_KEYS = ['company', 'address', 'phone', 'email', 'fax', 'website', 'return_policy', 'invoice_default_comments', 'quote_default_comments', 'payment_message', 'print_header', 'print_footer', 'default_tax_1_name', 'default_tax_2_name', 'invoice_email_message', 'tax_id'];

const IDENTIFIERS = [
    'items.item_number', 'items.supplier_code', 'items.hsn_code', 'item_kits.item_kit_number', 'customers.account_number', 'customers.tax_id', 'customers.area',
    'suppliers.account_number', 'suppliers.tax_id', 'suppliers.commercial_registry', 'giftcards.giftcard_number', 'people.phone_number', 'people.state', 'people.zip',
    'sales.invoice_number', 'sales.quote_number', 'sales.work_order_number', 'sales_payments.reference_code', 'receivings_payments.reference_code',
    'customer_advances.reference_code', 'supplier_advances.reference_code', 'cheques.cheque_number', 'cheques.endorsed_to', 'sales_items.serialnumber',
    'receivings_items.serialnumber', 'attribute_values.attribute_value', 'custom_field_values.field_value', 'treasuries.account_number', 'treasuries.bank_code',
    'stock_batches.batch_number', 'stock_serials.serial_number', 'party_contacts.position', 'party_contacts.mobile', 'assets.code', 'asset_units.serial_number',
    'asset_units.used_by', 'asset_units.barcode', 'tax_codes.tax_code', 'tax_codes.state', 'tax_jurisdictions.tax_group', 'tax_jurisdictions.reporting_authority',
    'sales_taxes.tax_group', 'accounting_cost_centers.code', 'accounting_accounts.code', 'branches.code', 'branches.tax_registration', 'companies.tax_id',
    'customer_einvoice_parties.street', 'customer_einvoice_parties.district', 'customer_einvoice_parties.province', 'customer_einvoice_parties.postal_code',
    'customer_einvoice_parties.building_number', 'customer_einvoice_parties.additional_street', 'customer_einvoice_parties.identification_number',
    'accounting_journals.number', 'accounting_posting_failures.message', 'document_numbers.number', 'portal_accounts.username'
];

const poisonColumn = (table, column, length, touched) => {
    const payload = length === 0 || length >= 40 ? PAYLOAD_LONG : PAYLOAD_SHORT;
    const cap = length === 0 ? 65535 : length;
    try {
        sql(`SET @n = 0; UPDATE \`${table}\` SET \`${column}\` = LEFT(CONCAT(${quote(payload)}, @n := @n + 1), ${cap}) WHERE \`${column}\` IS NOT NULL AND \`${column}\` <> ''`);
        touched.push(`${table}.${column}`);
    } catch (error) {
        console.log(`  poison ${table}.${column} failed: ${failure(error)}`);
    }
};

const poison = () => {
    const columns = sql(`SELECT TABLE_NAME, COLUMN_NAME, IFNULL(CHARACTER_MAXIMUM_LENGTH, 0), COLUMN_KEY, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='${DB}' AND DATA_TYPE IN ('varchar','char','text','tinytext','mediumtext','longtext') ORDER BY TABLE_NAME, ORDINAL_POSITION`);
    const touched = [];
    const identifiers = new Set(IDENTIFIERS.map((entry) => `ospos_${entry}`));
    for (const [table, column, rawLength, key = '', extra = ''] of columns) {
        const length = Number(rawLength);
        const identifier = identifiers.has(`${table}.${column}`);
        if (SKIP_TABLE.test(table) || key === 'PRI' || /GENERATED/i.test(extra)) {
            continue;
        }
        if (!identifier) {
            if (!WANT.test(column) || (SKIP.test(column.replace('name', 'NAME')) && !FORCE.test(column))) {
                continue;
            }
            if (/(password|hash|token|secret|username|json|payload|details|status|type)$/i.test(column)) {
                continue;
            }
        }
        poisonColumn(table, column, length, touched);
    }
    sql(`UPDATE ospos_app_config SET value = ${quote(PAYLOAD_LONG)} WHERE \`key\` IN (${CONFIG_KEYS.map(quote).join(',')})`);
    writeFileSync(`${OUT}/poisoned-columns.txt`, touched.join('\n') + '\n');
    console.log(`${touched.length} columns poisoned`);
};

const cookieJar = () => {
    const cookies = {};
    const absorb = (response) => {
        for (const line of response.headers.getSetCookie?.() ?? []) {
            const [pair] = line.split(';');
            const [name, ...rest] = pair.split('=');
            cookies[name.trim()] = rest.join('=');
        }
    };
    return {
        header: () => Object.entries(cookies).map(([name, value]) => `${name}=${value}`).join('; '),
        absorb
    };
};

const login = async () => {
    const jar = cookieJar();
    const response = await fetch(`${BASE}/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `username=${USER}&password=${PASS}`,
        redirect: 'manual'
    });
    jar.absorb(response);
    if (response.status !== 302 && response.status !== 303) {
        throw new Error(`login returned ${response.status}`);
    }
    return jar;
};

const routes = () => {
    const out = execFileSync('php', ['spark', 'routes'], { cwd: ROOT, env: { ...process.env, CI_ENVIRONMENT: 'development', MYSQL_DB_NAME: DB }, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    const urls = [];
    for (const line of out.split('\n')) {
        const match = line.match(/^\|\s*(GET|GET\(auto\))\s*\|\s*(\S+)\s*\|/);
        if (!match) {
            continue;
        }
        let route = match[2];
        if (/^(portal|api\/|login|migrate|__hot)/.test(route) || route.includes('logout')) {
            continue;
        }
        let candidates = [route];
        if (route.includes('[/..]')) {
            candidates = [route.replace('[/..]', ''), route.replace('[/..]', '/1')];
        }
        for (let candidate of candidates) {
            candidate = candidate.replace('/..', '/1');
            const expanded = candidate.includes('([0-9]+)') ? ['1', '2'].map((id) => candidate.replace('([0-9]+)', id)) : [candidate];
            for (let url of expanded) {
                url = url.replace(/\(\[\^\/\]\+\)/g, '1').replace(/\(\.\*\)/g, '1').replace('/1/1/1/1/1', '/1');
                urls.push(url);
            }
        }
    }
    return [...new Set(urls)];
};

const stripScripts = (html) => html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');

const httpWalk = async (jar) => {
    const results = [];
    const only = option('only') ? new RegExp(option('only')) : null;
    for (const url of routes().filter((candidate) => !only || only.test(candidate))) {
        for (const query of ['', '?limit=100&offset=0&search=&order=asc']) {
            const target = `${BASE}/${url.replace(/^\//, '')}${query}`;
            try {
                const response = await fetch(target, { headers: { Cookie: jar.header(), 'X-Requested-With': 'XMLHttpRequest' }, redirect: 'manual', signal: AbortSignal.timeout(60000) });
                const body = await response.text();
                const type = (response.headers.get('content-type') ?? '').split(';')[0];
                let hit = null;
                if (type.includes('json') && RAW_MARKER.test(body)) {
                    hit = 'json-raw';
                } else if (type.includes('html') && RAW_MARKER.test(stripScripts(body))) {
                    hit = 'html-raw';
                }
                const doubled = type.includes('html') && DOUBLE_ESCAPED.test(stripScripts(body));
                results.push({ url: target, path: `/${url.replace(/^\//, '')}`, query, status: response.status, type, length: body.length, hit, doubled });
            } catch (error) {
                results.push({ url: target, path: `/${url.replace(/^\//, '')}`, query, status: `EXC ${String(error).slice(0, 80)}`, hit: null });
            }
        }
    }
    return results;
};

const launch = async () => {
    const { chromium } = require('playwright');
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    return browser;
};

const signedInPage = async (browser, viewport) => {
    const context = await browser.newContext({ viewport, locale: 'en-US' });
    const page = await context.newPage();
    const events = [];
    page.on('pageerror', (error) => events.push(`pageerror: ${String(error.message ?? error)}`));
    page.on('console', (message) => {
        if (message.type() === 'error') {
            events.push(`console: ${message.text()}`);
        }
    });
    page.on('dialog', async (dialog) => {
        events.push(`dialog: ${dialog.message()}`);
        await dialog.dismiss().catch(() => {});
    });
    await page.goto(`${BASE}/login`);
    await page.fill('input[name=username]', USER);
    await page.fill('input[name=password]', PASS);
    await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login')), page.click('button[type=submit]')]);
    return { page, context, events };
};

const injectedElements = (page) => page.evaluate(() => ({
    elements: document.querySelectorAll('img[src="x"], [onerror], b[zq9]').length,
    literal: document.body ? document.body.innerText.includes('<img src=x onerror=zq9(1)>') : false
}));

const executed = (events) => events.filter((event) => /zq9/i.test(event) || event.startsWith('dialog:'));

const pageWalk = async (urls) => {
    const browser = await launch();
    const { page, events } = await signedInPage(browser, { width: 1440, height: 900 });
    const findings = [];
    let literalPages = 0;
    for (const path of urls) {
        events.length = 0;
        await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 45000 }).catch(() => {});
        await page.waitForTimeout(300);
        const dom = await injectedElements(page).catch(() => ({ elements: 0, literal: false }));
        if (dom.literal) {
            literalPages += 1;
        }
        const ran = executed(events);
        if (ran.length || dom.elements) {
            findings.push({ path, ran: ran.slice(0, 2), injectedElements: dom.elements });
        }
    }
    await browser.close();
    return { findings, literalPages, pages: urls.length };
};

const crawl = async () => {
    const jar = await login();
    const walk = await httpWalk(jar);
    writeFileSync(`${OUT}/http-walk.json`, JSON.stringify(walk, null, 1));
    const htmlPages = [...new Set(walk.filter((row) => row.status === 200 && row.type.includes('html') && row.query === '').map((row) => row.path))];
    const raw = walk.filter((row) => row.hit);
    const statuses = {};
    for (const row of walk) {
        statuses[row.status] = (statuses[row.status] ?? 0) + 1;
    }
    console.log(`http: ${walk.length} requests, statuses ${JSON.stringify(statuses)}`);
    console.log(`http: ${raw.filter((row) => row.hit === 'html-raw').length} html responses carry the hostile string unescaped`);
    console.log(`http: ${raw.filter((row) => row.hit === 'json-raw').length} json responses carry the hostile string as raw text`);
    console.log(`http: ${walk.filter((row) => row.doubled && row.query === '').length} html responses show an escaped entity escaped a second time`);
    writeFileSync(`${OUT}/double-escaped.txt`, walk.filter((row) => row.doubled && row.query === '').map((row) => row.url).join('\n') + '\n');
    writeFileSync(`${OUT}/html-raw.txt`, raw.filter((row) => row.hit === 'html-raw').map((row) => row.url).join('\n') + '\n');
    writeFileSync(`${OUT}/json-raw.txt`, [...new Set(raw.filter((row) => row.hit === 'json-raw').map((row) => row.path))].join('\n') + '\n');
    if (argv.includes('--no-browser')) {
        return { walk };
    }
    const pages = await pageWalk(htmlPages);
    writeFileSync(`${OUT}/browser-pages.json`, JSON.stringify(pages, null, 1));
    console.log(`browser: ${pages.pages} screens loaded, ${pages.findings.length} ran script or grew injected elements, ${pages.literalPages} show the hostile text literally`);
    for (const finding of pages.findings) {
        console.log(`  EXECUTED ${finding.path} ${finding.ran[0] ?? ''} elements=${finding.injectedElements}`);
    }
    return { walk, pages };
};

const tablePagesOf = async () => {
    const jar = await login();
    const walk = JSON.parse(readFileSync(`${OUT}/http-walk.json`, 'utf8'));
    const pages = [];
    for (const row of walk.filter((entry) => entry.status === 200 && entry.type.includes('html') && entry.query === '')) {
        const response = await fetch(row.url, { headers: { Cookie: jar.header() }, redirect: 'manual' });
        if ((await response.text()).includes('id="table"')) {
            pages.push(row.path);
        }
    }
    return pages;
};

const refreshWalk = async () => {
    const tablePages = await tablePagesOf();
    const browser = await launch();
    const report = [];
    for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
        const { page, events } = await signedInPage(browser, viewport);
        for (const path of tablePages) {
            events.length = 0;
            await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 45000 }).catch(() => {});
            await page.waitForTimeout(400);
            const pushed = await page.evaluate(async () => {
                const table = window.jQuery && window.jQuery('#table').data('bootstrap.table');
                if (!table) {
                    return { rows: 0 };
                }
                const resource = table.options.resource;
                const uniqueId = table.options.uniqueId || 'id';
                let rows = 0;
                for (const data of table.getData()) {
                    const id = data[uniqueId];
                    const reply = await fetch(`${resource}/row/${id}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then((response) => response.json()).catch(() => null);
                    if (reply) {
                        table.updateByUniqueId({ id, row: reply[id] || reply });
                        rows += 1;
                    }
                }
                return { rows };
            }).catch(() => ({ rows: 0 }));
            await page.waitForTimeout(300);
            const dom = await injectedElements(page).catch(() => ({ elements: 0, literal: false }));
            const ran = executed(events);
            report.push({ path, width: viewport.width, refreshedRows: pushed.rows, ran: ran.slice(0, 2), injectedElements: dom.elements, literal: dom.literal });
        }
    }
    await browser.close();
    writeFileSync(`${OUT}/refresh.json`, JSON.stringify(report, null, 1));
    const bad = report.filter((row) => row.ran.length || row.injectedElements);
    console.log(`refresh: ${report.length} table screens x widths, ${report.filter((row) => row.refreshedRows > 0).length} refreshed at least one row, ${bad.length} ran script or grew injected elements`);
    for (const row of bad) {
        console.log(`  EXECUTED ${row.path} @${row.width} ${row.ran[0] ?? ''} elements=${row.injectedElements}`);
    }
    return report;
};

const editFlows = async () => {
    const tablePages = await tablePagesOf();
    const browser = await launch();
    const { page, events } = await signedInPage(browser, { width: 1440, height: 900 });
    const outcome = [];
    for (const path of tablePages) {
        events.length = 0;
        await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 45000 }).catch(() => {});
        await page.waitForTimeout(400);
        const link = page.locator('#table tbody a.modal-dlg[href*="/view"]').first();
        if ((await link.count()) === 0) {
            continue;
        }
        let submitted = false;
        let notice = '';
        try {
            await link.click({ timeout: 5000 });
            await page.waitForSelector('.modal.show form', { timeout: 8000 });
            await page.waitForTimeout(400);
            await page.click('.modal.show .modal-footer [data-dialog-button="submit"]', { timeout: 3000 });
            submitted = true;
            await page.waitForTimeout(2500);
            notice = await page.evaluate(() => [...document.querySelectorAll('[data-notify="container"], .app-toast')].map((node) => node.innerText).join(' | '));
        } catch (error) {
            notice = `flow stopped: ${String(error.message ?? error).split('\n')[0]}`;
        }
        const dom = await injectedElements(page).catch(() => ({ elements: 0, literal: false }));
        const ran = executed(events);
        outcome.push({ path, submitted, ran: ran.slice(0, 2), injectedElements: dom.elements, notice: notice.slice(0, 160) });
        await page.keyboard.press('Escape').catch(() => {});
    }
    await browser.close();
    writeFileSync(`${OUT}/edit.json`, JSON.stringify(outcome, null, 1));
    const bad = outcome.filter((row) => row.ran.length || row.injectedElements);
    console.log(`edit: ${outcome.length} edit dialogs opened, ${outcome.filter((row) => row.submitted).length} submitted, ${bad.length} ran script or grew injected elements`);
    for (const row of bad) {
        console.log(`  EXECUTED ${row.path} ${row.ran[0] ?? ''} elements=${row.injectedElements}`);
    }
    return outcome;
};

const portalWalk = async () => {
    const hash = execFileSync('php', ['-r', 'echo password_hash("Zq9-portal-pass", PASSWORD_DEFAULT);'], { encoding: 'utf8' });
    const customerId = sql('SELECT customer_id FROM ospos_sales WHERE customer_id IS NOT NULL ORDER BY sale_id DESC LIMIT 1')[0]?.[0] ?? sql('SELECT person_id FROM ospos_customers ORDER BY person_id LIMIT 1')[0][0];
    sql("REPLACE INTO ospos_app_config (`key`, value) VALUES ('portal_enabled', '1')");
    sql(`DELETE FROM ospos_portal_accounts WHERE username = 'zq9portal'; INSERT INTO ospos_portal_accounts (customer_id, username, password_hash, must_change, status) VALUES (${customerId}, 'zq9portal', ${quote(hash)}, 0, 'active')`);
    const browser = await launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
    const page = await context.newPage();
    const events = [];
    page.on('pageerror', (error) => events.push(`pageerror: ${String(error.message ?? error)}`));
    page.on('dialog', async (dialog) => {
        events.push(`dialog: ${dialog.message()}`);
        await dialog.dismiss().catch(() => {});
    });
    await page.goto(`${BASE}/portal/login`);
    await page.fill('input[name=username]', 'zq9portal');
    await page.fill('input[name=password]', 'Zq9-portal-pass');
    await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/portal/login')), page.click('button[type=submit]')]);
    const paths = ['/portal', '/portal/statement', '/portal/invoices', '/portal/credit_notes', '/portal/payments', '/portal/password'];
    const seen = new Set(paths);
    const report = [];
    for (let index = 0; index < paths.length; index++) {
        const path = paths[index];
        events.length = 0;
        const response = await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 45000 }).catch(() => null);
        await page.waitForTimeout(300);
        const html = stripScripts(await page.content());
        const links = await page.evaluate(() => [...document.querySelectorAll('a[href*="/portal/"]')].map((anchor) => new URL(anchor.href).pathname));
        for (const link of links) {
            if (/\/portal\/(invoice|credit_note|payment)\/\d+$/.test(link) && !seen.has(link)) {
                seen.add(link);
                paths.push(link);
            }
        }
        const dom = await injectedElements(page).catch(() => ({ elements: 0, literal: false }));
        report.push({ path, status: response?.status() ?? 0, ran: executed(events).slice(0, 2), injectedElements: dom.elements, literal: dom.literal, rawInSource: RAW_MARKER.test(html) });
    }
    await browser.close();
    writeFileSync(`${OUT}/portal.json`, JSON.stringify(report, null, 1));
    const bad = report.filter((row) => row.ran.length || row.injectedElements || row.rawInSource);
    console.log(`portal: ${report.length} screens of the customer portal, ${report.filter((row) => row.status === 200).length} answered 200, ${report.filter((row) => row.literal).length} show the hostile text literally, ${bad.length} ran script or carry it unescaped`);
    for (const row of bad) {
        console.log(`  EXECUTED ${row.path} ${row.ran[0] ?? ''} elements=${row.injectedElements}`);
    }
    return report;
};

const LITERAL_NAME = 'Tom & Jerry\'s "Café" مرحبا';

const LITERAL_SCREENS = [
    ['/items/inventory', '#stock_location option'],
    ['/items/view', 'label[for="quantity_1"]'],
    ['/api_keys', 'select[name="branch_id"] option'],
    ['/cashups/view', '#open_employee_id option'],
    ['/reports/specific_suppliers', '#specific_input_data option'],
    ['/reports/specific_customers', '#specific_input_data option'],
    ['/reports/inventory_summary', '#location_id option'],
    ['/accounting_receivables/advance_form/2', '#treasury_id option'],
    ['/taxes/view', '#rate_tax_category_id option'],
    ['/expenses/view', '#category option'],
    ['/expenses/view', '#expense_branch_id option']
];

const LANGUAGE_ROWS = { en: ['en', 'english'], ar: ['ar-EG', 'arabic'] };

const literalProbe = async () => {
    const language = option('lang', 'en');
    const name = quote(LITERAL_NAME);
    const original = sql("SELECT IFNULL(language_code, '<null>'), IFNULL(language, '<null>') FROM ospos_employees WHERE person_id = 1")[0];
    sql(`UPDATE ospos_employees SET language_code = ${quote(LANGUAGE_ROWS[language][0])}, language = ${quote(LANGUAGE_ROWS[language][1])} WHERE person_id = 1`);
    sql(`UPDATE ospos_stock_locations SET location_name = ${name}; UPDATE ospos_branches SET name = ${name}; UPDATE ospos_people SET first_name = ${name}, last_name = ${name}; UPDATE ospos_suppliers SET company_name = ${name}; UPDATE ospos_customers SET company_name = ${name}; DELETE FROM ospos_tax_categories; INSERT INTO ospos_tax_categories (tax_category, tax_group_sequence) VALUES (LEFT(${name}, 32), 1)`);
    sql(`SET @c = 0; UPDATE ospos_expense_categories SET category_name = CONCAT(${name}, ' ', @c := @c + 1), category_description = ${name}`);
    sql(`SET @n = 0; UPDATE ospos_treasuries SET name = CONCAT(${name}, ' ', @n := @n + 1)`);
    const browser = await launch();
    const { page } = await signedInPage(browser, { width: 1440, height: 900 });
    const rows = [];
    for (const [path, selector] of LITERAL_SCREENS) {
        await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 45000 }).catch(() => {});
        const texts = await page.$$eval(selector, (nodes) => nodes.map((node) => node.textContent)).catch(() => []);
        const shown = texts.some((text) => text.includes(LITERAL_NAME));
        const doubled = texts.some((text) => /&(amp|lt|gt|quot|#0?39);/.test(text));
        rows.push({ path, selector, options: texts.length, shownLiterally: shown, doubleEscaped: doubled });
    }
    await browser.close();
    const restore = (value) => (value === '<null>' ? 'NULL' : quote(value));
    sql(`UPDATE ospos_employees SET language_code = ${restore(original[0])}, language = ${restore(original[1])} WHERE person_id = 1`);
    writeFileSync(`${OUT}/literal-${language}.json`, JSON.stringify(rows, null, 1));
    for (const row of rows) {
        console.log(`literal ${language} ${row.shownLiterally && !row.doubleEscaped ? 'OK  ' : 'FAIL'} ${row.path} ${row.selector} (${row.options})`);
    }
    return rows;
};

const commands = { seed, poison, crawl, refresh: refreshWalk, edit: editFlows, portal: portalWalk, literal: literalProbe };
if (command === 'all') {
    seed();
    poison();
    await crawl();
    await refreshWalk();
    await editFlows();
    await portalWalk();
} else if (commands[command]) {
    await commands[command]();
} else {
    console.error(`Unknown command ${command}; use seed, poison, crawl, refresh, edit, portal or all`);
    process.exit(1);
}
