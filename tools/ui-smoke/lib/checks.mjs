import AxeBuilder from '@axe-core/playwright';

const BS3_DOM_SELECTORS = {
    glyphicon: '.glyphicon',
    gridXs: '[class*="col-xs-"]',
    pull: '.pull-left, .pull-right',
    formHorizontal: '.form-horizontal',
    inputSize: '.input-sm, .input-lg',
    panel: '.panel',
    btnDefault: '.btn-default',
    navbarDefault: '.navbar-default'
};

const IGNORED_FAILURES = ['net::ERR_ABORTED'];

export const watchPage = (page) => {
    const state = {
        status: null,
        consoleErrors: [],
        pageErrors: [],
        failedRequests: [],
        badResponses: [],
        bytes: {},
        requests: 0
    };
    const pending = [];
    const mainFrame = page.mainFrame();

    page.on('console', (message) => {
        if (message.type() === 'error') {
            state.consoleErrors.push(`${message.text()} ${message.location().url ?? ''}`.trim().slice(0, 240));
        }
    });
    page.on('pageerror', (error) => state.pageErrors.push(error.message.slice(0, 240)));
    page.on('requestfailed', (request) => {
        const reason = request.failure()?.errorText ?? 'unknown';
        if (!IGNORED_FAILURES.includes(reason)) {
            state.failedRequests.push(`${reason} ${request.url()}`.slice(0, 240));
        }
    });
    page.on('response', (response) => {
        const request = response.request();
        const type = request.resourceType();
        state.requests++;

        if (request.isNavigationRequest() && request.frame() === mainFrame) {
            state.status = response.status();
        } else if (response.status() >= 400) {
            state.badResponses.push(`${response.status()} ${response.url()}`.slice(0, 240));
        }

        pending.push(
            response.body()
                .then((body) => { state.bytes[type] = (state.bytes[type] ?? 0) + body.length; })
                .catch(() => {})
        );
    });

    return { state, done: () => Promise.allSettled(pending) };
};

export const collectMetrics = (page) => page.evaluate((selectors) => {
    const root = document.documentElement;
    const bs3 = {};
    for (const [name, selector] of Object.entries(selectors)) {
        bs3[name] = document.querySelectorAll(selector).length;
    }
    return {
        title: document.title.slice(0, 80),
        lang: root.lang,
        htmlDir: root.getAttribute('dir'),
        direction: getComputedStyle(root).direction,
        viewportMeta: Boolean(document.querySelector('meta[name=viewport]')),
        overflowX: root.scrollWidth > root.clientWidth,
        scrollWidth: root.scrollWidth,
        clientWidth: root.clientWidth,
        bodyFont: getComputedStyle(document.body).fontFamily.slice(0, 120),
        plexFacesLoaded: [...document.fonts].filter((face) => face.family.includes('IBM Plex Sans Arabic') && face.status === 'loaded').length,
        bs3
    };
}, BS3_DOM_SELECTORS);

export const runAxe = async (page) => {
    try {
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
        const rules = {};
        for (const violation of result.violations) {
            rules[violation.id] = violation.nodes.length;
        }
        return {
            violations: result.violations.length,
            nodes: Object.values(rules).reduce((sum, count) => sum + count, 0),
            rules
        };
    } catch (error) {
        return { error: error.message.slice(0, 160), violations: 0, nodes: 0, rules: {} };
    }
};
