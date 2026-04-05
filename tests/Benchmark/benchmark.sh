#!/usr/bin/env bash

# Run Psalm on a Laravel project and output timing/memory metrics as JSON.
# Expects the project to already have vendor/ installed and a psalm.xml config.
#
# Usage:
#   benchmark.sh <project-dir> [psalm-config]
#
# Output (stdout): {"wall_time_s": float, "peak_memory_mb": float, "psalm_exit_code": int, "issue_count": int}
# All other output goes to stderr so JSON stays clean.

set -euo pipefail

PROJECT_DIR="${1:?Usage: benchmark.sh <project-dir> [psalm-config]}"
PSALM_CONFIG="${2:-psalm.xml}"

# Resolve to absolute paths
PROJECT_DIR="$(cd "$PROJECT_DIR" && pwd)"
if [[ "$PSALM_CONFIG" != /* ]]; then
    PSALM_CONFIG="$PROJECT_DIR/$PSALM_CONFIG"
fi

# Detect GNU time (required for -f format string)
detect_time_cmd() {
    if command -v gtime &>/dev/null; then
        echo "gtime"
    elif [[ -x /usr/bin/time ]] && /usr/bin/time --version 2>&1 | grep -q GNU; then
        echo "/usr/bin/time"
    else
        echo ""
    fi
}

TIME_CMD=$(detect_time_cmd)
if [[ -z "$TIME_CMD" ]]; then
    echo "GNU time is required. Install: brew install gnu-time (macOS) or apt install time (Linux)" >&2
    exit 1
fi

# Verify project has Psalm installed
if [[ ! -f "$PROJECT_DIR/vendor/bin/psalm" ]]; then
    echo "Error: vendor/bin/psalm not found in $PROJECT_DIR" >&2
    exit 1
fi

if [[ ! -f "$PSALM_CONFIG" ]]; then
    echo "Error: Psalm config not found: $PSALM_CONFIG" >&2
    exit 1
fi

TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

cd "$PROJECT_DIR"

# Run Psalm with GNU time to capture wall time and peak memory.
# --threads=1 for deterministic results (no scheduler noise).
# --no-cache so every run does full analysis.
PSALM_EXIT=0
"$TIME_CMD" -f '%e %M' -o "$TMPDIR/time.txt" \
    php -d memory_limit=-1 vendor/bin/psalm \
    --config="$PSALM_CONFIG" \
    --threads=1 \
    --no-cache \
    --no-suggestions \
    --no-progress \
    >"$TMPDIR/stdout.txt" \
    2>"$TMPDIR/stderr.txt" \
    || PSALM_EXIT=$?

# Psalm exit codes: 0 = no issues, 1 = config/runtime error, 2 = issues found.
# Exit 0 and 2 both mean analysis completed successfully.
# Exit 1 or >=128 (signal) means something went wrong.
if [[ $PSALM_EXIT -eq 1 || $PSALM_EXIT -ge 128 ]]; then
    echo "Warning: Psalm exited with code $PSALM_EXIT (config error or crash)" >&2
    cat "$TMPDIR/stderr.txt" >&2 2>/dev/null || true
fi

# Parse GNU time output (last line — gtime prepends a status line on non-zero exit).
# "%e" = elapsed wall seconds, "%M" = max RSS in KB.
# read can fail under set -e if time.txt is empty (e.g. Psalm was killed before gtime wrote)
if ! read -r WALL_S PEAK_KB < <(tail -1 "$TMPDIR/time.txt"); then
    echo "Error: failed to parse GNU time output (empty or missing)" >&2
    echo "Psalm exit code was $PSALM_EXIT (137=OOM, 139=segfault)" >&2
    cat "$TMPDIR/time.txt" >&2 2>/dev/null || true
    exit 1
fi

# Validate parsed values are numeric (guards against corrupt time output)
if ! [[ "$WALL_S" =~ ^[0-9.]+$ ]] || ! [[ "$PEAK_KB" =~ ^[0-9]+$ ]]; then
    echo "Error: non-numeric GNU time output: WALL_S='$WALL_S' PEAK_KB='$PEAK_KB'" >&2
    echo "Psalm exit code was $PSALM_EXIT (137=OOM, 139=segfault)" >&2
    cat "$TMPDIR/time.txt" >&2 2>/dev/null || true
    exit 1
fi

# Convert KB to MB with 1 decimal
PEAK_MB=$(awk -v kb="$PEAK_KB" 'BEGIN {printf "%.1f", kb / 1024}')

# Extract issue count from Psalm's stderr (e.g. "329 errors found").
# Strip ANSI escape codes first — Psalm may colorize the output.
ISSUE_COUNT=$(sed $'s/\x1b\\[[0-9;]*m//g' "$TMPDIR/stderr.txt" 2>/dev/null | grep -oE '[0-9]+ errors? found' | grep -oE '^[0-9]+' || echo "0")

# Output clean JSON to stdout
echo "{\"wall_time_s\":$WALL_S,\"peak_memory_mb\":$PEAK_MB,\"psalm_exit_code\":$PSALM_EXIT,\"issue_count\":$ISSUE_COUNT}"
