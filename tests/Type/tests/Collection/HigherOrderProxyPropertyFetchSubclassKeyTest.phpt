--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.31.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Enumerable;

/** @extends EloquentCollection<int, Customer> */
final class PropFetchCustomers extends EloquentCollection {}

/**
 * A non-generic subclass resolves its proxy from the vendor trait annotation, whose TValue Psalm leaves
 * unresolved: the mapping goes over `Enumerable`, keyed from the proxy's TCollection. Laravel 12.27-13.30's
 * annotation has no TCollection, so the key is `array-key` there (see HigherOrderProxySubclassKeyTest).
 */
function propFetchNonGenericSubclass(PropFetchCustomers $sub): void
{
    $_map = $sub->map->id;
    /** @psalm-check-type-exact $_map = Enumerable<int, mixed> */
    $_sum = $sub->sum->id;
    /** @psalm-check-type-exact $_sum = float|int */
    $_max = $sub->max->id;
    /** @psalm-check-type-exact $_max = mixed|null */
}
?>
--EXPECTF--
