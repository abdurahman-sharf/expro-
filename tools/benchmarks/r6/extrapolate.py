#!/usr/bin/env python3
"""Dev only: from two tiers (documents n1 < n2) the growth exponent of each page, and what the same power law gives at a third size.
Usage: extrapolate.py ROOT FOLDER1 DOCS1 FOLDER2 DOCS2 DOCS3"""
import json, math, os, sys

root, f1, n1, f2, n2, n3 = sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4], int(sys.argv[5]), int(sys.argv[6])

def load(folder):
    rows = {}
    for suffix in ('', '-b', '-suspended'):
        path = os.path.join(root, folder + suffix, 'http.json')
        if os.path.exists(path):
            for row in json.load(open(path))['results']:
                rows[row['name']] = row
    return rows

a, b = load(f1), load(f2)
print('| الصفحة | زمن %d | زمن %d | الأس | متوقع %d | ذاكرة %d | ذاكرة %d | متوقع %d |' % (n1, n2, n3, n1, n2, n3))
print('|---|---|---|---|---|---|---|---|')
for name, r2 in b.items():
    r1 = a.get(name)
    if r1 is None or r1['fatal'] or r2['fatal'] or str(r1['status']).split('/')[0] != '200' or str(r2['status']).split('/')[0] != '200':
        continue
    t1, t2 = max(r1['median_ms'], 1), max(r2['median_ms'], 1)
    m1, m2 = max(r1['peak_mb'], 0.1), max(r2['peak_mb'], 0.1)
    et = math.log(t2 / t1) / math.log(n2 / n1)
    em = math.log(m2 / m1) / math.log(n2 / n1)
    if t2 < 300 and m2 < 20:
        continue
    print(f"| `{name}` | {t1} | {t2} | {et:.2f} | {round(t2 * (n3 / n2) ** max(et, 0))} ms | {m1} | {m2} | {round(m2 * (n3 / n2) ** max(em, 0))} MB |")
