--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.5.0');
--FILE--
<?php declare(strict_types=1);

// Laravel 13.5.0+ accepts a deferred-value closure for the $values argument of
// firstOrNew()/updateOrCreate(). The stubs/13.5.0/ overrides widen $values to
// (\Closure(): array<string, mixed>)|array<string, mixed>; this test asserts the
// closure form is accepted (no ArgumentTypeCoercion) AND that the model/template/
// pivot metadata still resolves through each override's copied class header.

use App\Models\Customer;
use App\Models\Part;
use App\Models\Shop;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\Pivot;

// Eloquent\Builder
function test_builder(): void {
    $_arr = Customer::query()->firstOrNew(['email' => 'a@b.c'], ['name' => 'x']);
    /** @psalm-check-type-exact $_arr = Customer */

    $_closure = Customer::query()->firstOrNew(['email' => 'a@b.c'], fn(): array => ['name' => 'x']);
    /** @psalm-check-type-exact $_closure = Customer */

    $_upd = Customer::query()->updateOrCreate(['email' => 'a@b.c'], fn(): array => ['name' => 'x']);
    /** @psalm-check-type-exact $_upd = Customer */
}

// HasOneOrMany (via HasMany)
function test_has_many(Shop $shop): void {
    $_new = $shop->workOrders()->firstOrNew(['shop_id' => 1], fn(): array => ['status' => 'open']);
    /** @psalm-check-type-exact $_new = WorkOrder */

    $_upd = $shop->workOrders()->updateOrCreate(['shop_id' => 1], fn(): array => ['status' => 'open']);
    /** @psalm-check-type-exact $_upd = WorkOrder */
}

// BelongsToMany
function test_belongs_to_many(Shop $shop): void {
    $_new = $shop->parts()->firstOrNew(['name' => 'bolt'], fn(): array => ['price' => 1]);
    /** @psalm-check-type-exact $_new = Part&object{pivot: Pivot} */

    $_upd = $shop->parts()->updateOrCreate(['name' => 'bolt'], fn(): array => ['price' => 1]);
    /** @psalm-check-type-exact $_upd = Part&object{pivot: Pivot} */
}
?>
--EXPECTF--
