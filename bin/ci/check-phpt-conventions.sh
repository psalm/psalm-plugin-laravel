#!/usr/bin/env bash
#
# Enforce PHPT authoring conventions for the type-test suite (tests/Type/tests).
# See tests/Type/CLAUDE.local.md for the rationale.
#
# Rules:
#   1. Use the `--EXPECTF--` section, never `--EXPECT--`. EXPECTF supports the
#      `%d` / `%s` / `%A` format specifiers we rely on for line numbers and
#      version-dependent output; EXPECT does exact matching and breaks on any
#      drift.
#   2. Never hardcode `on line <number>`. Line numbers shift whenever the
#      fixture above the expectation changes; use `on line %d` instead.
#
# Exits non-zero (and prints file:line) on the first kind of violation found.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TESTS_DIR="${ROOT}/tests/Type/tests"

status=0

# Rule 1: --EXPECT-- marker (a line that is exactly "--EXPECT--").
if expect_hits="$(grep -rnE '^--EXPECT--$' "${TESTS_DIR}")"; then
    echo "ERROR: PHPT files must use '--EXPECTF--', not '--EXPECT--':"
    echo "${expect_hits}"
    echo
    status=1
fi

# Rule 2: hardcoded line number in expectations ("on line 34" -> "on line %d").
if line_hits="$(grep -rnE 'on line [0-9]' "${TESTS_DIR}")"; then
    echo "ERROR: PHPT expectations must use 'on line %d', not a hardcoded number:"
    echo "${line_hits}"
    echo
    status=1
fi

if [ "${status}" -eq 0 ]; then
    echo "PHPT conventions OK (tests/Type/tests)."
fi

exit "${status}"
