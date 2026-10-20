import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const BASELINE_FILE = fileURLToPath(new URL('./gates.baseline.json', import.meta.url));

const SCAN_DIRS = ['app/Views', 'app/Helpers', 'app/Controllers', 'app/Libraries', 'app/Models', 'public/js', 'resources/assets/js'];
const SKIPPED = ['app/Views/errors'];
const EXTENSIONS = ['.php', '.js'];

const PATTERNS = {
    glyphicon: /glyphicon/g,
    gridXs: /\bcol-xs-/g,
    gridOffset: /\bcol-(?:sm|md|lg)-offset-/g,
    pull: /\bpull-(?:left|right)\b/g,
    inputSize: /\binput-(?:sm|lg)\b/g,
    controlLabel: /\bcontrol-label\b/g,
    formHorizontal: /\bform-horizontal\b/g,
    btnDefault: /\bbtn-default\b/g,
    btnXs: /\bbtn-xs\b/g,
    panel: /\bpanel-(?:heading|body|footer|title|default|primary|info|success|warning|danger)\b/g,
    dataAttributes: /\bdata-(?:toggle|dismiss|target|ride|slide|spy)=/g,
    jumbotron: /\bjumbotron\b/g,
    navbarBs3: /\bnavbar-(?:default|inverse|toggle|right|left|form|btn)\b/g,
    helpBlock: /\bhelp-block\b/g,
    hiddenVisible: /\b(?:hidden|visible)-(?:xs|sm|md|lg)\b/g,
    labelBs3: /\blabel-(?:default|primary|success|info|warning|danger)\b/g,
    textAlign: /\btext-(?:left|right)\b/g,
    imgResponsive: /\bimg-responsive\b/g,
    inlineStyle: /\sstyle=["']/g,
    pluginBootstrapDialog: /BootstrapDialog|bootstrap-dialog/g,
    pluginSelectpicker: /\bselectpicker\b/g,
    pluginDatetimepicker: /datetimepicker/g,
    pluginToggle: /bootstrapToggle|bootstrap-toggle|data-toggle="toggle"/g,
    pluginTagsinput: /tagsinput/g,
    pluginJasny: /jasny|fileinput/g,
    pluginNotify: /\$\.notify|bootstrap-notify/g,
    pluginChartist: /[Cc]hartist/g
};

const listFiles = (directory) => {
    const files = [];
    for (const name of readdirSync(directory)) {
        const path = join(directory, name);
        const rel = relative(ROOT, path);
        if (SKIPPED.some((skipped) => rel.startsWith(skipped))) {
            continue;
        }
        if (statSync(path).isDirectory()) {
            files.push(...listFiles(path));
        } else if (EXTENSIONS.some((extension) => name.endsWith(extension))) {
            files.push(path);
        }
    }
    return files;
};

const measure = () => {
    const counts = Object.fromEntries(Object.keys(PATTERNS).map((name) => [name, 0]));
    for (const directory of SCAN_DIRS) {
        for (const file of listFiles(join(ROOT, directory))) {
            const content = readFileSync(file, 'utf8');
            for (const [name, pattern] of Object.entries(PATTERNS)) {
                counts[name] += (content.match(pattern) ?? []).length;
            }
        }
    }
    return counts;
};

const counts = measure();
const update = process.argv.includes('--update');

if (update) {
    writeFileSync(BASELINE_FILE, `${JSON.stringify(counts, null, 2)}\n`);
    console.log(`Baseline updated: ${relative(process.cwd(), BASELINE_FILE)}`);
}

const baseline = JSON.parse(readFileSync(BASELINE_FILE, 'utf8'));
const increased = [];
const rows = Object.keys(PATTERNS).map((name) => {
    const before = baseline[name] ?? 0;
    if (counts[name] > before) {
        increased.push(name);
    }
    return `${name.padEnd(24)} ${String(before).padStart(6)} -> ${String(counts[name]).padStart(6)}${counts[name] > before ? '  INCREASED' : ''}`;
});

console.log(rows.join('\n'));
console.log(`\nTotal: ${Object.values(baseline).reduce((a, b) => a + b, 0)} -> ${Object.values(counts).reduce((a, b) => a + b, 0)}`);

if (increased.length > 0) {
    console.error(`\nGate failed, legacy markup increased: ${increased.join(', ')}`);
    process.exit(1);
}
