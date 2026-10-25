<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\View\Component;

/** `self` and `parent` in an inherited or trait method name the class the method appears in, not the rendered one. */
abstract class VcBase extends Component
{
    use VcTrait;

    public function takesParent(parent $component): string
    {
        return $component::class;
    }

    public function retParent(int $x): parent
    {
        return $this;
    }
}
