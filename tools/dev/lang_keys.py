#!/usr/bin/env python3
"""Add keys to one language file in every locale of app/Language.

usage: python3 tools/dev/lang_keys.py LangFile.php keys.json [--root app/Language]
keys.json: {"key": {"en": "English text", "ar": "Arabic text"}, ...}
en and en-GB get the English text, ar-EG and ar-LB the Arabic text, every other locale ''.
Keys are inserted in alphabetical order; a file that pads '=>' to a column keeps the column
(widened for the whole file when a new key is longer than the widest one).
"""
import json
import os
import re
import sys

ENTRY = re.compile(r"^(\s*)'((?:[^'\\]|\\.)*)'(\s*)=>(\s*)'((?:[^'\\]|\\.)*)',\s*$")


def quote(text):
    return "'" + text.replace('\\', '\\\\').replace("'", "\\'") + "'"


def value_for(locale, texts):
    if locale in ('en', 'en-GB'):
        return texts['en']
    if locale in ('ar-EG', 'ar-LB'):
        return texts['ar']
    return ''


def padded_column(lines):
    columns = set()
    for line in lines:
        match = ENTRY.match(line)
        if match:
            columns.add(len(match.group(1)) + len(match.group(2)) + 2 + len(match.group(3)))
    return columns.pop() if len(columns) == 1 and next(iter(columns)) > 0 else None


def render(key, value, column):
    left = f"    {quote_raw(key)}"
    if column is None:
        return f"{left} => {value},"
    return f"{left.ljust(column)}=> {value},"


def quote_raw(key):
    return "'" + key + "'"


def process(path, new_keys, locale):
    if not os.path.exists(path):
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, 'w', encoding='utf-8') as handle:
            handle.write("<?php\n\nreturn [\n];\n")

    with open(path, encoding='utf-8') as handle:
        lines = handle.read().split('\n')

    start = next(i for i, line in enumerate(lines) if line.strip() == 'return [') + 1
    end = max(i for i, line in enumerate(lines) if line.strip() == '];')
    body = lines[start:end]
    column = padded_column(body)
    existing = {}
    for line in body:
        match = ENTRY.match(line)
        if match:
            existing[match.group(2)] = line

    todo = {key: texts for key, texts in new_keys.items() if key not in existing}
    if not todo:
        return 0

    width = column
    needed = 4 + max(len(key) + 2 for key in todo) + 1
    if column is not None and needed > column:
        width = needed
    rows = []
    for line in body:
        match = ENTRY.match(line)
        rows.append((match.group(2) if match else None, line))
    for key, texts in todo.items():
        rows.append((key, render(key, quote(value_for(locale, texts)), width)))
    keyed = [row for row in rows if row[0] is not None]
    other = [row for row in rows if row[0] is None]
    keyed.sort(key=lambda row: row[0])
    rendered = []
    for key, line in keyed:
        match = ENTRY.match(line)
        if width is not None and match:
            rendered.append(render(key, "'" + match.group(5) + "'", width))
        else:
            rendered.append(line)
    rendered.extend(line for _, line in other)

    with open(path, 'w', encoding='utf-8') as handle:
        handle.write('\n'.join(lines[:start] + rendered + lines[end:]))
    return len(todo)


def main():
    args = sys.argv[1:]
    root = 'app/Language'
    if '--root' in args:
        index = args.index('--root')
        root = args[index + 1]
        del args[index:index + 2]
    if len(args) != 2:
        sys.exit(__doc__)
    file_name, keys_path = args
    with open(keys_path, encoding='utf-8') as handle:
        new_keys = json.load(handle)
    for key, texts in new_keys.items():
        if 'en' not in texts or 'ar' not in texts:
            sys.exit(f'{key}: both "en" and "ar" are required')
    added = 0
    for locale in sorted(os.listdir(root)):
        directory = os.path.join(root, locale)
        if os.path.isdir(directory):
            added += process(os.path.join(directory, file_name), new_keys, locale)
    print(f'{added} entries added to {file_name}')


if __name__ == '__main__':
    main()
