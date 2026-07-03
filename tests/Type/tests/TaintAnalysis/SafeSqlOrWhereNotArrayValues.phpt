--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Representative or-variant. The keyed-map strip is method-agnostic (one shared path), so this
 * covers orWhere/whereNot/orWhereNot too — the value-binding map form is safe on all of them. #734
 *
 * @psalm-suppress TooFewArguments, MixedAssignment
 */
function safeOrWhereNotArray(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $status = $request->input('status');

    $builder->orWhereNot(['status_id' => $status]);
}
?>
--EXPECTF--
