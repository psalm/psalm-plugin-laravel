--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Ancestry alone is not enough: a Model subclass with its OWN CONCRETE static `where()`
 * declaration never reaches `__callStatic`/`addArrayOfWheres` — PHP dispatches to the declared
 * method directly. So its own sink must survive, unlike the plain-subclass case in
 * SafeSqlStaticWhereNestedCondition.phpt, which has no such declaration and is correctly stripped.
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\WhereColumnTaintHandler::isStaticReceiverLaravelBound}
 * gates on `getDeclaringMethodId` (with_pseudo: false, so the plugin's own `@mixin`-injected
 * forwarding is invisible to it) finding a REAL declaration outside `Illuminate\`. #1300
 */
class ConcreteOverrideModel extends \Illuminate\Database\Eloquent\Model {
    /**
     * @psalm-taint-sink sql $column
     */
    public static function where(mixed $column): void {}
}

function unsafeStaticConcreteModelOverride(\Illuminate\Http\Request $request): void {
    $term = (string) $request->input('term');

    ConcreteOverrideModel::where([['name', '=', $term]]);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
