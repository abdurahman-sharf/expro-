import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const sum = (values) => Object.values(values ?? {}).reduce((total, value) => total + value, 0);

export const isHardFailure = (entry) => Boolean(entry.failure)
    || entry.status === null
    || entry.status >= 400
    || entry.pageErrors.length > 0
    || entry.badResponses.length > 0;

export const toBaseline = (entries) => {
    const baseline = {};
    for (const entry of entries) {
        baseline[entry.key] = {
            status: entry.status,
            errors: entry.consoleErrors.length + entry.pageErrors.length + entry.failedRequests.length + entry.badResponses.length,
            overflowX: entry.metrics?.overflowX ?? null,
            viewportMeta: entry.metrics?.viewportMeta ?? null,
            direction: entry.metrics?.direction ?? null,
            bs3: sum(entry.metrics?.bs3),
            axeNodes: entry.axe?.nodes ?? null,
            requests: entry.requests,
            bytes: sum(entry.bytes)
        };
    }
    return baseline;
};

export const compareToBaseline = (baseline, current) => {
    const regressions = [];
    for (const [key, before] of Object.entries(baseline)) {
        const after = current[key];
        if (!after) {
            regressions.push(`${key}: missing from this run`);
            continue;
        }
        if (after.status !== before.status && (after.status === null || after.status >= 400)) {
            regressions.push(`${key}: status ${before.status} -> ${after.status}`);
        }
        if (after.errors > before.errors) {
            regressions.push(`${key}: errors ${before.errors} -> ${after.errors}`);
        }
        if (before.overflowX === false && after.overflowX === true) {
            regressions.push(`${key}: new horizontal overflow`);
        }
        if (before.viewportMeta === true && after.viewportMeta === false) {
            regressions.push(`${key}: viewport meta removed`);
        }
        if (after.bs3 > before.bs3) {
            regressions.push(`${key}: Bootstrap 3 markup ${before.bs3} -> ${after.bs3}`);
        }
        if (before.axeNodes !== null && after.axeNodes !== null && after.axeNodes > before.axeNodes) {
            regressions.push(`${key}: axe nodes ${before.axeNodes} -> ${after.axeNodes}`);
        }
    }
    return regressions;
};

export const summarize = (entries) => {
    const bad = entries.filter(isHardFailure);
    return {
        visits: entries.length,
        hardFailures: bad.length,
        withConsoleErrors: entries.filter((entry) => entry.consoleErrors.length > 0).length,
        withOverflowX: entries.filter((entry) => entry.metrics?.overflowX).length,
        withoutViewportMeta: entries.filter((entry) => entry.metrics && !entry.metrics.viewportMeta).length,
        axeNodes: entries.reduce((total, entry) => total + (entry.axe?.nodes ?? 0), 0),
        bs3Markup: entries.reduce((total, entry) => total + sum(entry.metrics?.bs3), 0)
    };
};

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

const renderGallery = (meta, entries, summary) => {
    const groups = new Map();
    for (const entry of entries) {
        const groupKey = `${entry.lang} / ${entry.viewport}`;
        groups.set(groupKey, [...(groups.get(groupKey) ?? []), entry]);
    }

    const sections = [...groups.entries()].map(([title, items]) => {
        const cards = items.map((entry) => {
            const flags = [
                isHardFailure(entry) ? 'FAIL' : '',
                entry.metrics?.overflowX ? 'overflow-x' : '',
                entry.consoleErrors.length ? `console:${entry.consoleErrors.length}` : '',
                entry.axe?.nodes ? `axe:${entry.axe.nodes}` : ''
            ].filter(Boolean).join(' ');
            const image = entry.screenshot
                ? `<a href="${escapeHtml(entry.screenshot)}"><img loading="lazy" src="${escapeHtml(entry.screenshot)}" alt=""></a>`
                : '';
            return `<figure class="${isHardFailure(entry) ? 'bad' : ''}">${image}<figcaption><b>${escapeHtml(entry.id)}</b> <small>${escapeHtml(entry.status)}</small><br><small>${escapeHtml(flags)}</small></figcaption></figure>`;
        });
        return `<h2>${escapeHtml(title)}</h2><div class="grid">${cards.join('')}</div>`;
    });

    return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>UI smoke report</title>
<style>
body{font:14px/1.4 system-ui,sans-serif;margin:24px;color:#0b1b3a}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
figure{margin:0;border:1px solid #cfd8e8;border-radius:6px;padding:6px;background:#fff}
figure.bad{border-color:#c0392b;background:#fdf0ee}
img{width:100%;height:160px;object-fit:cover;object-position:top;display:block}
figcaption{margin-top:4px;word-break:break-all}
</style></head><body>
<h1>UI smoke report</h1>
<p>${escapeHtml(meta.baseUrl)} - ${escapeHtml(meta.startedAt)}</p>
<pre>${escapeHtml(JSON.stringify(summary, null, 2))}</pre>
${sections.join('\n')}
</body></html>`;
};

export const writeReport = (outDir, meta, entries) => {
    mkdirSync(outDir, { recursive: true });
    const summary = summarize(entries);
    writeFileSync(join(outDir, 'report.json'), JSON.stringify({ meta, summary, entries }, null, 2));
    writeFileSync(join(outDir, 'index.html'), renderGallery(meta, entries, summary));
    return summary;
};
