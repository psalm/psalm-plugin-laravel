---
description: "Comprehensive PR review using specialized agents crafted for psalm-plugin-laravel development. Launches domain-specific experts (Laravel, Psalm, architecture, security/taint, stub correctness, performance, tests, docs, error handling, type design, code quality, simplification) to review code changes with confidence-based filtering. Use this skill when reviewing code, preparing a PR, or wanting a thorough quality check on recent changes to the psalm-plugin-laravel project."
argument-hint: "[review-aspects] [sequential]"
allowed-tools: ["Bash", "Glob", "Grep", "Read", "Agent"]
effort: max
---

# Psalm Plugin Laravel — Comprehensive Code Review

Run a comprehensive code review using specialized agents, each focusing on a different aspect relevant to psalm-plugin-laravel development.

**Review Aspects (optional):** "$ARGUMENTS"

## Review Workflow

### 1. Determine Review Scope

- Run `git diff --name-only` to identify changed files (staged + unstaged)
- If a PR exists (`gh pr view`), use the PR diff
- Parse arguments: user may request specific aspects or `all`
- Read `composer.json` to determine Psalm version (currently Psalm 7) — pass this to psalm-expert

### 2. Available Review Aspects

| Aspect | Agent | Color | What It Checks |
|--------|-------|-------|----------------|
| **laravel** | laravel-expert | blue | Laravel API accuracy, version coverage (12-13), feature completeness |
| **psalm** | psalm-expert | purple | Psalm API usage, hook selection, type construction, worker safety |
| **arch** | architecture | orange | Structure, separation of concerns, dependency direction |
| **perf** | performance | red | Hot-path efficiency, memory, algorithmic complexity |
| **simplify** | simplify | olive | Code clarity, DX, naming consistency, reusability |
| **tests** | tests | cyan | Test coverage, type test quality, missing edge cases |
| **docs** | docs | teal | Documentation accuracy, code comment quality |
| **security** | security-taint | magenta | Taint annotations, security modeling |
| **stubs** | stub-correctness | brown | Stub signatures match Laravel source |
| **errors** | errors | yellow | Silent failures, error handling quality |
| **types** | types | pink | Type design, invariants (only if new types added) |
| **code** | code | green | AGENTS.md compliance, bugs, style |
| **all** | — | — | Run all applicable reviews (default) |

### 3. Determine Applicable Reviews

Based on changed files, selectively launch agents:

- **Always run**: `code` (general quality)
- **If `src/` changed**: `psalm`, `architecture`, `performance`, `simplify`
- **If `stubs/` changed**: `stub-correctness`, `security`, `laravel`, `tests` (new stubs or changed signatures need test coverage)
- **If `tests/` changed**: `tests`
- **If docs, README, or AGENTS.md changed**: `docs`
- **If new classes/interfaces/enums added**: `types`
- **If error handling code changed** (try/catch blocks): `errors`
- **If any PHP changed**: `laravel` (the plugin models Laravel behavior)
- **User requests specific aspects**: run only those

### 4. Provide Context to Each Agent

Each agent receives:
- The git diff of changed files (or specific files if scoped)
- Instruction to review only the changed code
- The Psalm version from `composer.json` (for psalm-expert)
- Access to web search and web fetch for docs verification

**For psalm-expert**: Analyze the diff content and pass a reference hint so it loads only relevant docs:
- Handler/hook changes → `references/05-plugin-system.md` + `references/07-laravel-plugin-guide.md`
- Type construction (`Union`, `Atomic`, `TNamedObject`, etc.) → `references/04-type-system.md`
- Storage modifications (`ClassLikeStorage`, `PropertyStorage`, `MethodStorage`) → `references/02-scanning-phase.md`
- Analysis hooks (`Context`, `StatementsAnalyzer`, expression analysis) → `references/03-analysis-phase.md`
- Caching, parallelism, or worker concerns → `references/06-caching-parallelism.md`
- General architecture questions → `references/01-architecture-overview.md`

Include the hint in the agent prompt, e.g.: "Relevant reference docs for this diff: 04-type-system.md, 05-plugin-system.md"

### 5. Launch Review Agents

**Default: Parallel** — launch all applicable agents simultaneously for speed. Since agents are independent (no agent depends on another's output), there's no reason to wait.

**Two-tier parallel** when many agents apply:
- **Tier 1** (launch together): All domain and analysis agents — `laravel-expert`, `psalm-expert`, `stub-correctness`, `security-taint`, `architecture`, `performance`, `errors`, `types`, `code`, `tests`, `docs`
- **Tier 2** (after Tier 1 completes): `simplify` — benefits from seeing the aggregated findings to avoid suggesting changes that conflict with other agents' recommendations

**If user requests `sequential`**:
- Launch agents one at a time
- Useful for interactive review where you want to discuss each report before continuing

### 6. Aggregate Results

After all agents complete, synthesize a unified report. **Always end the report with the review round footer** — this is mandatory and must not be omitted:

```markdown
# PR Review Summary — psalm-plugin-laravel

## Critical Issues (X found)
- [agent-name]: Issue description [file:line] (confidence: XX)

## Important Issues (X found)
- [agent-name]: Issue description [file:line] (confidence: XX)

## Suggestions (X found)
- [agent-name]: Suggestion [file:line]

## Strengths
- What's well-done in these changes

## Recommended Action
1. Fix critical issues first
2. Address important issues
3. Consider suggestions
4. Re-run review after fixes

---
**Review round complete.** If this review is part of a review loop (e.g., /psalm-task), at least 2 clean rounds are required before committing. Do not skip remaining rounds.
```

## Usage Examples

**Full review (default):**
```
/psalm-review
```

**Specific aspects:**
```
/psalm-review stubs laravel
# Reviews only stub correctness and Laravel API accuracy

/psalm-review psalm perf
# Reviews only Psalm API usage and performance

/psalm-review security
# Reviews only taint analysis stubs

/psalm-review tests
# Reviews only test coverage
```

**Sequential review (one at a time):**
```
/psalm-review all sequential
# Launches agents one at a time for interactive discussion
```

## Agent Reference

Each agent uses confidence-based filtering (0-100 scale, only reporting issues >= 80) to minimize noise. Agents have access to:

- **Web search/fetch**: For verifying against Laravel docs and Psalm docs
- **Codebase access**: Full read access to the repository
- **References**: Psalm internals docs in `references/` directory (7 documents covering architecture, scanning, analysis, type system, plugin system, caching, and Laravel plugin patterns)

### Domain Experts
- **laravel-expert**: Verifies the plugin correctly models Laravel's behavior. Sources: Laravel docs (master branch), `vendor/laravel/framework/` source
- **psalm-expert**: Verifies correct Psalm API usage. Sources: `references/` docs, `vendor/vimeo/psalm/` source, Psalm GitHub docs
- **stub-correctness**: Audits stub signatures against Laravel's actual source. Checks method existence, parameter counts, default values, and signature accuracy

### Security
- **security-taint**: Reviews taint annotations for accuracy. Prevents false positives and missed vulnerabilities

### Quality
- **architecture**: Reviews structural design and consistency with project patterns
- **performance**: Reviews hot-path efficiency (critical for plugin hooks that fire per-expression)
- **simplify**: Simplifies code and improves DX (naming, consistency, reusability)
- **code**: General AGENTS.md compliance, bug detection, style

### Verification
- **tests**: Reviews test coverage across all three test layers (Type/Unit/Application)
- **docs**: Reviews documentation and code comment accuracy
- **errors**: Hunts for silent failures in error handling
- **types**: Analyzes type design quality for new types (only when applicable)

## Tips

- **Run before committing**: Catch issues early
- **Focus on changes**: Agents analyze git diff by default
- **Critical first**: Fix high-confidence issues before lower priority
- **Re-run after fixes**: Verify issues are resolved
- **Use specific aspects**: Target specific concerns when you know what to check
- **Sequential for discussion**: Use `sequential` when you want to discuss each report before continuing
