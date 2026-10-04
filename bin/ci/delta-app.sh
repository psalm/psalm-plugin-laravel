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
#   <out>/<app>/<app>-<label>-<date-marker>--perf.json      (see below)
#   <out>/<app>/<app>-<label>-<date-marker>--crash.log      (side has no report)
#
# perf.json: app, version, date, wall_seconds, type_coverage_pct, total_issues,
#   exit_code, plus the fields delta-report.php uses to qualify a comparison:
#   threads         Psalm --threads/--scan-threads used (always 1, see run_side)
#   plugin_status   "ok" | "degraded" | "disabled", from the plugin's stderr warnings
#   versions        {php, vimeo/psalm, laravel/framework} from the app's vendor
#   deps_diverged   HEAD only: true when app vendor, minus the plugin itself,
#                   differs from the base vendor (head's relink changed a dependency)
# crash.log is written whenever a side yields no usable report (missing, or not a
# JSON list), and for ANY failure inside a side (copy, config, relink, parse); the
# other side still runs. It is also written for clone/install failures. Its first
# line is "=== <app>/<label> exit N after Ms ===".
#
# A per-side failure exits 0 so delta-report.php renders the app as crashed
# instead of blocking the whole report. Hard setup errors (bad args, clone/install
# failure) record the crash log for both sides, then exit non-zero.

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

# Psalm merges parallel worker results in completion order, so with >1 thread the
# issue set of an identical run flaps (e.g. which of two same-named functions
# wins a mutation-info slot). One thread for both scan and analysis makes a run
# a pure function of its inputs; CI wall time is the price.
THREADS=1

# All scratch (logs, per-side plugin caches) lives under one dir, removed on any
# exit; the per-side app copy sits inside $OUT and is excluded from the artifact.
TMP_ROOT=$(mktemp -d)
: > "$TMP_ROOT/setup.log"

side_file() { echo "${OUT}/${APP}/${APP}-$1-${DATE_MARKER}--$2"; }

# write_crash <label> <exit> <wall-seconds> <stderr-file> [stdout-file]
write_crash() {
    {
        echo "=== $APP/$1 exit $2 after ${3}s ==="
        echo "--- stderr ---"; cat "$4"
        echo "--- stdout ---"; cat "${5:-/dev/null}"
    } > "$(side_file "$1" crash.log)"
}

# A side is "settled" once it has a complete cached result or run_side recorded
# its outcome (report or crash.log). The EXIT trap treats every unsettled side as
# crashed, so ANY abort — set -e anywhere in setup, a signal — still leaves a
# crash.log instead of a silent hole the report cannot explain. setup.log carries
# the failing step's output.
side_settled() {
    [[ -f "$TMP_ROOT/settled-$1" ]] \
        || [[ -f "$(side_file "$1" issues.json)" && -f "$(side_file "$1" perf.json)" ]]
}

on_exit() {
    local rc=$? label
    if [[ "$rc" != 0 ]]; then
        for label in "$BASE_LABEL" "$HEAD_LABEL"; do
            side_settled "$label" && continue
            rm -f "$(side_file "$label" issues.json)" "$(side_file "$label" perf.json)"
            write_crash "$label" "${SETUP_RC:-$rc}" "$SECONDS" "$TMP_ROOT/setup.log" || true
        done
    fi
    rm -rf "$TMP_ROOT" "$OUT/$APP/work"
}
trap on_exit EXIT

# Hard setup failure: neither side can run; on_exit records both as crashed,
# keeping the failing step's own exit code as the crash.log headline.
SETUP_RC=""
setup_failed() {
    SETUP_RC="$2"
    echo "[$APP] $1 failed (exit $2)" >&2
    cat "$TMP_ROOT/setup.log" >&2
    exit 1
}

# Versions that decide analysis results, read from the app's own installed.json
# (what Psalm actually loads), because the install floats on Packagist.
installed_versions() {
    php -d memory_limit=-1 -r '
        $f = $argv[1] . "/vendor/composer/installed.json";
        $j = is_file($f) ? json_decode(file_get_contents($f), true) : null;
        $v = ["php" => PHP_VERSION, "vimeo/psalm" => null, "laravel/framework" => null];
        foreach ($j["packages"] ?? $j ?? [] as $p) {
            if (is_array($p) && array_key_exists($p["name"] ?? "", $v)) { $v[$p["name"]] = $p["version"] ?? null; }
        }
        echo json_encode($v);
    ' "$1"
}

# md5 of every installed package except the plugin under test (name, version,
# source revision): equal between sides iff only the plugin differs.
deps_sig() {
    php -d memory_limit=-1 -r '
        $f = $argv[1] . "/vendor/composer/installed.json";
        $j = is_file($f) ? json_decode(file_get_contents($f), true) : null;
        $s = [];
        foreach ($j["packages"] ?? $j ?? [] as $p) {
            if (!is_array($p) || ($p["name"] ?? "") === "psalm/plugin-laravel") { continue; }
            $s[] = ($p["name"] ?? "") . "@" . ($p["version"] ?? "") . "@" . ($p["source"]["reference"] ?? $p["dist"]["reference"] ?? "");
        }
        sort($s);
        echo md5(implode("\n", $s));
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
    # Explicit `|| return`s: errexit is off inside a function run on the left of `||`.
    clone_app() {
        git clone --quiet --no-checkout "$REPO" "$APP_SRC" || return
        cd "$APP_SRC" || return
        git checkout --quiet "$REF" 2>/dev/null \
            || { git fetch --quiet origin "$REF" && git checkout --quiet "$REF"; }
    }
    (clone_app) >"$TMP_ROOT/setup.log" 2>&1 || setup_failed clone "$?"
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
            // minimum-stability=dev above is only needed for the dev-branch
            // path repo install above; it also makes vimeo/psalm eligible to
            // resolve to its dev-master branch instead of a tagged beta, which
            // is a problem because dev-master is a rolling branch that can be
            // transiently broken (e.g. a bad merge dropping a typed property,
            // producing a dynamic-property deprecation that the Psalm error
            // handler escalates into an uncaught crash). Pin vimeo/psalm to at
            // least beta stability, reusing the floor constraint declared in
            // the plugin composer.json, so this one package still resolves to
            // a tagged release regardless of the relaxed root stability.
            $plugin_composer = json_decode(file_get_contents($argv[2] . "/composer.json"), true, 512, JSON_THROW_ON_ERROR);
            $psalm_constraint = trim(explode("||", $plugin_composer["require"]["vimeo/psalm"] ?? "^7.0.0-beta19")[0]);
            $d["require-dev"]["vimeo/psalm"] = $psalm_constraint . "@beta";
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

# Records the base plugin dependency signature the cached vendor was solved
# against. Lives inside the cached app-src so a later run can prove the vendor
# still matches the plugin shape under test.
PLUGIN_SIG_FILE="$APP_SRC/.plugin-dep-sig"

# Reuse the cached vendor only when it provably matches the base plugin's
# dependency shape. A cached app-src is keyed on the app ref, NOT on the plugin
# under test, so a vendor solved for one plugin line can be restored for another
# (e.g. a Psalm 7 line on 4.x feeding a Psalm 6 line on 3.x and vice versa).
# Reusing a mismatched vendor loads the wrong dependency set — most visibly an
# `AddTaintsInterface` whose `addTaints()` return type differs (array in 6, int
# in 7), fataling every handler at class-load before analysis even starts.
#
# `plugin_dep_sig` (require + autoload — the only sections that change what gets
# installed) is the same signature the head/base relink already trusts, so ANY
# dependency drift triggers a reinstall: a Psalm major OR minor constraint bump,
# an added runtime dependency, an autoload change. A missing or unreadable
# signature means we cannot prove compatibility, so we also reinstall — a
# self-healing guard must never reuse an unverifiable vendor. The reinstall is a
# clean `composer update` that honours the plugin's constraints.
want_sig="$(plugin_dep_sig "$PLUGIN_BASE")"
need_install=0
if [[ ! -f "$APP_SRC/vendor/bin/psalm" ]]; then
    need_install=1
elif [[ -z "$want_sig" ]]; then
    echo "[$APP] cannot compute base plugin dependency signature; reinstalling to stay safe" >&2
    need_install=1
elif [[ ! -f "$PLUGIN_SIG_FILE" || "$(cat "$PLUGIN_SIG_FILE")" != "$want_sig" ]]; then
    echo "[$APP] cached vendor was solved for a different plugin dependency shape; reinstalling" >&2
    need_install=1
fi

# Run the one-time source install only when needed (cache-friendly otherwise).
install_app() {
    if [[ -n "$PRIME" ]]; then
        echo "[$APP] prime: $PRIME" >&2
        (cd "$APP_SRC" && eval "$PRIME") || return
    fi
    configure_plugin_repo "$APP_SRC" "$PLUGIN_BASE" 1 || return
    write_psalm_xml || return
    # Don't let Composer's security-advisory policy block the solve. Some apps
    # pin a transitive (e.g. symfony/http-foundation) to an advisory-flagged
    # version; we only type-analyse the code, never run it, so the advisory is
    # irrelevant here and would otherwise fail the whole install. The setting is
    # written into composer.json, so the per-side COW copies inherit it.
    (cd "$APP_SRC" && composer config --no-interaction policy.advisories.block false 2>/dev/null || true)
    (cd "$APP_SRC" && composer update "${COMPOSER_FLAGS[@]}") || return
    # Stamp what this vendor was solved against, so a future run can verify reuse.
    printf '%s' "$want_sig" > "$PLUGIN_SIG_FILE"
}

if [[ "$need_install" == 1 ]]; then
    echo "[$APP] installing dependencies" >&2
    install_app >"$TMP_ROOT/setup.log" 2>&1 || setup_failed install "$?"
else
    echo "[$APP] reusing installed vendor" >&2
    write_psalm_xml >"$TMP_ROOT/setup.log" 2>&1 || setup_failed config "$?"
fi

# Base vendor fingerprint (minus the plugin) for the head deps-divergence check.
BASE_DEPS_SIG=$(deps_sig "$APP_SRC")

# --- 3. Run one side: relink plugin, run Psalm, emit JSON --------------------

# relink: base | symlink | composer (head). Head's composer relink is the only
# step that can change app dependencies, hence the divergence check below.
run_side_body() {
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
    local issues_file perf_file crash_log
    issues_file=$(side_file "$label" issues.json)
    perf_file=$(side_file "$label" perf.json)
    crash_log=$(side_file "$label" crash.log)

    # Skip-if-cached: a complete side is reused verbatim on rerun.
    if [[ -f "$issues_file" && -f "$perf_file" ]]; then
        echo "[$APP/$label] cached, skipping" >&2
        return 0
    fi

    # Drop leftovers of an interrupted run: a stale report would pass the
    # "usable report" check below even if Psalm dies before writing a new one.
    rm -f "$issues_file" "$perf_file" "$crash_log"

    local t_side=$SECONDS
    local out_txt="$TMP_ROOT/$label.out" err_txt="$TMP_ROOT/$label.err"
    local raw_report="$TMP_ROOT/$label.report.json"

    # Fresh working copy off the source (the shared work dir is rebuilt per side).
    rm -rf "$app_dir"
    fast_copy "$APP_SRC" "$app_dir"

    # Point this copy's plugin at $plugin_dir. The PSR-4 map resolves through the
    # vendor symlink, so the cheapest relink is a symlink swap — no Composer solve.
    # Composer runs only when head changed the plugin's installed dependency shape
    # (see plugin_dep_sig), and only for the plugin package itself: no
    # --with-dependencies, so it cannot silently upgrade app dependencies the
    # base side did not get (an unsatisfiable plugin constraint fails the side
    # and surfaces as a crash instead).
    #
    # Critical: `composer update psalm/plugin-laravel` does NOT re-point the path
    # symlink when the package version string is unchanged (both checkouts carry
    # the same dev version), so Composer alone would leave the copy linked to
    # PLUGIN_BASE and silently analyse head with the base plugin. Every side
    # therefore sets the symlink explicitly — base too, because a cached vendor
    # may link to a worktree that no longer exists.
    local link="$app_dir/vendor/psalm/plugin-laravel"
    if [[ "$relink" == composer ]]; then
        configure_plugin_repo "$app_dir" "$plugin_dir"
        local relink_rc=0
        # Not --quiet: on failure the log is the crash report, and --quiet drops the
        # resolver's reasons.
        (cd "$app_dir" && composer update psalm/plugin-laravel "${COMPOSER_FLAGS[@]}") \
            >"$TMP_ROOT/relink.log" 2>&1 || relink_rc=$?
        if [[ "$relink_rc" != 0 ]]; then
            echo "[$APP/$label] composer relink failed (exit $relink_rc)" >&2
            write_crash "$label" "$relink_rc" "$((SECONDS - t_side))" "$TMP_ROOT/relink.log"
            rm -rf "$app_dir"
            return 0
        fi
    fi
    rm -rf "$link"
    ln -s "$plugin_dir" "$link"

    local deps_diverged=""
    if [[ "$relink" != base ]]; then
        deps_diverged=false
        [[ "$(deps_sig "$app_dir")" == "$BASE_DEPS_SIG" ]] || deps_diverged=true
    fi
    local versions
    versions=$(installed_versions "$app_dir")

    local t0 exit_code wall coverage count plugin_status
    # Sub-second wall time via PHP microtime — portable (macOS `date` lacks %N)
    # and finer than whole-second `date +%s`. Still threshold-filtered downstream
    # because CI runner jitter dominates small deltas.
    t0=$(php -r 'echo microtime(true);')
    exit_code=0
    # A private plugin cache per side: with --no-cache the plugin falls back to
    # <sys_get_temp_dir()>/psalm-laravel-<md5(cwd)> (PluginConfig::resolveCachePath),
    # and both sides share the cwd, so head would read the schema base cached.
    # A per-side TMPDIR moves that fallback (and any other temp use) out of reach.
    # PSALM_LARAVEL_PLUGIN_CACHE_PATH would also work but is deprecated, and
    # Psalm's error handler turns its E_USER_DEPRECATED notice into a crash.
    local side_tmp="$TMP_ROOT/tmp-$label"
    mkdir -p "$side_tmp"
    (
        cd "$app_dir"
        # --long-progress (not --no-progress): VoidProgress::write() is a no-op, so
        # it would swallow the plugin's InternalErrorReporter warnings that
        # plugin_status below is read from. Piped stderr selects the quiet,
        # carriage-return-free variant.
        TMPDIR="$side_tmp" \
        php -d memory_limit="$MEM" \
            vendor/bin/psalm -c psalm.xml \
            --threads="$THREADS" --scan-threads="$THREADS" \
            --no-cache --no-diff --long-progress --no-suggestions --monochrome \
            ${PSALM_EXTRA[@]+"${PSALM_EXTRA[@]}"} \
            --report="$raw_report" >"$out_txt" 2>"$err_txt"
    ) || exit_code=$?
    wall=$(php -r 'printf("%.3f", microtime(true) - (float) $argv[1]);' "$t0")
    rm -rf "$side_tmp"

    # Psalm exits non-zero whenever issues are found, so its exit code says nothing
    # about the report. The report itself is the signal: it must exist and decode
    # as a JSON list (a crash mid-write leaves truncated JSON), and only then is
    # it published to issues.json — a bad one becomes a crash, never a partial file.
    #
    # The same pass makes file_path relative to the (now side-independent) work
    # dir, so stored identities are readable. Because both sides share the
    # work-dir path, every other place Psalm embeds it (messages, anon-class
    # names, literal types) is already byte-identical across sides and needs no
    # normalisation. Use the canonical (symlink-resolved) dir — Psalm reports
    # realpath'd paths, so on macOS the report says /private/tmp/... while
    # $app_dir is /tmp/... memory_limit=-1: the decoded report of a big app
    # exceeds PHP's 128M default locally (CI sets it via setup-php).
    local app_dir_real
    app_dir_real=$(cd "$app_dir" && pwd -P)
    if ! count=$(php -d memory_limit=-1 -r '
        $file = $argv[1]; $prefix = rtrim($argv[2], "/") . "/";
        try {
            if (!is_file($file) || filesize($file) === 0) {
                throw new RuntimeException("Psalm wrote no report");
            }
            $d = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($d) || !array_is_list($d)) {
                throw new RuntimeException("report is not a JSON list");
            }
            foreach ($d as &$i) {
                if (!is_array($i)) {
                    throw new RuntimeException("report entry is not an object");
                }
                if (isset($i["file_path"]) && str_starts_with($i["file_path"], $prefix)) {
                    $i["file_path"] = substr($i["file_path"], strlen($prefix));
                }
            }
            file_put_contents($file, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            fwrite(STDERR, "delta-app: unusable Psalm report: " . $e->getMessage() . "\n");
            exit(1);
        }
        echo count($d);
    ' "$raw_report" "$app_dir_real" 2>>"$err_txt"); then
        echo "[$APP/$label] no usable report (exit $exit_code) — recording crash" >&2
        write_crash "$label" "$exit_code" "$wall" "$err_txt" "$out_txt"
        rm -rf "$app_dir"
        return 0
    fi
    mv "$raw_report" "$issues_file"

    # InternalErrorReporter only warns (failOnInternalError=false in psalm.xml), so
    # a side that lost its plugin analyses fine but with hundreds of bogus Mixed*
    # issues. Detect it from the warning text; "disabled" outranks "degraded".
    plugin_status=ok
    if grep -qsF 'Laravel plugin is running in degraded mode' "$err_txt" "$out_txt"; then
        plugin_status=degraded
    fi
    if grep -qsF 'Laravel plugin has been disabled for this run' "$err_txt" "$out_txt"; then
        plugin_status=disabled
    fi

    coverage=$(sed -n 's/.*infer types for \([0-9.]*\)%.*/\1/p' "$out_txt" | tail -1)

    php -r '
        $cov = $argv[6];
        $perf = [
            "app" => $argv[2], "version" => $argv[3], "date" => $argv[4],
            "wall_seconds" => (float) $argv[5],
            "type_coverage_pct" => $cov === "" ? null : (float) $cov,
            "total_issues" => (int) $argv[7], "exit_code" => (int) $argv[8],
            "threads" => (int) $argv[9], "plugin_status" => $argv[10],
            "versions" => json_decode($argv[11], true),
        ];
        if ($argv[12] !== "") { $perf["deps_diverged"] = $argv[12] === "true"; }
        file_put_contents($argv[1], json_encode($perf, JSON_PRETTY_PRINT));
    ' "$perf_file" "$APP" "$label" "$DATE_MARKER" "$wall" "${coverage:-}" "${count:-0}" "$exit_code" \
        "$THREADS" "$plugin_status" "$versions" "$deps_diverged"

    echo "[$APP/$label] $count issues, ${coverage:-?}% coverage, ${wall}s, plugin $plugin_status${deps_diverged:+, deps_diverged=$deps_diverged}" >&2
    rm -rf "$app_dir"
}

# Run one side so ANY failure inside it (copy, config, relink, psalm, report
# parse) is recorded as that side's crash.log and the other side still runs.
run_side() {
    local label="$1" rc=0 t_side=$SECONDS
    local side_log="$TMP_ROOT/$label.side.log"
    # errexit is suspended for any command whose status is tested (`||`, `if`),
    # even after `set -e` inside a subshell, so the body must run as a plain
    # pipeline stage with its status read back from PIPESTATUS. tee keeps the
    # output live on stderr and keeps a copy as crash.log material.
    set +e
    ( set -e; run_side_body "$@" ) 2>&1 | tee "$side_log" >&2
    rc=${PIPESTATUS[0]}
    set -e
    if [[ "$rc" != 0 ]]; then
        echo "[$APP/$label] side aborted (exit $rc) — recording crash" >&2
        # Never leave a half-written side behind: no report may sit next to a crash.
        rm -f "$(side_file "$label" issues.json)" "$(side_file "$label" perf.json)"
        [[ -f "$(side_file "$label" crash.log)" ]] \
            || write_crash "$label" "$rc" "$((SECONDS - t_side))" "$side_log"
        rm -rf "${OUT}/${APP}/work"
    fi
    : > "$TMP_ROOT/settled-$label"
}

# Base reuses the source install; head re-points a symlink when the plugin's
# dependency shape is unchanged (the common case), and only re-solves with
# Composer when it differs.
if [[ "$(plugin_dep_sig "$PLUGIN_BASE")" == "$(plugin_dep_sig "$PLUGIN_HEAD")" ]]; then
    HEAD_RELINK=symlink
else
    HEAD_RELINK=composer
fi

run_side "$BASE_LABEL" "$PLUGIN_BASE" base
run_side "$HEAD_LABEL" "$PLUGIN_HEAD" "$HEAD_RELINK"

echo "[$APP] done" >&2
