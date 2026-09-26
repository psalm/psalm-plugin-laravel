--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentSelfDispatchedDynamicCalleeSurvives;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/** A variadic callee, so `label:` matches no declared parameter and is capture-stripped. */
function outer(string $safe = '', mixed ...$extra): void { if ($extra === []) { echo $safe; } }

/**
 * `$fn()` — a `FuncCall` with a DYNAMIC (tainted) callee — is written as the value of `label:`,
 * which names no parameter of `outer()` and is captured by its variadic.
 * `FunctionCallAnalyzer` independently dispatches `AddRemoveTaintsEvent` on that SAME `FuncCall`
 * node to check its own `INPUT_CALLABLE` ("variable-call") sink; recording it for the capture
 * strip would erase `TaintedCallable` too (MUST-FIX A, #1395 round 3).
 */
function dynamicFuncCallCalleeSurvives(): void
{
    $fn = tainted();
    outer(label: $fn());
}

/**
 * Same collision for `New_` with a dynamic class-name expression (`NewAnalyzer`'s own
 * `INPUT_CALLABLE` sink on `new $class()`).
 */
function dynamicNewCalleeSurvives(): void
{
    $class = tainted();
    outer(label: new $class());
}
?>
--EXPECTF--
TaintedCallable on line %d: Detected tainted text
InvalidStringClass on line %d: String cannot be used as a class
TaintedCallable on line %d: Detected tainted text
