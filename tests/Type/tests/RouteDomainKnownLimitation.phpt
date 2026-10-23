--FILE--
<?php declare(strict_types=1);

use Illuminate\Routing\Route;

/**
 * KNOWN LIMITATION: a possibly-empty unpack binds `TDomain` from the element type and misses the getter
 * branch. Same as `fragment()`; Laravel 13.18+'s native conditional behaves identically.
 *
 * @param list<string> $args
 */
function domain_possibly_empty_unpack(Route $route, array $args): void
{
    $_result = $route->domain(...$args);
    /** @psalm-check-type-exact $_result = Illuminate\Routing\Route&static */
}

/**
 * KNOWN LIMITATION: a subclass override with a native return only (no docblock) still resolves through
 * the inherited conditional docblock, because Psalm prefers the inherited docblock return over the native one.
 * Same on Laravel 13.18+.
 */
class ChildRoute extends Route
{
    #[\Override]
    public function domain($domain = null): string
    {
        return 'child';
    }
}

function domain_subclass_native_return(ChildRoute $child): void
{
    $_result = $child->domain('x');
    /** @psalm-check-type-exact $_result = ChildRoute&static */
}

/**
 * KNOWN LIMITATION: a first-class callable leaves `TDomain` unresolved, so calling it with a string is
 * reported as InvalidArgument. Laravel 13.18+'s native type reports it too (as `TGeneratedFromParam0`).
 */
function domain_first_class_callable(Route $route): void
{
    $fn = $route->domain(...);
    $fn('api.example.com');
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 expects TDomain:fn-illuminate\routing\route::domain as BackedEnum|null|string, but 'api.example.com' provided
