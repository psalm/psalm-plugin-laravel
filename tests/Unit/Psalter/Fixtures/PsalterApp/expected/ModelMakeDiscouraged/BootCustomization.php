<?php

declare(strict_types=1);

namespace PsalterFixture\Cases\ModelMake;

use PsalterFixture\Models\ModelMakeBootedModel;
use PsalterFixture\Models\ModelMakeScopedByModel;

/**
 * A global scope registered while booting (`booted()`, `#[ScopedBy]`) can add pending attributes to `make()` that
 * `new` does not apply: these calls are reported and never rewritten.
 */
final class BootCustomization
{
    public function build(): void
    {
        ModelMakeBootedModel::make();
        ModelMakeScopedByModel::make();
    }
}
