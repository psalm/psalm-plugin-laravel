#!/usr/bin/env bash
# Run shipmonk/name-collision-detector once per stub bucket.
#
# Buckets are independent because stub layout intentionally re-declares
# Laravel classes across versions and integrations:
#   - stubs/common/                      strict, no duplicates
#   - stubs/<laravel-version>/           may override stubs/common/ (per Plugin.php loader)
#   - stubs/integrations/<package>/      may re-declare third-party classes
# Running the detector on the union would surface those overrides as false
# positives. Scoping each bucket separately keeps duplicate detection useful
# without fighting the intentional layering.
set -euo pipefail

cd "$(dirname "$0")/../.."

CONFIG="tests/scripts/name-collision-detector.json"
DETECTOR="vendor/bin/detect-collisions"

if [ ! -x "$DETECTOR" ]; then
    echo "ERROR: $DETECTOR not found. Run 'composer install' first." >&2
    exit 2
fi

if [ ! -r "$CONFIG" ]; then
    echo "ERROR: $CONFIG not found or unreadable." >&2
    exit 2
fi

# nullglob so empty globs (e.g. no stubs/integrations/*) expand to nothing
# instead of leaving the literal pattern.
shopt -s nullglob

buckets=()
[ -d stubs/common ] && buckets+=(stubs/common)
for dir in stubs/*/; do
    name="${dir%/}"
    case "$name" in
        stubs/common|stubs/integrations) continue ;;
    esac
    buckets+=("$name")
done
for dir in stubs/integrations/*/; do
    buckets+=("${dir%/}")
done

if [ "${#buckets[@]}" -eq 0 ]; then
    echo "ERROR: no stub buckets found under stubs/." >&2
    exit 2
fi

status=0
failed_buckets=()
for bucket in "${buckets[@]}"; do
    echo "=== Scanning $bucket ==="
    # Capture exit code without tripping `set -e`. `cmd || rc=$?` keeps the
    # real exit code (vs `if ! cmd; then $?` which always reads 0 inside the
    # then-branch because `!` flips the pipeline's status).
    rc=0
    "$DETECTOR" --configuration="$CONFIG" "$bucket" || rc=$?
    if [ "$rc" -ne 0 ]; then
        echo "ERROR: detector exited $rc for $bucket" >&2
        failed_buckets+=("$bucket")
        status=1
    fi
done

if [ "${#failed_buckets[@]}" -gt 0 ]; then
    echo "FAILED buckets: ${failed_buckets[*]}" >&2
fi

exit "$status"
