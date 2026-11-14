#!/usr/bin/env python3
"""Dev only: turns the evidence folders of the tiers (http.json and cli.jsonl) into one markdown table per kind."""
import json, sys, os

root = sys.argv[1]
folders = sys.argv[2:]
http = {}
cli = {}
for folder in folders:
    path = os.path.join(root, folder)
    if os.path.exists(os.path.join(path, 'http.json')):
        for row in json.load(open(os.path.join(path, 'http.json')))['results']:
            http.setdefault(row['name'], {})[folder] = row
    if os.path.exists(os.path.join(path, 'cli.jsonl')):
        for line in open(os.path.join(path, 'cli.jsonl')):
            line = line.strip()
            if line.startswith('{'):
                row = json.loads(line)
                cli.setdefault(row['name'], {})[folder] = row

print('| page | ' + ' | '.join(folders) + ' |')
print('|---|' + '---|' * len(folders))
for name, byfolder in http.items():
    cells = []
    for folder in folders:
        row = byfolder.get(folder)
        if row is None:
            cells.append('-')
            continue
        flag = ' FATAL' if row['fatal'] else ''
        cells.append(f"{row['median_ms']} ms / {row['peak_mb']} MB / {row['questions']} q / {row['status']}{flag}")
    print(f'| {name} | ' + ' | '.join(cells) + ' |')

print()
print('| cli | ' + ' | '.join(folders) + ' |')
print('|---|' + '---|' * len(folders))
for name, byfolder in cli.items():
    cells = []
    for folder in folders:
        row = byfolder.get(folder)
        if row is None:
            cells.append('-')
            continue
        flag = ' FAILED ' + str(row['failed'])[:60] if row['failed'] else ''
        cells.append(f"{row['ms']} ms / {row['peak_mb']} MB / {row['questions']} q{flag}")
    print(f'| {name} | ' + ' | '.join(cells) + ' |')
