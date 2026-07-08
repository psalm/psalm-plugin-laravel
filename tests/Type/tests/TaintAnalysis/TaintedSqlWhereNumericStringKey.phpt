--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * A numeric-string key ('1.5', which PHP keeps as a string) must NOT be treated as a bound-value map:
 * Laravel's addArrayOfWheres dispatches on `is_numeric($key)`, routing a numeric key to the
 * nested-column branch where the value would be a raw column identifier. isBoundValueMap therefore
 * rejects any numeric key (int or numeric-string) like an int key, so the sink stands and this flags.
 * Uses whereNot per the batch-artifact guidance (see TaintedSqlWhereWholeArrayInput.phpt).
 *
 * @psalm-suppress TooFewArguments
 */
function unsafeNumericStringKeyMap(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();

    $builder->whereNot(['1.5' => (string) $request->input('c')]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
