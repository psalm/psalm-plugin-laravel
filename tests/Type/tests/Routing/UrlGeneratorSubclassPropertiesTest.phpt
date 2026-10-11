--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\UrlGenerator;

// $rootNamespace, $sessionResolver, $keyResolver, $missingNamedRouteResolver, $formatHostUsing
// and $formatPathUsing stay unset until their setters run, so they are nullable and a subclass
// is not asked to initialize them. $routes and $request are still covered by the parent
// constructor, and the subclass's own uninitialized property is still reported.

final class InheritedConstructorUrlGenerator extends UrlGenerator
{
}

final class OwnConstructorUrlGenerator extends UrlGenerator
{
    private string $uninitialized;

    public function __construct(RouteCollectionInterface $routes, Request $request, private readonly string $locale)
    {
        parent::__construct($routes, $request);
    }

    public function locale(): string
    {
        return $this->locale . $this->uninitialized;
    }

    public function hasRootNamespace(): bool
    {
        /** @psalm-check-type-exact $namespace = null|string */
        $namespace = $this->rootNamespace;

        return $namespace !== null;
    }
}
?>
--EXPECTF--
PropertyNotSetInConstructor on line %d: Property Tests\Psalm\LaravelPlugin\Sandbox\OwnConstructorUrlGenerator::$uninitialized is not defined in constructor of Tests\Psalm\LaravelPlugin\Sandbox\OwnConstructorUrlGenerator or in any private or final methods called in the constructor
