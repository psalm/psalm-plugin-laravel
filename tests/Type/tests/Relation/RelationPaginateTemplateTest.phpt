--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/1051
 * and https://github.com/psalm/psalm-plugin-laravel/issues/1052.
 *
 * #1051: paginate() forwarded through a relation must keep the related-model
 * template (HasMany<Vehicle, …>::paginate() => LengthAwarePaginator<int, Vehicle>),
 * not collapse it to the `Model` bound of TRelatedModel.
 *
 * #1052: the paginator must be the concrete Illuminate\Pagination class, so the
 * concrete-only surface (getCollection(), …) resolves on the result.
 */

function relation_paginate_keeps_template(Customer $customer): void
{
    $_paginator = $customer->vehicles()->paginate();
    /** @psalm-check-type-exact $_paginator = LengthAwarePaginator<int, Vehicle> */

    // #1052/#978 — getCollection() lives on the concrete AbstractPaginator only.
    $_collection = $customer->vehicles()->paginate()->getCollection();
    /** @psalm-check-type-exact $_collection = Collection<int, Vehicle> */
}

function relation_simple_paginate_keeps_template(Customer $customer): void
{
    $_paginator = $customer->vehicles()->simplePaginate();
    /** @psalm-check-type-exact $_paginator = Paginator<int, Vehicle> */
}

function relation_cursor_paginate_keeps_template(Customer $customer): void
{
    $_paginator = $customer->vehicles()->cursorPaginate();
    /** @psalm-check-type-exact $_paginator = CursorPaginator<int, Vehicle> */
}

function has_many_through_paginate_keeps_template(Customer $customer): void
{
    $_paginator = $customer->workOrders()->paginate();
    /** @psalm-check-type-exact $_paginator = LengthAwarePaginator<int, WorkOrder> */

    $_simple = $customer->workOrders()->simplePaginate();
    /** @psalm-check-type-exact $_simple = Paginator<int, WorkOrder> */

    $_cursor = $customer->workOrders()->cursorPaginate();
    /** @psalm-check-type-exact $_cursor = CursorPaginator<int, WorkOrder> */
}

function belongs_to_many_paginate_keeps_pivot(Mechanic $mechanic): void
{
    $_paginator = $mechanic->specializations()->paginate();
    /** @psalm-check-type-exact $_paginator = LengthAwarePaginator<int, MechanicSpecialization&object{pivot: Pivot}> */
}

/**
 * BelongsTo extends Relation directly (not HasOneOrMany), so this pins the fix to the
 * BASE Relation class. If paginate() were ever moved down to HasOneOrMany, every case
 * above would still pass while this one would regress — that is exactly what it guards.
 */
function belongs_to_paginate_keeps_template(Part $part): void
{
    $_paginator = $part->supplier()->paginate();
    /** @psalm-check-type-exact $_paginator = LengthAwarePaginator<int, Supplier> */
}

/**
 * HasOneThrough is the OTHER subclass of HasOneOrManyThrough (alongside HasManyThrough),
 * so this guards the contract->concrete change on that stub for the has-one branch.
 */
function has_one_through_paginate_keeps_template(Mechanic $mechanic): void
{
    $_paginator = $mechanic->vehicleOwner()->paginate();
    /** @psalm-check-type-exact $_paginator = LengthAwarePaginator<int, Customer> */

    $_simple = $mechanic->vehicleOwner()->simplePaginate();
    /** @psalm-check-type-exact $_simple = Paginator<int, Customer> */

    $_cursor = $mechanic->vehicleOwner()->cursorPaginate();
    /** @psalm-check-type-exact $_cursor = CursorPaginator<int, Customer> */
}

/**
 * The base Relation forwards to Eloquent\Builder::paginate via __call, so its paginate()
 * carries the Closure $perPage and the fifth $total argument. These named-argument calls
 * must type-check (no TooManyArguments / InvalidArgument) and keep the template.
 */
function relation_paginate_accepts_total_and_closure(Customer $customer): void
{
    $_total = $customer->vehicles()->paginate(total: 100);
    /** @psalm-check-type-exact $_total = LengthAwarePaginator<int, Vehicle> */

    $_closure = $customer->vehicles()->paginate(perPage: fn (int $total): int => $total, total: fn (): int => 100);
    /** @psalm-check-type-exact $_closure = LengthAwarePaginator<int, Vehicle> */
}
?>
--EXPECTF--
