import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { collectMetrics, runAxe, watchPage } from './lib/checks.mjs';
import { LANGUAGES, loadConfig } from './lib/env.mjs';
import { compareToBaseline, isHardFailure, toBaseline, writeReport } from './lib/report.mjs';
import { launchBrowser, newSession, readLanguage, runSteps, setLanguage, settle } from './lib/session.mjs';

const config = loadConfig(process.argv.slice(2));
const allScreens = JSON.parse(readFileSync(new URL('./screens.json', import.meta.url), 'utf8')).screens;
const screens = config.only ? allScreens.filter((screen) => config.only.includes(screen.id)) : allScreens;

if (screens.length === 0) {
    throw new Error('No screens selected');
}

const visit = async (session, screen, lang, viewport) => {
    const key = `${lang}/${viewport.label}/${screen.id}`;
    const entry = {
        key,
        id: screen.id,
        group: screen.group,
        lang,
        viewport: viewport.label,
        status: null,
        failure: null,
        consoleErrors: [],
        pageErrors: [],
        failedRequests: [],
        badResponses: [],
        bytes: {},
        requests: 0,
        metrics: null,
        axe: null,
        screenshot: null
    };
    const page = await session.openPage();
    const watcher = watchPage(page);

    try {
        await page.goto(`${config.baseUrl}${screen.path}`, { waitUntil: 'networkidle', timeout: 45000 });
        await settle(page);
        await runSteps(page, screen.steps);
        entry.metrics = await collectMetrics(page);

        if (config.axe) {
            entry.axe = await runAxe(page);
        }
        if (config.screenshots) {
            const relative = join(lang, viewport.label, `${screen.id}.png`);
            mkdirSync(join(config.outDir, lang, viewport.label), { recursive: true });
            await page.screenshot({ path: join(config.outDir, relative), fullPage: true });
            entry.screenshot = relative;
        }
    } catch (error) {
        entry.failure = error.message.split('\n')[0].slice(0, 200);
    }

    await watcher.done();
    Object.assign(entry, {
        status: watcher.state.status,
        consoleErrors: watcher.state.consoleErrors,
        pageErrors: watcher.state.pageErrors,
        failedRequests: watcher.state.failedRequests,
        badResponses: watcher.state.badResponses,
        bytes: watcher.state.bytes,
        requests: watcher.state.requests
    });
    await page.close();

    const mark = isHardFailure(entry) ? 'FAIL' : ' ok ';
    const axeNodes = entry.axe ? ` axe=${entry.axe.nodes}` : '';
    console.log(`[${mark}] ${lang} ${viewport.label.padEnd(9)} ${screen.id.padEnd(30)} status=${entry.status}${axeNodes}${entry.failure ? ` ${entry.failure}` : ''}`);
    return entry;
};

const visitAll = async (browser, lang, viewport) => {
    const authenticated = await newSession(browser, config, viewport);
    const anonymous = await newSession(browser, config, viewport, { authenticated: false });
    const entries = [];
    for (const screen of screens) {
        entries.push(await visit(screen.anonymous ? anonymous : authenticated, screen, lang, viewport));
    }
    await authenticated.close();
    await anonymous.close();
    return entries;
};

const startedAt = new Date().toISOString();
const browser = await launchBrowser(config);
const admin = await newSession(browser, config, config.viewports[0]);
const adminPage = await admin.openPage();
const initialLanguage = await readLanguage(adminPage, config);
const entries = [];

try {
    for (const lang of config.langs) {
        await setLanguage(adminPage, config, LANGUAGES[lang]);
        const perViewport = await Promise.all(config.viewports.map((viewport) => visitAll(browser, lang, viewport)));
        entries.push(...perViewport.flat());
    }
} finally {
    await setLanguage(adminPage, config, initialLanguage).catch((error) => console.error(`Could not restore language ${initialLanguage}: ${error.message}`));
    await browser.close();
}

const meta = {
    baseUrl: config.baseUrl,
    startedAt,
    langs: config.langs,
    viewports: config.viewports.map((viewport) => viewport.label),
    screens: screens.length,
    axe: config.axe
};
const summary = writeReport(config.outDir, meta, entries);
const current = toBaseline(entries);

if (config.writeBaseline) {
    writeFileSync(config.writeBaseline, `${JSON.stringify({ meta: { ...meta, startedAt }, entries: current }, null, 2)}\n`);
    console.log(`Baseline written to ${config.writeBaseline}`);
}

console.log(`\nReport: ${join(config.outDir, 'index.html')}`);
console.log(JSON.stringify(summary, null, 2));

let exitCode = summary.hardFailures > 0 ? 1 : 0;

if (config.compare) {
    const baseline = JSON.parse(readFileSync(config.compare, 'utf8')).entries;
    const regressions = compareToBaseline(baseline, current);
    console.log(regressions.length ? `\nRegressions (${regressions.length}):\n${regressions.join('\n')}` : '\nNo regressions against baseline');
    if (config.failOnRegression && regressions.length > 0) {
        exitCode = 1;
    }
}

process.exit(exitCode);
