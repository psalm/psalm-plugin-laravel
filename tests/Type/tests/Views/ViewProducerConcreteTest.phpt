--FILE--
<?php declare(strict_types=1);

// View::make()/file()/first() and Factory::make()/file()/first() are narrowed to the
// concrete \Illuminate\View\View, so concrete-only methods (renderSections, withErrors,
// getPath, setPath, getFactory, getEngine, fragments) resolve without errors.

$_view = \Illuminate\Support\Facades\View::make('welcome');
/** @psalm-check-type-exact $_view = \Illuminate\View\View */

$_fragment = \Illuminate\Support\Facades\View::make('welcome')->fragment('x');
/** @psalm-check-type-exact $_fragment = string */

function _diFactory(\Illuminate\View\Factory $factory): void {
    $_make = $factory->make('welcome');
    /** @psalm-check-type-exact $_make = \Illuminate\View\View */

    $_file = $factory->file(__FILE__);
    /** @psalm-check-type-exact $_file = \Illuminate\View\View */

    $_first = $factory->first(['welcome', 'also-missing']);
    /** @psalm-check-type-exact $_first = \Illuminate\View\View */
}

function _concreteOnlyMethods(\Illuminate\View\Factory $factory): void {
    $_sections = $factory->make('welcome')->renderSections();
    /** @psalm-check-type-exact $_sections = array */

    $_path = $factory->make('welcome')->getPath();
    /** @psalm-check-type-exact $_path = string */

    $factory->make('welcome')->withErrors([]);
    $factory->make('welcome')->setPath('/tmp/other.blade.php');

    $_factory = $factory->make('welcome')->getFactory();
    /** @psalm-check-type-exact $_factory = \Illuminate\View\Factory */

    $_engine = $factory->make('welcome')->getEngine();
    /** @psalm-check-type-exact $_engine = \Illuminate\Contracts\View\Engine */

    $factory->make('welcome')->fragments();
}
?>
--EXPECTF--
