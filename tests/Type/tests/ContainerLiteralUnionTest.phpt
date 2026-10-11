--FILE--
<?php declare(strict_types=1);

// `app($flag ? 'cache' : 'db')` has a union-of-string-literals abstract. ContainerResolver resolves
// every literal through the container and returns the combined union, or declines (mixed) when any
// element cannot be resolved or when a non-literal member is mixed in.

use Illuminate\Cache\CacheManager;
use Illuminate\Database\DatabaseManager;

function literalUnionViaApp(bool $flag): int
{
    $manager = app($flag ? 'cache' : 'db');
    /** @psalm-check-type-exact $manager = CacheManager|DatabaseManager */
    return \spl_object_id($manager);
}

function literalUnionViaResolve(bool $flag): int
{
    $manager = resolve($flag ? 'cache' : 'db');
    /** @psalm-check-type-exact $manager = CacheManager|DatabaseManager */
    return \spl_object_id($manager);
}

function classConstantUnion(bool $flag): int
{
    $manager = app($flag ? CacheManager::class : DatabaseManager::class);
    /** @psalm-check-type-exact $manager = CacheManager|DatabaseManager */
    return \spl_object_id($manager);
}

function literalUnionViaMake(bool $flag): int
{
    $manager = app()->make($flag ? 'cache' : 'db');
    /** @psalm-check-type-exact $manager = CacheManager|DatabaseManager */
    return \spl_object_id($manager);
}

function literalUnionViaArrayAccess(bool $flag): int
{
    $manager = app()[$flag ? 'cache' : 'db'];
    /** @psalm-check-type-exact $manager = CacheManager|DatabaseManager */
    return \spl_object_id($manager);
}

// An alias and its concrete class name resolve to the same class: one atomic.
function sameClassCollapses(bool $flag): int
{
    $manager = app($flag ? 'cache' : CacheManager::class);
    /** @psalm-check-type-exact $manager = CacheManager */
    return \spl_object_id($manager);
}

// One unresolvable element declines the whole call: a partial union would be unsound.
function unresolvableBranchDeclines(bool $flag): void
{
    $manager = app($flag ? 'cache' : 'definitely-not-bound');
    /** @psalm-check-type-exact $manager = mixed */
}

// A literal mixed with a non-literal member declines.
/** @param 'cache'|class-string<\Illuminate\Routing\Redirector> $abstract */
function literalWithClassStringDeclines(string $abstract): void
{
    $manager = app($abstract);
    /** @psalm-check-type-exact $manager = mixed */
}
?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $manager is being assigned to
UnusedVariable on line %d: $manager is never referenced or the value is not used
MixedAssignment on line %d: Unable to determine the type that $manager is being assigned to
UnusedVariable on line %d: $manager is never referenced or the value is not used
