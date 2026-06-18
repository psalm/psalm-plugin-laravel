#!/usr/bin/env bash
#
# Run Psalm + plugin on ONE real-world Laravel app for two plugin checkouts
# (base + head) and emit per-side issue/perf JSON in the layout delta-report.php
# consumes. Portable: pure bash + git + composer + php, no GNU-only flags, no
# local hardcoded paths. The SAME script runs locally (driven by delta.sh) and
# on CI (one matrix job per app).
#
# It deliberately does NOT depend on the local Psalm fork or any of bench.sh's
# macOS-isms. Psalm comes from Packagist (the plugin's normal `vimeo/psalm`
# constraint).
#
# Usage:
#   delta-app.sh \
#     --app monica --repo https://github.com/monicahq/monica.git --ref e08e917 \
#     --plugin-base /path/plugin-base --plugin-head /path/plugin-head \
#     --out /path/output-dir --base-label base-AAAA --head-label pr-BBBB \
#     [--php 8.3] [--project-dir app] [--date-marker cache] \
#     [--prime 'composer update foo --no-interaction'] \
#     [--psalm-args '--php-version=8.0'] \
#     [--app-src /cache/monica-src] [--mem 4G]
#
# Output (per side, <label> in {base-label, head-label}):
#   <out>/<app>/<app>-<label>-<date-marker>--issues.json   (Psalm --report JSON)
#   <out>/<app>/<app>-<label>-<date-marker>--perf.json      (wall, coverage, count)
#
# Exit 0 even on a per-app Psalm failure: a crash log is written and the side's
# JSON is omitted so delta-report.php renders the app as (missing) instead of
# blocking the whole report. Hard setup errors (bad args, clone/install failure)
# exit non-zero.

set -euo pipefail

# --- Argument parsing --------------------------------------------------------

APP="" REPO="" REF="" PLUGIN_BASE="" PLUGIN_HEAD="" OUT=""
BASE_LABEL="" HEAD_LABEL="" PROJECT_DIR=""
DATE_MARKER="cache" PRIME="" APP_SRC="" MEM="4G" PSALM_ARGS=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --app) APP="$2"; shift 2 ;;
        --repo) REPO="$2"; shift 2 ;;
        --ref) REF="$2"; shift 2 ;;
        --plugin-base) PLUGIN_BASE="$2"; shift 2 ;;
        --plugin-head) PLUGIN_HEAD="$2"; shift 2 ;;
        --out) OUT="$2"; shift 2 ;;
        --base-label) BASE_LABEL="$2"; shift 2 ;;
        --head-label) HEAD_LABEL="$2"; shift 2 ;;
        # --php is the caller's setup concern (setup-php on CI, system php
        # locally); accepted for interface symmetry with the registry, ignored.
        --php) shift 2 ;;
        --project-dir) PROJECT_DIR="$2"; shift 2 ;;
        --date-marker) DATE_MARKER="$2"; shift 2 ;;
        --prime) PRIME="$2"; shift 2 ;;
        --psalm-args) PSALM_ARGS="$2"; shift 2 ;;
        --app-src) APP_SRC="$2"; shift 2 ;;
        --mem) MEM="$2"; shift 2 ;;
        *) echo "ERROR: unknown argument '$1'" >&2; exit 2 ;;
    esac
done

for req in APP REPO REF PLUGIN_BASE PLUGIN_HEAD OUT BASE_LABEL HEAD_LABEL; do
    if [[ -z "${!req}" ]]; then
        # tr for the flag name: bash 3.2 (macOS default) lacks ${var,,}.
        echo "ERROR: --$(echo "$req" | tr '[:upper:]' '[:lower:]') is required" >&2
        exit 2
    fi
done

# Canonicalise plugin dirs so the composer path repo records an absolute target.
PLUGIN_BASE=$(cd "$PLUGIN_BASE" && pwd -P)
PLUGIN_HEAD=$(cd "$PLUGIN_HEAD" && pwd -P)
mkdir -p "$OUT/$APP"

# Default app source dir — a cacheable working copy keyed on the frozen ref.
APP_SRC="${APP_SRC:-${OUT}/${APP}/src}"

COMPOSER_FLAGS=(--no-interaction --no-progress --ignore-platform-reqs)
export COMPOSER_MEMORY_LIMIT=-1

# Optional extra Psalm CLI args (e.g. --php-version=8.0), split on whitespace
# into an array so each token is passed as a separate argument. `=` is not in
# IFS, so --php-version=8.0 stays one token. `read -ra` returns 0 even on empty
# input (set -e safe); the array is expanded with the bash-3.2-safe
# ${arr[@]+...} guard at the call site to avoid an unbound-variable error.
read -ra PSALM_EXTRA <<< "$PSALM_ARGS"

# Copy a tree using a copy-on-write clone where the filesystem supports it
# (APFS clonefile on macOS, btrfs/xfs reflinks on Linux): instant and low-disk,
# yet safe — writes to the copy never touch the source, unlike `cp -al`
# hardlinks which would write through composer.json edits into APP_SRC. Falls
# back to a deep copy everywhere else. The first two forms exit non-zero on
# coreutils variants that lack the flag, so the chain degrades cleanly.
fast_copy() {
    local src="$1" dst="$2"
    cp -c -a "$src" "$dst" 2>/dev/null && return 0             # BSD/macOS clonefile
    cp --reflink=auto -a "$src" "$dst" 2>/dev/null && return 0 # GNU coreutils reflink
    cp -a "$src" "$dst"
}

# md5 of the plugin's dependency-affecting composer.json sections. Only `require`
# and `autoload` change what gets installed into the app's vendor; if they match
# between base and head, the head side can reuse the base install and re-point a
# symlink instead of re-running Composer. require-dev / autoload-dev are never
# installed, so excluding them is safe (a stray match only skips a no-op solve).
plugin_dep_sig() {
    php -r '
        $d = json_decode(file_get_contents($argv[1] . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);
        $sig = ["require" => $d["require"] ?? [], "autoload" => $d["autoload"] ?? []];
        foreach ($sig as &$s) { if (is_array($s)) { ksort($s); } }
        echo md5(json_encode($sig));
    ' "$1"
}

# --- 1. Clone app at the frozen commit (cache-friendly) ----------------------
#
# The ref is an immutable commit, so a populated APP_SRC is reusable across runs
# and is what CI caches. Clone the branch tip then reset to the exact commit,
# with a fetch fallback when the commit is not the tip (mirrors setup.sh).

if [[ ! -d "$APP_SRC/.git" ]]; then
    echo "[$APP] cloning $REPO @ $REF" >&2
    rm -rf "$APP_SRC"
    mkdir -p "$APP_SRC"
    git clone --quiet --no-checkout "$REPO" "$APP_SRC"
    (
        cd "$APP_SRC"
        git checkout --quiet "$REF" 2>/dev/null || {
            git fetch --quiet origin "$REF"
            git checkout --quiet "$REF"
        }
    )
else
    echo "[$APP] reusing cached source at $APP_SRC" >&2
fi

# --- 2. Configure composer + write psalm.xml on the source -------------------
#
# Point the plugin path-repo at PLUGIN_BASE for the source install; the head
# copy re-points to PLUGIN_HEAD afterwards. Released psalm resolves from
# Packagist via the plugin's own `vimeo/psalm` constraint — no psalm path repo.

# Point a project's composer.json at the given plugin checkout via a symlinked
# path repo. $full=1 also applies the one-time root tweaks (stability, require,
# relaxed PHP constraint) needed on the source install; the per-side relink
# only swaps the path-repo URL.
configure_plugin_repo() {
    local dir="$1" plugin_dir="$2" full="${3:-0}"
    php -r '
        $file = $argv[1] . "/composer.json";
        $d = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $repos = $d["repositories"] ?? [];
        if (array_is_list($repos) === false && $repos !== []) { $repos = array_values($repos); }
        // Drop any prior plugin path repo, prepend ours.
        $repos = array_values(array_filter($repos, fn($r) => strpos(json_encode($r), "psalm-plugin-laravel") === false));
        array_unshift($repos, ["name" => "psalm/plugin-laravel", "type" => "path", "url" => $argv[2], "options" => ["symlink" => true]]);
        $d["repositories"] = $repos;
        if ($argv[3] === "1") {
            $d["minimum-stability"] = "dev";
            $d["prefer-stable"] = true;
            $d["require-dev"]["psalm/plugin-laravel"] = "*";
            // Relax a pinned PHP constraint (e.g. "8.3.*" -> "^8.3") so install
            // does not fail on the runner PHP; analysis runs under the real binary.
            $php = $d["require"]["php"] ?? "";
            if ($php !== "" && $php[0] !== "^" && $php[0] !== ">") {
                $parts = trim(str_replace([".*", "*"], "", explode("|", $php)[0]));
                if ($parts !== "") { $d["require"]["php"] = "^" . $parts; }
            }
        }
        file_put_contents($file, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    ' "$dir" "$plugin_dir" "$full"
}

write_psalm_xml() {
    # Reuse the app project layout heuristic from setup.sh: app/ (Laravel app),
    # packages/*/src (monorepo like filament), or src/ (library). An explicit
    # --project-dir overrides detection.
    local target="$APP_SRC/psalm.xml"
    local dirs=""
    if [[ -n "$PROJECT_DIR" ]]; then
        dirs="<directory name=\"$PROJECT_DIR\" />"
    elif [[ -d "$APP_SRC/app" ]]; then
        for d in app bootstrap config database routes; do
            [[ -d "$APP_SRC/$d" ]] && dirs="$dirs<directory name=\"$d\" />"
        done
    elif compgen -G "$APP_SRC/packages/*/src" >/dev/null 2>&1; then
        for d in "$APP_SRC"/packages/*/src; do
            dirs="$dirs<directory name=\"packages/$(basename "$(dirname "$d")")/src\" />"
        done
    elif [[ -d "$APP_SRC/src" ]]; then
        dirs="<directory name=\"src\" />"
    else
        dirs="<directory name=\".\" />"
    fi

    cat > "$target" <<XMLEOF
<?xml version="1.0"?>
<psalm errorLevel="1" resolveFromConfigFile="true"
    xmlns="https://getpsalm.org/schema/config"
    findUnusedCode="false" findUnusedPsalmSuppress="false">
    <projectFiles>
        ${dirs}
        <ignoreFiles><directory name="vendor" /></ignoreFiles>
    </projectFiles>
    <plugins>
        <pluginClass class="Psalm\LaravelPlugin\Plugin">
            <failOnInternalError>false</failOnInternalError>
        </pluginClass>
    </plugins>
    <issueHandlers>
        <PropertyNotSetInConstructor errorLevel="info" />
        <DeprecatedMethod errorLevel="info" />
        <DeprecatedClass errorLevel="info" />
        <MissingOverrideAttribute errorLevel="info" />
        <MissingImmutableAnnotation errorLevel="info" />
        <ClassMustBeFinal errorLevel="info" />
    </issueHandlers>
</psalm>
XMLEOF
}

# Run the one-time source install only if vendor is absent (cache-friendly).
if [[ ! -f "$APP_SRC/vendor/bin/psalm" ]]; then
    echo "[$APP] installing dependencies" >&2
    (
        cd "$APP_SRC"
        if [[ -n "$PRIME" ]]; then
            echo "[$APP] prime: $PRIME" >&2
            eval "$PRIME"
        fi
    )
    configure_plugin_repo "$APP_SRC" "$PLUGIN_BASE" 1
    write_psalm_xml
    # Don't let Composer's security-advisory policy block the solve. Some apps
    # pin a transitive (e.g. symfony/http-foundation) to an advisory-flagged
    # version; we only type-analyse the code, never run it, so the advisory is
    # irrelevant here and would otherwise fail the whole install. The setting is
    # written into composer.json, so the per-side COW copies inherit it.
    (cd "$APP_SRC" && composer config --no-interaction policy.advisories.block false 2>/dev/null || true)
    (cd "$APP_SRC" && composer update "${COMPOSER_FLAGS[@]}" --quiet)
else
    echo "[$APP] reusing installed vendor" >&2
    write_psalm_xml
fi

# --- 3. Run one side: relink plugin, run Psalm, emit JSON --------------------

run_side() {
    local label="$1" plugin_dir="$2" relink="$3"
    # Both sides use the SAME absolute work-dir path (not work-<label>). Psalm
    # bakes the analysis path into the report in ways that survive any post-hoc
    # normalisation — notably literal-string TYPES that Psalm then TRUNCATES, so
    # only a side-dependent prefix of the path survives with no full token left to
    # rewrite. A per-side path therefore manufactures false +N/-N churn for every
    # such issue. Reusing one path makes the baked path byte-identical on both sides,
    # so only real changes differ. Safe because base and head run sequentially
    # for an app (one matrix job), each starting with rm -rf below.
    local app_dir="${OUT}/${APP}/work"
    local issues_file="${OUT}/${APP}/${APP}-${label}-${DATE_MARKER}--issues.json"
    local perf_file="${OUT}/${APP}/${APP}-${label}-${DATE_MARKER}--perf.json"
    local crash_log="${OUT}/${APP}/${APP}-${label}-${DATE_MARKER}--crash.log"

    # Skip-if-cached: a complete side is reused verbatim on rerun.
    if [[ -f "$issues_file" && -f "$perf_file" ]]; then
        echo "[$APP/$label] cached, skipping" >&2
        return 0
    fi

    # Fresh working copy off the source (the shared work dir is rebuilt per side).
    rm -rf "$app_dir"
    fast_copy "$APP_SRC" "$app_dir"

    # Point this copy's plugin at $plugin_dir. The source install already linked
    # vendor/psalm/plugin-laravel at PLUGIN_BASE (symlink path repo), and the
    # PSR-4 map resolves through that symlink, so the cheapest relink is a symlink
    # swap — no Composer solve. Only fall back to Composer when head changed the
    # plugin's installed dependency shape (see plugin_dep_sig).
    #
    # Critical: `composer update psalm/plugin-laravel` does NOT re-point the path
    # symlink when the package version string is unchanged (both checkouts carry
    # the same dev version), so Composer alone would leave the copy linked to
    # PLUGIN_BASE and silently analyse head with the base plugin. Every branch
    # therefore sets the symlink explicitly; Composer runs only to pull head's
    # new dependencies into vendor.
    local link="$app_dir/vendor/psalm/plugin-laravel"
    case "$relink" in
        none)
            : # base side: the copied vendor already symlinks to PLUGIN_BASE
            ;;
        symlink)
            rm -rf "$link"
            ln -s "$plugin_dir" "$link"
            ;;
        composer)
            configure_plugin_repo "$app_dir" "$plugin_dir"
            (cd "$app_dir" && composer update psalm/plugin-laravel "${COMPOSER_FLAGS[@]}" --with-all-dependencies --quiet) \
                || { echo "[$APP/$label] composer relink failed" >&2; rm -rf "$app_dir"; return 0; }
            rm -rf "$link"
            ln -s "$plugin_dir" "$link"
            ;;
    esac

    local out_txt err_txt t0 exit_code wall coverage count
    out_txt=$(mktemp); err_txt=$(mktemp)
    # Sub-second wall time via PHP microtime — portable (macOS `date` lacks %N)
    # and finer than whole-second `date +%s`. Still threshold-filtered downstream
    # because CI runner jitter dominates small deltas.
    t0=$(php -r 'echo microtime(true);')
    exit_code=0
    (
        cd "$app_dir"
        php -d memory_limit="$MEM" vendor/bin/psalm -c psalm.xml \
            --no-cache --no-diff --no-progress --no-suggestions --monochrome \
            ${PSALM_EXTRA[@]+"${PSALM_EXTRA[@]}"} \
            --report="${issues_file}" >"$out_txt" 2>"$err_txt"
    ) || exit_code=$?
    wall=$(php -r 'printf("%.3f", microtime(true) - (float) $argv[1]);' "$t0")

    # Psalm exits non-zero whenever issues are found, so a non-empty report is
    # the real success signal; treat a missing/empty report as a crash.
    if [[ ! -s "$issues_file" ]]; then
        echo "[$APP/$label] no report (exit $exit_code) — recording (missing)" >&2
        { echo "=== $APP/$label exit $exit_code after ${wall}s ==="; echo "--- stderr ---"; cat "$err_txt"; echo "--- stdout ---"; cat "$out_txt"; } > "$crash_log"
        rm -f "$issues_file"
        rm -rf "$app_dir" "$out_txt" "$err_txt"
        return 0
    fi

    # Make file_path relative to the (now side-independent) work dir, so stored
    # identities are readable. Because both sides share the work-dir path, every
    # other place Psalm embeds it (messages, anon-class names, literal types) is
    # already byte-identical across sides and needs no normalisation.
    # Use the canonical (symlink-resolved) dir — Psalm reports realpath'd paths,
    # so on macOS the report says /private/tmp/... while $app_dir is /tmp/...
    local app_dir_real
    app_dir_real=$(cd "$app_dir" && pwd -P)
    php -r '
        $file = $argv[1]; $prefix = rtrim($argv[2], "/") . "/";
        $d = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        foreach ($d as &$i) {
            if (isset($i["file_path"]) && str_starts_with($i["file_path"], $prefix)) {
                $i["file_path"] = substr($i["file_path"], strlen($prefix));
            }
        }
        file_put_contents($file, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    ' "$issues_file" "$app_dir_real"

    coverage=$(sed -n 's/.*infer types for \([0-9.]*\)%.*/\1/p' "$out_txt" | tail -1)
    count=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo is_array($d)?count($d):0;' "$issues_file")

    php -r '
        $cov = $argv[6];
        file_put_contents($argv[1], json_encode([
            "app" => $argv[2], "version" => $argv[3], "date" => $argv[4],
            "wall_seconds" => (float) $argv[5],
            "type_coverage_pct" => $cov === "" ? null : (float) $cov,
            "total_issues" => (int) $argv[7], "exit_code" => (int) $argv[8],
        ], JSON_PRETTY_PRINT));
    ' "$perf_file" "$APP" "$label" "$DATE_MARKER" "$wall" "${coverage:-}" "${count:-0}" "$exit_code"

    rm -f "$crash_log"
    echo "[$APP/$label] $count issues, ${coverage:-?}% coverage, ${wall}s" >&2
    rm -rf "$app_dir" "$out_txt" "$err_txt"
}

# Base reuses the source install verbatim (its vendor already links PLUGIN_BASE).
# Head re-points a symlink when the plugin's dependency shape is unchanged
# (the common case), and only re-solves with Composer when it differs.
if [[ "$(plugin_dep_sig "$PLUGIN_BASE")" == "$(plugin_dep_sig "$PLUGIN_HEAD")" ]]; then
    HEAD_RELINK=symlink
else
    HEAD_RELINK=composer
fi

run_side "$BASE_LABEL" "$PLUGIN_BASE" none
run_side "$HEAD_LABEL" "$PLUGIN_HEAD" "$HEAD_RELINK"

echo "[$APP] done" >&2
