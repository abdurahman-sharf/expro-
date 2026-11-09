#!/usr/bin/env python3
"""Prove that tools/ui-smoke/picker.mjs catches the removal of a part of the search picker.

usage: PLAYWRIGHT_EXECUTABLE_PATH=/path/to/chromium python3 tools/ui-smoke/picker-mutations.py [name-prefix ...]
Each mutation is applied alone to resources/assets/js/45-accounting.js and to the built bundle
public/assets/js/app.js (which holds the same code), the smoke runs, and both files are always restored.
DETECTED: the smoke failed. UNDETECTED: it passed. INVALID: the text to change is not in each file exactly once.
"""
import json
import os
import subprocess
import sys
from pathlib import Path

ROOT = str(Path(__file__).resolve().parents[2])
SOURCE = ROOT + '/resources/assets/js/45-accounting.js'
BUNDLE = ROOT + '/public/assets/js/app.js'
ENV = dict(os.environ)

M = [
    ('J01 the box announces itself as a combobox', "role: 'combobox', ", ''),
    ('J02 the box names the list it controls', "'aria-controls': listId, ", ''),
    ('J03 the live region has the status role', 'role="status" aria-live="polite"', 'aria-live="polite"'),
    ('J04 the arrow keys wrap around the options', '(active + step + entries.length) % entries.length', 'Math.max(0, Math.min(entries.length - 1, active + step))'),
    ('J05 Escape closes the list', "event.key === 'Escape' && open", "event.key === 'EscapeNever' && open"),
    ('J06 the active option is marked selected', ".attr('aria-selected', position === index ? 'true' : 'false')", ".attr('aria-selected', 'false')"),
    ('J07 the box points at the active option', "$input.attr('aria-activedescendant', listId + '_' + index);", ''),
    ('J08 the list says that more results exist', 'if (more) {', 'if (false) {'),
    ('J09 the live region says how many results there are', "text.count.replace('{0}', found.length)", 'text.count'),
    ('J10 a click chooses an option', "$list.on('mousedown', '[role=option]'", "$list.on('mouseup', '[role=option]'"),
    ('J11 the list sits under the box', 'left: rect.left + window.pageXOffset,', 'left: rect.left + window.pageXOffset + 1000,'),
    ('J12 choosing fills the hidden id through the events of the screen', "$chosen.val(entry.label).trigger('input').trigger('change');", '$chosen.val(entry.label);'),
    ('J13 a search that fails says so', "state !== 'abort' && $status.text(text.failed);", 'void 0;'),
    ('J14 an empty result says so', 'found.length === 0 ? text.none :', "found.length === 0 ? '' :"),
    ('J15 the term is trimmed and sent with the kind', '$.extend({}, remote.params, {term: $.trim($asked.val())})', '{term: $.trim($asked.val())}'),
    ('J16 the box is closed when it loses focus', "}).on('focusout', remote.selector, function() {\n            clearTimeout(timer);\n            request && request.abort();\n            close();", "}).on('focusout', remote.selector, function() {\n            clearTimeout(timer);\n            request && request.abort();"),
    ('J17 choosing sends no search of its own', 'choosing || search();', 'search();'),
    ('J18 choosing cancels a search that was typed a moment before', "clearTimeout(timer);\n            request && request.abort();\n            close();\n            choosing = true;", "close();\n            choosing = true;"),
    ('J19 an entry that was chosen out of twins is the one the name stands for', 'pinned[normalize(entry.label)] = remember(entry);', 'remember(entry);'),
    ('J20 a name that two entries share is left unresolved', 'found && found.length === 1 ? found[0] : null', 'found ? found[found.length - 1] : null'),
    ('J21 an entry is known by the same id wherever it came from', 'const known = {id: String(entry.id),', 'const known = {id: entry.id,'),
    ('J22 an entry already on the screen is the one its name stands for', 'pin({id: String(entry.id), label: entry.label, code: entry.code});', 'remember({id: String(entry.id), label: entry.label, code: entry.code});'),
]

results = []
ONLY = sys.argv[1:]
for name, find, replace in M:
    if ONLY and not any(name.startswith(x) for x in ONLY):
        continue
    originals = {}
    verdict = 'INVALID'
    try:
        ok = True
        for path in (SOURCE, BUNDLE):
            with open(path, encoding='utf-8') as handle:
                text = handle.read()
            originals[path] = text
            if text.count(find) != 1:
                ok = False
                print('count', text.count(find), path, flush=True)
        if ok:
            for path in (SOURCE, BUNDLE):
                with open(path, 'w', encoding='utf-8') as handle:
                    handle.write(originals[path].replace(find, replace))
            run = subprocess.run(['node', 'picker.mjs'], cwd=ROOT + '/tools/ui-smoke', env=ENV, capture_output=True, text=True, timeout=300)
            verdict = 'UNDETECTED' if run.returncode == 0 else 'DETECTED'
    finally:
        for path, text in originals.items():
            with open(path, 'w', encoding='utf-8') as handle:
                handle.write(text)
    results.append({'name': name, 'detected': verdict == 'DETECTED', 'verdict': verdict})
    print(f'{verdict}: {name}', flush=True)

print(json.dumps({'DETECTED': sum(r['detected'] for r in results), 'TOTAL': len(results)}))
sys.exit(0 if all(r['detected'] for r in results) else 1)
