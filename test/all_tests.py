#!/usr/bin/env python3
"""Aggregator for nc_bot_webhooks tests.

Discovers test files in this directory and runs each one:
  - ``*.py``  run with this interpreter
  - ``*.php`` run with ``php`` (requires a PHP CLI on the host; if ``php`` is
              not on PATH the test is reported as skipped, not failed)

Prints a per-test pass/fail report and a summary. Exits non-zero if any test
fails. Uses only the standard library.
"""
import os
import shutil
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
APP = os.path.dirname(HERE)


def discover():
    tests = []
    for name in sorted(os.listdir(HERE)):
        path = os.path.join(HERE, name)
        if name.startswith('all_tests') or not os.path.isfile(path):
            continue
        if name.endswith('.py'):
            tests.append(('python', name, [sys.executable, path]))
        elif name.endswith('.php'):
            php = shutil.which('php')
            tests.append(('php', name, [php, path] if php else None))
    return tests


def main():
    tests = discover()
    if not tests:
        print('No tests found in %s' % HERE)
        return 0

    passed, failed, skipped = 0, 0, 0
    print('Running %d test(s) in %s\n' % (len(tests), HERE))
    for kind, name, cmd in tests:
        if cmd is None:
            print('[SKIP] %s  (no %s on PATH)' % (name, 'php' if kind == 'php' else 'python'))
            skipped += 1
            continue
        print('── %s ──' % name)
        proc = subprocess.run(cmd, cwd=APP, capture_output=True, text=True)
        if proc.stdout:
            print(proc.stdout.rstrip())
        if proc.returncode == 0:
            print('[PASS] %s\n' % name)
            passed += 1
        else:
            if proc.stderr:
                print(proc.stderr.rstrip())
            print('[FAIL] %s (exit %d)\n' % (name, proc.returncode))
            failed += 1

    print('=' * 40)
    print('Summary: %d passed, %d failed, %d skipped' % (passed, failed, skipped))
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
