<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Support\PromotesMessage;
use Illuminate\View\Component;

/** The trait constructor is analyzed under this class, while the tag instantiates the concrete subclass. */
abstract class TraitCtorBase extends Component
{
    use PromotesMessage;
}
