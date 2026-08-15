--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1 --no-cache
--FILE--
<?php declare(strict_types=1);

/**
 * A concrete userland `where()` override on a Query Builder subclass does not dispatch to
 * `addArrayOfWheres()`. Its array-shape value reaches this class's raw SQL sink, so the Laravel
 * value strip must not run merely because the receiver inherits from Query Builder. #1337
 *
 * Uses a dedicated no-cache batch because `DB::unprepared()` is otherwise saturated by the
 * non-builder receiver pin.
 */
final class ConcreteQueryBuilderOverride extends \Illuminate\Database\Query\Builder {
    /**
     * @param array{array{string, string, string}} $column
     * @param mixed $operator
     * @param mixed $value
     * @param mixed $boolean
     */
    #[\Override]
    public function where($column, $operator = null, $value = null, $boolean = 'and'): static {
        \Illuminate\Support\Facades\DB::unprepared('select * from reports where name = ' . $column[0][2]);

        return $this;
    }
}

/**
 * @psalm-suppress TooFewArguments
 */
function unsafeConcreteQueryBuilderOverride(\Illuminate\Http\Request $request): void {
    $term = (string) $request->input('term');

    (new ConcreteQueryBuilderOverride())->where([['name', '=', $term]]);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
