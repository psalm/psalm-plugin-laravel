--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION — a scope that collides with a method the shared custom builder itself declares
 * (real or stub, here the stub's `Builder::count(string $columns = '*')`), where only some of the
 * builder's models declare the scope. Psalm asks for the call's params before any receiver-aware
 * provider runs, so `CustomBuilderMethodHandler::getScopeMethodParamsOnBuilder()` has no receiver to pick
 * `InheritedBuilderChild::scopeCount(int $limit = 0)` and validates against the builder's declaration
 * instead. Laravel runs the scope on that model, so both calls below are accepted at runtime; the
 * reported issues are accepted false positives. See the docblock on that method. #1620
 *
 * The same params-time blindness is why `InheritedGenericBuilderTest` expects the base model's
 * `count(columns: 'id')` to keep validating against the stub.
 */

use App\Models\InheritedBuilderChild;

function scope_collision_positional(): void
{
    InheritedBuilderChild::query()->count(1);
}

function scope_collision_named(): void
{
    InheritedBuilderChild::query()->count(limit: 1);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Builders\InheritedModelBuilder::count expects string, but 1 provided
InvalidNamedArgument on line %d: Parameter $limit does not exist on function App\Builders\InheritedModelBuilder::count
