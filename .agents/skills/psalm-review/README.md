# Psalm Plugin Laravel — Code Review Skill

Comprehensive code review using 12 specialized agents crafted for psalm-plugin-laravel development.

## Agents

### Domain Experts
1. **laravel-expert** (blue) — Laravel API accuracy, version coverage (12-13), feature completeness
2. **psalm-expert** (purple) — Psalm internals, API usage, hook selection, type construction
3. **stub-correctness** (brown) — Stub signatures match Laravel's actual source

### Security
4. **security-taint** (magenta) — Taint annotation accuracy, false positive/negative prevention

### Quality
5. **architecture** (orange) — Structure, separation of concerns, project patterns
6. **performance** (red) — Hot-path efficiency, memory, PHP runtime behavior
7. **simplify** (olive) — Code clarity, DX, naming consistency, reusability
8. **code** (green) — CLAUDE.md compliance, bugs, style

### Verification
9. **tests** (cyan) — Test coverage across Type/Unit/Application layers
10. **docs** (teal) — Documentation and code comment accuracy
11. **errors** (yellow) — Silent failures, error handling quality
12. **types** (pink) — Type design and invariants (new types only)

## Usage

```bash
# Full review (runs applicable agents based on changed files)
/psalm-review

# Specific aspects
/psalm-review stubs laravel
/psalm-review psalm perf
/psalm-review security
/psalm-review tests

# All agents in parallel
/psalm-review all parallel
```

## How It Works

1. Identifies changed files via `git diff`
2. Selectively launches agents based on what changed
3. Each agent uses confidence-based filtering (only reports issues >= 80/100)
4. Aggregates results into Critical / Important / Suggestions / Strengths

## References

The `references/` directory contains 7 Psalm internals documents that the psalm-expert agent consults:

1. Architecture Overview
2. Scanning Phase
3. Analysis Phase
4. Type System
5. Plugin System
6. Caching & Parallelism
7. Laravel Plugin Guide
