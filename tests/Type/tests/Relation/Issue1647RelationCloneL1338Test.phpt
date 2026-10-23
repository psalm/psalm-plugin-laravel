--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.38.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Shop;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Laravel 13.38 added `Relation::clone(): static` (laravel/framework#62081), so cloning a relation
 * keeps its class at runtime and the chain `hasMany()->one()->clone()` is a `HasOne` (#1647).
 * Earlier versions decline, see Issue1647RelationCloneBeforeL1338Test.phpt.
 */
function issue1647_relation_clone_keeps_relation(Shop $shop): HasOne
{
    $result = $shop->untypedClonedLastWorkOrder();
    /** @psalm-check-type-exact $result = HasOne<WorkOrder, Shop> */
    return $result;
}
?>
--EXPECTF--
