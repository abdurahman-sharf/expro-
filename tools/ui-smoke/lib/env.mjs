import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const LANGUAGES = {
    ar: 'ar-EG:arabic',
    en: 'en:english'
};

const DEFAULT_VIEWPORTS = ['390x844', '768x1024', '1440x900'];

const parseArgs = (argv) => {
    const args = {};
    for (let index = 0; index < argv.length; index++) {
        const token = argv[index];
        if (!token.startsWith('--')) {
            continue;
        }
        const next = argv[index + 1];
        if (next === undefined || next.startsWith('--')) {
            args[token.slice(2)] = true;
        } else {
            args[token.slice(2)] = next;
            index++;
        }
    }
    return args;
};

const parseList = (value, fallback) => (typeof value === 'string' ? value.split(',').filter(Boolean) : fallback);

const parseViewport = (value) => {
    const [width, height] = value.split('x').map(Number);
    if (!width || !height) {
        throw new Error(`Invalid viewport "${value}", expected WIDTHxHEIGHT`);
    }
    return { width, height, label: `${width}x${height}` };
};

export const loadConfig = (argv, env = process.env) => {
    const args = parseArgs(argv);
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const defaultOut = fileURLToPath(new URL(`../../../build/ui-smoke/${stamp}`, import.meta.url));
    const langs = parseList(args.langs, ['ar', 'en']);

    for (const lang of langs) {
        if (!LANGUAGES[lang]) {
            throw new Error(`Unsupported language "${lang}", expected one of: ${Object.keys(LANGUAGES).join(', ')}`);
        }
    }

    return {
        baseUrl: String(args['base-url'] ?? env.UI_BASE_URL ?? 'http://127.0.0.1').replace(/\/+$/, ''),
        user: env.UI_USER ?? 'admin',
        password: env.UI_PASS ?? 'pointofsale',
        executablePath: env.PLAYWRIGHT_EXECUTABLE_PATH,
        langs,
        viewports: parseList(args.viewports, DEFAULT_VIEWPORTS).map(parseViewport),
        only: parseList(args.only, null),
        outDir: args.out ? resolve(String(args.out)) : defaultOut,
        axe: !args['no-axe'],
        screenshots: !args['no-screenshots'],
        writeBaseline: typeof args['write-baseline'] === 'string' ? resolve(args['write-baseline']) : null,
        compare: typeof args.compare === 'string' ? resolve(args.compare) : null,
        failOnRegression: Boolean(args['fail-on-regression'])
    };
};
