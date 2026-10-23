--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('13.38.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Shop;

/**
 * Before Laravel 13.38 `Relation` has no `clone()`, so the call forwards to `Eloquent\Builder::clone()`,
 * which returns a new builder that `Relation::forwardDecoratedCallTo()` does not map back to the
 * relation: the chain must decline instead of reporting the relation class (#1647).
 * Laravel 13.38 added `Relation::clone(): static`, see Issue1647RelationCloneL1338Test.phpt.
 */
function issue1647_new_instance_clone_declines(Shop $shop): mixed
{
    /** @psalm-suppress MixedAssignment */
    $result = $shop->untypedClonedLastWorkOrder();
    /** @psalm-check-type-exact $result = mixed */
    return $result;
}
?>
--EXPECTF--
