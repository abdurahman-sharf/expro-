import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launchBrowser } from './lib/session.mjs';

const usage = `usage: node picker.mjs

Opens the search picker of the accounting screens (resources/assets/js/45-accounting.js) in Chromium on a page of
its own, with the server answered by a stub, and checks what a keyboard and a screen reader rely on: the combobox
roles and states, the live region, the arrow keys, Enter, Escape, the click, the limit of twenty and the message that
says there are more, a row added after the page loaded, the right-to-left layout, and that the built bundle
public/assets/js/app.js holds the same code. It needs no application and no database. It exits with 1 on a failure.`;

if (process.argv.includes('--help')) {
    console.log(usage);
    process.exit(0);
}

const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const source = readFileSync(resolve(ROOT, 'resources/assets/js/45-accounting.js'), 'utf8');
const bundle = readFileSync(resolve(ROOT, 'public/assets/js/app.js'), 'utf8');
const jquery = resolve(ROOT, 'public/assets/js/jquery.min.js');
const failures = [];
const check = (name, ok, detail = '') => {
    console.log(`${ok ? 'PASS' : 'FAIL'}: ${name}${ok || detail === '' ? '' : ` (${detail})`}`);
    ok || failures.push(name);
};

check('the built bundle holds the source of the picker unchanged', bundle.includes(source));

const entries = (term) => {
    if (term === 'none') {
        return { results: [], more: false, limit: 20 };
    }

    if (term === 'many') {
        return { results: Array.from({ length: 20 }, (_, index) => ({ id: 100 + index, code: `9${index}`, label: `9${index} - Account ${index}` })), more: true, limit: 20 };
    }

    return { results: [{ id: 7, code: '1101', label: '1101 - Cash' }, { id: 8, code: '1102', label: '1102 - Cash in transit' }, { id: 9, code: '1103', label: '1103 - Cashier float' }], more: false, limit: 20 };
};

const html = (direction) => `<!doctype html><html dir="${direction}"><head><style>*,*::before,*::after{box-sizing:border-box}</style></head><body style="margin:0;padding:1rem">
<form id="f"><table><tbody id="rows"><tr class="row"><td>
<input type="text" class="acc-account-text" aria-label="Account" style="width:16rem"><input type="hidden" class="acc-account" value="">
</td></tr></tbody></table></form></body></html>`;

const browser = await launchBrowser({ executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH });

try {
    for (const direction of ['ltr', 'rtl']) {
        const context = await browser.newContext({ viewport: { width: 390, height: 700 } });
        const page = await context.newPage();
        const requests = [];
        const errors = [];

        page.on('pageerror', (error) => errors.push(error.message));
        await page.route('http://picker.test/**', (route) => {
            const url = new URL(route.request().url());
            requests.push(Object.fromEntries(url.searchParams));
            route.fulfill({ contentType: 'application/json', body: JSON.stringify(entries(url.searchParams.get('term'))) });
        });
        await page.setContent(html(direction));
        await page.addScriptTag({ path: jquery });
        await page.addScriptTag({ content: source });
        await page.evaluate(() => {
            window.picker = window.accounting_support.accountPicker('unused_list', {
                url: 'http://picker.test/search',
                selector: '.acc-account-text',
                params: { kind: 'account', mode: 'postable' },
                initial: [{ id: 42, code: '4201', label: '4201 - Sales' }],
                text: { count: '{0} results', failed: 'failed', hint: 'Type to search', more: 'Only the first 20 are shown', none: 'No results' },
            });

            const $ = window.$;
            const hiddenOf = (box) => $(box).closest('td').find('.acc-account');

            $(document).on('input change', '.acc-account-text', function () {
                window.picker.sync($(this), hiddenOf(this));
            }).on('blur', '.acc-account-text', function () {
                window.picker.complete($(this), hiddenOf(this));
            });
        });

        const label = `${direction}: `;
        const input = page.locator('.acc-account-text').first();
        const hidden = page.locator('.acc-account').first();
        const list = page.locator('[role=listbox]');

        await input.focus();
        check(`${label}the box announces itself as a combobox that controls a list`, (await input.getAttribute('role')) === 'combobox' && (await input.getAttribute('aria-expanded')) === 'false' && (await input.getAttribute('aria-controls')) !== null && (await input.getAttribute('aria-autocomplete')) === 'list');

        await input.fill('');
        await page.keyboard.type('cash');
        await list.waitFor({ state: 'visible' });
        check(`${label}typing asks the server for the term and the kind`, requests.some((request) => request.term === 'cash' && request.kind === 'account' && request.mode === 'postable'));
        check(`${label}the results are options of the list`, (await page.locator('[role=option]').count()) === 3 && (await input.getAttribute('aria-expanded')) === 'true');
        check(`${label}the live region says how many results there are`, (await page.locator('[role=status]').innerText()).includes('3 results'));

        const box = await list.boundingBox();
        check(`${label}the list sits inside the screen under the box`, box !== null && box.x >= 0 && box.x + box.width <= 390 + 1, JSON.stringify(box));

        await page.keyboard.press('ArrowDown');
        const first = await page.locator('[role=option]').nth(0).getAttribute('id');
        check(`${label}the down arrow points at the first option and marks it selected`, (await input.getAttribute('aria-activedescendant')) === first && (await page.locator('[role=option]').nth(0).getAttribute('aria-selected')) === 'true');
        await page.keyboard.press('ArrowDown');
        check(`${label}the down arrow moves on`, (await input.getAttribute('aria-activedescendant')) === (await page.locator('[role=option]').nth(1).getAttribute('id')));
        await page.keyboard.press('ArrowUp');
        await page.keyboard.press('ArrowUp');
        check(`${label}the up arrow wraps to the last option`, (await input.getAttribute('aria-activedescendant')) === (await page.locator('[role=option]').nth(2).getAttribute('id')));

        await page.keyboard.press('Enter');
        check(`${label}Enter chooses the option and fills the hidden id`, (await input.inputValue()) === '1103 - Cashier float' && (await hidden.inputValue()) === '9');
        check(`${label}choosing closes the list`, (await list.isHidden()) && (await input.getAttribute('aria-expanded')) === 'false');

        await input.fill('');
        await page.keyboard.type('cas');
        await list.waitFor({ state: 'visible' });
        await page.keyboard.press('Escape');
        check(`${label}Escape closes the list and keeps what was typed`, (await list.isHidden()) && (await input.inputValue()) === 'cas');

        await input.fill('');
        await page.keyboard.type('none');
        await page.waitForFunction(() => document.querySelector('[role=status]').textContent.includes('No results'));
        check(`${label}a search with no result says so and shows no list`, await list.isHidden());

        await input.fill('');
        await page.keyboard.type('many');
        await list.waitFor({ state: 'visible' });
        check(`${label}twenty results are shown and the list says more exist`, (await page.locator('[role=option]').count()) === 20 && (await list.innerText()).includes('Only the first 20 are shown'));
        check(`${label}the live region says more exist`, (await page.locator('[role=status]').innerText()).includes('20 results'));

        await page.locator('[role=option]').nth(2).dispatchEvent('mousedown');
        check(`${label}a click chooses an option`, (await hidden.inputValue()) === '102' && (await input.inputValue()) === '92 - Account 2');

        await input.fill('4201 - Sales');
        check(`${label}the label of the account already on the screen resolves without a search`, (await hidden.inputValue()) === '42');
        await input.fill('4201');
        await input.blur();
        check(`${label}leaving the box with an exact code completes it to the label`, (await input.inputValue()) === '4201 - Sales' && (await hidden.inputValue()) === '42');

        await input.fill('nonsense');
        await input.blur();
        check(`${label}text that is no account leaves no id and marks the box invalid`, (await hidden.inputValue()) === '' && (await input.getAttribute('class')).includes('is-invalid'));

        await page.evaluate(() => {
            window.$('#rows').append(window.$('.row').first().clone().find('input').val('').end());
        });
        const added = page.locator('.acc-account-text').nth(1);

        await added.focus();
        await page.keyboard.type('cash');
        await list.waitFor({ state: 'visible' });
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');
        check(`${label}a row added after the page loaded is searched too`, (await added.inputValue()) === '1101 - Cash' && (await page.locator('.acc-account').nth(1).inputValue()) === '7');

        check(`${label}no script error`, errors.length === 0, errors.join('; '));
        await context.close();
    }
} finally {
    await browser.close();
}

if (failures.length > 0) {
    console.error(`\n${failures.length} check(s) failed`);
    process.exit(1);
}

console.log('\nAll checks passed');
