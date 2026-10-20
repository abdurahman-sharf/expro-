import { copyFileSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../', import.meta.url));
const OUT = join(ROOT, 'public/assets');
const SOURCES = join(ROOT, 'resources/assets');
const FONT_WEIGHTS = [400, 500, 600, 700];
const FONT_SUBSETS = ['arabic', 'latin'];

const modules = (path) => join(ROOT, 'node_modules', path);
const read = (file) => readFileSync(file, 'utf8');
const stripSourceMaps = (content) => content
    .replace(/\n?\/\/# sourceMappingURL=.*$/gm, '')
    .replace(/\n?\/\*# sourceMappingURL=.*?\*\//g, '');

const write = (relative, content) => {
    const target = join(OUT, relative);
    mkdirSync(join(target, '..'), { recursive: true });
    writeFileSync(target, content);
    return target;
};

const copy = (from, relative) => {
    const target = join(OUT, relative);
    mkdirSync(join(target, '..'), { recursive: true });
    copyFileSync(from, target);
};

const PATCHES = {
    'bootstrap-daterangepicker/daterangepicker.js': [
        ['fa fa-calendar glyphicon glyphicon-calendar', 'bi bi-calendar3'],
        ['fa fa-clock-o glyphicon glyphicon-time', 'bi bi-clock'],
        ['btn-default', 'btn-outline-secondary']
    ]
};

const patched = (file) => {
    const patches = Object.entries(PATCHES).find(([name]) => file.endsWith(name))?.[1] ?? [];
    return patches.reduce((content, [from, to]) => {
        if (!content.includes(from)) {
            throw new Error(`Patch target "${from}" not found in ${file}`);
        }
        return content.replaceAll(from, to);
    }, read(file));
};

const concat = (files, separator) => files.map((file) => stripSourceMaps(patched(file)).trim()).join(separator);

const sourceFiles = (directory, extension) => {
    const path = join(SOURCES, directory);
    return existsSync(path)
        ? readdirSync(path).filter((name) => name.endsWith(extension)).sort().map((name) => join(path, name))
        : [];
};

const plexFontFaces = () => {
    const blocks = [];
    for (const weight of FONT_WEIGHTS) {
        const css = read(modules(`@fontsource/ibm-plex-sans-arabic/${weight}.css`));
        for (const match of css.matchAll(/\/\* ibm-plex-sans-arabic-([a-z-]+)-\d+-normal \*\/\s*(@font-face\s*\{[^}]*\})/g)) {
            if (!FONT_SUBSETS.includes(match[1])) {
                continue;
            }
            const file = `ibm-plex-sans-arabic-${match[1]}-${weight}-normal.woff2`;
            copy(modules(`@fontsource/ibm-plex-sans-arabic/files/${file}`), `fonts/plex/${file}`);
            blocks.push(match[2].replace(/src:[^;]*;/, `src: url("../fonts/plex/${file}") format("woff2");`));
        }
    }
    return blocks.join('\n');
};

const VENDOR_CSS = [
    'bootstrap-select/dist/css/bootstrap-select.min.css',
    'bootstrap-table/dist/bootstrap-table.min.css',
    'bootstrap-table/dist/extensions/sticky-header/bootstrap-table-sticky-header.min.css',
    'bootstrap-daterangepicker/daterangepicker.css',
    'flatpickr/dist/flatpickr.min.css',
    'jquery-ui-dist/jquery-ui.structure.min.css',
    'chartist/dist/chartist.min.css',
    'chartist-plugin-tooltips/dist/chartist-plugin-tooltip.css'
];

const VENDOR_JS = [
    'jquery/dist/jquery.min.js',
    'jquery-ui-dist/jquery-ui.min.js',
    'jquery-form/dist/jquery.form.min.js',
    'jquery-validation/dist/jquery.validate.min.js',
    'bootstrap/dist/js/bootstrap.bundle.min.js',
    'bootstrap-select/dist/js/bootstrap-select.min.js',
    'bootstrap-table/dist/bootstrap-table.min.js',
    'bootstrap-table/dist/extensions/export/bootstrap-table-export.min.js',
    'bootstrap-table/dist/extensions/mobile/bootstrap-table-mobile.min.js',
    'bootstrap-table/dist/extensions/sticky-header/bootstrap-table-sticky-header.min.js',
    'moment/min/moment.min.js',
    'bootstrap-daterangepicker/daterangepicker.js',
    'flatpickr/dist/flatpickr.min.js',
    'bootstrap-notify/bootstrap-notify.min.js',
    'es6-promise/dist/es6-promise.min.js',
    'file-saver/dist/FileSaver.min.js',
    'html2canvas/dist/html2canvas.min.js',
    'jspdf/dist/jspdf.umd.min.js',
    'dompurify/dist/purify.min.js',
    'jspdf-autotable/dist/jspdf.plugin.autotable.min.js',
    'tableexport.jquery.plugin/tableExport.min.js',
    'chartist/dist/chartist.min.js',
    'chartist-plugin-pointlabels/dist/chartist-plugin-pointlabels.min.js',
    'chartist-plugin-axistitle/dist/chartist-plugin-axistitle.min.js',
    'chartist-plugin-tooltips/dist/chartist-plugin-tooltip.min.js',
    'chartist-plugin-barlabels/dist/chartist-plugin-barlabels.min.js',
    'clipboard/dist/clipboard.min.js'
];

for (const directory of ['css', 'js', 'fonts']) {
    rmSync(join(OUT, directory), { recursive: true, force: true });
}

write('css/bootstrap.min.css', stripSourceMaps(read(modules('bootstrap/dist/css/bootstrap.min.css'))));
write('css/bootstrap.rtl.min.css', stripSourceMaps(read(modules('bootstrap/dist/css/bootstrap.rtl.min.css'))));

copy(modules('bootstrap-icons/font/fonts/bootstrap-icons.woff2'), 'fonts/bootstrap-icons.woff2');
copy(modules('bootstrap-icons/font/fonts/bootstrap-icons.woff'), 'fonts/bootstrap-icons.woff');
const icons = stripSourceMaps(read(modules('bootstrap-icons/font/bootstrap-icons.min.css')))
    .replace(/url\("(?:\.\/)?fonts\/(bootstrap-icons\.woff2?)\?[^"]*"\)/g, 'url("../fonts/$1")');

write('css/vendor.min.css', [plexFontFaces(), icons, concat(VENDOR_CSS.map(modules), '\n')].join('\n'));
write('js/vendor.min.js', concat(VENDOR_JS.map(modules), '\n;\n'));
write('js/jquery.min.js', stripSourceMaps(read(modules('jquery/dist/jquery.min.js'))));

write('css/app.css', concat(sourceFiles('css', '.css'), '\n\n'));
write('css/login.css', concat(sourceFiles('pages', 'login.css'), ''));
write('js/app.js', concat(sourceFiles('js', '.js'), '\n;\n'));

const size = (relative) => `${(statSync(join(OUT, relative)).size / 1024).toFixed(1)} KB`;
for (const file of ['css/bootstrap.min.css', 'css/bootstrap.rtl.min.css', 'css/vendor.min.css', 'css/app.css', 'js/vendor.min.js', 'js/app.js']) {
    console.log(`${basename(file).padEnd(24)} ${size(file)}`);
}
