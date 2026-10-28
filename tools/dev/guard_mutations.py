#!/usr/bin/env python3
"""Prove that tests catch the removal of a guard.

usage: MYSQL_DB_NAME=ospos_test_x python3 tools/dev/guard_mutations.py mutations.json [name-prefix ...]
mutations.json: [{"name": "G01 ...", "file": "app/...", "find": "exact text", "replace": "text",
                  "tests": ["tests/..."], "all": false}, ...]
Each mutation is applied alone, the listed test files run, and the file is always restored.
DETECTED: the tests failed. UNDETECTED: they passed. INVALID: the find text is missing or
ambiguous, or the mutated file no longer parses.
"""
import json
import subprocess
import sys


def run_tests(tests):
    result = subprocess.run(['php', 'vendor/bin/phpunit', '--no-coverage', '--stop-on-failure', *tests], capture_output=True, text=True)
    return result.returncode, result.stdout + result.stderr


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    with open(sys.argv[1], encoding='utf-8') as handle:
        mutations = json.load(handle)
    prefixes = sys.argv[2:]
    summary = {'DETECTED': 0, 'UNDETECTED': 0, 'INVALID': 0}
    for mutation in mutations:
        if prefixes and not any(mutation['name'].startswith(prefix) for prefix in prefixes):
            continue
        with open(mutation['file'], encoding='utf-8') as handle:
            original = handle.read()
        count = original.count(mutation['find'])
        if count == 0 or (count > 1 and not mutation.get('all')):
            verdict = 'INVALID'
            detail = f'find text occurs {count} times'
        else:
            mutated = original.replace(mutation['find'], mutation['replace'])
            try:
                with open(mutation['file'], 'w', encoding='utf-8') as handle:
                    handle.write(mutated)
                lint = subprocess.run(['php', '-l', mutation['file']], capture_output=True, text=True)
                if lint.returncode != 0:
                    verdict, detail = 'INVALID', 'mutated file does not parse'
                else:
                    code, output = run_tests(mutation['tests'])
                    verdict = 'UNDETECTED' if code == 0 else 'DETECTED'
                    detail = ''
            finally:
                with open(mutation['file'], 'w', encoding='utf-8') as handle:
                    handle.write(original)
        summary[verdict] += 1
        print(f"{verdict}: {mutation['name']} {detail}".rstrip(), flush=True)
    print(json.dumps(summary))
    sys.exit(0 if summary['UNDETECTED'] == 0 and summary['INVALID'] == 0 else 1)


if __name__ == '__main__':
    main()
