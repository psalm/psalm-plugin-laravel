--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Events\Dispatcher;

// Known limitation: SuppressHandler marks Dispatcher::$container as initialized for every
// subclass, because the Events\Dispatcher stub hides the vendor constructor body from Psalm.
// A subclass that never calls parent::__construct() is therefore not reported for $container,
// although listener resolution would fail at runtime. The setter-only resolvers stay reported.
// See the Dispatcher entry in SuppressHandler for the rationale.

final class SkippedParentConstructorDispatcher extends Dispatcher
{
    public function __construct()
    {
    }
}
?>
--EXPECTF--
PropertyNotSetInConstructor on line %d: Property Tests\Psalm\LaravelPlugin\Sandbox\SkippedParentConstructorDispatcher::$queueResolver is not defined in constructor of Tests\Psalm\LaravelPlugin\Sandbox\SkippedParentConstructorDispatcher or in any methods called in the constructor
PropertyNotSetInConstructor on line %d: Property Tests\Psalm\LaravelPlugin\Sandbox\SkippedParentConstructorDispatcher::$transactionManagerResolver is not defined in constructor of Tests\Psalm\LaravelPlugin\Sandbox\SkippedParentConstructorDispatcher or in any methods called in the constructor
