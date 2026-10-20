--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedShellDispatchableParentStatic;

use Illuminate\Foundation\Bus\Dispatchable as BusDispatchable;
use Illuminate\Http\Request;

class BaseParentJob
{
    use BusDispatchable;

    /** @psalm-taint-sink shell $command */
    public function __construct(mixed $command) {}
}

/**
 * KNOWN LIMITATION: `parent::dispatch($tainted)` does not report TaintedShell although the constructor
 * inherited from BaseParentJob declares a shell sink (the same call through `self::` or `static::`
 * does report, see TaintedShellDispatchableSelfStatic.phpt).
 *
 * Psalm 7.0.0-rc6 marks the argument nodes of a `self::` / `parent::` call made from inside a class with the
 * class whose body it analyzes (node attribute `inheritedMethodBodyClass`). DispatchableHandler re-checks
 * those same argument expressions against `__construct()` with the mark still set, so their taint flows to
 * the constructor body analyzed for the calling class instead of the constructor's own parameter, and the
 * sink the constructor inherits from BaseParentJob is dropped. Flipped by the DispatchableHandler change
 * that clears the mark for that re-check.
 */
final class ChildParentJob extends BaseParentJob
{
    public static function requeue(Request $request): void
    {
        parent::dispatch($request->input('parent-command'));
    }
}
?>
--EXPECTF--
