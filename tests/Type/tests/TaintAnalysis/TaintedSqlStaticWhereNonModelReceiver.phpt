--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Counterexample for #1300: a project's own class can name a static `where()` method with its own
 * `@psalm-taint-sink sql` — the method NAME alone does not mean `addArrayOfWheres()`. The static
 * gate ({@see \Psalm\LaravelPlugin\Handlers\Eloquent\WhereColumnTaintHandler::isStaticReceiverLaravelBound})
 * must reject this receiver so both the tainted value in the nested-condition ordinal that a
 * Laravel builder would PDO-bind, and the raw column, keep flagging here. Mirrors the
 * MethodCall-receiver counterpart TaintedSqlWhereNonBuilderReceiver.phpt.
 */
final class NonModelStaticQuery
{
    /**
     * @psalm-taint-sink sql $parts
     */
    public static function where(array $parts): void {}
}

function unsafeStaticNonModelReceiverValue(\Illuminate\Http\Request $request): void {
    $term = (string) $request->input('term');

    NonModelStaticQuery::where([['name', '=', $term]]);
}

/**
 * Regression pin for the WHOLE-ARGUMENT path specifically: a SEALED all-string-key map (not a
 * nested-condition list) is what `isBoundValueMap` accepts, so it is the shape the #1306 whole-arg
 * gate — tightened here to require `isStaticReceiverLaravelBound` rather than trusting every
 * `StaticCall` — actually exercises. The nested-condition case above is rejected by
 * `isBoundValueMap`'s numeric-key check regardless of the receiver gate, so it alone would pass
 * even with the pre-#1300 unconditional-trust whole-arg design; this call is the one that pins it.
 */
function unsafeStaticNonModelReceiverWholeArgMap(\Illuminate\Http\Request $request): void {
    $term = (string) $request->input('term');

    NonModelStaticQuery::where(['name' => $term]);
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
