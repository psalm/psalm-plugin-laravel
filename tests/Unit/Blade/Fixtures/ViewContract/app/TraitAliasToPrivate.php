<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

trait PublicHelperTrait
{
    public function helper(): View
    {
        return view('greeting');
    }
}

// The using class makes the trait's public method private: a helper, so its caller completes the view.
final class AdaptedToPrivate
{
    use PublicHelperTrait {
        helper as private;
    }

    public function show(): View
    {
        return $this->helper()->with('name', 'Ada');
    }
}
