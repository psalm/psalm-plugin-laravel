--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentSelfDispatchedDynamicCalleeSurvives;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

function outer(string $safe = '', mixed $label = null): void { echo (string) $label; }

/**
 * `$fn()` — a `FuncCall` with a DYNAMIC (tainted) callee — is written as `label:`'s
 * mismatched value. `FunctionCallAnalyzer` independently dispatches
 * `AddRemoveTaintsEvent` on that SAME `FuncCall` node to check its own `INPUT_CALLABLE`
 * ("variable-call") sink; recording it for the mismatch strip would erase
 * `TaintedCallable` too (MUST-FIX A, #1395 round 3).
 */
function dynamicFuncCallCalleeSurvives(): void
{
    $fn = tainted();
    outer(label: $fn());
}

/**
 * Same collision for `New_` with a dynamic class-name expression (`NewAnalyzer`'s own
 * `INPUT_CALLABLE` sink on `new $class()`). Psalm 7 additionally reports
 * `InvalidStringClass` for the `new $class()` itself; Psalm 6 does not, which is a core
 * difference and leaves the two `TaintedCallable` findings this test is about intact.
 */
function dynamicNewCalleeSurvives(): void
{
    $class = tainted();
    outer(label: new $class());
}
?>
--EXPECTF--
TaintedCallable on line %d: Detected tainted text
TaintedCallable on line %d: Detected tainted text
