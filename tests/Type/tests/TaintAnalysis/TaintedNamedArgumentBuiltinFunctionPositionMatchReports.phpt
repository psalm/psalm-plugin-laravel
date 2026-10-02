--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentBuiltinFunctionPositionMatchReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * `file_put_contents()` is a PHP-internal (CallMap-only) function: it has no
 * `FunctionStorage`, so `Codebase::getFunctionLikeStorage()` throws for it and its declared
 * param NAMES are only reachable via `InternalCallMapHandler::getCallablesFromCallMap()`
 * (`resolveParamsForCandidate`'s CallMap fallback). Detection of the real vendor `file` sink
 * must survive a named-argument call to a PHP builtin, not just to a userland function.
 *
 * This pins the PRESERVE direction only. Since a resolution failure now preserves, breaking the
 * CallMap fallback would not fail this file: it would only stop the handler from proving a
 * variadic capture on a builtin, whose cost is a retained upstream false positive. No stock
 * builtin pairs a variadic with a sink on a non-variadic parameter, so that half has no fixture.
 */
function builtinNamedArgumentPositionMatchReports(): void
{
    file_put_contents(filename: tainted(), data: 'x');
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling
