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

// Accepted bounded trade-off (see ProducerReturnTypeHandler docblock), demonstrated
// with a REAL override rather than an empty subclass. OverridingFactory replaces the
// protected viewInstance() with a non-stock View but inherits make(); Psalm reports
// the declaring Factory for the inherited call, so make() still narrows to the stock
// \Illuminate\View\View even though the runtime value is a CustomView.
//
// For View the observable consequence is a false NEGATIVE only: a stock-only method
// resolves although CustomView may not implement it. The false-positive direction
// (a CustomView-only method flagged undefined) does not manifest here because the
// stock \Illuminate\View\View is Macroable, so its __call resolves any unknown method
// to mixed. A producer whose stock concrete is not Macroable (e.g. PasswordBroker)
// would exhibit the false positive too.
final class CustomView implements \Illuminate\Contracts\View\View {
    #[\Override]
    public function name(): string { return 'x'; }

    /**
     * @param array<array-key, mixed>|string $key
     * @param mixed $value
     */
    #[\Override]
    public function with($key, $value = null): static { return $this; }

    /** @return array<string, mixed> */
    #[\Override]
    public function getData(): array { return []; }

    #[\Override]
    public function render(?callable $callback = null): string { return ''; }

    public function badge(): string { return 'b'; }
}

final class OverridingFactory extends \Illuminate\View\Factory {
    #[\Override]
    protected function viewInstance($view, $path, $data): CustomView { return new CustomView(); }
}

function _factorySubclassBoundary(OverridingFactory $factory): void {
    $_view = $factory->make('welcome');
    /** @psalm-check-type-exact $_view = \Illuminate\View\View */

    // The false-positive direction is masked here: badge() is a CustomView-only
    // method, but the narrowed stock View is Macroable, so its __call resolves the
    // unknown method (to a View) instead of raising UndefinedMethod.
    $_masked = $_view->badge();
    /** @psalm-check-type-exact $_masked = \Illuminate\View\View */
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: %s
UndefinedInterfaceMethod on line %d: %s
