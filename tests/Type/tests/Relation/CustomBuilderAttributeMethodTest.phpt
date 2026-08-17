--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Split out of CustomBuilderMethodTest.phpt: these three relations point at models whose
// custom builder is bound with #[UseEloquentBuilder], a Laravel-12-only Eloquent attribute.
// On Laravel 11 the attribute class does not exist, the builder is never associated, and the
// forwarded call degrades to mixed. The sibling file keeps the newEloquentBuilder() and
// static-$builder mechanisms, which do work on 11, running on every matrix cell.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.0.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Artist;
use App\Models\Invoice;
use App\Models\Shop;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Issue #1262: Relation::__call forwards custom methods to the related model's
 * effective builder and substitutes the Relation only for builder-returning branches.
 *
 * This file covers the #[UseEloquentBuilder] binding specifically; see the SKIPIF above.
 */

function test_use_eloquent_builder_attribute_fluent_method(): void {
    $_result = (new Shop())->workOrders()->whereCompleted();
    /** @psalm-check-type-exact $_result = HasMany<WorkOrder, Shop> */
}

function test_non_templated_custom_builder_fluent_method(): void {
    // Explicitly reference the related model so psalm-tester scans and registers its
    // metadata before analyzing the relation-only call in this isolated fixture.
    static function (Invoice $_invoice): void {};
    $_result = (new Shop())->latestInvoice()->wherePaid();
    /** @psalm-check-type-exact $_result = HasOne<Invoice, Shop> */
}

function test_method_inherited_from_abstract_custom_builder(): void {
    static function (Artist $_artist): void {};
    $_result = (new Shop())->artists()->accessible();
    /** @psalm-check-type-exact $_result = HasMany<Artist, Shop> */

    $_nullable = (new Shop())->artists()->accessibleOrNull();
    /** @psalm-check-type-exact $_nullable = HasMany<Artist, Shop>|null */
}
?>
--EXPECTF--
