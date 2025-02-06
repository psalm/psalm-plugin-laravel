--FILE--
<?php declare(strict_types=1);

$application = new \Illuminate\Foundation\Application();

$application->undefined_method("a", "b");

$_redirector = $application->make(\Illuminate\Routing\Redirector::class);
/** @psalm-check-type-exact $_redirector = \Illuminate\Routing\Redirector */

$_redirectorUsingArrayAccess = $application[\Illuminate\Routing\Redirector::class];
/** @psalm-check-type-exact $_redirectorUsingArrayAccess = \Illuminate\Routing\Redirector */

// the app function helper resolves correct types
class Foo3
{
    public function appHelperGetContainer(): \Illuminate\Contracts\Foundation\Application
    {
        return app();
    }

    public function appHelperResolvesTypes(): \Illuminate\Routing\Redirector
    {
        return app(\Illuminate\Routing\Redirector::class);
    }
}

// the resolve function helper resolves correct types
class Foo4
{
    public function resolveHelperResolvesTypes(): \Illuminate\Routing\Redirector
    {
        return resolve(\Illuminate\Routing\Redirector::class);
    }
}

// app helper can be chained with make / makeWith
function testMake(): \Illuminate\Routing\Redirector
{
    return app()->make(\Illuminate\Routing\Redirector::class);
}

function testMakeWith(): \Illuminate\Routing\Redirector
{
    return app()->makeWith(\Illuminate\Routing\Redirector::class);
}

// container can resolve aliases
function canResolveKnownDependencyByAlias(): \Illuminate\Log\LogManager
{
    return app()->make('log');
}

function canResolveKnownDependencyByAliasWith(): \Illuminate\Log\LogManager
{
    return app()->makeWith('log');
}

// container cannot resolve unknown aliases
function cannotResolveUnknownDependency(): \Illuminate\Log\LogManager
{
    return app()->make('logg');
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method Illuminate\Foundation\Application::undefined_method does not exist
MixedReturnStatement on line %d: Could not infer a return type
