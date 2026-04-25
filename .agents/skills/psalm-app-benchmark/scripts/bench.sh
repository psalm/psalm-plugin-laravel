#!/usr/bin/env bash
#
# Benchmark psalm-plugin-laravel on real-world Laravel apps.
#
# Usage:
#   bash bench.sh <git-ref> <version-label> [app-name]
#
# Output:
#   JSON result files in OUTPUT_DIR, plus a summary JSON on stdout.

set -euo pipefail

# --- Configuration -----------------------------------------------------------

PLUGIN_DIR="${PLUGIN_DIR:-/Users/alies/code/psalm/psalm-plugin-laravel}"
APPS_DIR="${APPS_DIR:-/Users/alies/code/psalm/benchmark-apps}"
TIMEOUT_SEC="${TIMEOUT_SEC:-300}"
DATE=$(date +%Y-%m-%d)

ALL_APPS=(
    bagisto coolify monica pixelfed solidtime
    spatie-dashboard tastyigniter unit3d vito laravel-excel filament corcel ixdf-web
)

# --- Argument parsing ---------------------------------------------------------

if [[ $# -lt 2 ]]; then
    echo "Usage: bench.sh <git-ref> <version-label> [app-name]" >&2
    exit 1
fi

GIT_REF="$1"
VERSION_LABEL="$2"
SINGLE_APP="${3:-}"
[[ "$SINGLE_APP" == "all" ]] && SINGLE_APP=""

OUTPUT_DIR="${OUTPUT_DIR:-${PLUGIN_DIR}/.alies/.track}"
mkdir -p "$OUTPUT_DIR"

# --- Capture environment versions (once) --------------------------------------

PLUGIN_COMMIT=$(cd "$PLUGIN_DIR" && git rev-parse --short HEAD)
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
PSALM_VERSION=""
for _app in "${ALL_APPS[@]}"; do
    for _subdir in "" "/4.x" "/3.x"; do
        _psalm_bin="${APPS_DIR}/${_app}${_subdir}/vendor/bin/psalm"
        if [[ -f "$_psalm_bin" ]]; then
            PSALM_VERSION=$(php "$_psalm_bin" --version 2>/dev/null | head -1 | sed 's/^Psalm //')
            break 2
        fi
    done
done

# Validate single app
if [[ -n "$SINGLE_APP" ]]; then
    found=0
    for app in "${ALL_APPS[@]}"; do [[ "$app" == "$SINGLE_APP" ]] && found=1 && break; done
    if [[ $found -eq 0 ]]; then
        echo "ERROR: Unknown app '$SINGLE_APP'. Known apps: ${ALL_APPS[*]}" >&2
        exit 1
    fi
    APPS=("$SINGLE_APP")
else
    APPS=("${ALL_APPS[@]}")
fi

# --- Git checkout with guaranteed restore ------------------------------------

ORIGINAL_REF=$(cd "$PLUGIN_DIR" && git rev-parse --abbrev-ref HEAD)
CURRENT_SHA=$(cd "$PLUGIN_DIR" && git rev-parse HEAD)
TARGET_SHA=$(cd "$PLUGIN_DIR" && git rev-parse "$GIT_REF" 2>/dev/null || echo "")

RESTORED=0
NEEDS_CHECKOUT=1

if [[ "$ORIGINAL_REF" == "$GIT_REF" ]] || [[ -n "$TARGET_SHA" && "$CURRENT_SHA" == "$TARGET_SHA" ]]; then
    NEEDS_CHECKOUT=0
    echo "Already on '$GIT_REF', skipping checkout." >&2
fi

restore_branches() {
    if [[ $NEEDS_CHECKOUT -eq 1 && $RESTORED -eq 0 ]]; then
        RESTORED=1
        echo "Restoring plugin to: $ORIGINAL_REF" >&2
        cd "$PLUGIN_DIR" && git checkout "$ORIGINAL_REF" --quiet 2>/dev/null || true
    fi
    if [[ -n "${ORIGINAL_PSALM_BRANCH:-}" && -d "${PSALM_REPO:-}/.git" ]]; then
        _cur_psalm=$(cd "$PSALM_REPO" && git branch --show-current 2>/dev/null)
        if [[ "$_cur_psalm" != "$ORIGINAL_PSALM_BRANCH" ]]; then
            echo "Restoring Psalm repo to: $ORIGINAL_PSALM_BRANCH" >&2
            cd "$PSALM_REPO" && git checkout "$ORIGINAL_PSALM_BRANCH" --quiet 2>/dev/null || true
        fi
    fi
    if [[ "${APP_BRANCH:-plugin-4.x}" != "plugin-4.x" ]]; then
        echo "Restoring apps to plugin-4.x..." >&2
        for _restore_app in "${APPS[@]}"; do
            _restore_dir="${APPS_DIR}/${_restore_app}"
            if [[ -d "${_restore_dir}/.git" ]]; then
                _cur=$(cd "$_restore_dir" && git branch --show-current 2>/dev/null)
                if [[ "$_cur" != "plugin-4.x" ]] && cd "$_restore_dir" && git rev-parse --verify plugin-4.x &>/dev/null; then
                    cd "$_restore_dir" && git checkout plugin-4.x --quiet 2>/dev/null || true
                fi
            fi
        done
    fi
}
trap restore_branches EXIT

if [[ $NEEDS_CHECKOUT -eq 1 ]]; then
    echo "Switching plugin from '$ORIGINAL_REF' to '$GIT_REF'..." >&2
    cd "$PLUGIN_DIR" && git checkout "$GIT_REF" --quiet
fi

# --- Determine app branch from version label ----------------------------------

PSALM_REPO="${PSALM_REPO:-/Users/alies/code/psalm/psalm}"

if [[ "$VERSION_LABEL" == v3* ]]; then
    APP_BRANCH="plugin-3.x"
    PSALM_BRANCH="alies-test-6.x"
else
    APP_BRANCH="plugin-4.x"
    PSALM_BRANCH="alies-test-7.x"
fi
echo "App branch: $APP_BRANCH (from version label $VERSION_LABEL)" >&2

if [[ -d "$PSALM_REPO/.git" ]]; then
    ORIGINAL_PSALM_BRANCH=$(cd "$PSALM_REPO" && git branch --show-current)
    if [[ "$ORIGINAL_PSALM_BRANCH" != "$PSALM_BRANCH" ]]; then
        echo "Switching Psalm repo from '$ORIGINAL_PSALM_BRANCH' to '$PSALM_BRANCH'..." >&2
        cd "$PSALM_REPO" && git checkout "$PSALM_BRANCH" --quiet
    fi
fi

# --- Run Psalm on a single app (function) ------------------------------------

run_psalm_on_app() {
    local app="$1"
    # Resolve app directory: prefer version-specific subdir (3.x/ or 4.x/) if it exists
    local APP_DIR="${APPS_DIR}/${app}"
    local VERSION_SUBDIR
    if [[ "$APP_BRANCH" == "plugin-3.x" ]]; then
        VERSION_SUBDIR="3.x"
    else
        VERSION_SUBDIR="4.x"
    fi
    if [[ -d "${APP_DIR}/${VERSION_SUBDIR}" ]]; then
        APP_DIR="${APP_DIR}/${VERSION_SUBDIR}"
    fi
    local APP_OUTPUT_DIR="${OUTPUT_DIR}/${app}"
    local OUTPUT_FILE="${APP_OUTPUT_DIR}/${app}-${VERSION_LABEL}-${DATE}--issues.json"
    local TAINT_FILE="${APP_OUTPUT_DIR}/${app}-${VERSION_LABEL}-${DATE}--taint.json"
    local PERF_FILE="${APP_OUTPUT_DIR}/${app}-${VERSION_LABEL}-${DATE}--perf.json"
    mkdir -p "$APP_OUTPUT_DIR"

    # Prune older files for same app+version
    for older in "${APP_OUTPUT_DIR}/${app}-${VERSION_LABEL}"-*--issues.json; do
        [[ -f "$older" && "$older" != "$OUTPUT_FILE" ]] && rm -f "$older"
    done
    for older in "${APP_OUTPUT_DIR}/${app}-${VERSION_LABEL}"-*--taint.json; do
        [[ -f "$older" && "$older" != "$TAINT_FILE" ]] && rm -f "$older"
    done
    for older in "${APP_OUTPUT_DIR}/${app}-${VERSION_LABEL}"-*--perf.json; do
        [[ -f "$older" && "$older" != "$PERF_FILE" ]] && rm -f "$older"
    done
    local CRASH_LOG="${APP_OUTPUT_DIR}/${app}-${VERSION_LABEL}-${DATE}--crash.log"
    for older in "${APP_OUTPUT_DIR}/${app}-${VERSION_LABEL}"-*--crash.log; do
        [[ -f "$older" && "$older" != "$CRASH_LOG" ]] && rm -f "$older"
    done

    # Skip memory-intensive apps on pre-v3.8.0 (they exceed 100 GB RAM and crash the system).
    # v3.8.0+ has a fixed architecture that keeps memory in check for these apps.
    if [[ "$APP_BRANCH" == "plugin-3.x" ]] && [[ "$app" == "ixdf-web" || "$app" == "filament" ]]; then
        # VERSION_LABEL < v3.8.0 → skip. Uses `sort -V` for semver-aware comparison.
        local SMALLEST
        SMALLEST=$(printf '%s\n' "v3.8.0" "$VERSION_LABEL" | sort -V | head -1)
        if [[ "$SMALLEST" != "v3.8.0" ]]; then
            echo "SKIP: ${app} — excluded on pre-v3.8.0 (memory issue: >100 GB RAM; fixed in v3.8.0)" >&2
            printf '{"app":"%s","status":"skipped","reason":"excluded_pre_v3_8_0"}\n' "$app"
            return 0
        fi
    fi

    # Skip if all outputs already exist
    if [[ -f "$OUTPUT_FILE" ]]; then
        if [[ "$APP_BRANCH" != "plugin-3.x" || -f "$TAINT_FILE" ]]; then
            echo "SKIP: ${app} — results already exist" >&2
            printf '{"app":"%s","status":"skipped","file":"%s"}\n' "$app" "$OUTPUT_FILE"
            return 0
        fi
    fi

    # Switch app branch if needed (skip if using version subdirs like 3.x/ 4.x/)
    if [[ "$APP_DIR" == "${APPS_DIR}/${app}" && -d "${APP_DIR}/.git" ]]; then
        local CURRENT_APP_BRANCH
        CURRENT_APP_BRANCH=$(cd "$APP_DIR" && git branch --show-current)
        if [[ "$CURRENT_APP_BRANCH" != "$APP_BRANCH" ]]; then
            if cd "$APP_DIR" && git rev-parse --verify "$APP_BRANCH" &>/dev/null; then
                echo "Switching ${app} to '$APP_BRANCH'..." >&2
                cd "$APP_DIR" && git checkout "$APP_BRANCH" --quiet 2>&1
                rm -rf "${APP_DIR}/vendor/vimeo/psalm" "${APP_DIR}/vendor/psalm/plugin-laravel"
                echo "Running composer install for ${app}..." >&2
                cd "$APP_DIR" && composer install --no-interaction --quiet 2>&1 || true
            else
                echo "SKIP: ${app} — branch '$APP_BRANCH' not found" >&2
                printf '{"app":"%s","status":"error","error":"NO_BRANCH"}\n' "$app"
                return 0
            fi
        fi
    fi

    # Preflight checks
    if [[ ! -f "${APP_DIR}/vendor/bin/psalm" ]]; then
        echo "SKIP: ${app} — vendor/bin/psalm not found" >&2
        printf '{"app":"%s","status":"error","error":"NO_PSALM"}\n' "$app"
        return 0
    fi

    local ISSUE_COUNT=0 TAINT_COUNT=0 TOP3="" ELAPSED_SEC=0

    # --- Main type analysis run (skip if issues file already exists) -----------
    if [[ ! -f "$OUTPUT_FILE" ]]; then
        echo "Running Psalm on ${app}..." >&2

        local TEMP_OUT TEMP_ERR TEMP_TIME START_TIME EXIT_CODE
        TEMP_OUT=$(mktemp)
        TEMP_ERR=$(mktemp)
        TEMP_TIME=$(mktemp)
        START_TIME=$(date +%s)
        EXIT_CODE=0

        (
            cd "${APP_DIR}" && \
            /usr/bin/time -l sh -c \
                'exec php -d memory_limit=4G vendor/bin/psalm -c psalm.xml --no-cache --no-diff --no-progress --no-suggestions --output-format=json >"$1" 2>"$2"' \
                _ "$TEMP_OUT" "$TEMP_ERR" \
            2> "$TEMP_TIME"
        ) &
        local PSALM_PID=$!
        ( sleep "${TIMEOUT_SEC}"; kill "$PSALM_PID" 2>/dev/null ) &
        local WATCHDOG_PID=$!
        wait "$PSALM_PID" 2>/dev/null || EXIT_CODE=$?
        kill "$WATCHDOG_PID" 2>/dev/null
        wait "$WATCHDOG_PID" 2>/dev/null || true

        ELAPSED_SEC=$(( $(date +%s) - START_TIME ))

        if [[ $EXIT_CODE -eq 137 || $EXIT_CODE -eq 143 ]]; then
            echo "TIMEOUT: ${app} exceeded ${TIMEOUT_SEC}s" >&2
            {
                echo "=== TIMEOUT: ${app} — exit code ${EXIT_CODE} after ${ELAPSED_SEC}s ==="
                echo "=== STDERR ==="
                cat "$TEMP_ERR"
                echo ""
                echo "=== STDOUT ==="
                cat "$TEMP_OUT"
            } > "$CRASH_LOG"
            printf '{"app":"%s","status":"error","error":"TIMEOUT","elapsed_sec":%d}\n' "$app" "$ELAPSED_SEC"
            rm -f "$TEMP_OUT" "$TEMP_ERR" "$TEMP_TIME"
            return 0
        fi

        # Parse JSON and extract stats in one python3 call
        local RESULT
        RESULT=$(python3 -c "
import json, re, sys

raw = open('$TEMP_OUT').read()
# Find valid JSON array in output
candidates = [0] if raw.startswith('[') else []
candidates += [m.start() for m in re.finditer(r'(?m)^\[', raw)]
lb = raw.rfind(']')
if lb != -1:
    pos = raw.rfind('\n[', 0, lb)
    if pos != -1: candidates.append(pos + 1)

data = None
for start in candidates:
    try:
        d = json.loads(raw[start:])
        if isinstance(d, list):
            data = d
            break
    except (json.JSONDecodeError, ValueError):
        continue

if data is None:
    sys.exit(1)

data.sort(key=lambda i: (i.get('file_path',''), i.get('line_from',0), i.get('type',''), i.get('message','')))
json.dump(data, open('$OUTPUT_FILE', 'w'), indent=2)

from collections import Counter
total = len(data)
taint = sum(1 for i in data if i.get('taint_trace') is not None)
top3 = Counter(i['type'] for i in data).most_common(3)
top3_s = ','.join(f'{t}:{c}' for t, c in top3)
print(json.dumps({'total': total, 'taint': taint, 'top3': top3_s}))
" 2>/dev/null) || true

        if [[ -n "$RESULT" ]]; then
            ISSUE_COUNT=$(echo "$RESULT" | python3 -c "import sys,json; print(json.load(sys.stdin)['total'])")
            TAINT_COUNT=$(echo "$RESULT" | python3 -c "import sys,json; print(json.load(sys.stdin)['taint'])")
            TOP3=$(echo "$RESULT" | python3 -c "import sys,json; print(json.load(sys.stdin)['top3'])")

            # Parse /usr/bin/time -l output
            local USER_SEC SYS_SEC PEAK_MEM
            USER_SEC=$(awk '/user/{for(i=1;i<=NF;i++) if($i=="user") print $(i-1)}' "$TEMP_TIME" || echo "")
            SYS_SEC=$(awk '/sys/{for(i=1;i<=NF;i++) if($i=="sys") print $(i-1)}' "$TEMP_TIME" || echo "")
            PEAK_MEM=$(awk '/peak memory footprint/{print $1}' "$TEMP_TIME" || echo "")
            [[ -z "$PEAK_MEM" ]] && PEAK_MEM=$(awk '/maximum resident set size/{print $1}' "$TEMP_TIME" || echo "")

            # Extract type coverage percentage from Psalm stderr.
            # Psalm prints "Psalm can infer types for X% of the codebase" at the end of analysis.
            # With --output-format=json the JSON issues go to stdout; the summary stays on stderr.
            local TYPE_COVERAGE
            TYPE_COVERAGE=$(sed -n 's/.*infer types for \([0-9.]*\)%.*/\1/p' "$TEMP_ERR" | tail -1 || echo "")

            # Write perf JSON
            python3 -c "
import json
json.dump({
    'app': '${app}', 'version': '${VERSION_LABEL}', 'date': '${DATE}',
    'plugin_commit': '${PLUGIN_COMMIT}', 'php_version': '${PHP_VERSION}',
    'psalm_version': '${PSALM_VERSION}',
    'wall_seconds': ${ELAPSED_SEC}, 'user_seconds': float('${USER_SEC}' or 0),
    'sys_seconds': float('${SYS_SEC}' or 0), 'peak_memory_bytes': int('${PEAK_MEM}' or 0),
    'total_issues': ${ISSUE_COUNT}, 'taint_issues': ${TAINT_COUNT}, 'exit_code': ${EXIT_CODE},
    'type_coverage_pct': float('${TYPE_COVERAGE}') if '${TYPE_COVERAGE}' else None,
}, open('${PERF_FILE}', 'w'), indent=2)
"

            rm -f "$CRASH_LOG"
            COVERAGE_MSG=${TYPE_COVERAGE:+" (${TYPE_COVERAGE}% coverage)"}
            echo "OK: ${app} — ${ISSUE_COUNT} issues (${TAINT_COUNT} taint) in ${ELAPSED_SEC}s${COVERAGE_MSG}" >&2
        else
            local STDERR_MSG
            STDERR_MSG=$(head -5 "$TEMP_ERR" | tr '\n' ' ' | cut -c1-200)
            echo "PSALM_ERROR: ${app} — exit code ${EXIT_CODE}" >&2
            {
                echo "=== PSALM_ERROR: ${app} — exit code ${EXIT_CODE} after ${ELAPSED_SEC}s ==="
                echo "=== STDERR ==="
                cat "$TEMP_ERR"
                echo ""
                echo "=== STDOUT ==="
                cat "$TEMP_OUT"
            } > "$CRASH_LOG"
            printf '{"app":"%s","status":"error","error":"PSALM_ERROR","elapsed_sec":%d,"message":"exit %d: %s"}\n' \
                "$app" "$ELAPSED_SEC" "$EXIT_CODE" "$(echo "$STDERR_MSG" | sed 's/"/\\"/g')"
            rm -f "$TEMP_OUT" "$TEMP_ERR" "$TEMP_TIME"
            return 0
        fi

        rm -f "$TEMP_OUT" "$TEMP_ERR" "$TEMP_TIME"
    fi

    # --- Separate taint analysis run for Psalm 6 (v3.x) ----------------------
    if [[ "$APP_BRANCH" == "plugin-3.x" && ! -f "$TAINT_FILE" ]]; then
        echo "Running taint analysis on ${app}..." >&2
        local TAINT_OUT TAINT_ERR TAINT_EXIT TAINT_START TAINT_ELAPSED
        TAINT_OUT=$(mktemp)
        TAINT_ERR=$(mktemp)
        TAINT_START=$(date +%s)
        TAINT_EXIT=0

        (
            cd "${APP_DIR}" && \
            php -d memory_limit=4G vendor/bin/psalm -c psalm.xml \
                --taint-analysis --no-cache --no-diff --no-progress --no-suggestions \
                --output-format=json >"$TAINT_OUT" 2>"$TAINT_ERR"
        ) &
        local TAINT_PID=$!
        ( sleep "${TIMEOUT_SEC}"; kill "$TAINT_PID" 2>/dev/null ) &
        local TAINT_WATCHDOG=$!
        wait "$TAINT_PID" 2>/dev/null || TAINT_EXIT=$?
        kill "$TAINT_WATCHDOG" 2>/dev/null
        wait "$TAINT_WATCHDOG" 2>/dev/null || true

        TAINT_ELAPSED=$(( $(date +%s) - TAINT_START ))

        if [[ $TAINT_EXIT -eq 137 || $TAINT_EXIT -eq 143 ]]; then
            echo "TAINT_TIMEOUT: ${app} exceeded ${TIMEOUT_SEC}s" >&2
        else
            local TAINT_RESULT
            TAINT_RESULT=$(python3 -c "
import json, re, sys
raw = open('$TAINT_OUT').read()
candidates = [0] if raw.startswith('[') else []
candidates += [m.start() for m in re.finditer(r'(?m)^\[', raw)]
lb = raw.rfind(']')
if lb != -1:
    pos = raw.rfind('\n[', 0, lb)
    if pos != -1: candidates.append(pos + 1)
data = None
for start in candidates:
    try:
        d = json.loads(raw[start:])
        if isinstance(d, list):
            data = d
            break
    except (json.JSONDecodeError, ValueError):
        continue
if data is None:
    sys.exit(1)
data.sort(key=lambda i: (i.get('file_path',''), i.get('line_from',0), i.get('type',''), i.get('message','')))
json.dump(data, open('$TAINT_FILE', 'w'), indent=2)
print(len(data))
" 2>/dev/null) || true

            if [[ -n "$TAINT_RESULT" ]]; then
                TAINT_COUNT="$TAINT_RESULT"
                echo "TAINT_OK: ${app} — ${TAINT_COUNT} taint issues in ${TAINT_ELAPSED}s" >&2
            else
                echo "TAINT_ERROR: ${app} — no parseable JSON (exit ${TAINT_EXIT})" >&2
            fi
        fi
        rm -f "$TAINT_OUT" "$TAINT_ERR"
    fi

    printf '{"app":"%s","status":"ok","file":"%s","total":%d,"taint":%d,"elapsed_sec":%d,"top3":"%s","perf_file":"%s"}\n' \
        "$app" "$OUTPUT_FILE" "$ISSUE_COUNT" "$TAINT_COUNT" "$ELAPSED_SEC" "$TOP3" "$PERF_FILE"
}

# --- Run on each app ----------------------------------------------------------

RESULTS_FILE=$(mktemp)

for app in "${APPS[@]}"; do
    run_psalm_on_app "$app" >> "$RESULTS_FILE"
done

# --- Retry failed apps --------------------------------------------------------

FAILED_APPS=()
while IFS= read -r line; do
    app=$(echo "$line" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['app']) if d.get('status')=='error' and d.get('error')=='PSALM_ERROR' else print('')" 2>/dev/null)
    [[ -n "$app" ]] && FAILED_APPS+=("$app")
done < "$RESULTS_FILE"

if [[ ${#FAILED_APPS[@]} -gt 0 ]]; then
    echo "" >&2
    echo "Retrying ${#FAILED_APPS[@]} failed app(s): ${FAILED_APPS[*]}..." >&2
    for app in "${FAILED_APPS[@]}"; do
        echo "Retrying ${app}..." >&2
        # Remove old error line
        grep -v "\"app\":\"${app}\"" "$RESULTS_FILE" > "${RESULTS_FILE}.tmp" && mv "${RESULTS_FILE}.tmp" "$RESULTS_FILE"
        run_psalm_on_app "$app" >> "$RESULTS_FILE"
    done
fi

# --- Output -------------------------------------------------------------------

echo "" >&2
echo "=== Results ===" >&2
cat "$RESULTS_FILE"
rm -f "$RESULTS_FILE"