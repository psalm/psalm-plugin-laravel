---
title: Adopting on an Existing Codebase
nav_order: 7
---

# Adopting on an Existing Codebase

The first run on a project that has never been analyzed will report a lot. That is expected, and it does not mean the tool is misconfigured. This page is the order of operations that keeps the first week useful instead of overwhelming.

## 1. Fix the security findings first

These are the ones you cannot defer, and there are usually few of them. They are easy to pick out of the output: every security finding is named `Tainted...` (`TaintedSql`, `TaintedHtml`, `TaintedShell`, `TaintedFile`, `TaintedHeader`, `TaintedSSRF`, `TaintedUserSecret`, and so on).

Security findings are reported at every `errorLevel`, including the most lenient (`8`), so raising or lowering strictness never hides them. Only a baseline entry or an explicit `<issueHandlers>` suppression can.

## 2. Baseline the type issues

Once the security findings are handled, park the remaining type issues in a [baseline](https://psalm.dev/docs/running_psalm/dealing_with_code_issues/#using-a-baseline-file) so that only new code is checked:

```bash
./vendor/bin/psalm --set-baseline=psalm-baseline.xml
```

> [!IMPORTANT]
> `--set-baseline` records **every** issue it sees, security findings included, and a baselined `TaintedSql` stops being reported.
> After generating the baseline, delete the `<Tainted...>` blocks from `psalm-baseline.xml`, otherwise the vulnerabilities you just found go quiet.

On the 3.x line (Psalm 6) there is a second trap here. Taint analysis is an exclusive mode there, so if `runTaintAnalysis="true"` is in your `psalm.xml`, the `--set-baseline` run above sees only security findings and baselines those instead of your type issues. Keep the option out of the config on 3.x and pass `--taint-analysis` only for the security pass.

## 3. Raise strictness gradually

Start at `errorLevel="4"`, work toward `1`, and shrink the baseline as you go. Each step down surfaces a new class of issue, so moving one level at a time keeps each batch reviewable.

Psalm's levels run from `1` (strictest) to `8` (most lenient). `psalm-laravel init` writes `4` by default.

## Turning down the noise

* **The noisier checks are opt-in.** `findMissingViews`, `findMissingTranslations`, and `reportImplicitQueryBuilderCalls` are all off by default. Enable them when the rest of the analysis is quiet, not on day one. See [Configuration](config.md).
* **Any issue type can be downgraded or suppressed project-wide** in `<issueHandlers>`, for example `<MissingReturnType errorLevel="info"/>`.
* **Per line, use `@psalm-suppress`.** This works for type issues only. Taint findings ignore the inline form, so silencing one takes an `<issueHandlers>` entry or a baseline line. Prefer fixing the flow, or annotating the sanitizer with `@psalm-taint-escape`, over suppressing a security finding.

## Related

* [Configuration](config.md) for every plugin option.
* [GitHub Actions](github-actions.md) for gating pull requests once the baseline is in place.
* [Security (Taint) Checks](security.md) for what the security analysis detects.
