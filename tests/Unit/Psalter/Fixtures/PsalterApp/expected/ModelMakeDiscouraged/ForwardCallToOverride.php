<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\ImplicitQueryBuilderCallForwardCallModel;

/**
 * A `forwardCallTo()` override sits on the route of `make()`: reported, never rewritten.
 */
final class ForwardCallToOverride
{
    public function build(): ImplicitQueryBuilderCallForwardCallModel
    {
        return ImplicitQueryBuilderCallForwardCallModel::make();
    }
}
