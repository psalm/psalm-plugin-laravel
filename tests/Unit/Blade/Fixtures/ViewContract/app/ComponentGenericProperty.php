<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * @template T
 */
final class ComponentGenericProperty extends Component
{
    /** @var T */
    public $value;

    public int $count = 0;

    /** @param T $value */
    public function __construct($value)
    {
        $this->value = $value;
    }

    public function render(): View
    {
        // `T` is unbound inside the class, so `$value` says nothing about what a template receives.
        // `$count` is a plain `int`, and a wrong declaration of it still reports.
        return view()->make('component-generic-property', ['component' => $this]);
    }
}
