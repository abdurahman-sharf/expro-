import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { loadConfig } from './lib/env.mjs';
import { launchBrowser, newSession, settle } from './lib/session.mjs';

// An employee limited to one branch works the closing and opening balance screens in a real
// browser, and nothing of the other branch may reach them. Run it on a database prepared by
// `npm run setup`: it needs the head office (HQ) with posted opening balances and a second branch
// (JED) with an open start date. It creates one employee and changes closings, then puts the
// closings back as it found them.

const config = loadConfig(process.argv.slice(2));
const PASSWORD = 'Jed-Branch-2026!';
const USERNAME = `jed.accountant.${Date.now().toString(36)}`;
const HQ_CLOSING = '2026-10-05';
const JED_CLOSING = '2026-10-10';
const results = [];
const bodies = [];

mkdirSync(config.outDir, { recursive: true });

const check = (name, ok, detail = '') => {
    results.push({ name, ok: Boolean(ok), detail });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`);
};

const shot = (page, name) => page.screenshot({ path: join(config.outDir, `${name}.png`), fullPage: true });

const json = async (page, path) => {
    const response = await page.request.get(`${config.baseUrl}/${path}`);
    return { status: response.status(), body: await response.json().catch(() => null) };
};

const status = async (page, path) => (await page.request.get(`${config.baseUrl}/${path}`)).status();

const post = (page, path, data) => page.evaluate(
    ({ target, body }) => new Promise((resolve) => {
        $.ajax({ type: 'POST', url: target, data: body, dataType: 'text' })
            .always((first, state, second) => {
                const request = state === 'success' ? second : first;
                const text = state === 'success' ? first : request.responseText;
                let parsed = null;
                try { parsed = JSON.parse(text); } catch { /* not JSON */ }
                resolve({ status: request.status, json: parsed });
            });
    }),
    { target: path, body: data }
);

const visibleText = (page) => page.evaluate(() => document.body.innerText);

const collect = (page) => page.on('response', async (response) => {
    const type = response.headers()['content-type'] ?? '';
    if (!response.url().startsWith(config.baseUrl) || !/json|html/.test(type)) {
        return;
    }
    try {
        bodies.push({ url: response.url(), text: await response.text() });
    } catch { /* redirects and aborted requests have no body */ }
});

const adminCloseOrReopen = async (page, action, scope, extra) => {
    const result = await post(page, `accounting_periods/closings/${action}`, new URLSearchParams({ scope, ...extra }).toString());
    if (!result.json?.success) {
        throw new Error(`${action} ${scope} failed: ${result.json?.message ?? result.status}`);
    }
};

const browser = await launchBrowser(config);
let failed = false;

try {
    const admin = await newSession(browser, config, { width: 1440, height: 900 });
    const adminPage = await admin.openPage();
    await adminPage.goto(`${config.baseUrl}/accounting_periods/closings`, { waitUntil: 'networkidle' });

    const branches = (await json(adminPage, 'branches/search')).body.rows;
    const hq = branches.find((row) => row.code === 'HQ');
    const jed = branches.find((row) => row.code === 'JED');
    const hqName = hq.name.replace(/<[^>]*>/g, '').replace(/\s*Default\s*$/, '').trim();

    const markers = [
        { label: 'the head office code', test: (text) => /\bHQ\b/.test(text) },
        { label: 'the head office name', test: (text) => text.includes(hqName) },
        { label: 'the head office opening balance 25,000', test: (text) => /25,?000(\.00)?/.test(text) },
        { label: 'the head office closing date', test: (text) => ['2026-10-05', '10/05/2026', '05/10/2026'].some((date) => text.includes(date)) },
        { label: 'a head office journal description', test: (text) => ['إيداع نقدي في الصندوق', 'قيد بمبلغ خاطئ', 'مسودة قيد قيد الإعداد', 'رصيد الصندوق الافتتاحي'].some((phrase) => text.includes(phrase)) }
    ];

    const noLeak = (name, text) => {
        const leaked = markers.filter((marker) => marker.test(text)).map((marker) => marker.label);
        check(`${name}: nothing of the other branch`, leaked.length === 0, leaked.join('; '));
    };

    // The administrator prepares the scene: the company reopens so the new branch can post its
    // opening balances on the start date, and the head office closes on its own.
    const companyClosing = (await json(adminPage, 'accounting_periods/closings/search?scope=company&status=active')).body.rows[0];
    const companyCutoff = companyClosing ? companyClosing.date_to.replace(/<[^>]*>/g, '') : null;
    if (companyClosing) {
        await adminCloseOrReopen(adminPage, 'reopen', 'company', { reason: 'Prepare the branch scope check', cutoff: '2026-09-30' });
    }
    await adminCloseOrReopen(adminPage, 'close', String(hq.branch_id), { date: HQ_CLOSING, note: 'Head office only' });

    // The explicit way to give the sensitive permissions: tick them one by one on the employee form.
    await adminPage.goto(`${config.baseUrl}/employees`, { waitUntil: 'networkidle' });
    await adminPage.click('[data-href="employees/view"]');
    await adminPage.waitForSelector('#employee_form', { timeout: 15000 });

    const sensitiveIds = ['acc_settings_manage', 'acc_periods_close', 'acc_periods_reopen', 'acc_opening_manage'];
    const sensitiveState = () => adminPage.$$eval('.sensitive-permission', (nodes) => nodes.map((node) => ({ id: node.id, checked: node.checked, disabled: node.disabled })));

    await adminPage.click('a[href="#employee_permission_info"]');
    const before = await sensitiveState();
    check('employee form: the four sensitive permissions are marked', before.length === 4 && sensitiveIds.every((id) => before.some((item) => item.id === `grant_${id}`)));
    check('employee form: they start unticked', before.every((item) => !item.checked));
    check('employee form: each carries the Sensitive badge', (await adminPage.locator('.perm-subs .badge.text-bg-warning').count()) === 4);

    await adminPage.check('#permission_select_all');
    check('employee form: Select all does not tick a sensitive permission', (await sensitiveState()).every((item) => !item.checked));
    await adminPage.uncheck('#permission_select_all');
    check('employee form: Select all off leaves nothing ticked', (await adminPage.$$eval('#permission_list input[type=checkbox]:checked', (nodes) => nodes.length)) === 0);

    await adminPage.click('a[href="#employee_basic_info"]');
    await adminPage.fill('#employee_form #first_name', 'Jeddah');
    await adminPage.fill('#employee_form #last_name', 'Accountant');
    await adminPage.fill('#employee_form #email', `${USERNAME}@example.test`);
    await adminPage.click('a[href="#employee_login_info"]');
    await adminPage.fill('#employee_form #username', USERNAME);
    await adminPage.fill('#employee_form #password', PASSWORD);
    await adminPage.fill('#employee_form #repeat_password', PASSWORD);
    await adminPage.selectOption('#employee_form #language', 'ar-EG:arabic');

    await adminPage.click('a[href="#employee_permission_info"]');
    for (const module of ['home', 'accounting_periods', 'accounting_journals']) {
        await adminPage.check(`#grant_${module}`);
    }
    await adminPage.check('#grant_acc_journals_all');
    for (const id of ['acc_periods_close', 'acc_periods_reopen', 'acc_opening_manage']) {
        await adminPage.check(`#grant_${id}`);
    }
    const afterTicking = await sensitiveState();
    check('employee form: ticking one by one works, and settings management stays off', afterTicking.filter((item) => item.checked).length === 3 && !afterTicking.find((item) => item.id === 'grant_acc_settings_manage').checked);
    await shot(adminPage, '01-employee-permissions');

    await adminPage.click('a[href="#employee_branch_info"]');
    await adminPage.check('#branch_scope_selected');
    check('employee form: a new employee starts with the starting branch ticked', await adminPage.isChecked(`#branch_${hq.branch_id}`));
    await adminPage.check(`#branch_${jed.branch_id}`);
    await adminPage.uncheck(`#branch_${hq.branch_id}`);
    await adminPage.check(`#default_branch_${jed.branch_id}`);
    await shot(adminPage, '02-employee-branches');

    const [saved] = await Promise.all([
        adminPage.waitForResponse((response) => response.url().includes('employees/save')),
        adminPage.click('.modal button#submit')
    ]);
    const savedBody = await saved.json();
    check('employee saved with the Jeddah branch only', savedBody.success === true, savedBody.message);

    const holders = await adminPage.request.get(`${config.baseUrl}/accounting_settings`);
    const holdersHtml = await holders.text();
    check('settings screen lists the new holder with the three permissions', holdersHtml.includes(USERNAME) && holdersHtml.includes('sensitive_holders'));

    // The branch employee works the screens.
    const jedSession = await newSession(browser, { ...config, user: USERNAME, password: PASSWORD }, { width: 1440, height: 900 });
    const page = await jedSession.openPage();
    const consoleErrors = [];
    page.on('console', (message) => message.type() === 'error' && consoleErrors.push(message.text()));
    page.on('pageerror', (error) => consoleErrors.push(String(error)));
    collect(page);

    // --- Closings ---
    await page.goto(`${config.baseUrl}/accounting_periods/closings`, { waitUntil: 'networkidle' });
    const chips = await page.$$eval('.acc-closing-chip', (nodes) => nodes.map((node) => node.innerText.replace(/\s+/g, ' ').trim()));
    check('closings: the branch employee sees the company and their own branch only', chips.length === 2 && chips.some((chip) => chip.includes('JED')) && !chips.some((chip) => /\bHQ\b/.test(chip)), chips.join(' | '));
    check('closings: no button closes or reopens the company', (await page.locator('[data-href*="scope=company"]').count()) === 0);
    check('closings: the branch can be closed', (await page.locator(`[data-href*="close_form?scope=${jed.branch_id}"]`).count()) === 1);
    noLeak('closings page', await visibleText(page));
    await shot(page, '10-closings');

    const listing = await json(page, 'accounting_periods/closings/search');
    check('closings: the table lists the branch employee\'s scopes only', listing.status === 200 && listing.body.rows.every((row) => !/HQ/.test(row.scope)), JSON.stringify(listing.body.rows.map((row) => row.scope)));
    noLeak('closings JSON', JSON.stringify(listing.body));

    check('closings: the head office close dialog is not found', (await status(page, `accounting_periods/closings/close_form?scope=${hq.branch_id}`)) === 404);
    check('closings: the company close dialog is not found', (await status(page, 'accounting_periods/closings/close_form?scope=company')) === 404);
    check('closings: the head office reopen dialog is not found', (await status(page, `accounting_periods/closings/reopen_form?scope=${hq.branch_id}`)) === 404);

    const preview = await json(page, `accounting_periods/closings/preview?scope=${hq.branch_id}&date=2026-10-10`);
    check('closings: previewing the head office is refused', preview.body?.success === false, preview.body?.message ?? preview.status);
    noLeak('closings preview refusal', JSON.stringify(preview.body));

    await page.goto(`${config.baseUrl}/accounting_periods/closings`, { waitUntil: 'networkidle' });
    const forgedClose = await post(page, 'accounting_periods/closings/close', new URLSearchParams({ scope: String(hq.branch_id), date: '2026-10-15', note: 'forged' }).toString());
    check('closings: a forged close of the head office is refused', forgedClose.json?.success !== true, forgedClose.json?.message ?? forgedClose.status);
    const forgedCompany = await post(page, 'accounting_periods/closings/close', new URLSearchParams({ scope: 'company', date: '2026-10-15', note: 'forged' }).toString());
    check('closings: a forged close of the company is refused', forgedCompany.json?.success !== true, forgedCompany.json?.message ?? forgedCompany.status);
    const forgedReopen = await post(page, 'accounting_periods/closings/reopen', new URLSearchParams({ scope: String(hq.branch_id), reason: 'forged', cutoff: HQ_CLOSING }).toString());
    check('closings: a forged reopen of the head office is refused', forgedReopen.json?.success !== true, forgedReopen.json?.message ?? forgedReopen.status);

    const hqRows = (await json(adminPage, `accounting_periods/closings/search?scope=${hq.branch_id}&status=active`)).body.rows;
    check('closings: the head office closing is untouched after the forged requests', hqRows.length === 1 && hqRows[0].date_to.includes('2026') && hqRows[0].reopened === '');

    await page.click(`[data-href*="close_form?scope=${jed.branch_id}"]`);
    await page.waitForSelector('#close_form');
    await page.fill('#close_date', JED_CLOSING);
    await page.dispatchEvent('#close_date', 'change');
    await page.waitForSelector('#preview_box p');
    const previewText = (await page.innerText('#preview_box')).trim();
    check('closings: the dialog previews the branch\'s own journals', previewText.length > 0, previewText);
    noLeak('close dialog', await visibleText(page));
    await shot(page, '11-close-dialog');
    await Promise.all([
        page.waitForResponse((response) => response.url().includes('closings/close') && response.request().method() === 'POST'),
        page.click('.modal button#submit')
    ]);
    await page.waitForLoadState('networkidle');
    await settle(page, 900);
    await page.goto(`${config.baseUrl}/accounting_periods/closings`, { waitUntil: 'networkidle' });
    const afterClose = await page.$$eval('.acc-closing-chip', (nodes) => nodes.map((node) => node.innerText.replace(/\s+/g, ' ').trim()));
    check('closings: the branch is closed through the chosen date', afterClose.some((chip) => chip.includes('JED') && chip.includes('10/10/2026')), afterClose.join(' | '));
    noLeak('closings page after closing', await visibleText(page));
    await shot(page, '12-closed');

    await page.click(`[data-href*="reopen_form?scope=${jed.branch_id}"]`);
    await page.waitForSelector('#reopen_form');
    check('closings: the reopen dialog carries the closing it shows', (await page.inputValue('#reopen_form input[name=cutoff]')) === JED_CLOSING);
    await page.fill('#reason', 'Count corrected');
    await Promise.all([
        page.waitForResponse((response) => response.url().includes('closings/reopen') && response.request().method() === 'POST'),
        page.click('.modal button#submit')
    ]);
    await settle(page, 900);
    await page.goto(`${config.baseUrl}/accounting_periods/closings`, { waitUntil: 'networkidle' });
    const afterReopen = await page.$$eval('.acc-closing-chip', (nodes) => nodes.map((node) => node.innerText.replace(/\s+/g, ' ').trim()));
    check('closings: reopening puts the branch back to nothing closed', afterReopen.some((chip) => chip.includes('JED') && !chip.includes('10/10/2026')), afterReopen.join(' | '));

    // --- Opening balances ---
    await page.goto(`${config.baseUrl}/accounting_periods/opening`, { waitUntil: 'networkidle' });
    check('opening: the screen works on the employee\'s own branch', (await page.inputValue('input[name=branch_id]')) === String(jed.branch_id));
    check('opening: there is no branch chooser', (await page.locator('#branch').count()) === 0);
    noLeak('opening page', await visibleText(page));
    await shot(page, '20-opening-empty');
    check('opening: the head office screen is not found', (await status(page, `accounting_periods/opening?branch=${hq.branch_id}`)) === 404);
    check('opening: the head office reverse dialog is not found', (await status(page, `accounting_periods/opening/reverse_form?branch=${hq.branch_id}`)) === 404);

    const forgedLines = { 'lines[0][account_id]': '1', 'lines[0][debit]': '10', 'lines[1][account_id]': '2', 'lines[1][credit]': '10' };
    for (const [path, extra] of [['save_draft', {}], ['post', {}], ['discard', {}], ['reverse', { date: '2026-01-02', reason: 'forged' }]]) {
        const forged = await post(page, `accounting_periods/opening/${path}`, new URLSearchParams({ branch_id: String(hq.branch_id), ...forgedLines, ...extra }).toString());
        check(`opening: a forged ${path} for the head office is refused`, forged.json?.success !== true, forged.json?.message ?? forged.status);
        noLeak(`opening forged ${path} response`, JSON.stringify(forged.json ?? ''));
    }

    const accountLabel = async (code) => page.$eval(`#accounts_list option[data-code="${code}"]`, (node) => node.value);
    const fillRow = async (index, code, side, amount) => {
        const row = page.locator('#lines_body tr').nth(index);
        await row.locator('.acc-account-text').fill(await accountLabel(code));
        await row.locator('.acc-account-text').press('Tab');
        await row.locator(side === 'debit' ? '.acc-debit' : '.acc-credit').fill(amount);
        await row.locator(side === 'debit' ? '.acc-debit' : '.acc-credit').press('Tab');
    };

    await fillRow(0, '120101', 'debit', '1000');
    await fillRow(1, '32', 'credit', '1000');
    check('opening: the grid shows a balanced entry', (await page.innerText('#balance_state')).trim().length > 0 && (await page.isEnabled('#post_opening')));
    await shot(page, '21-opening-filled');

    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('#save_draft')]);
    check('opening: the draft is kept and shown again', (await page.$$eval('#lines_body .acc-debit', (nodes) => nodes.map((node) => node.value))).includes('1000'));
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('#post_opening')]);
    const posted = await visibleText(page);
    check('opening: the entry is posted and shown read-only', (await page.locator('#opening_form').count()) === 0 && posted.includes('1,000.00'));
    noLeak('opening page after posting', posted);
    await shot(page, '22-opening-posted');

    const journals = await json(page, 'accounting_journals/search?limit=100');
    check('journals: every journal the employee sees is in their branch', journals.body.rows.length > 0 && journals.body.rows.every((row) => row.branch_code === 'JED'), [...new Set(journals.body.rows.map((row) => row.branch_code))].join(','));
    noLeak('journals JSON', JSON.stringify(journals.body));
    const jedOpenings = async () => (await json(adminPage, 'accounting_journals/search?limit=200')).body.rows.filter((row) => row.branch_code === 'JED' && /Opening/i.test(row.source_type));
    check('journals: exactly one opening journal after the first posting', (await jedOpenings()).length === 1, String((await jedOpenings()).length));

    await page.click('[data-href*="opening/reverse_form"]');
    await page.waitForSelector('#opening_reverse_form');
    await page.fill('#opening_reverse_form input[name=reason]', 'Counted again');
    noLeak('reverse dialog', await visibleText(page));
    await Promise.all([
        page.waitForResponse((response) => response.url().includes('opening/reverse') && response.request().method() === 'POST'),
        page.click('.modal button#submit')
    ]);
    await settle(page, 900);
    await page.goto(`${config.baseUrl}/accounting_periods/opening`, { waitUntil: 'networkidle' });
    check('opening: after the reversal the branch gets an empty grid again', (await page.locator('#opening_form').count()) === 1);
    await fillRow(0, '120101', 'debit', '1500');
    await fillRow(1, '32', 'credit', '1500');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('#post_opening')]);
    check('opening: the replacement is posted', (await visibleText(page)).includes('1,500.00'));
    await shot(page, '23-opening-replaced');

    const after = await json(page, 'accounting_journals/search?limit=100');
    const openings = await jedOpenings();
    const postedOpenings = openings.filter((row) => /Posted/i.test(row.status));
    check('journals: the opening was replaced once, one version stays posted and one is reversed', openings.length === 2 && postedOpenings.length === 1, `${openings.length} opening journals, ${postedOpenings.length} posted`);
    noLeak('journals JSON after replacement', JSON.stringify(after.body));
    check('journals: every journal still belongs to the employee\'s branch', after.body.rows.every((row) => row.branch_code === 'JED'));

    const hqOpening = await adminPage.goto(`${config.baseUrl}/accounting_periods/opening?branch=${hq.branch_id}`, { waitUntil: 'networkidle' });
    check('opening: the head office opening is still posted and unchanged', hqOpening.status() === 200 && (await visibleText(adminPage)).includes('25,000.00'));

    check('no console or page errors for the branch employee', consoleErrors.length === 0, consoleErrors.slice(0, 3).join(' | '));

    // The same markers must fire on the administrator's view, or a silent detector would pass everything.
    await adminPage.goto(`${config.baseUrl}/accounting_periods/closings`, { waitUntil: 'networkidle' });
    const adminText = await visibleText(adminPage);
    check('control: the markers do fire on the administrator\'s closings page', markers.filter((marker) => marker.test(adminText)).length >= 3);

    // Everything the employee fetched, checked once more.
    const leaks = bodies.filter(({ text }) => markers.some((marker) => marker.test(text)));
    check('every response the employee received is free of the other branch', leaks.length === 0, leaks.slice(0, 3).map((item) => item.url).join(' ; '));

    // Put the closings back as they were.
    await adminCloseOrReopen(adminPage, 'reopen', String(hq.branch_id), { reason: 'Restore after the branch scope check', cutoff: HQ_CLOSING });
    if (companyCutoff) {
        await adminCloseOrReopen(adminPage, 'close', 'company', { date: '2026-09-30', note: 'إقفال حتى نهاية سبتمبر' });
    }

    await jedSession.close();
    await admin.close();
} catch (error) {
    failed = true;
    console.error(error);
} finally {
    await browser.close();
}

const passed = results.filter((item) => item.ok).length;
console.log(`\n${passed}/${results.length} checks passed`);
writeFileSync(join(config.outDir, 'branch-scope.json'), JSON.stringify({ results, passed, total: results.length, bodies: bodies.length }, null, 2));
process.exit(failed || passed !== results.length ? 1 : 0);
