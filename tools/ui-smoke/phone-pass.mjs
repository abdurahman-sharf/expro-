import { mkdirSync, readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { launchBrowser } from './lib/session.mjs';

const usage = `usage: node phone-pass.mjs --pages DIR [--shots DIR] [--widths 360,390,768] [--css FILE]

Opens every .html file of DIR (the pages written by tests/Support/PhonePagesDump.php) at each width in
Chromium with no network and prints, per page: the horizontal scroll of the page, every table of the
content with whether its rows are stacked into cards and whether it still scrolls sideways, and the
elements that stick out of the screen outside a scrolling box. It exits with 1 when a page scrolls
sideways or a table of the content scrolls sideways below the breakpoint of the cards (768px).
--css FILE answers the request for assets/css/app.css with FILE, to measure the same pages with another
stylesheet (for example the one of an earlier commit).`;

const option = (name, fallback) => {
    const index = process.argv.indexOf(`--${name}`);

    return index === -1 ? fallback : process.argv[index + 1];
};

const pages = option('pages');

if (!pages) {
    console.error(usage);
    process.exit(2);
}

const directory = resolve(pages);
const shots = option('shots') === undefined ? null : resolve(option('shots'));
const widths = option('widths', '360,390,768').split(',').map(Number);
const cssOverride = option('css') === undefined ? null : readFileSync(resolve(option('css')));
const CARDS_BELOW = 768;

if (shots !== null) {
    mkdirSync(shots, { recursive: true });
}

const measure = (width) => {
    const root = document.documentElement;
    const result = { pageScroll: root.scrollWidth, client: root.clientWidth, dir: root.dir, tables: [], overflowing: [] };

    for (const table of document.querySelectorAll('.app-main table, main table')) {
        const wrap = table.closest('.table-responsive, .acc-lines-wrap');
        const row = table.querySelector('tbody tr');

        result.tables.push({
            id: table.id || table.className.slice(0, 30),
            columns: table.querySelectorAll('thead th').length,
            stacked: row ? getComputedStyle(row).display === 'block' : null,
            wrapScrolls: wrap ? wrap.scrollWidth > wrap.clientWidth + 1 : table.scrollWidth > table.clientWidth + 1,
            labels: table.querySelectorAll('td[data-label]').length,
        });
    }

    for (const element of (document.querySelector('.app-main') || document.body).querySelectorAll('*')) {
        const box = element.getBoundingClientRect();

        if (box.width === 0 || box.height === 0 || (box.right <= width + 1 && box.left >= -1)) {
            continue;
        }

        let inScroller = false;

        for (let parent = element.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
            if (['auto', 'scroll', 'hidden'].includes(getComputedStyle(parent).overflowX)) {
                inScroller = true;
                break;
            }
        }

        if (!inScroller) {
            result.overflowing.push(`${element.tagName}#${element.id}.${String(element.className).slice(0, 40)} ${Math.round(box.left)}..${Math.round(box.right)}`);
        }
    }

    result.overflowing = result.overflowing.slice(0, 6);

    return result;
};

const browser = await launchBrowser({ executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH });
const files = readdirSync(directory).filter((file) => file.endsWith('.html')).sort();
let failures = 0;

for (const width of widths) {
    let scrolling = 0;
    let stacked = 0;

    console.log(`\n== ${width}px`);

    for (const file of files) {
        const page = await browser.newPage({ viewport: { width, height: 800 } });

        await page.route(/^https?:/, (route) => route.abort());

        if (cssOverride !== null) {
            await page.route(/assets\/css\/app\.css/, (route) => route.fulfill({ body: cssOverride, contentType: 'text/css' }));
        }

        await page.goto(`file://${directory}/${file}`, { waitUntil: 'load' });
        await page.waitForTimeout(300);

        const result = await page.evaluate(measure, width);
        const sideways = result.pageScroll > result.client;

        console.log(`${file} dir=${result.dir} pageScroll=${result.pageScroll}/${result.client}${sideways ? ' OVERFLOW' : ''}`);

        if (sideways) {
            failures++;
        }

        for (const table of result.tables) {
            console.log(`   table ${table.id} columns=${table.columns} stacked=${table.stacked} scrolls=${table.wrapScrolls} labels=${table.labels}`);

            if (table.stacked) {
                stacked++;
            }

            if (table.wrapScrolls) {
                scrolling++;

                if (width < CARDS_BELOW) {
                    failures++;
                }
            }
        }

        for (const line of result.overflowing) {
            console.log(`   OUT ${line}`);
        }

        if (shots !== null) {
            await page.screenshot({ path: `${shots}/${file.replace('.html', '')}-${width}.png`, fullPage: true });
        }

        await page.close();
    }

    console.log(`-- ${width}px: ${scrolling} tables scroll sideways, ${stacked} tables are stacked into cards`);
}

await browser.close();

process.exit(failures === 0 ? 0 : 1);
