#!/usr/bin/env bash

# Wrapper for hyperfine: runs Psalm and captures peak memory + issue count as side effects.
# hyperfine handles timing; this script captures what hyperfine can't (peak RSS, issue count).
#
# Usage: run-psalm.sh <project-dir> <psalm-config> <memory-file> [issues-file] [stats-file]
#
# The memory file receives peak RSS in MB (one value per line, appended).
# The issues file receives issue count (one value per line, appended).
# Psalm's exit code is passed through.

set -euo pipefail

PROJECT_DIR="${1:?Usage: run-psalm.sh <project-dir> <psalm-config> <memory-file> [issues-file] [stats-file]}"
PSALM_CONFIG="${2:?Usage: run-psalm.sh <project-dir> <psalm-config> <memory-file> [issues-file] [stats-file]}"
MEMORY_FILE="${3:?Usage: run-psalm.sh <project-dir> <psalm-config> <memory-file> [issues-file] [stats-file]}"
ISSUES_FILE="${4:-}"
STATS_FILE="${5:-}"

cd "$PROJECT_DIR"

if [[ ! -f "vendor/bin/psalm" ]]; then
    echo "Error: vendor/bin/psalm not found in $PROJECT_DIR" >&2
    exit 1
fi
if [[ ! -f "$PSALM_CONFIG" ]]; then
    echo "Error: Psalm config not found: $PSALM_CONFIG" >&2
    exit 1
fi

# Detect GNU time for memory capture
if command -v gtime &>/dev/null; then
    TIME_CMD=gtime
elif [[ -x /usr/bin/time ]] && /usr/bin/time --version 2>&1 | grep -q GNU; then
    TIME_CMD=/usr/bin/time
else
    echo "Error: GNU time is required for memory capture. Install: brew install gnu-time (macOS) or apt install time (Linux)" >&2
    exit 1
fi

TMPFILE=$(mktemp -t psalm-bench.XXXXXX)
PSALM_OUT=$(mktemp -t psalm-out.XXXXXX)
trap 'rm -f "$TMPFILE" "$PSALM_OUT"' EXIT

PSALM_EXIT=0
"$TIME_CMD" -f '%M' -o "$TMPFILE" \
    php -d memory_limit=-1 vendor/bin/psalm \
    --config="$PSALM_CONFIG" --threads=1 --no-cache --no-suggestions --no-progress --show-snippet=false --monochrome \
    >"$PSALM_OUT" 2>&1 \
    || PSALM_EXIT=$?

# Append peak RSS in MB (GNU time reports KB; last line to skip gtime's status prefix)
PEAK_KB=$(tail -1 "$TMPFILE")
if [[ ! "$PEAK_KB" =~ ^[0-9]+$ ]]; then
    echo "Error: failed to capture valid peak RSS from GNU time: '${PEAK_KB:-<empty>}'" >&2
    exit 1
fi
awk -v kb="$PEAK_KB" 'BEGIN {printf "%.1f\n", kb / 1024}' >> "$MEMORY_FILE"

# Parse Psalm summary (--monochrome ensures no ANSI codes)
# Append issue count (from summary line like "2000 errors found")
if [[ -n "$ISSUES_FILE" ]]; then
    ISSUE_COUNT=$(sed -n 's/^\([0-9]*\) error.*/\1/p' "$PSALM_OUT" | tail -1)
    echo "${ISSUE_COUNT:-0}" >> "$ISSUES_FILE"
fi

# Append type coverage percentage (from "infer types for 95.2214% of the codebase")
if [[ -n "$STATS_FILE" ]]; then
    TYPE_COVERAGE=$(sed -n 's/.*infer types for \([0-9.]*\)%.*/\1/p' "$PSALM_OUT" | tail -1)
    echo "${TYPE_COVERAGE:-}" >> "$STATS_FILE"
fi

exit $PSALM_EXIT
