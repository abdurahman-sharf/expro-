import { existsSync } from 'node:fs';
import { chromium } from 'playwright-core';

const CHROMIUM_CANDIDATES = [
    '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    '/opt/pw-browsers/chromium/chrome-linux/chrome'
];

export const launchBrowser = (config) => chromium.launch({
    executablePath: config.executablePath ?? CHROMIUM_CANDIDATES.find((path) => existsSync(path)),
    args: ['--no-sandbox']
});

export const settle = async (page, extraMs = 700) => {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(extraMs);
};

export const login = async (page, config) => {
    await page.goto(`${config.baseUrl}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=username]', config.user);
    await page.fill('input[name=password]', config.password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 })
            .catch(() => { throw new Error(`Login failed for user "${config.user}" at ${config.baseUrl}`); }),
        page.click('button[type=submit]')
    ]);
    await settle(page, 300);
};

const openPage = async (context) => {
    const page = await context.newPage();
    page.on('dialog', (dialog) => dialog.accept());
    const cdp = await context.newCDPSession(page);
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
    return page;
};

export const newSession = async (browser, config, viewport, { authenticated = true } = {}) => {
    const context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height },
        ignoreHTTPSErrors: true
    });
    const session = {
        openPage: () => openPage(context),
        close: () => context.close()
    };

    if (authenticated) {
        const page = await session.openPage();
        await login(page, config);
        await page.close();
    }
    return session;
};

export const runSteps = async (page, steps = []) => {
    for (const step of steps) {
        if (step.click) {
            await page.click(step.click, { timeout: 15000 });
        } else if (step.open) {
            if (await page.isVisible(step.open)) {
                await page.click(step.open, { timeout: 15000 });
                await page.waitForTimeout(400);
            }
        } else if (step.fill) {
            await page.fill(step.fill[0], step.fill[1]);
        } else if (step.type) {
            await page.locator(step.type[0]).pressSequentially(step.type[1], { delay: 90 });
        } else if (step.press) {
            await page.press(step.press[0], step.press[1]);
        } else if (step.waitFor) {
            await page.waitForSelector(step.waitFor, { timeout: 15000 });
        } else if (step.pause) {
            await page.waitForTimeout(step.pause);
        } else if (step.idle) {
            await settle(page);
        } else {
            throw new Error(`Unknown step ${JSON.stringify(step)}`);
        }
    }
};

const NAV_TOGGLE = '#settings_nav_toggle';
const LOCALE_TAB = '#settings_tab_locale';

export const readLanguage = async (page, config) => {
    await page.goto(`${config.baseUrl}/config`, { waitUntil: 'networkidle' });
    await runSteps(page, [{ open: NAV_TOGGLE }, { click: LOCALE_TAB }]);
    return page.$eval('select[name=language]', (select) => select.value);
};

export const setLanguage = async (page, config, languageKey) => {
    if (await readLanguage(page, config) === languageKey) {
        return;
    }
    await page.selectOption('select[name=language]', languageKey);
    await Promise.all([
        page.waitForResponse((response) => response.url().includes('config/saveLocale'), { timeout: 30000 }),
        page.click('#submit_locale')
    ]);
    await settle(page, 500);

    await page.goto(`${config.baseUrl}/home`, { waitUntil: 'networkidle' });
    const expected = languageKey.split(':')[0].split('-')[0];
    const actual = await page.evaluate(() => document.documentElement.lang);
    if (!actual.startsWith(expected)) {
        throw new Error(`Language switch to ${languageKey} failed, <html lang> is "${actual}"`);
    }
};
