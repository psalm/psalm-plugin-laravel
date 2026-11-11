<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ImplicitQueryBuilder;

use PsalterFixture\Models\ImplicitQueryBuilderCallForwardCallModel;

/**
 * A `forwardCallTo()` override sits on the route of a forwarded call: reported, never rewritten.
 */
final class ForwardCallToOverride
{
    public function run(): void
    {
        ImplicitQueryBuilderCallForwardCallModel::where('tenant_id', 99)->get();
    }
}
