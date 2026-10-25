<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\View\Component;

class ComponentOwnerBase extends Component
{
    use ComponentOwnerProperties;

    public parent $owner;
}
