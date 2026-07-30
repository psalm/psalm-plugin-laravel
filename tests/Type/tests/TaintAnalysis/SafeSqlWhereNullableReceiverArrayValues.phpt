--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * A `Builder|null` receiver kept the `sql` sink on the map-form argument even though a null
 * receiver never reaches `addArrayOfWheres()`: a plain `->` fatals before any SQL exists, so
 * `isLaravelBuilder` tolerating the `TNull` atomic in the receiver's union is safe. #1336
 *
 * @psalm-suppress PossiblyNullReference
 */
function safeNullableReceiverArrayWhere(\Illuminate\Http\Request $request, ?\Illuminate\Database\Query\Builder $query): void {
    $query->where(['status_id' => $request->input('status')]);
}
?>
--EXPECTF--
