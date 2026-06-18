#!/usr/bin/env bash
#
# Local orchestrator for the CI psalm-delta. Runs the SAME per-app runner
# (delta-app.sh) and comparator (delta-report.php) the GitHub workflow uses, so
# a contributor can reproduce the PR delta locally with one command.
#
# Unlike the /psalm-delta skill it does NOT checkout refs in the working tree:
# it spins up two throwaway git worktrees (base + head) and leaves your checkout
# untouched. Apps come from bin/ci/test-apps.yml (requires `yq`).
#
# Usage:
#   bash bin/ci/delta.sh <head-ref>                 # base = merge-base(master, head)
#   bash bin/ci/delta.sh <base-ref> vs <head-ref>   # explicit base + head
#   APPS="monica,coolify" bash bin/ci/delta.sh <head-ref>   # subset
#
# Output: markdown delta report on stdout; raw JSON cached under
#   .cache/psalm-delta-ci/<BASE_SHA>--<HEAD_SHA>/ (gitignored, reused on rerun).

set -euo pipefail

PLUGIN_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
REGISTRY="${REGISTRY:-${PLUGIN_DIR}/bin/ci/test-apps.yml}"
cd "$PLUGIN_DIR"

command -v git >/dev/null || { echo "ERROR: git is required" >&2; exit 2; }
command -v php >/dev/null || { echo "ERROR: php is required (for delta-report.php)" >&2; exit 2; }
command -v yq  >/dev/null || { echo "ERROR: yq (mikefarah v4) is required to read $REGISTRY" >&2; exit 2; }

# --- Read registry + validate optional APPS= subset (before any heavy work) ---

# Optional APPS=a,b,c subset filter.
SUBSET="${APPS:-}"
# read loop (not mapfile) for bash 3.2 compatibility (macOS default shell).
APP_NAMES=()
while IFS= read -r line; do
    [[ -n "$line" ]] && APP_NAMES+=("$line")
done < <(yq '.apps[].name' "$REGISTRY")

# Validate an APPS= subset up front: an unknown name otherwise silently yields
# an empty RUN_APPS and surfaces only later as delta-report.php's "--apps is
# required" — after the worktrees and clones are already built. List the valid
# names instead so the typo is obvious.
if [[ -n "$SUBSET" ]]; then
    valid=",$(IFS=,; echo "${APP_NAMES[*]}"),"
    IFS=',' read -ra REQUESTED <<< "$SUBSET"
    for req in "${REQUESTED[@]}"; do
        [[ -z "$req" ]] && continue
        if [[ "$valid" != *",$req,"* ]]; then
            echo "ERROR: unknown app '$req' in APPS=. Valid apps:" >&2
            printf '  %s\n' "${APP_NAMES[@]}" >&2
            exit 2
        fi
    done
fi

# --- Resolve base / head refs ------------------------------------------------

if [[ $# -eq 1 ]]; then
    HEAD_REF="$1"
    BASE_REF=$(git merge-base master "$HEAD_REF") \
        || { echo "ERROR: cannot compute merge-base(master, $HEAD_REF)" >&2; exit 1; }
elif [[ $# -eq 3 && "$2" == "vs" ]]; then
    BASE_REF="$1"; HEAD_REF="$3"
else
    echo "Usage: $0 <head-ref> | <base-ref> vs <head-ref>" >&2
    exit 1
fi

BASE_SHA=$(git rev-parse --short "$BASE_REF") || exit 1
HEAD_SHA=$(git rev-parse --short "$HEAD_REF") || exit 1
if [[ "$BASE_SHA" == "$HEAD_SHA" ]]; then
    echo "ERROR: base and head are the same commit ($BASE_SHA)." >&2
    exit 1
fi
BASE_LABEL="base-${BASE_SHA}"
HEAD_LABEL="pr-${HEAD_SHA}"

OUT="${PLUGIN_DIR}/.cache/psalm-delta-ci/${BASE_SHA}--${HEAD_SHA}"
mkdir -p "$OUT"

echo "Base: $BASE_REF ($BASE_SHA)   Head: $HEAD_REF ($HEAD_SHA)" >&2
echo "Output: $OUT" >&2

# --- Plugin worktrees (working tree untouched) -------------------------------
#
# Two detached worktrees hold the base and head plugin source. They need no
# vendor of their own: each app installs the plugin as a symlinked path repo, so
# the plugin's requires (vimeo/psalm, testbench) resolve into the APP's vendor.

WT_BASE=$(mktemp -d)
WT_HEAD=$(mktemp -d)
git worktree add --detach --force "$WT_BASE" "$BASE_REF" --quiet
git worktree add --detach --force "$WT_HEAD" "$HEAD_REF" --quiet

cleanup() {
    git worktree remove --force "$WT_BASE" 2>/dev/null || rm -rf "$WT_BASE"
    git worktree remove --force "$WT_HEAD" 2>/dev/null || rm -rf "$WT_HEAD"
}
trap cleanup EXIT

# --- Loop apps ---------------------------------------------------------------

RUN_APPS=()
for app in "${APP_NAMES[@]}"; do
    if [[ -n "$SUBSET" && ",$SUBSET," != *",$app,"* ]]; then
        continue
    fi
    RUN_APPS+=("$app")
done

# Local PHP major.minor, for the registry-mismatch warning below. --php is the
# caller's concern locally (delta-app.sh ignores it), so a registry app pinned to
# a different minor than the system php can fail Composer in confusing ways.
LOCAL_PHP=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

for app in "${RUN_APPS[@]}"; do
    # $app comes from the registry itself (trusted, filename-safe), so embedding
    # it in the yq expression is safe. `// ""` supplies defaults for optional keys.
    repo=$(yq ".apps[] | select(.name == \"$app\") | .repo" "$REGISTRY")
    ref=$(yq ".apps[] | select(.name == \"$app\") | .ref" "$REGISTRY")
    php_ver=$(yq ".apps[] | select(.name == \"$app\") | .php // \"8.3\"" "$REGISTRY")
    pdir=$(yq ".apps[] | select(.name == \"$app\") | .project_dir // \"\"" "$REGISTRY")
    prime=$(yq ".apps[] | select(.name == \"$app\") | .prime // \"\"" "$REGISTRY")
    psalm_args=$(yq ".apps[] | select(.name == \"$app\") | .psalm_args // \"\"" "$REGISTRY")

    # Warn (don't block) when the system php differs from the app's pinned minor:
    # Composer installs under the local binary, so a mismatch can fail oddly.
    if [[ "$php_ver" != "$LOCAL_PHP" ]]; then
        echo "WARN: $app pins php $php_ver but local php is $LOCAL_PHP; install may differ from CI." >&2
    fi

    args=(
        --app "$app" --repo "$repo" --ref "$ref" --php "$php_ver"
        --plugin-base "$WT_BASE" --plugin-head "$WT_HEAD"
        --out "$OUT" --base-label "$BASE_LABEL" --head-label "$HEAD_LABEL"
    )
    [[ -n "$pdir"  ]] && args+=(--project-dir "$pdir")
    [[ -n "$prime" ]] && args+=(--prime "$prime")
    [[ -n "$psalm_args" ]] && args+=(--psalm-args "$psalm_args")

    echo "=== $app ===" >&2
    bash "${PLUGIN_DIR}/bin/ci/delta-app.sh" "${args[@]}" || echo "WARN: $app runner failed" >&2
done

# --- Report ------------------------------------------------------------------

echo "" >&2
APPS_CSV=$(IFS=,; echo "${RUN_APPS[*]}")
php "${PLUGIN_DIR}/bin/ci/delta-report.php" "$OUT" "$BASE_LABEL" "$HEAD_LABEL" \
    --apps="$APPS_CSV" --base-ref="$BASE_REF" --head-ref="$HEAD_REF" \
    --base-sha="$BASE_SHA" --head-sha="$HEAD_SHA" --date-marker=cache

echo "" >&2
echo "Raw data: $OUT (reused on rerun for this SHA pair; rm -rf to force clean)" >&2
