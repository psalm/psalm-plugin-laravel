--FILE--
<?php declare(strict_types=1);

// Boundary tests: producer narrowing must never leak onto bare contract-typed values —
// only the producer class (Factory), its canonical facade (View), and root aliases are
// registered, never the View/Factory contracts themselves.

function _bareContractFactory(\Illuminate\Contracts\View\Factory $factory): void {
    $_view = $factory->make('welcome');
    /** @psalm-check-type-exact $_view = \Illuminate\Contracts\View\View */

    $_view->fragment('x');
}

function _bareContractView(\Illuminate\Contracts\View\View $view): void {
    $view->fragment('x');
}

// Intent pin for the accepted bounded trade-off (see ProducerReturnTypeHandler
// docblock): a Factory subclass that inherits make() narrows to the stock View
// even though a protected viewInstance() override could construct a different
// implementation — Psalm reports the declaring class for inherited methods.
final class SubFactory extends \Illuminate\View\Factory {}

function _factorySubclassPin(SubFactory $factory): void {
    $_view = $factory->make('welcome');
    /** @psalm-check-type-exact $_view = \Illuminate\View\View */
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: %s
UndefinedInterfaceMethod on line %d: %s
