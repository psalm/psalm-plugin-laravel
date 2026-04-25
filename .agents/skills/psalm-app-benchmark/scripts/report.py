#!/usr/bin/env python3
"""
Generate per-app markdown reports and SUMMARY.md from benchmark JSON files.

Usage:
    python3 report.py [app-name]        # Generate report for one app (or all if omitted)
    python3 report.py --summary         # Regenerate SUMMARY.md only
    python3 report.py --all             # Generate all app reports + SUMMARY.md

Reads JSON files from TRACK_DIR/{app}/ subdirectories.
Writes markdown to TRACK_DIR/{app}.md and TRACK_DIR/SUMMARY.md.
"""

import json
import os
import re
import sys
from collections import Counter
from pathlib import Path

TRACK_DIR = Path(os.environ.get(
    "TRACK_DIR",
    "/Users/alies/code/psalm/psalm-plugin-laravel/.alies/.track",
))

# Issue categories — explicit sets for precision.
#
# Plugin Gaps: issues likely caused by incomplete plugin coverage of Laravel
# magic (Eloquent, Facades, macros, container). These are what the plugin
# can fix by adding handlers or stubs.
PLUGIN_GAP_TYPES = {
    "UndefinedMagicMethod",
    "UndefinedMagicPropertyFetch",
    "UndefinedMagicPropertyAssignment",
    "TooManyArguments",
    "InvalidTemplateParam",
    "InvalidDocblock",
    "TooManyTemplateParams",
}

# Enforcement: custom issues emitted by the plugin's own rules.
ENFORCEMENT_TYPES = {
    "NoEnvOutsideConfig",
    "InvalidConsoleArgumentName",
    "InvalidConsoleOptionName",
}

# Code Quality: style/quality issues, not bugs. Explicit list.
CODE_QUALITY_TYPES = {
    # Missing type annotations
    "MissingReturnType", "MissingParamType", "MissingClosureParamType",
    "MissingClosureReturnType", "MissingPropertyType", "MissingConstructor",
    "MissingTemplateParam", "MissingClassConstType", "MissingFile",
    "MissingDependency", "MissingOverrideAttribute",
    # Deprecation
    "DeprecatedInterface", "DeprecatedConstant", "DeprecatedTrait",
    "DeprecatedProperty", "DeprecatedMethod", "DeprecatedClass",
    # Style/design
    "ClassMustBeFinal", "UnsafeInstantiation", "ImpureMethodCall",
    "ImpureFunctionCall", "MutableDependency", "ImmutableDependency",
    "ForbiddenCode", "ReservedWord", "StringIncrement",
    # Redundancy
    "RedundantCondition", "RedundantConditionGivenDocblockType",
    "RedundantCast", "RedundantCastGivenDocblockType",
    "RedundantPropertyInitializationCheck", "RedundantFunctionCall",
    "RedundantFunctionCallGivenDocblockType", "UnusedPsalmSuppress",
    # Docblock issues (not from plugin-generated docblocks)
    "NonInvariantDocblockPropertyType", "DocblockTypeContradiction",
    "MismatchingDocblockParamType", "MismatchingDocblockReturnType",
    "InvalidDocblockParamName", "PossiblyInvalidDocblockTag",
    "RedundantDocblockParamType",
    # Risky patterns
    "RiskyTruthyFalsyComparison", "RiskyCast",
    "PossibleRawObjectIteration", "RawObjectIteration",
}

# Taint: detected via issue type prefix (all start with "Tainted").
# No explicit set needed — categorize_issue() uses startswith("Tainted").

# Everything else falls into "Real App Issues" (genuine type errors).

# App metadata (owner/repo, description, taint priority)
APP_META = {
    "bagisto": ("bagisto/bagisto", "Open-source Laravel e-commerce platform", "P1"),
    "coolify": ("coollabsio/coolify", "Self-hosted PaaS (Heroku/Netlify alternative)", "P1"),
    "ixdf-web": ("InteractionDesignFoundation/IxDF-web", "Large-scale education platform", "P2"),
    "monica": ("monicahq/monica", "Personal CRM", "P2"),
    "pixelfed": ("pixelfed/pixelfed", "Decentralized photo sharing (Instagram alternative)", "P1"),
    "solidtime": ("solidtime-io/solidtime", "Open-source time tracking app", "P3"),
    "spatie-dashboard": ("spatie/dashboard.spatie.be", "Spatie's company dashboard", "P4"),
    "tastyigniter": ("tastyigniter/TastyIgniter", "Restaurant online ordering platform", "P1"),
    "unit3d": ("HDInnovations/UNIT3D", "Private tracker platform", "P1"),
    "vito": ("vitodeploy/vito", "Server management and deployment tool", "P3"),
    "laravel-excel": ("SpartnerNL/Laravel-Excel", "Supercharged Excel/CSV imports and exports", "P3"),
    "filament": ("filamentphp/filament", "Full-stack components for Laravel development", "P3"),
    "corcel": ("jgrossi/corcel", "WordPress content management through Eloquent models (uses Aliases trait for attributes)", "P4"),
}

ALL_APPS = list(APP_META.keys())


def version_sort_key(v: str) -> tuple:
    """Sort versions: v3.2.0 < v3.2.1 < v4.0.0 etc."""
    nums = re.findall(r"\d+", v)
    return tuple(int(n) for n in nums)


def load_app_data(app: str) -> dict[str, list[dict]]:
    """Load all issue JSON files for an app, merging taint results.

    For Psalm 6 (v3.x), taint issues come from a separate --taint-analysis run
    stored in *--taint.json files. These are merged into the main issue list.
    For Psalm 7 (v4.x), taint issues are already in --issues.json.
    Returns {version: [issues]}.
    """
    app_dir = TRACK_DIR / app
    if not app_dir.is_dir():
        return {}

    data = {}
    for f in sorted(app_dir.glob(f"{app}-*--issues.json")):
        # Extract version from filename: {app}-{version}-{date}--issues.json
        m = re.match(rf"^{re.escape(app)}-(.+?)-\d{{4}}-\d{{2}}-\d{{2}}--issues\.json$", f.name)
        if not m:
            continue
        version = m.group(1)
        # Skip non-version labels (like "baseline", "master")
        if not version.startswith("v"):
            continue
        try:
            issues = json.loads(f.read_text())
            data[version] = issues
        except (json.JSONDecodeError, OSError):
            continue

    # Merge taint results from separate --taint-analysis runs (Psalm 6 / v3.x)
    for f in sorted(app_dir.glob(f"{app}-*--taint.json")):
        m = re.match(rf"^{re.escape(app)}-(.+?)-\d{{4}}-\d{{2}}-\d{{2}}--taint\.json$", f.name)
        if not m:
            continue
        version = m.group(1)
        if not version.startswith("v"):
            continue
        try:
            taint_issues = json.loads(f.read_text())
        except (json.JSONDecodeError, OSError):
            continue
        if version in data:
            data[version].extend(taint_issues)
        else:
            data[version] = taint_issues

    return data


def load_app_perf(app: str) -> dict[str, dict]:
    """Load all perf JSON files for an app. Returns {version: perf_data}."""
    app_dir = TRACK_DIR / app
    if not app_dir.is_dir():
        return {}

    data = {}
    for f in sorted(app_dir.glob(f"{app}-*--perf.json")):
        m = re.match(rf"^{re.escape(app)}-(.+?)-\d{{4}}-\d{{2}}-\d{{2}}--perf\.json$", f.name)
        if not m:
            continue
        version = m.group(1)
        if not version.startswith("v"):
            continue
        try:
            data[version] = json.loads(f.read_text())
        except (json.JSONDecodeError, OSError):
            continue

    return data


def count_by_type(issues: list[dict]) -> Counter:
    return Counter(i["type"] for i in issues)


def count_taint(issues: list[dict]) -> int:
    return sum(1 for i in issues if i.get("taint_trace") is not None)


def categorize_issue(issue_type: str) -> str:
    if issue_type in PLUGIN_GAP_TYPES:
        return "Plugin Gaps"
    if issue_type in ENFORCEMENT_TYPES:
        return "Enforcement"
    if issue_type.startswith("Tainted"):
        return "Taint"
    if issue_type in CODE_QUALITY_TYPES:
        return "Code Quality"
    return "Real App Issues"


def format_time(seconds: int | float) -> str:
    s = int(seconds)
    if s < 60:
        return f"{s}s"
    return f"{s // 60}m {s % 60}s"


def format_coverage(pct: float | None) -> str:
    if pct is None:
        return "?"
    return f"{pct:.1f}%"


def generate_app_report(app: str) -> str:
    """Generate a per-app markdown report."""
    data = load_app_data(app)
    perf = load_app_perf(app)
    if not data:
        return ""

    versions = sorted(data.keys(), key=version_sort_key)
    owner_repo, description, taint_prio = APP_META.get(app, (app, "", "P3"))

    # Collect all issue types across all versions
    all_types: set[str] = set()
    for issues in data.values():
        all_types.update(i["type"] for i in issues)

    # Sort by count in latest version descending
    latest_counts = count_by_type(data[versions[-1]])
    sorted_types = sorted(all_types, key=lambda t: -latest_counts.get(t, 0))
    # Move types with 0 in latest to end, sorted by max historical count
    active = [t for t in sorted_types if latest_counts.get(t, 0) > 0]
    inactive = [t for t in sorted_types if latest_counts.get(t, 0) == 0]
    inactive.sort(key=lambda t: -max(count_by_type(data[v]).get(t, 0) for v in versions))
    sorted_types = active + inactive

    lines = []
    lines.append(f"# {owner_repo}")
    lines.append("")
    lines.append(f"{description}.")
    lines.append("")
    lines.append(f"- Repository: https://github.com/{owner_repo}")
    lines.append(f"- Taint priority: {taint_prio}")
    lines.append("")

    # Results table
    lines.append("## Results by Plugin Version")
    lines.append("")

    # Header
    hdr = "| Issue Type |" + "|".join(f" {v} " for v in versions) + "|"
    sep = "| --- |" + "|".join(" ---:" for _ in versions) + "|"
    lines.append(hdr)
    lines.append(sep)

    for itype in sorted_types:
        row = f"| {itype} |"
        for v in versions:
            c = count_by_type(data[v]).get(itype, 0)
            row += f" {c} |"
        lines.append(row)

    # Totals row
    row_total = "| **Total** |"
    row_time = "| **Time** |"
    row_coverage = "| **Coverage** |"
    for v in versions:
        row_total += f" **{len(data[v])}** |"
        p = perf.get(v)
        row_time += f" {format_time(p['wall_seconds']) if p else '?'} |"
        row_coverage += f" {format_coverage(p.get('type_coverage_pct') if p else None)} |"
    lines.append(row_total)
    lines.append(row_time)
    lines.append(row_coverage)
    lines.append("")

    # Issues by Category table
    lines.append("### Issues by Category")
    lines.append("")
    cat_hdr = "| Category |" + "|".join(f" {v} " for v in versions) + "|"
    cat_sep = "| --- |" + "|".join(" ---:" for _ in versions) + "|"
    lines.append(cat_hdr)
    lines.append(cat_sep)

    categories = ["Taint", "Plugin Gaps", "Real App Issues", "Code Quality", "Enforcement"]
    for cat in categories:
        row = f"| {cat} |"
        for v in versions:
            cat_count = sum(
                1 for issue in data[v]
                if categorize_issue(issue["type"]) == cat
            )
            row += f" {cat_count} |"
        lines.append(row)
    lines.append("")

    # Version-over-version deltas (only non-zero)
    lines.append("## Deltas")
    lines.append("")
    has_delta = False
    for i in range(1, len(versions)):
        v_prev, v_curr = versions[i - 1], versions[i]
        prev_counts = count_by_type(data[v_prev])
        curr_counts = count_by_type(data[v_curr])
        total_delta = len(data[v_curr]) - len(data[v_prev])
        if total_delta == 0:
            continue
        has_delta = True
        lines.append(f"**{v_prev} -> {v_curr}** (delta: {total_delta:+d})")
        lines.append("")
        # Show issue types that changed by more than 0
        changed = {}
        for t in all_types:
            d = curr_counts.get(t, 0) - prev_counts.get(t, 0)
            if d != 0:
                changed[t] = d
        for t, d in sorted(changed.items(), key=lambda x: x[1]):
            lines.append(f"- {t}: {prev_counts.get(t, 0)} -> {curr_counts.get(t, 0)} ({d:+d})")
        lines.append("")

    if not has_delta:
        lines.append("No changes between versions.")
        lines.append("")

    return "\n".join(lines)


def generate_summary(apps: list[str] | None = None) -> str:
    """Generate SUMMARY.md from all app data."""
    if apps is None:
        apps = ALL_APPS

    # Load all data
    all_data: dict[str, dict[str, list[dict]]] = {}
    all_perf: dict[str, dict[str, dict]] = {}
    all_versions: set[str] = set()
    for app in apps:
        d = load_app_data(app)
        if d:
            all_data[app] = d
            all_versions.update(d.keys())
        p = load_app_perf(app)
        if p:
            all_perf[app] = p

    if not all_data:
        return "# Benchmark Summary\n\nNo data.\n"

    versions = sorted(all_versions, key=version_sort_key)
    active_apps = [a for a in apps if a in all_data]

    lines = []
    lines.append("# Benchmark Summary")
    lines.append("")
    lines.append("Cross-app issue counts for psalm-plugin-laravel across versions.")
    lines.append("")

    # Total Issues by Version
    lines.append("## Total Issues by Version")
    lines.append("")
    hdr = "| App |" + "|".join(f" {v} " for v in versions) + "|"
    sep = "| --- |" + "|".join(" ---:" for _ in versions) + "|"
    lines.append(hdr)
    lines.append(sep)

    grand_totals = {v: 0 for v in versions}
    for app in active_apps:
        row = f"| {app} |"
        for v in versions:
            if v in all_data[app]:
                c = len(all_data[app][v])
                row += f" {c:,} |"
                grand_totals[v] += c
            else:
                row += " --- |"
        lines.append(row)

    row_total = "| **Total** |"
    row_coverage = "| **Avg Coverage** |"
    for v in versions:
        row_total += f" **{grand_totals[v]:,}** |"
        coverages = [
            all_perf[app][v]["type_coverage_pct"]
            for app in active_apps
            if app in all_perf and v in all_perf[app]
            and all_perf[app][v].get("type_coverage_pct") is not None
        ]
        avg = sum(coverages) / len(coverages) if coverages else None
        row_coverage += f" {format_coverage(avg)} |"
    lines.append(row_total)
    lines.append(row_coverage)
    lines.append("")

    # Taint Issues by Version
    lines.append("## Taint Issues by Version")
    lines.append("")
    lines.append(hdr)
    lines.append(sep)

    taint_totals = {v: 0 for v in versions}
    for app in active_apps:
        row = f"| {app} |"
        for v in versions:
            if v in all_data[app]:
                c = count_taint(all_data[app][v])
                row += f" {c} |"
                taint_totals[v] += c
            else:
                row += " --- |"
        lines.append(row)

    row = "| **Total** |"
    for v in versions:
        row += f" **{taint_totals[v]}** |"
    lines.append(row)
    lines.append("")

    # Version-over-Version Deltas
    lines.append("## Version-over-Version Deltas")
    lines.append("")
    delta_pairs = [f"{versions[i]}..{versions[i+1]}" for i in range(len(versions) - 1)]
    hdr_d = "| App |" + "|".join(f" {p} " for p in delta_pairs) + "|"
    sep_d = "| --- |" + "|".join(" ---:" for _ in delta_pairs) + "|"
    lines.append(hdr_d)
    lines.append(sep_d)

    for app in active_apps:
        row = f"| {app} |"
        for i in range(len(versions) - 1):
            v1, v2 = versions[i], versions[i + 1]
            if v1 in all_data[app] and v2 in all_data[app]:
                d = len(all_data[app][v2]) - len(all_data[app][v1])
                if d == 0:
                    row += " 0 |"
                else:
                    row += f" **{d:+,}** |"
            else:
                row += " --- |"
        lines.append(row)
    lines.append("")

    # Latest version detail
    latest_v = versions[-1]
    lines.append(f"## Latest: {latest_v}")
    lines.append("")
    lines.append(f"| App | Total | Plugin | Taint | Delta vs prev | Top 3 Issue Types |")
    lines.append(f"| --- | ---: | ---: | ---: | ---: | --- |")

    prev_v = versions[-2] if len(versions) > 1 else None
    for app in active_apps:
        if latest_v not in all_data[app]:
            continue
        issues = all_data[app][latest_v]
        total = len(issues)
        plugin = sum(1 for i in issues if i["type"] in ENFORCEMENT_TYPES)
        taint = count_taint(issues)
        if prev_v and prev_v in all_data[app]:
            delta = total - len(all_data[app][prev_v])
            delta_s = f"{delta:+d}" if delta != 0 else "0"
        else:
            delta_s = "NEW"
        top3 = count_by_type(issues).most_common(3)
        top3_s = ", ".join(f"{t}({c})" for t, c in top3)
        lines.append(f"| {app} | {total:,} | {plugin} | {taint} | {delta_s} | {top3_s} |")
    lines.append("")

    # Issues by Category
    lines.append("## Issues by Category")
    lines.append("")
    cat_hdr = "| Category |" + "|".join(f" {v} " for v in versions) + "|"
    cat_sep = "| --- |" + "|".join(" ---:" for _ in versions) + "|"
    lines.append(cat_hdr)
    lines.append(cat_sep)

    categories = ["Taint", "Plugin Gaps", "Real App Issues", "Code Quality", "Enforcement"]
    for cat in categories:
        row = f"| {cat} |"
        for v in versions:
            cat_total = 0
            for app in active_apps:
                if v in all_data[app]:
                    cat_total += sum(
                        1 for i in all_data[app][v]
                        if categorize_issue(i["type"]) == cat
                    )
            row += f" {cat_total:,} |"
        lines.append(row)
    lines.append("")

    # Plugin Gaps Trend (sampled: skip versions where nothing changed)
    lines.append("## Plugin Gaps Trend")
    lines.append("")
    # Show all versions for simplicity
    lines.append(hdr)
    lines.append(sep)
    for app in active_apps:
        row = f"| {app} |"
        for v in versions:
            if v in all_data[app]:
                c = sum(
                    1 for i in all_data[app][v]
                    if i["type"] in PLUGIN_GAP_TYPES
                )
                row += f" {c} |"
            else:
                row += " --- |"
        lines.append(row)
    lines.append("")

    return "\n".join(lines)


def main():
    args = sys.argv[1:]

    if not args or args == ["--all"]:
        # Generate all app reports + summary
        for app in ALL_APPS:
            report = generate_app_report(app)
            if report:
                out = TRACK_DIR / f"{app}.md"
                out.write_text(report)
                print(f"Generated {out.name}", file=sys.stderr)
        summary = generate_summary()
        (TRACK_DIR / "SUMMARY.md").write_text(summary)
        print("Generated SUMMARY.md", file=sys.stderr)

    elif args == ["--summary"]:
        summary = generate_summary()
        (TRACK_DIR / "SUMMARY.md").write_text(summary)
        print("Generated SUMMARY.md", file=sys.stderr)

    else:
        # Generate report for specific app(s)
        for app in args:
            if app.startswith("-"):
                continue
            report = generate_app_report(app)
            if report:
                out = TRACK_DIR / f"{app}.md"
                out.write_text(report)
                print(f"Generated {out.name}", file=sys.stderr)
            else:
                print(f"No data for {app}", file=sys.stderr)
        summary = generate_summary()
        (TRACK_DIR / "SUMMARY.md").write_text(summary)
        print("Generated SUMMARY.md", file=sys.stderr)


if __name__ == "__main__":
    main()