--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentSelfDispatchedEvalIncludeSurvives;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/** A variadic callee, so `label:` matches no declared parameter and is capture-stripped. */
function outer(string $safe = '', mixed ...$extra): void { if ($extra === []) { echo $safe; } }

/**
 * `eval(tainted())` is written as the VALUE of `label:`, which names no parameter of `outer()`
 * and is therefore captured by its variadic — the one shape NamedArgumentTaintHandler strips.
 * `EvalAnalyzer` independently dispatches `AddRemoveTaintsEvent` on that SAME `Eval_` node to
 * check its own `eval` sink, and the strip matches by node IDENTITY, so recording the node would
 * erase `TaintedEval` along with the capture strip (MUST-FIX A, #1395 round 3).
 * `isSelfDispatchedSinkSubject()` excludes `Eval_` values, so the real finding survives.
 */
function evalValueSurvives(): void
{
    outer(label: eval(tainted()));
}

/**
 * Same collision, `IncludeAnalyzer` in place of `EvalAnalyzer`.
 */
function includeValueSurvives(): void
{
    $path = tainted();
    outer(label: include $path);
}
?>
--EXPECTF--
TaintedEval on line %d: Detected tainted code passed to eval or similar
UnresolvableInclude on line %d: Cannot resolve the given expression to a file path
TaintedInclude on line %d: Detected tainted code passed to include or similar
