--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentSelfDispatchedDynamicCalleeSurvives;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

function outer(mixed ...$rest): int { return \count($rest); }

/**
 * `$fn()` — a `FuncCall` with a DYNAMIC (tainted) callee — is the value of `label:`, which
 * `outer()`'s variadic captures, so the handler would record it for the variadic strip.
 * `FunctionCallAnalyzer` independently dispatches `AddRemoveTaintsEvent` on that SAME `FuncCall`
 * node to check its own `INPUT_CALLABLE` ("variable-call") sink; recording it would erase
 * `TaintedCallable` too.
 */
function dynamicFuncCallCalleeSurvives(): void
{
    $fn = tainted();
    $_ = outer(label: $fn());
}

/**
 * Same collision for `New_` with a dynamic class-name expression (`NewAnalyzer`'s own
 * `INPUT_CALLABLE` sink on `new $class()`).
 */
function dynamicNewCalleeSurvives(): void
{
    $class = tainted();
    $_ = outer(label: new $class());
}
?>
--EXPECTF--
TaintedCallable on line %d: Detected tainted text
InvalidStringClass on line %d: String cannot be used as a class
TaintedCallable on line %d: Detected tainted text
