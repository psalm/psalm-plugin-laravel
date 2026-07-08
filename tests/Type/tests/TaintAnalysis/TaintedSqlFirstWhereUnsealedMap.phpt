--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

class UnsealedMapModel extends \Illuminate\Database\Eloquent\Model {}

/**
 * A GENUINE unsealed keyed array: the declared shape keeps a `...<string, mixed>` fallback whose extra
 * keys are unknown, possibly user-controlled column names. `firstWhere()` receives this shape, so
 * isBoundValueMap must reject it on `fallback_params !== null` and the sink must stand. This is the
 * only coverage of that branch (TaintedSqlWhereWholeArrayInput uses a plain `array<string, mixed>` /
 * TArray, which is not a TKeyedArray at all).
 *
 * The `+ $request->all()` union is a plain array at runtime, so the declared shape is more specific
 * than Psalm infers for the body (MixedReturnTypeCoercion). That is intentional. It is what carries
 * the unsealed TKeyedArray to the call site as the argument's type.
 *
 * @return array{status: int, ...<string, mixed>}
 *
 * @psalm-suppress MixedReturnTypeCoercion
 */
function unsealedFilters(\Illuminate\Http\Request $request): array {
    return ['status' => 1] + $request->all();
}

function unsafeUnsealedMapFirstWhere(\Illuminate\Http\Request $request): void {
    UnsealedMapModel::firstWhere(unsealedFilters($request));
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
