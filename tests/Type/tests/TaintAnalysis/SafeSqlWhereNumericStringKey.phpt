--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * A numeric-string key ('1.5', which PHP keeps as a string) still routes through addArrayOfWheres'
 * `else` branch when its value is not an array: `is_numeric($key)` is true but `is_array($value)` is
 * false, so it dispatches `where('1.5', '=', $value)` — the key is a fixed literal from the source
 * (never attacker-controlled) and the value is PDO-bound. A numeric key only reaches the nested-column
 * branch (a raw column identifier) when its value is itself an array. #1300
 *
 * @psalm-suppress TooFewArguments
 */
function safeNumericStringKeyMap(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();

    $builder->whereNot(['1.5' => (string) $request->input('c')]);
}
?>
--EXPECTF--
