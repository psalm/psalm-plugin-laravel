--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentSelfDispatchedEvalIncludeSurvives;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

function outer(mixed ...$rest): int { return \count($rest); }

/**
 * `eval(tainted())` is the VALUE of `label:`, a named argument that `outer()`'s variadic
 * captures, so NamedArgumentTaintHandler would otherwise record and strip it. `EvalAnalyzer`
 * independently dispatches `AddRemoveTaintsEvent` on that SAME `Eval_` node to check its own
 * `eval` sink; recording it would erase `TaintedEval` along with the variadic strip.
 * `isSelfDispatchedSinkSubject()` excludes `Eval_` values, so the value is never
 * recorded and the real `TaintedEval` finding survives.
 */
function evalValueSurvives(): void
{
    $_ = outer(label: eval(tainted()));
}

/**
 * Same collision, `IncludeAnalyzer` in place of `EvalAnalyzer`.
 */
function includeValueSurvives(): void
{
    $path = tainted();
    $_ = outer(label: include $path);
}
?>
--EXPECTF--
TaintedEval on line %d: Detected tainted code passed to eval or similar
UnresolvableInclude on line %d: Cannot resolve the given expression to a file path
TaintedInclude on line %d: Detected tainted code passed to include or similar
