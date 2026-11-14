// Dev-only HTTP timing of the pages and endpoints a bookkeeper uses, against the router of tools/benchmarks/r6/router.php.
//
//   node tools/benchmarks/r6/http-bench.mjs --last 2025-01-15 --log /path/http.log --out /path/result.json [--only regex] [--runs 3]
//
// Every request is made after a login with the initial administrator account of a fixture database; the server
// side figures (peak memory, statements, fatal error) are read from the line the router appends per request.

import { readFileSync, writeFileSync, existsSync, statSync } from 'node:fs';

const args = Object.fromEntries(process.argv.slice(2).reduce((pairs, value, index, all) => {
    if (value.startsWith('--')) {
        pairs.push([value.slice(2), all[index + 1] && !all[index + 1].startsWith('--') ? all[index + 1] : '1']);
    }
    return pairs;
}, []));

const base = args.base ?? 'http://127.0.0.1:8086';
const last = args.last ?? '2025-01-15';
const logFile = args.log;
const runs = Number(args.runs ?? 3);
const only = args.only ? new RegExp(args.only) : null;
const timeoutMs = Number(args.timeout ?? 180) * 1000;

const addDays = (day, days) => {
    const date = new Date(`${day}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
};

const day30 = addDays(last, -29);
const yearStart = `${last.slice(0, 4)}-01-01`;
const q = (params) => new URLSearchParams(params).toString();
const pages = JSON.parse(readFileSync(new URL('./pages.json', import.meta.url), 'utf8'))
    .map((page) => ({ ...page, path: page.path.replaceAll('{LAST}', last).replaceAll('{DAY30}', day30).replaceAll('{YEAR_START}', yearStart).replaceAll('{CUSTOMER}', args.customer ?? '3').replaceAll('{SUPPLIER}', args.supplier ?? '2003').replaceAll('{CASH}', args.cash ?? '1') }));

const jar = new Map();
const cookieHeader = () => [...jar].map(([key, value]) => `${key}=${value}`).join('; ');
const absorb = (response) => {
    for (const line of response.headers.getSetCookie?.() ?? []) {
        const [pair] = line.split(';');
        const [key, ...value] = pair.split('=');
        jar.set(key.trim(), value.join('='));
    }
};

const request = async (path, options = {}) => {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    const started = performance.now();

    try {
        const response = await fetch(base + path, { redirect: 'manual', ...options, headers: { cookie: cookieHeader(), ...(options.headers ?? {}) }, signal: controller.signal });
        absorb(response);
        const body = await response.arrayBuffer();

        return { status: response.status, bytes: body.byteLength, ms: performance.now() - started, location: response.headers.get('location'), type: response.headers.get('content-type') };
    } catch (error) {
        return { status: 0, bytes: 0, ms: performance.now() - started, error: String(error.cause?.code ?? error.name ?? error) };
    } finally {
        clearTimeout(timer);
    }
};

const logLines = () => (logFile && existsSync(logFile) ? readFileSync(logFile, 'utf8').split('\n').filter(Boolean) : []);

const login = async () => {
    const result = await request('/login', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: q({ username: 'admin', password: 'pointofsale' }) });

    if (![301, 302, 303].includes(result.status)) {
        throw new Error(`login returned ${result.status}`);
    }
};

await login();

const results = [];

for (const page of pages) {
    if (only && !only.test(page.name)) {
        continue;
    }

    const samples = [];

    for (let run = 0; run < runs; run++) {
        const before = logLines().length;
        const result = await request(page.path);
        const added = logLines().slice(before).map((line) => JSON.parse(line));
        const own = added.filter((entry) => entry.uri === page.path).pop() ?? added.pop() ?? {};

        samples.push({ ...result, server: own });

        if (result.status === 0 || own.fatal) {
            break;
        }
    }

    const ok = samples.filter((sample) => sample.status > 0);
    const ms = ok.map((sample) => sample.ms).sort((a, b) => a - b);
    const record = {
        name: page.name,
        path: page.path,
        runs: samples.length,
        status: samples.map((sample) => sample.status).join('/'),
        first_ms: Math.round(samples[0].ms),
        median_ms: Math.round(ms[Math.floor(ms.length / 2)] ?? 0),
        max_ms: Math.round(ms[ms.length - 1] ?? 0),
        bytes: samples[0].bytes,
        peak_mb: Math.max(...samples.map((sample) => sample.server.peak_mb ?? 0)),
        questions: samples[0].server.questions ?? null,
        load: samples[0].server.load ?? null,
        fatal: samples.map((sample) => sample.server.fatal).find(Boolean) ?? null,
        error: samples.map((sample) => sample.error).find(Boolean) ?? null,
        location: samples[0].location ?? null,
    };

    results.push(record);
    process.stdout.write(`${record.name.padEnd(34)} ${String(record.status).padEnd(11)} first ${String(record.first_ms).padStart(6)} ms  median ${String(record.median_ms).padStart(6)} ms  peak ${String(record.peak_mb).padStart(6)} MB  q ${record.questions ?? '-'}  ${record.bytes} B${record.fatal ? '  FATAL ' + record.fatal : ''}${record.error ? '  ERROR ' + record.error : ''}\n`);
}

if (args.out) {
    writeFileSync(args.out, JSON.stringify({ base, last, runs, results }, null, 2));
}
