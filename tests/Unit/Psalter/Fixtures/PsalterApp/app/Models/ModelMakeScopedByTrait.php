<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;

/**
 * Laravel collects `#[ScopedBy]` from the traits a model uses, so this trait registers a global scope.
 */
#[ScopedBy(ModelMakeTenantScope::class)]
trait ModelMakeScopedByTrait {}
