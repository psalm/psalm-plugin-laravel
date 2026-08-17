#!/usr/bin/env bash
#
# Enforce PHPT authoring conventions for the type-test suite (tests/Type/tests).
# See the Conventions section of tests/Type/README.md for the rationale.
#
# Rules:
#   1. Use the `--EXPECTF--` section, never `--EXPECT--`. EXPECTF supports the
#      `%d` / `%s` / `%A` format specifiers we rely on for line numbers and
#      version-dependent output; EXPECT does exact matching and breaks on any
#      drift.
#   2. Never hardcode `on line <number>`. Line numbers shift whenever the
#      fixture above the expectation changes; use `on line %d` instead.
#   3. Never lead an issue line with `%A`. PsalmTester::formatErrors() has no
#      preamble or trailer, so a leading `%A` can only absorb unasserted ISSUE
#      lines, silently under-checking the test (#1359). Allowlist a file only
#      for provably version-variable output.
#
# Exits non-zero (and prints file:line) on the first kind of violation found.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TESTS_DIR="${ROOT}/tests/Type/tests"
WILDCARD_ALLOWLIST="${ROOT}/bin/ci/phpt-leading-wildcard-allowlist.txt"

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

# Rule 3: `%A` leading an issue line, in any of the forms "%AFooIssue on
# line ...", "%AFooIssue%A", or "%AFooIssue%a". `%A` (`.*?`) and `%a` (`.+?`)
# both compile to a lazy dotall match (PHPUnit's StringMatchesFormatDescription
# uses the `/s` modifier), so both can absorb unasserted ISSUE lines across
# newlines; only they pose the swallowing risk this rule guards against.
# Anchor on `%A` directly followed by an issue-type identifier, not `^%A`: a
# single physical --EXPECTF-- line can pack multiple issues, so the leading
# `%A` is not always at the start of the line.
wildcard_hits=""
while IFS= read -r hit; do
    file="${hit%%:*}"
    rel="${file#"${TESTS_DIR}"/}"
    grep -qxF "${rel}" "${WILDCARD_ALLOWLIST}" 2>/dev/null || wildcard_hits="${wildcard_hits}${hit}"$'\n'
done < <(grep -rnE '%A[A-Za-z]+(%A|%a| on line)' "${TESTS_DIR}" --include='*.phpt' || true)
if [ -n "${wildcard_hits}" ]; then
    echo "ERROR: PHPT expectations must not lead an issue line with '%A':"
    printf '%s' "${wildcard_hits}"
    echo "Enumerate the issue explicitly, or add the file to ${WILDCARD_ALLOWLIST}"
    echo "only for provably version-variable output."
    echo
    status=1
fi

if [ "${status}" -eq 0 ]; then
    echo "PHPT conventions OK (tests/Type/tests)."
fi

exit "${status}"
