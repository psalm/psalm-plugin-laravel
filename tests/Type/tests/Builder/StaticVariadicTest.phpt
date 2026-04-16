--FILE--
<?php declare(strict_types=1);

use App\Builders\WorkOrderBuilder;
use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Issue #216: @psalm-variadic methods should accept variadic args when called
 * statically on Models or through Relation __call forwarding.
 *
 * Query\Builder::select() uses @psalm-variadic (internally func_get_args()).
 * ModelMethodHandler::getParamsWithVariadicFlag() propagates the storage-level
 * flag to the parameter level so Psalm allows extra arguments.
 */

// --- Model static calls (ModelMethodHandler) ---

/** Static call with multiple string args — the original #216 case. */
function test_static_select_variadic(): void
{
    $_result = Customer::select('name', 'email');
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

/** Static call with array arg. */
function test_static_select_array(): void
{
    $_result = Customer::select(['name', 'email']);
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

/** addSelect() is also @psalm-variadic. */
function test_static_addselect_variadic(): void
{
    $_result = Customer::addSelect('name', 'email');
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

/** distinct() has zero formal params — purely func_get_args(). */
function test_static_distinct_variadic(): void
{
    $_result = Customer::distinct('name');
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

/** Custom builder model returns WorkOrderBuilder<WorkOrder>, not base Builder. */
function test_custom_builder_static_select_variadic(): void
{
    $_result = WorkOrder::select('title', 'body');
    // L12: WorkOrderBuilder<WorkOrder>&static; L11: Eloquent\Builder<WorkOrder>&static (handler differs)
    /** @psalm-check-type $_result is Illuminate\Database\Eloquent\Builder */
}

// --- Arity preservation: required params still enforced ---

/** addSelect($column) requires at least one arg — variadic flag must not relax arity. */
function test_addselect_zero_args_still_fails(): void
{
    $_result = Customer::addSelect();
}

/** Non-variadic methods must still reject extra args. */
function test_non_variadic_too_many_args(): void
{
    $_result = Customer::orderBy('name', 'asc', 'extra');
}

// --- Relation instance calls (MethodForwardingHandler) ---

/** select() has its own stub on Relation — exercises Path 1 (mixin interception). */
/** @param HasOne<Invoice, WorkOrder> $r */
function test_relation_select_variadic(HasOne $r): void
{
    $_result = $r->select('name', 'email');
    /** @psalm-check-type-exact $_result = HasOne<Invoice, WorkOrder>&static */
}

/** addSelect/distinct go through Path 2 (MethodForwardingHandler __call). */
/** @param HasOne<Invoice, WorkOrder> $r */
function test_relation_addselect_variadic(HasOne $r): void
{
    $_result = $r->addSelect('name', 'email');
    /** @psalm-check-type-exact $_result = HasOne<Invoice, WorkOrder> */
}

/** @param HasOne<Invoice, WorkOrder> $r */
function test_relation_distinct_variadic(HasOne $r): void
{
    $_result = $r->distinct('name');
    /** @psalm-check-type-exact $_result = HasOne<Invoice, WorkOrder> */
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for App\Models\Customer::addselect - expecting column to be passed
TooManyArguments on line %d: Too many arguments for App\Models\Customer::orderby - expecting 2 but saw 3
