#!/usr/bin/env bash
#
# Local orchestrator for the CI psalm-delta. Runs the SAME per-app runner
# (delta-app.sh) and comparator (delta-report.php) the GitHub workflow uses, so
# a contributor can reproduce the PR delta locally with one command.
#
# It does NOT checkout refs in the working tree: it spins up two throwaway git
# worktrees (base + head) and leaves your checkout untouched. Apps come from
# bin/ci/test-apps.yml, or the registry file in $REGISTRY (requires `yq`).
#
# Usage:
#   bash bin/ci/delta.sh <head-ref>                 # base = merge-base(4.x, head)
#   bash bin/ci/delta.sh <base-ref> vs <head-ref>   # explicit base + head
#   bash bin/ci/delta.sh --apps "octane,vito" <head-ref>   # default group + octane + vito
#   bash bin/ci/delta.sh --apps "blade --blade" <head-ref> # + blade apps, Blade analysis on
#
# --apps takes the `/psalm-delta` comment grammar without the prefix (resolved by
# bin/ci/delta-select-apps.php): group tags and app names add to the `default` group,
# `all` selects every app, `help` lists groups, and `--flag` tokens declared under
# `flags:` in the registry go to `psalm-laravel analyze` on both sides. Omitted = `default`.
#
# Output: markdown delta report on stdout; raw JSON cached under
#   .cache/psalm-delta-ci/<BASE_SHA>--<HEAD_SHA>[+<flag>...]/ (gitignored, reused on rerun).
# Installed apps are cached per pinned ref under .cache/psalm-delta-apps/ and
# shared by every SHA pair.

set -euo pipefail

PLUGIN_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
REGISTRY="${REGISTRY:-${PLUGIN_DIR}/bin/ci/test-apps.yml}"
cd "$PLUGIN_DIR"

command -v git >/dev/null || { echo "ERROR: git is required" >&2; exit 2; }
command -v php >/dev/null || { echo "ERROR: php is required (for delta-report.php)" >&2; exit 2; }
command -v yq  >/dev/null || { echo "ERROR: yq (mikefarah v4) is required to read $REGISTRY" >&2; exit 2; }

# --- Resolve the app selection (before any heavy work) -----------------------

SELECTOR=""
POSITIONAL=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --apps)
            [[ $# -ge 2 ]] || { echo "ERROR: --apps needs a selector (try --apps help)" >&2; exit 2; }
            SELECTOR="$2"; shift 2 ;;
        *) POSITIONAL+=("$1"); shift ;;
    esac
done
# ${arr[@]+...}: bash 3.2 (macOS default) treats an empty array as unbound under set -u.
set -- ${POSITIONAL[@]+"${POSITIONAL[@]}"}

SELECTION=$(yq -o=json "$REGISTRY" | php "${PLUGIN_DIR}/bin/ci/delta-select-apps.php" "$SELECTOR") || exit 2
case "$(yq -p=json '.status' <<< "$SELECTION")" in
    run) ;;
    help) yq -p=json '.reply' <<< "$SELECTION"; exit 0 ;;
    *) yq -p=json '.reply' <<< "$SELECTION" >&2; exit 2 ;;
esac
SELECTION_LABEL=$(yq -p=json '.label' <<< "$SELECTION")
RUN_FLAGS=$(yq -p=json '.flags' <<< "$SELECTION")
IFS=',' read -ra RUN_APPS <<< "$(yq -p=json '.apps_csv' <<< "$SELECTION")"

# --- Resolve base / head refs ------------------------------------------------

if [[ $# -eq 1 ]]; then
    HEAD_REF="$1"
    BASE_REF=$(git merge-base 4.x "$HEAD_REF") \
        || { echo "ERROR: cannot compute merge-base(4.x, $HEAD_REF)" >&2; exit 1; }
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

# Flags change the results, so they key the cache too: `--blade` -> `+blade`.
# Registry flags are [a-z0-9._=-] only, so the unquoted split is glob-safe.
FLAGS_SUFFIX=""
for flag in $RUN_FLAGS; do
    FLAGS_SUFFIX+="+${flag#--}"
done
OUT="${PLUGIN_DIR}/.cache/psalm-delta-ci/${BASE_SHA}--${HEAD_SHA}${FLAGS_SUFFIX}"
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

LOCAL_PHP=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

# Registry entry field for one selected app ("" when absent). App names are
# registry values ([a-z0-9_-]), so embedding one in the expression is safe.
app_field() {
    yq -p=json ".matrix.include[] | select(.name == \"$1\") | .$2 // \"\"" <<< "$SELECTION"
}

for app in "${RUN_APPS[@]}"; do
    ref=$(app_field "$app" ref)
    pdir=$(app_field "$app" project_dir)
    before_install=$(app_field "$app" before_install)
    psalm_args=$(app_field "$app" psalm_args)

    # Composer installs under the local binary, so a minor other than the app's
    # pinned one can fail in ways CI does not.
    php_ver=$(app_field "$app" php)
    if [[ "$php_ver" != "$LOCAL_PHP" ]]; then
        echo "WARN: $app pins php $php_ver but local php is $LOCAL_PHP; install may differ from CI." >&2
    fi

    args=(
        --app "$app" --repo "$(app_field "$app" repo)" --ref "$ref"
        --plugin-base "$WT_BASE" --plugin-head "$WT_HEAD"
        --out "$OUT" --base-label "$BASE_LABEL" --head-label "$HEAD_LABEL"
        --app-src "${PLUGIN_DIR}/.cache/psalm-delta-apps/${app}-${ref}"
    )
    [[ -n "$pdir"  ]] && args+=(--project-dir "$pdir")
    [[ -n "$before_install" ]] && args+=(--before-install "$before_install")
    [[ -n "$psalm_args" ]] && args+=(--psalm-args "$psalm_args")
    [[ -n "$RUN_FLAGS" ]] && args+=(--flags "$RUN_FLAGS")

    echo "=== $app ===" >&2
    bash "${PLUGIN_DIR}/bin/ci/delta-app.sh" "${args[@]}" || echo "WARN: $app runner failed" >&2
done

# --- Report ------------------------------------------------------------------

echo "" >&2
APPS_CSV=$(IFS=,; echo "${RUN_APPS[*]}")
php -d memory_limit=-1 "${PLUGIN_DIR}/bin/ci/delta-report.php" "$OUT" "$BASE_LABEL" "$HEAD_LABEL" \
    --apps="$APPS_CSV" --base-ref="$BASE_REF" --head-ref="$HEAD_REF" \
    --base-sha="$BASE_SHA" --head-sha="$HEAD_SHA" \
    --selection="$SELECTION_LABEL" --flags="$RUN_FLAGS"

echo "" >&2
echo "Raw data: $OUT (reused on rerun for this SHA pair; rm -rf to force clean)" >&2
