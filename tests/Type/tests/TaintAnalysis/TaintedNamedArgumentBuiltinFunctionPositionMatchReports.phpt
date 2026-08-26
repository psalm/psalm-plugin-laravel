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
 * (`resolveParamsForCandidate`'s CallMap fallback). `filename:` is written at offset 0, the
 * same offset `$filename` is declared at, so this must NOT be stripped — detection of the
 * real vendor `file` sink must survive a named-argument call to a PHP builtin, not just to
 * a userland function.
 *
 * This only covers the OFFSET-MATCH shape. The mismatched-offset sibling
 * (`file_put_contents(data: tainted(), filename: 'safe.txt')`, offsets swapped) is not
 * pinned separately: vanilla Psalm already reports nothing for it (`$data` carries no
 * sink), so an empty `--EXPECTF--` there would not discriminate the handler's strip from
 * upstream's own silence — it is covered only by the class docblock's accepted
 * false-negative surface, not by a phpt.
 */
function builtinNamedArgumentPositionMatchReports(): void
{
    file_put_contents(filename: tainted(), data: 'x');
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling
