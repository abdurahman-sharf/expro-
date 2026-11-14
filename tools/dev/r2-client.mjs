// HTTP client with a cookie jar and the session CSRF token, for driving the application like a browser would.
const BASE = process.env.R2_BASE ?? 'http://127.0.0.1:8082';

export class Client {
    constructor(name = 'c') {
        this.name = name;
        this.cookies = new Map();
        this.log = [];
    }

    cookieHeader() {
        return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
    }

    store(res) {
        for (const line of res.headers.getSetCookie?.() ?? []) {
            const [pair] = line.split(';');
            const i = pair.indexOf('=');
            const key = pair.slice(0, i).trim();
            const value = pair.slice(i + 1).trim();
            if (/expires=Thu, 01 Jan 1970/i.test(line) || value === 'deleted') this.cookies.delete(key);
            else this.cookies.set(key, value);
        }
    }

    token = '';

    get csrf() {
        return this.token;
    }

    async request(method, path, { form, json, headers = {}, raw = false, redirect = 'manual', csrf = true, ajax = true } = {}) {
        const url = path.startsWith('http') ? path : BASE + path;
        const h = { Cookie: this.cookieHeader(), ...headers };
        if (ajax) h['X-Requested-With'] = 'XMLHttpRequest';
        let body;
        if (form) {
            const params = new URLSearchParams();
            const all = csrf && this.csrf ? { csrf_ospos_v4: this.csrf, ...form } : form;
            for (const [k, v] of Object.entries(all)) {
                if (Array.isArray(v)) v.forEach((x) => params.append(k, x));
                else if (v !== undefined && v !== null) params.append(k, String(v));
            }
            body = params.toString();
            h['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        const started = Date.now();
        const res = await fetch(url, { method, headers: h, body, redirect });
        this.store(res);
        const text = await res.text();
        const entry = { method, path, status: res.status, ms: Date.now() - started, location: res.headers.get('location') };
        this.log.push(entry);
        const found = text.match(/csrf_token = function\(\) \{\s*return "([0-9a-f]+)"/);
        if (found) this.token = found[1];
        let data = null;
        try { data = JSON.parse(text); } catch { /* not json */ }
        return { status: res.status, text, data, headers: res.headers, location: res.headers.get('location'), entry };
    }

    get(path, opts) { return this.request('GET', path, opts); }
    post(path, form, opts = {}) { return this.request('POST', path, { form: form ?? {}, ...opts }); }

    async login(user = 'admin', pass = 'pointofsale') {
        await this.get('/login', { ajax: false });
        const res = await this.request('POST', '/login', { form: { username: user, password: pass }, csrf: false, ajax: false });
        if (![301, 302, 303].includes(res.status)) throw new Error(`login ${res.status}`);
        await this.get('/sales', { ajax: false });
        return res;
    }
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
export const near = (a, b, eps = 0.005) => Math.abs(Number(a) - Number(b)) <= eps;

const decode = (s) => s.replace(/&#x([0-9a-f]+);/gi, (_, h) => String.fromCodePoint(parseInt(h, 16))).replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(Number(d))).replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

// Reads the first form of a page the way a browser would submit it untouched: every field with its default value.
export const formDefaults = (html, formIndex = 0) => {
    const forms = [...html.matchAll(/<form\b[^>]*>[\s\S]*?<\/form>/g)];
    const form = forms[formIndex]?.[0];
    if (!form) return null;
    const action = decode((form.match(/action="([^"]*)"/) ?? [])[1] ?? '');
    const fields = [];
    for (const m of form.matchAll(/<input\b([^>]*)>/g)) {
        const a = m[1];
        const name = decode((a.match(/name="([^"]*)"/) ?? [])[1] ?? '');
        if (!name || /disabled/.test(a)) continue;
        const type = (a.match(/type="([^"]*)"/) ?? [])[1] ?? 'text';
        const value = decode((a.match(/value="([^"]*)"/) ?? [])[1] ?? '');
        if (type === 'file' || type === 'submit' || type === 'button') continue;
        if ((type === 'checkbox' || type === 'radio') && !/\bchecked\b/.test(a)) continue;
        fields.push([name, type === 'checkbox' && value === '' ? 'on' : value]);
    }
    for (const m of form.matchAll(/<textarea\b([^>]*)>([\s\S]*?)<\/textarea>/g)) {
        const name = decode((m[1].match(/name="([^"]*)"/) ?? [])[1] ?? '');
        if (name) fields.push([name, decode(m[2])]);
    }
    for (const m of form.matchAll(/<select\b([^>]*)>([\s\S]*?)<\/select>/g)) {
        const name = decode((m[1].match(/name="([^"]*)"/) ?? [])[1] ?? '');
        if (!name || /disabled/.test(m[1])) continue;
        const options = [...m[2].matchAll(/<option\b([^>]*)>/g)];
        const picked = options.find((o) => /selected/.test(o[1])) ?? options[0];
        if (picked) fields.push([name, decode((picked[1].match(/value="([^"]*)"/) ?? [])[1] ?? '')]);
    }
    return { action, fields };
};

// Fills the form of a dialog or page with overrides (a name replaces every default of that name) and posts it.
Client.prototype.submitForm = async function (formPath, overrides = {}, { index = 0, action = null } = {}) {
    const page = await this.get(formPath, { ajax: false });
    const parsed = formDefaults(page.text, index);
    if (!parsed) throw new Error(`no form at ${formPath} (status ${page.status})`);
    const body = {};
    for (const [name, value] of parsed.fields) {
        if (name === 'csrf_ospos_v4' || name === 'honeypot') continue;
        if (name in overrides) continue;
        if (name.endsWith('[]')) (body[name] ??= []).push(value);
        else body[name] = value;
    }
    for (const [k, v] of Object.entries(overrides)) body[k] = v;
    const target = action ?? parsed.action;
    return this.post(target.replace(BASE, ''), body);
};
