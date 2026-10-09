--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentSelfDispatchedValueSurvives;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

function outer(mixed ...$rest): int { return \count($rest); }

final class Runner
{
    public function run(string ...$rest): int { return \count($rest); }
}

/**
 * The value of a variadic-bound named argument is itself the subject of a sink Psalm dispatches
 * `AddRemoveTaintsEvent` against (`eval`, `include`, a dynamic callee, `new $class`). Recording
 * it would erase that genuine `TaintedEval`/`TaintedInclude`/`TaintedCallable`.
 */
function selfDispatchedValues(): void
{
    $fn = tainted();
    $class = tainted();
    $path = tainted();

    $_ = outer(label: eval(tainted()));
    $_ = outer(label: include $path);
    $_ = outer(label: $fn());
    $_ = outer(label: new $class());
}

/** `$runner->run(...)` is a first-class callable: `getArgs()` throws, so the handler must decline. */
function firstClassCallableDoesNotCrash(Runner $runner): void
{
    $run = $runner->run(...);
    $run(zzz: 'x');
}
?>
--EXPECTF--
TaintedEval on line %d: Detected tainted code passed to eval or similar
UnresolvableInclude on line %d: Cannot resolve the given expression to a file path
TaintedInclude on line %d: Detected tainted code passed to include or similar
TaintedCallable on line %d: Detected tainted text
InvalidStringClass on line %d: String cannot be used as a class
TaintedCallable on line %d: Detected tainted text
