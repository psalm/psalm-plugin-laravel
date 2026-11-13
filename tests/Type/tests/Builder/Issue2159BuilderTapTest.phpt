--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/2159.
 *
 * `BuildsQueries::tap()` declares `callable($this)`, which Psalm resolves to `callable(Builder<Model>)`
 * inside the generic trait, so a callback typed `Builder<Customer>` was reported as ArgumentTypeCoercion.
 */
final class WhereActiveCustomer
{
    /** @param Builder<Customer> $query */
    public function __invoke(Builder $query): void
    {
        $query->where('active', true);
    }
}

function tap_accepts_typed_closure(): void
{
    $_builder = Customer::query()->tap(static function (Builder $query): void {
        $query->where('id', 1);
    });
    /** @psalm-check-type-exact $_builder = Builder<Customer>&static */
}

function tap_accepts_docblock_typed_closure(): void
{
    Customer::query()->tap(
        /** @param Builder<Customer> $query */
        static function (Builder $query): void {
            $query->where('id', 1);
        },
    );
}

function tap_accepts_invokable_scope_object(): void
{
    Customer::query()->tap(new WhereActiveCustomer());
}
?>
--EXPECTF--
