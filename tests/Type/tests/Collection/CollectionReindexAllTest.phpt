--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * CollectionReindexAllHandler narrows Collection::all() to list<TValue> when
 * the call is chained immediately after a receiver that is guaranteed to
 * reindex sequentially:
 *
 *     $c->values()->all()       // list<TValue>
 *     $c->pluck('col')->all()   // list<TValue>  (single-argument pluck only)
 *     $c->all()                 // array<TKey, TValue>  (unchanged)
 *
 * The handler keys on the AST shape — a Collection<TKey, TValue> typed
 * with TKey = int<0, max> does NOT trigger narrowing, because keys
 * matching that bound (e.g. [1 => ..., 3 => ...]) are not necessarily
 * list-shaped.
 */
final class CollectionReindexAllTest
{
    /**
     * Chain on array-key-keyed source: list inferred.
     *
     * @param Collection<array-key, string> $c
     */
    public function valuesThenAllProducesList(Collection $c): void
    {
        $r = $c->values()->all();
        /** @psalm-check-type-exact $r = list<string> */;
        echo $r[0] ?? '';
    }

    /**
     * Chain on string-keyed source: values() drops keys, all() returns list.
     *
     * @param Collection<string, int> $c
     */
    public function valuesOnStringKeyedProducesList(Collection $c): void
    {
        $r = $c->values()->all();
        /** @psalm-check-type-exact $r = list<int> */;
        echo (string) ($r[0] ?? 0);
    }

    /**
     * Plain all() on int-keyed source: handler does NOT fire, original
     * array<int, T> preserved. Guards against false list inference on
     * collections that happen to have non-negative int keys but are not
     * lists (e.g. keyed by model ids).
     *
     * @param Collection<int, string> $c
     */
    public function intKeyedAllStaysArray(Collection $c): void
    {
        $r = $c->all();
        /** @psalm-check-type-exact $r = array<int, string> */;
        echo array_values($r)[0] ?? '';
    }

    /**
     * Plain all() on string-keyed source: preserved.
     *
     * @param Collection<string, int> $c
     */
    public function stringKeyedAllStaysArray(Collection $c): void
    {
        $r = $c->all();
        /** @psalm-check-type-exact $r = array<string, int> */;
        echo (string) (array_values($r)[0] ?? 0);
    }

    /**
     * Documented limitation: variable-bound chains are not narrowed.
     * Detection is purely syntactic — the receiver of all() is not an
     * immediate values() MethodCall, so handler returns null and the
     * stubbed return type applies.
     *
     * @param Collection<array-key, string> $c
     */
    public function separatedVariableChainKeepsArrayShape(Collection $c): void
    {
        $values = $c->values();
        $r = $values->all();
        /** @psalm-check-type-exact $r = array<int, string> */;
        echo array_values($r)[0] ?? '';
    }

    /**
     * Documented limitation: nullsafe chains are not narrowed. Psalm's
     * NullsafeAnalyzer desugars `?->all()` into a VirtualMethodCall (a
     * MethodCall subclass) whose receiver is a synthesized temp variable,
     * not the inner values() call, so the receiver-shape check fails and
     * the handler returns null. Pins current behavior so a future AST
     * broadening is observable.
     *
     * @param Collection<array-key, string>|null $c
     */
    public function nullsafeChainKeepsArrayShape(?Collection $c): void
    {
        $r = $c?->values()?->all();
        /** @psalm-check-type-exact $r = array<int, string>|null */;
        echo array_values($r ?? [])[0] ?? '';
    }

    /**
     * LazyCollection mirrors the same narrowing — handler is registered
     * on it explicitly.
     *
     * @param LazyCollection<string, int> $c
     */
    public function lazyValuesThenAllProducesList(LazyCollection $c): void
    {
        $r = $c->values()->all();
        /** @psalm-check-type-exact $r = list<int> */;
        echo (string) ($r[0] ?? 0);
    }

    /**
     * Eloquent\Collection: handler registered explicitly so chain narrows.
     *
     * @param EloquentCollection<array-key, Customer> $c
     */
    public function eloquentValuesThenAllProducesList(EloquentCollection $c): void
    {
        $r = $c->values()->all();
        /** @psalm-check-type-exact $r = list<Customer> */;
        echo $r[0]?->id ?? '';
    }

    /**
     * Standard Eloquent shape Collection<int, Model>::all() is unchanged.
     *
     * @param EloquentCollection<int, Customer> $c
     */
    public function eloquentIntKeyedAllStaysArray(EloquentCollection $c): void
    {
        $r = $c->all();
        /** @psalm-check-type-exact $r = array<int, Customer> */;
        echo array_values($r)[0]?->id ?? '';
    }

    /**
     * Single-argument pluck() on a Builder reindexes sequentially, so all()
     * narrows to list<TValue> — same rule as values()->all().
     */
    public function pluckThenAllProducesList(): void
    {
        $r = Customer::query()->pluck('id')->all();
        /** @psalm-check-type-exact $r = list<string> */;
        echo $r[0] ?? '';
    }

    /**
     * Two-argument pluck() keys the result by the $key column, so it is not
     * list-shaped: the arity check in isImmediateChainFromReindex excludes it.
     */
    public function pluckWithKeyThenAllStaysArray(): void
    {
        $r = Customer::query()->pluck('email_verified_at', 'id')->all();
        /** @psalm-check-type-exact $r = array<string, \Carbon\CarbonInterface|null> */;
        echo array_key_first($r) ?? '';
    }

    /**
     * Unknown column: value type falls back to mixed, but the chain is still
     * list-shaped because no $key column was given.
     */
    public function pluckUnknownColumnThenAllProducesList(): void
    {
        $r = Customer::query()->pluck('unknown_column')->all();
        /** @psalm-check-type-exact $r = list<mixed> */;
        echo isset($r[0]) ? 'y' : 'n';
    }

    /**
     * Spread-form pluck(...$cols) is a single Arg with unpack=true, not a plain
     * positional argument — $cols may carry a $key column at runtime, so the
     * result can't be trusted to be list-shaped. Regression guard for the arity
     * check misclassifying this shape as single-argument pluck.
     *
     * @param non-empty-list<string> $cols
     */
    public function pluckSpreadArgsStaysArray(array $cols): void
    {
        $r = Customer::query()->pluck(...$cols)->all();
        /** @psalm-check-type-exact $r = array<array-key, mixed> */;
        echo (string) (array_key_first($r) ?? '');
    }
}
?>
--EXPECTF--
