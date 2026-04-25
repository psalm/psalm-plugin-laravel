#!/usr/bin/env python3
"""
Summarize a Psalm JSON output file for the psalm-package-inspection skill.

Default mode — full breakdown:
  1. Total count and top issue types.
  2. Top files per issue type (for targeted reading).
  3. Top code snippets per issue type (for spotting cascading root causes).
  4. A heuristic pass that classifies snippets into common Laravel patterns
     so Claude can jump to the right plugin source to verify.

Drill-down modes (combine freely):
  --filter-type TYPE    restrict to one issue type (e.g. MixedAssignment)
  --filter-file GLOB    restrict to files whose name contains GLOB (substring match)
  --trace REGEX         regex matched against snippets; reports direct hits
                        plus a cascade-size estimate (issues in the same file
                        within --cascade-window lines after each hit).

Intentionally dependency-free (stdlib only). Read-only — writes nothing.

Usage:
    python3 analyze.py /path/to/psalm-output.json
    python3 analyze.py out.json --filter-type MixedAssignment
    python3 analyze.py out.json --filter-file Zip.php
    python3 analyze.py out.json --trace 'app\\((\\w+)::class\\)'
    python3 analyze.py out.json --trace 'config\\(' --cascade-window 50
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

# Issue types that are most often plugin-gap candidates or that users ask
# about first. Not exhaustive — `--types all` dumps everything.
DEFAULT_FOCUS_TYPES = [
    "MixedAssignment",
    "MixedArgument",
    "MixedMethodCall",
    "MixedReturnStatement",
    "MixedOperand",
    "MixedArrayAccess",
    "MixedPropertyFetch",
    "MixedArgumentTypeCoercion",
    "DocblockTypeContradiction",
    "UndefinedMagicMethod",
    "UndefinedMethod",
    "UndefinedMagicPropertyFetch",
    "InvalidReturnType",
    "InvalidReturnStatement",
]

# Regex-lite heuristics for classifying snippets into known patterns.
# Used only for hint output; verification is still Claude's job.
PATTERN_HINTS = [
    ("app(static::class)", lambda s: "app(" in s and "static::class" in s),
    ("app(self::class)",   lambda s: "app(" in s and "self::class" in s),
    ("app(Foo::class)",    lambda s: re.search(r"\bapp\(\s*[A-Z]\w*(?:\\\w+)*::class\b", s) is not None),
    ("resolve(static::class)", lambda s: "resolve(" in s and "static::class" in s),
    ("config('...')",      lambda s: "config(" in s),
    ("env('...')",         lambda s: "env(" in s),
    ("filled(...)",        lambda s: "filled(" in s),
    ("blank(...)",         lambda s: "blank(" in s),
    ("$this->instance()",  lambda s: "$this->instance()" in s),
    ("$this->getLivewire()", lambda s: "$this->getLivewire()" in s),
    ("->getRelated()",     lambda s: "->getRelated()" in s),
    ("->toArray()",        lambda s: "->toArray()" in s),
    ("$record->{...}()",   lambda s: "$record->{" in s and "}()" in s),
    ("dynamic ->{$var}",   lambda s: "->{$" in s),
]


def classify(snippet: str) -> str:
    for name, test in PATTERN_HINTS:
        if test(snippet):
            return name
    return "other"


def load(path: Path) -> list[dict]:
    raw = path.read_text()
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        sys.stderr.write(f"error: {path} is not valid JSON: {exc}\n")
        sys.exit(1)
    if not isinstance(data, list):
        sys.stderr.write("error: expected a JSON array at top level\n")
        sys.exit(1)
    return data


def summarize_totals(data: list[dict], top_n: int) -> None:
    print(f"Total issues: {len(data)}")
    print(f"Distinct types: {len({d.get('type', '') for d in data})}")
    print()
    print("Top issue types:")
    counts = Counter(d.get("type", "") for d in data)
    width = max((len(t) for t, _ in counts.most_common(top_n)), default=0)
    for t, n in counts.most_common(top_n):
        print(f"  {n:6d}  {t:<{width}}")


def top_files(data: list[dict], issue_type: str, n: int) -> list[tuple[str, int]]:
    return Counter(
        d.get("file_name", "") for d in data if d.get("type") == issue_type
    ).most_common(n)


def top_snippets(data: list[dict], issue_type: str, n: int) -> list[tuple[str, int]]:
    return Counter(
        (d.get("snippet") or "").strip()[:100]
        for d in data
        if d.get("type") == issue_type
    ).most_common(n)


def top_messages(data: list[dict], issue_type: str, n: int) -> list[tuple[str, int]]:
    return Counter(
        (d.get("message") or "")[:120]
        for d in data
        if d.get("type") == issue_type
    ).most_common(n)


def pattern_breakdown(data: list[dict], issue_type: str) -> list[tuple[str, int]]:
    buckets: Counter[str] = Counter()
    for d in data:
        if d.get("type") != issue_type:
            continue
        buckets[classify((d.get("snippet") or "").strip())] += 1
    return buckets.most_common()


def report_type(data: list[dict], issue_type: str, top_n: int) -> None:
    matching = [d for d in data if d.get("type") == issue_type]
    if not matching:
        return
    print()
    print(f"===== {issue_type} ({len(matching)}) =====")

    print("-- top files --")
    for f, n in top_files(data, issue_type, top_n):
        print(f"  {n:5d}  {f}")

    print("-- top snippets --")
    for s, n in top_snippets(data, issue_type, top_n):
        print(f"  {n:4d}  {s}")

    print("-- pattern hints --")
    for name, n in pattern_breakdown(data, issue_type):
        if n == 0:
            continue
        print(f"  {n:5d}  {name}")

    # Show unique message templates once — these often reveal suspicious cases
    # like "Operand of type false is always falsy" (the filled() stub bug).
    msgs = top_messages(data, issue_type, top_n)
    if msgs and msgs[0][1] > 1:
        print("-- top message templates --")
        for m, n in msgs:
            if n < 2:
                break
            print(f"  {n:4d}  {m}")


def _apply_filters(data: list[dict], filter_type: str | None, filter_file: str | None) -> list[dict]:
    out = data
    if filter_type:
        out = [d for d in out if d.get("type") == filter_type]
    if filter_file:
        out = [d for d in out if filter_file in (d.get("file_name") or "")]
    return out


def _print_entry(d: dict, limit: int = 120) -> None:
    """One line per finding: type | file:line | snippet | message."""
    snippet = (d.get("snippet") or "").strip()[:limit]
    msg = (d.get("message") or "")[:limit]
    print(f"  {d.get('type','?'):<28}  {d.get('file_name','?')}:{d.get('line_from','?')}")
    print(f"      snippet: {snippet}")
    print(f"      message: {msg}")


def report_list(data: list[dict], filter_type: str | None, filter_file: str | None) -> None:
    """Print every matching entry — the --filter-type / --filter-file output."""
    subset = _apply_filters(data, filter_type, filter_file)
    print(f"Matches: {len(subset)}")
    if filter_type:
        print(f"  filter-type: {filter_type}")
    if filter_file:
        print(f"  filter-file: {filter_file}")
    print()

    by_file: dict[str, list[dict]] = defaultdict(list)
    for d in subset:
        by_file[d.get("file_name", "?")].append(d)

    for fname in sorted(by_file, key=lambda f: -len(by_file[f])):
        entries = sorted(by_file[fname], key=lambda d: (d.get("line_from", 0), d.get("type", "")))
        print(f"=== {fname} ({len(entries)}) ===")
        for d in entries:
            _print_entry(d)
        print()

    # Summary by type across the subset.
    type_counts = Counter(d.get("type", "?") for d in subset)
    if len(type_counts) > 1:
        print("--- type breakdown ---")
        for t, n in type_counts.most_common():
            print(f"  {n:5d}  {t}")


def report_trace(
    data: list[dict],
    pattern: str,
    cascade_window: int,
    filter_type: str | None,
    filter_file: str | None,
) -> None:
    """Regex over snippets; show direct hits plus a cascade-size estimate.

    Cascade heuristic: for each direct hit at (file, line), count distinct
    issues in the same file with line_from >= hit.line_from AND
    line_from <= hit.line_from + cascade_window (excluding the hit itself).
    Rough, but enough to estimate blast radius when one upstream `mixed`
    propagates into downstream fetches/calls within the same function.
    """
    try:
        rx = re.compile(pattern)
    except re.error as exc:
        sys.stderr.write(f"error: invalid regex {pattern!r}: {exc}\n")
        sys.exit(2)

    subset = _apply_filters(data, filter_type, filter_file)
    hits = [d for d in subset if rx.search(d.get("snippet") or "")]

    print(f"Trace pattern: {pattern}")
    if filter_type:
        print(f"  filter-type: {filter_type}")
    if filter_file:
        print(f"  filter-file: {filter_file}")
    print(f"  cascade-window: {cascade_window} lines")
    print(f"  direct hits: {len(hits)}")
    print()

    if not hits:
        return

    # Index all issues by file for cascade lookups.
    by_file: dict[str, list[dict]] = defaultdict(list)
    for d in data:
        by_file[d.get("file_name", "?")].append(d)

    # Direct hits — summarized by type.
    print("--- direct hits by type ---")
    hit_types = Counter(d.get("type", "?") for d in hits)
    for t, n in hit_types.most_common():
        print(f"  {n:5d}  {t}")
    print()

    # Per-hit cascade detail.
    hit_keys = {
        (d.get("file_name", "?"), d.get("line_from"), d.get("type"), (d.get("snippet") or "").strip())
        for d in hits
    }
    total_cascade = 0
    print("--- cascade detail ---")
    for h in sorted(hits, key=lambda d: (d.get("file_name", ""), d.get("line_from", 0))):
        fname = h.get("file_name", "?")
        line = h.get("line_from")
        if not isinstance(line, int):
            continue
        neighbours = [
            d for d in by_file[fname]
            if isinstance(d.get("line_from"), int)
            and line <= d["line_from"] <= line + cascade_window
            and (fname, d.get("line_from"), d.get("type"), (d.get("snippet") or "").strip()) not in hit_keys
        ]
        total_cascade += len(neighbours)
        print(f"  {fname}:{line}  {h.get('type','?')}  +{len(neighbours)} downstream within {cascade_window} lines")
        print(f"      snippet: {((h.get('snippet') or '').strip())[:120]}")
        if neighbours:
            ncounts = Counter(d.get("type", "?") for d in neighbours)
            parts = ", ".join(f"{t}:{n}" for t, n in ncounts.most_common(6))
            print(f"      downstream types: {parts}")

    print()
    print("--- totals ---")
    print(f"  direct:   {len(hits)}")
    print(f"  cascaded: {total_cascade}  (upper bound — neighbours may have other causes)")
    print(f"  combined: {len(hits) + total_cascade}")


def main() -> None:
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("json_file", type=Path)
    parser.add_argument(
        "--types",
        default=",".join(DEFAULT_FOCUS_TYPES),
        help="Comma-separated list of issue types for the default breakdown. Use 'all' for every type present.",
    )
    parser.add_argument("--top-n", type=int, default=10)

    # Drill-down flags. When any of these is set, the default breakdown is replaced.
    parser.add_argument("--filter-type", help="Restrict output to a single issue type.")
    parser.add_argument("--filter-file", help="Restrict output to files whose name contains this substring.")
    parser.add_argument("--trace", help="Regex matched against snippets; shows direct hits + cascade estimate.")
    parser.add_argument("--cascade-window", type=int, default=30,
                        help="Lines to look downstream of each --trace hit for cascade estimate (default: 30).")

    args = parser.parse_args()
    data = load(args.json_file)

    drill_down = bool(args.filter_type or args.filter_file or args.trace)

    if not drill_down:
        summarize_totals(data, args.top_n)
        present_types = {d.get("type", "") for d in data}
        if args.types == "all":
            focus = sorted(present_types, key=lambda t: -sum(1 for d in data if d.get("type") == t))
        else:
            focus = [t.strip() for t in args.types.split(",") if t.strip()]
        for t in focus:
            if t in present_types:
                report_type(data, t, args.top_n)
        return

    # Drill-down mode: --trace takes precedence; otherwise filter-list.
    if args.trace:
        report_trace(data, args.trace, args.cascade_window, args.filter_type, args.filter_file)
    else:
        report_list(data, args.filter_type, args.filter_file)


if __name__ == "__main__":
    main()