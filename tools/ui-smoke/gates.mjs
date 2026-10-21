import { existsSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../../', import.meta.url));
const BASELINE_FILE = fileURLToPath(new URL('./gates.baseline.json', import.meta.url));

const SCAN_DIRS = ['app/Views', 'app/Helpers', 'app/Controllers', 'app/Libraries', 'app/Models', 'public/js', 'resources/assets/js', 'resources/assets/css'];
const SKIPPED = ['app/Views/errors'];

const MARKUP = ['.php', '.js'];
const SCRIPT = ['.js'];
const STYLE = ['.css'];

// Print, email, PDF and barcode markup cannot rely on the application stylesheet or on its tokens.
const PRINT_CONTEXT = [
    'app/Views/sales/receipt',
    'app/Views/receivings/receipt',
    'app/Views/documents',
    'app/Views/barcodes',
    'app/Controllers/Exports.php',
    'app/Libraries/Barcode_lib.php',
    'resources/assets/css/80-receipt.css',
    'resources/assets/css/81-document.css',
    'resources/assets/css/82-print.css'
];

const THEME_FILE = 'resources/assets/css/00-theme.css';

// Every count must be zero, except the patterns in RETAINED, which may not grow past gates.baseline.json.
const RETAINED = ['pluginSelectpicker', 'pluginNotify', 'importantCss'];

const PATTERNS = {
    glyphicon: { regex: /glyphicon/g, kinds: MARKUP },
    gridXs: { regex: /\bcol-xs-/g, kinds: MARKUP },
    gridOffset: { regex: /\bcol-(?:sm|md|lg)-offset-/g, kinds: MARKUP },
    pull: { regex: /\bpull-(?:left|right)\b/g, kinds: MARKUP },
    inputSize: { regex: /\binput-(?:sm|lg)\b/g, kinds: MARKUP },
    controlLabel: { regex: /\bcontrol-label\b/g, kinds: MARKUP },
    formHorizontal: { regex: /\bform-horizontal\b/g, kinds: MARKUP },
    btnDefault: { regex: /\bbtn-default\b/g, kinds: MARKUP },
    btnXs: { regex: /\bbtn-xs\b/g, kinds: MARKUP },
    panel: { regex: /\bpanel-(?:heading|body|footer|title|default|primary|info|success|warning|danger)\b/g, kinds: MARKUP },
    dataAttributes: { regex: /\bdata-(?:toggle|dismiss|target|ride|slide|spy)=/g, kinds: MARKUP },
    jumbotron: { regex: /\bjumbotron\b/g, kinds: MARKUP },
    navbarBs3: { regex: /\bnavbar-(?:default|inverse|toggle|right|left|form|btn)\b/g, kinds: MARKUP },
    helpBlock: { regex: /\bhelp-block\b/g, kinds: MARKUP },
    hiddenVisible: { regex: /\b(?:hidden|visible)-(?:xs|sm|md|lg)\b/g, kinds: MARKUP },
    labelBs3: { regex: /\blabel-(?:default|primary|success|info|warning|danger)\b/g, kinds: MARKUP },
    textAlign: { regex: /\btext-(?:left|right)\b/g, kinds: MARKUP },
    imgResponsive: { regex: /\bimg-responsive\b/g, kinds: MARKUP },
    physicalUtility: { regex: /\b(?:ml|mr|pl|pr)-(?:[0-5]|auto)\b|\bfloat-(?:left|right)\b|\bborder-(?:left|right)\b|\brounded-(?:left|right)\b/g, kinds: MARKUP },
    inlineStyle: { regex: /\sstyle=["']/g, kinds: MARKUP, exempt: PRINT_CONTEXT },
    pluginBootstrapDialog: { regex: /BootstrapDialog|bootstrap-dialog/g, kinds: MARKUP },
    pluginSelectpicker: { regex: /\bselectpicker\b/g, kinds: MARKUP },
    pluginDatetimepicker: { regex: /datetimepicker/g, kinds: MARKUP },
    pluginToggle: { regex: /bootstrapToggle|bootstrap-toggle|data-toggle="toggle"/g, kinds: MARKUP },
    pluginTagsinput: { regex: /tagsinput/g, kinds: MARKUP },
    pluginJasny: { regex: /jasny|fileinput/g, kinds: MARKUP },
    pluginNotify: { regex: /\$\.notify|bootstrap-notify/g, kinds: MARKUP },
    pluginChartist: { regex: /[Cc]hartist/g, kinds: MARKUP },
    varInScripts: { regex: /(?:^|[^\w$.@])var\s+[A-Za-z_$]/gm, kinds: SCRIPT },
    varInViews: { regex: /(?:^|[^\w$.@])var\s+[A-Za-z_$]/gm, kinds: ['.php'], dirs: ['app/Views'] },
    inlineHandler: { regex: /\son(?:click|change|submit|input|keyup|keydown|focus|blur)=["']/g, kinds: ['.php'], dirs: ['app/Views'] },
    physicalCss: { regex: /(?:margin|padding)-(?:left|right)|border-(?:left|right)(?:-|:)|text-align:\s*(?:left|right)|float:\s*(?:left|right)|^\s*(?:left|right):/gm, kinds: STYLE, exempt: PRINT_CONTEXT },
    hexColor: { regex: /#[0-9a-fA-F]{3,8}\b/g, kinds: STYLE, exempt: [...PRINT_CONTEXT, THEME_FILE] },
    importantCss: { regex: /!important/g, kinds: STYLE, exempt: PRINT_CONTEXT }
};

const listFiles = (directory) => {
    const files = [];
    if (!existsSync(directory)) {
        return files;
    }
    for (const name of readdirSync(directory)) {
        const path = join(directory, name);
        const rel = relative(ROOT, path);
        if (SKIPPED.some((skipped) => rel.startsWith(skipped))) {
            continue;
        }
        if (statSync(path).isDirectory()) {
            files.push(...listFiles(path));
        } else {
            files.push(path);
        }
    }
    return files;
};

const measure = () => {
    const counts = Object.fromEntries(Object.keys(PATTERNS).map((name) => [name, 0]));
    for (const directory of SCAN_DIRS) {
        for (const file of listFiles(join(ROOT, directory))) {
            const rel = relative(ROOT, file);
            const extension = file.slice(file.lastIndexOf('.'));
            let content = null;
            for (const [name, pattern] of Object.entries(PATTERNS)) {
                if (!pattern.kinds.includes(extension)) {
                    continue;
                }
                if (pattern.dirs && !pattern.dirs.some((prefix) => rel.startsWith(prefix))) {
                    continue;
                }
                if (pattern.exempt && pattern.exempt.some((prefix) => rel.startsWith(prefix))) {
                    continue;
                }
                content ??= readFileSync(file, 'utf8');
                counts[name] += (content.match(pattern.regex) ?? []).length;
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
const failed = [];
const rows = Object.keys(PATTERNS).map((name) => {
    const before = baseline[name] ?? 0;
    const limit = RETAINED.includes(name) ? before : 0;
    if (counts[name] > limit) {
        failed.push(name);
    }
    const note = counts[name] > limit ? (RETAINED.includes(name) ? '  INCREASED' : '  MUST BE ZERO') : '';
    return `${name.padEnd(24)} ${String(before).padStart(6)} -> ${String(counts[name]).padStart(6)}${note}`;
});

console.log(rows.join('\n'));
console.log(`\nTotal: ${Object.values(baseline).reduce((a, b) => a + b, 0)} -> ${Object.values(counts).reduce((a, b) => a + b, 0)}`);

if (failed.length > 0) {
    console.error(`\nGate failed, legacy markup remains or increased: ${failed.join(', ')}`);
    process.exit(1);
}
