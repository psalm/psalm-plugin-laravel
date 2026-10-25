<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class ComponentExceptedMethod extends Component
{
    protected $except = ['label'];

    public function label(): string
    {
        return 'label';
    }

    public function render(): View
    {
        // `label` is excluded from `data()` for methods as it is for properties, so the int passed
        // here stays in place and must not be judged against the method's wrapper type.
        return view()->make('component-excepted-method', ['label' => 7]);
    }
}
