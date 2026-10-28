--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Contracts\Container\Container;
use Illuminate\Events\Dispatcher;

// The Dispatcher stub re-declares the class, which hides the vendor constructor body from Psalm,
// so Psalm takes the properties of Dispatcher as initialized by parent::__construct(), while the
// subclass's own uninitialized property is still reported.

final class CustomDispatcher extends Dispatcher
{
    private string $uninitialized;

    public function __construct(?Container $container = null)
    {
        parent::__construct($container);
    }

    public function label(): string
    {
        return $this->uninitialized;
    }
}
?>
--EXPECTF--
PropertyNotSetInConstructor on line %d: Property Tests\Psalm\LaravelPlugin\Sandbox\CustomDispatcher::$uninitialized is not defined in constructor of Tests\Psalm\LaravelPlugin\Sandbox\CustomDispatcher or in any private or final methods called in the constructor
