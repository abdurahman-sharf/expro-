#!/usr/bin/env python3
"""Resolve a merge conflict in language files where both sides added keys.

usage: python3 tools/dev/resolve_lang_conflicts.py path [path ...]   (inside the merge worktree)
For every listed file it reads the stages of the index (1 base, 2 ours, 3 theirs), keeps every key of
both sides (a key changed on one side only takes that side's value; changed on both sides differently
is reported and ours wins), sorts the keys alphabetically and keeps the alignment column of ours.
"""
import re
import subprocess
import sys

ENTRY = re.compile(r"^(\s*)'((?:[^'\\]|\\.)*)'(\s*)=>(\s*)'((?:[^'\\]|\\.)*)',\s*$")


def stage(number, path):
    result = subprocess.run(['git', 'show', f':{number}:{path}'], capture_output=True, text=True)
    return result.stdout if result.returncode == 0 else None


def entries(text):
    found = {}
    for line in text.split('\n'):
        match = ENTRY.match(line)
        if match:
            found[match.group(2)] = match.group(5)
    return found


def column(text):
    columns = set()
    for line in text.split('\n'):
        match = ENTRY.match(line)
        if match:
            columns.add(len(match.group(1)) + len(match.group(2)) + 2 + len(match.group(3)))
    return columns.pop() if len(columns) == 1 and next(iter(columns)) > 4 + 4 else None


def render(key, value, width):
    left = f"    '{key}'"
    return f"{left} => '{value}'," if width is None else f"{left.ljust(width)}=> '{value}',"


def resolve(path):
    ours, theirs, base = stage(2, path), stage(3, path), stage(1, path)
    if ours is None or theirs is None:
        print(f'{path}: not a both-sides change, left alone')
        return
    base_entries = entries(base) if base else {}
    merged = dict(entries(ours))
    for key, value in entries(theirs).items():
        if key not in merged:
            merged[key] = value
        elif merged[key] != value:
            if base_entries.get(key) == merged[key]:
                merged[key] = value
            elif base_entries.get(key) != value:
                print(f'{path}: {key} differs on both sides, ours kept')
    width = column(ours)
    needed = 4 + max((len(key) + 2 for key in merged), default=0) + 1
    if width is not None and needed > width:
        width = needed
    lines = ours.split('\n')
    start = next(i for i, line in enumerate(lines) if line.strip() == 'return [') + 1
    end = max(i for i, line in enumerate(lines) if line.strip() == '];')
    body = [render(key, merged[key], width) for key in sorted(merged)]
    with open(path, 'w', encoding='utf-8') as handle:
        handle.write('\n'.join(lines[:start] + body + lines[end:]))
    subprocess.run(['git', 'add', path], check=True)
    print(f'{path}: {len(merged)} keys')


if __name__ == '__main__':
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    for file_path in sys.argv[1:]:
        resolve(file_path)
