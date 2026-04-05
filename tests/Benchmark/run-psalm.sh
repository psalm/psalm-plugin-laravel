#!/usr/bin/env bash

# Wrapper for hyperfine: runs Psalm and captures peak memory as a side effect.
# hyperfine handles timing; this script captures what hyperfine can't (peak RSS).
#
# Usage: run-psalm.sh <project-dir> <psalm-config> <memory-file>
#
# The memory file receives peak RSS in MB (one value per line, appended).
# Psalm's exit code is passed through.

set -euo pipefail

PROJECT_DIR="${1:?Usage: run-psalm.sh <project-dir> <psalm-config> <memory-file>}"
PSALM_CONFIG="$2"
MEMORY_FILE="$3"

cd "$PROJECT_DIR"

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
trap 'rm -f "$TMPFILE"' EXIT

PSALM_EXIT=0
"$TIME_CMD" -f '%M' -o "$TMPFILE" \
    php -d memory_limit=-1 vendor/bin/psalm \
    --config="$PSALM_CONFIG" --threads=1 --no-cache --no-suggestions --no-progress \
    || PSALM_EXIT=$?

# Append peak RSS in MB (GNU time reports KB; last line to skip gtime's status prefix)
PEAK_KB=$(tail -1 "$TMPFILE")
if [[ ! "$PEAK_KB" =~ ^[0-9]+$ ]]; then
    echo "Error: failed to capture valid peak RSS from GNU time: '${PEAK_KB:-<empty>}'" >&2
    exit 1
fi
awk -v kb="$PEAK_KB" 'BEGIN {printf "%.1f\n", kb / 1024}' >> "$MEMORY_FILE"

exit $PSALM_EXIT
