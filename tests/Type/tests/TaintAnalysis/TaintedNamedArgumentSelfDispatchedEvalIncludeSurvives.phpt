--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentSelfDispatchedEvalIncludeSurvives;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

function outer(string $safe = '', mixed $label = null): void { echo (string) $label; }

/**
 * `eval(tainted())` is written as the VALUE of `label:` — a mismatched named argument
 * (offset 0, but `outer`'s param 0 is `$safe`) that NamedArgumentTaintHandler would
 * otherwise record and strip (MUST-FIX A, #1395 round 3). `EvalAnalyzer` independently
 * dispatches `AddRemoveTaintsEvent` on that SAME `Eval_` node to check its own `eval`
 * sink; recording it would erase `TaintedEval` along with the mismatch strip.
 * `isSelfDispatchedSinkSubject()` excludes `Eval_` values, so the mismatch is never
 * recorded and the real `TaintedEval` finding survives.
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
