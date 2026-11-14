// Phase B: walks every route of the application three ways: without a session, as a cashier who holds only the sales
// modules, and as the administrator with junk in every id. Lists what is open that should not be and every 500.
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { admin } from './r2-lib.mjs';
import { Client } from './r2-client.mjs';

const ROOT = new URL('../../', import.meta.url).pathname;
const out = execFileSync('php', ['spark', 'routes'], { cwd: ROOT, env: { ...process.env, CI_ENVIRONMENT: 'development', MYSQL_DB_NAME: process.env.R2_DB ?? 'ospos_test_r2_ui' }, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
const routes = [];
for (const line of out.split('\n')) {
    const cells = line.split('|').map((x) => x.trim());
    if (cells.length < 6 || !['GET', 'POST', 'auto'].includes(cells[1]) && !/^(GET|POST|PUT|DELETE|CLI)/.test(cells[1])) continue;
    if (cells[1] === 'Method') continue;
    routes.push({ method: cells[1], route: cells[2], handler: cells[4], before: cells[5] });
}
const gets = routes.filter((r) => r.method === 'GET' && !/\.(csv|xlsx|pdf)$/.test(r.route) && !/portal|login|^\/$|api\/v1|logout|migrate/.test(r.route) && !/(delete|cancel|logout)/i.test(r.route));
const posts = routes.filter((r) => r.method === 'POST' && !/portal|login|logout|migrate|delete|disable|cancel/i.test(r.route));
const fill = (route, token) => route.replace(/\(\[0-9\]\+\)/g, '1').replace(/\(\[\^\/\]\+\)/g, token).replace(/\(\.\*\)/g, token).replace(/\(:any\)/g, token);

const evidence = { counts: { routes: routes.length, gets: gets.length, posts: posts.length }, unauth: [], cashier: [], junk500: [], junkOther: [], post500: [] };
console.log(evidence.counts);

// 1. no session: every GET must send the visitor to the login screen
const anon = new Client('anon');
for (const r of gets) {
    const path = '/' + fill(r.route, 'abc');
    const res = await anon.get(path, { ajax: false });
    if (res.status === 200 || res.status >= 500) evidence.unauth.push({ route: r.route, status: res.status, handler: r.handler });
}
console.log('unauthenticated 200/500:', evidence.unauth.length);
for (const e of evidence.unauth.slice(0, 40)) console.log('  ', e.status, e.route, e.handler);

// 2. a cashier with only the sales modules
const cashier = await admin('cashier', 'Cashier#2026');
for (const r of gets) {
    const path = '/' + fill(r.route, '1');
    const res = await cashier.get(path, { ajax: false });
    if (res.status === 200 && !/no_access|No access|You are not allowed|not have permission/i.test(res.text.slice(0, 5000))) evidence.cashier.push({ route: r.route, status: res.status, handler: r.handler, len: res.text.length });
    else if (res.status >= 500) evidence.cashier.push({ route: r.route, status: res.status, handler: r.handler, len: res.text.length });
}
console.log('cashier gets content:', evidence.cashier.length);

// 3. the administrator with junk in every parameter
const root = await admin();
for (const r of gets) {
    if (!/\(\[\^\/\]\+\)|\(\.\*\)|\(:any\)/.test(r.route)) continue;
    for (const junk of ['abc', "'", '%00', '9999999999999999999']) {
        const path = '/' + fill(r.route, encodeURIComponent(junk));
        const res = await root.get(path, { ajax: false });
        if (res.status >= 500) evidence.junk500.push({ route: r.route, junk, status: res.status, handler: r.handler, message: (res.data?.message ?? res.text.match(/<title>([^<]*)/)?.[1] ?? '').slice(0, 160) });
    }
}
console.log('admin junk 500s:', evidence.junk500.length);

// 4. posts with an empty body: nothing may answer 500
for (const r of posts) {
    const path = '/' + fill(r.route, '1');
    const res = await root.post(path, {});
    if (res.status >= 500) evidence.post500.push({ route: r.route, status: res.status, handler: r.handler, message: (res.data?.message ?? '').slice(0, 200) });
}
console.log('admin empty posts 500s:', evidence.post500.length);
writeFileSync(process.env.R2_OUT ?? '/tmp/r2-sweep.json', JSON.stringify(evidence, null, 2));
