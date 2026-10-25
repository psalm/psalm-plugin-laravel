<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * @template T of int
 */
final class ComponentBoundedProperty extends Component
{
    /** @var T */
    public $value;

    /** @param T $value */
    public function __construct($value)
    {
        $this->value = $value;
    }

    public function render(): View
    {
        // `T` is unbound at the call site but never wider than its bound: an `int` still cannot be a `string`.
        return view()->make('component-bounded-property', ['component' => $this]);
    }
}
